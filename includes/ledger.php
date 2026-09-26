<?php
/**
 * The provenance ledger — append-only and hash-chained.
 *
 * Each entry stores its data (canonical JSON), the SHA-256 of that data, the
 * previous entry's hash and its own hash = SHA-256(prev | created | event |
 * attachment | release | data_sha256). Editing or deleting ANY past row breaks
 * every hash after it, which "Verify ledger" detects. Once a day the head hash
 * is timestamped with OpenTimestamps (anchored in the Bitcoin blockchain, free)
 * so we can prove the ledger existed in that exact state on that date — an
 * independent witness no one at DPF could back-date.
 *
 * Also here: Wayback Machine captures of every source page, and the one-click
 * "evidence package" ZIP for a claim response.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function bl_img_ledger_table() {
	global $wpdb;
	return $wpdb->prefix . 'bl_img_ledger';
}

function bl_img_entry_hash( $prev, $created, $event, $att, $rel, $data_sha ) {
	return hash( 'sha256', $prev . '|' . $created . '|' . $event . '|' . (int) $att . '|' . (int) $rel . '|' . $data_sha );
}

/**
 * Append one event. $data is any array (stored as canonical JSON).
 * Serialized with a MySQL named lock so two simultaneous imports can't fork the chain.
 */
function bl_img_ledger_append( $event, $data, $attachment_id = 0, $release_id = 0, $actor = null ) {
	global $wpdb;
	$t     = bl_img_ledger_table();
	$actor = $actor ? $actor : bl_img_current_actor();
	$json  = bl_img_canonical_json( $data );
	$dsha  = hash( 'sha256', $json );

	$wpdb->get_var( "SELECT GET_LOCK('bl_img_ledger', 10)" );
	$prev    = (string) $wpdb->get_var( "SELECT entry_hash FROM $t ORDER BY id DESC LIMIT 1" );
	$prev    = $prev ? $prev : str_repeat( '0', 64 );
	$created = bl_img_now_utc();
	$hash    = bl_img_entry_hash( $prev, $created, $event, $attachment_id, $release_id, $dsha );
	$wpdb->insert( $t, array(
		'created_utc'   => $created,
		'event'         => substr( $event, 0, 40 ),
		'attachment_id' => (int) $attachment_id,
		'release_id'    => (int) $release_id,
		'user_id'       => (int) $actor['user_id'],
		'user_login'    => (string) $actor['user_login'],
		'user_name'     => (string) $actor['user_name'],
		'ip'            => (string) $actor['ip'],
		'data'          => $json,
		'data_sha256'   => $dsha,
		'prev_hash'     => $prev,
		'entry_hash'    => $hash,
	) );
	$id = (int) $wpdb->insert_id;
	$wpdb->get_var( "SELECT RELEASE_LOCK('bl_img_ledger')" );
	return array( 'id' => $id, 'hash' => $hash, 'created' => $created );
}

function bl_img_ledger_for( $attachment_id = 0, $release_id = 0 ) {
	global $wpdb;
	$t = bl_img_ledger_table();
	if ( $attachment_id ) {
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE attachment_id = %d ORDER BY id ASC", $attachment_id ), ARRAY_A );
	}
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE release_id = %d ORDER BY id ASC", $release_id ), ARRAY_A );
}

/** Walk the whole chain. Returns [ ok, count, first_bad_id, message, head ]. */
function bl_img_ledger_verify() {
	global $wpdb;
	$t     = bl_img_ledger_table();
	$prev  = str_repeat( '0', 64 );
	$n     = 0;
	$last  = 0;
	$batch = 500;
	do {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE id > %d ORDER BY id ASC LIMIT %d", $last, $batch ), ARRAY_A );
		foreach ( $rows as $r ) {
			$n++;
			$last = (int) $r['id'];
			if ( hash( 'sha256', $r['data'] ) !== $r['data_sha256'] ) {
				return array( 'ok' => false, 'count' => $n, 'bad' => $last, 'message' => 'Entry #' . $last . ': data was altered after it was written.' );
			}
			if ( $r['prev_hash'] !== $prev ) {
				return array( 'ok' => false, 'count' => $n, 'bad' => $last, 'message' => 'Entry #' . $last . ': chain broken — an earlier entry was altered or deleted.' );
			}
			$h = bl_img_entry_hash( $r['prev_hash'], $r['created_utc'], $r['event'], $r['attachment_id'], $r['release_id'], $r['data_sha256'] );
			if ( $h !== $r['entry_hash'] ) {
				return array( 'ok' => false, 'count' => $n, 'bad' => $last, 'message' => 'Entry #' . $last . ': entry fields were altered.' );
			}
			$prev = $r['entry_hash'];
		}
	} while ( count( $rows ) === $batch );
	return array( 'ok' => true, 'count' => $n, 'bad' => 0, 'message' => $n ? 'All ' . $n . ' entries intact.' : 'Ledger is empty.', 'head' => $prev );
}

/* ------------------------------------------------------------------ *
 *  Daily: OpenTimestamps anchor + pending Wayback captures
 * ------------------------------------------------------------------ */

add_action( 'bl_img_daily', 'bl_img_daily_tasks' );
function bl_img_daily_tasks() {
	if ( bl_img_setting( 'ots' ) ) {
		bl_img_ots_anchor();
	}
	bl_img_wayback_retry_pending();
}

/**
 * Submit the ledger head hash to two public OpenTimestamps calendars. The
 * returned receipts are saved to the vault; `ots upgrade` + `ots verify`
 * (free CLI) later turn them into a Bitcoin-anchored proof.
 */
function bl_img_ots_anchor( $force = false ) {
	global $wpdb;
	$t    = bl_img_ledger_table();
	$head = $wpdb->get_row( "SELECT id, entry_hash FROM $t WHERE event <> 'ots_anchor' ORDER BY id DESC LIMIT 1", ARRAY_A );
	if ( ! $head ) {
		return array( 'ok' => false, 'message' => 'Nothing to anchor yet.' );
	}
	$last = get_option( 'bl_img_ots_last_head' );
	if ( ! $force && $last === $head['entry_hash'] ) {
		return array( 'ok' => true, 'message' => 'Head unchanged since last anchor.' );
	}
	$digest   = hex2bin( $head['entry_hash'] );
	$receipts = array();
	foreach ( array( 'https://a.pool.opentimestamps.org', 'https://b.pool.opentimestamps.org', 'https://alice.btc.calendar.opentimestamps.org' ) as $cal ) {
		$r = wp_remote_post( $cal . '/digest', array(
			'timeout'    => 20,
			'headers'    => array( 'Accept' => 'application/vnd.opentimestamps.v1', 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'       => $digest,
			'user-agent' => bl_img_user_agent_header(),
		) );
		if ( ! is_wp_error( $r ) && 200 === wp_remote_retrieve_response_code( $r ) ) {
			$bytes = wp_remote_retrieve_body( $r );
			list( $rel, $sha ) = bl_img_vault_put( 'ledger/ots', 'head-' . $head['id'] . '-' . preg_replace( '/[^a-z]/', '', parse_url( $cal, PHP_URL_HOST ) ) . '.receipt', $bytes );
			$receipts[] = array( 'calendar' => $cal, 'file' => $rel, 'sha256' => $sha, 'bytes' => strlen( $bytes ) );
		}
	}
	if ( ! $receipts ) {
		return array( 'ok' => false, 'message' => 'No OpenTimestamps calendar reachable; will retry tomorrow.' );
	}
	update_option( 'bl_img_ots_last_head', $head['entry_hash'], false );
	bl_img_ledger_append( 'ots_anchor', array(
		'anchored_entry_id' => (int) $head['id'],
		'anchored_hash'     => $head['entry_hash'],
		'receipts'          => $receipts,
		'how_to_verify'     => 'Install the OpenTimestamps client (pip install opentimestamps-client). Build a .ots file from the receipt (or ask any OTS tool to "upgrade" the calendar receipt) and verify it against the anchored_hash digest.',
	), 0, 0, array( 'user_id' => 0, 'user_login' => 'system', 'user_name' => 'WP-Cron', 'ip' => '', 'ua' => '' ) );
	return array( 'ok' => true, 'message' => 'Anchored entry #' . $head['id'] . ' with ' . count( $receipts ) . ' calendar(s).' );
}

/* ------------------------------------------------------------------ *
 *  Wayback Machine — independent third-party capture of the source page
 * ------------------------------------------------------------------ */

function bl_img_wayback_queue( $attachment_id, $url ) {
	if ( ! bl_img_setting( 'wayback' ) || ! $url ) {
		return;
	}
	update_post_meta( $attachment_id, '_bl_img_wayback_status', 'pending' );
	update_post_meta( $attachment_id, '_bl_img_wayback_target', $url );
	wp_schedule_single_event( time() + 20, 'bl_img_wayback_capture', array( (int) $attachment_id ) );
}

add_action( 'bl_img_wayback_capture', 'bl_img_wayback_capture' );
function bl_img_wayback_capture( $attachment_id ) {
	$url = get_post_meta( $attachment_id, '_bl_img_wayback_target', true );
	if ( ! $url ) {
		return;
	}
	$tries = (int) get_post_meta( $attachment_id, '_bl_img_wayback_tries', true ) + 1;
	update_post_meta( $attachment_id, '_bl_img_wayback_tries', $tries );

	$r = wp_remote_get( 'https://web.archive.org/save/' . $url, array(
		'timeout'     => 60,
		'redirection' => 0,
		'user-agent'  => bl_img_user_agent_header(),
	) );
	$snap = '';
	if ( ! is_wp_error( $r ) ) {
		$loc = wp_remote_retrieve_header( $r, 'content-location' );
		if ( ! $loc ) {
			$loc = wp_remote_retrieve_header( $r, 'location' );
		}
		if ( $loc && preg_match( '#/web/\d{14}#', $loc ) ) {
			$snap = 0 === strpos( $loc, 'http' ) ? $loc : 'https://web.archive.org' . $loc;
		}
	}
	if ( ! $snap ) {
		// Fall back to asking the availability API for the closest existing capture.
		$a = wp_remote_get( 'https://archive.org/wayback/available?url=' . rawurlencode( $url ), array( 'timeout' => 20 ) );
		if ( ! is_wp_error( $a ) ) {
			$d = json_decode( wp_remote_retrieve_body( $a ), true );
			if ( ! empty( $d['archived_snapshots']['closest']['url'] ) ) {
				$ts = $d['archived_snapshots']['closest']['timestamp'];
				// Only accept a capture from within 2 days of import as "our" witness.
				$imp = strtotime( (string) get_post_meta( $attachment_id, '_bl_img_imported_at', true ) );
				$cap = strtotime( substr( $ts, 0, 8 ) . 'T' . substr( $ts, 8, 6 ) . 'Z' );
				if ( $imp && $cap && abs( $cap - $imp ) < 2 * DAY_IN_SECONDS ) {
					$snap = $d['archived_snapshots']['closest']['url'];
				}
			}
		}
	}
	if ( $snap ) {
		update_post_meta( $attachment_id, '_bl_img_wayback_url', esc_url_raw( $snap ) );
		update_post_meta( $attachment_id, '_bl_img_wayback_status', 'done' );
		bl_img_ledger_append( 'wayback_capture', array( 'source_page' => $url, 'snapshot' => $snap ), $attachment_id, 0, array( 'user_id' => 0, 'user_login' => 'system', 'user_name' => 'WP-Cron', 'ip' => '', 'ua' => '' ) );
	} elseif ( $tries < 4 ) {
		wp_schedule_single_event( time() + HOUR_IN_SECONDS * $tries, 'bl_img_wayback_capture', array( (int) $attachment_id ) );
	} else {
		update_post_meta( $attachment_id, '_bl_img_wayback_status', 'failed' );
	}
}

function bl_img_wayback_retry_pending() {
	$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => 20, 'meta_key' => '_bl_img_wayback_status', 'meta_value' => 'pending' ) );
	foreach ( $ids as $id ) {
		if ( ! wp_next_scheduled( 'bl_img_wayback_capture', array( (int) $id ) ) ) {
			wp_schedule_single_event( time() + 60, 'bl_img_wayback_capture', array( (int) $id ) );
		}
	}
}

/* ------------------------------------------------------------------ *
 *  Evidence package — everything needed to answer a claim, in one ZIP
 * ------------------------------------------------------------------ */

function bl_img_evidence_zip( $attachment_id ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'nozip', 'The server lacks ZipArchive.' );
	}
	$attachment_id = (int) $attachment_id;
	bl_img_raise_limits();
	$tmp           = wp_tempnam( 'bl-img-evidence-' . $attachment_id . '.zip' );
	$zip           = new ZipArchive();
	if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
		return new WP_Error( 'zip', 'Could not create ZIP.' );
	}
	$folder = 'evidence-' . $attachment_id;

	// 1. Certificate (human-readable).
	$zip->addFromString( $folder . '/00-PROVENANCE-CERTIFICATE.html', bl_img_certificate_html( $attachment_id ) );

	// 2. Vault files for this item (master original, API snapshot, landing page, attestation…).
	$dir = bl_img_vault_dir( 'items/' . $attachment_id );
	foreach ( bl_img_list_files( $dir ) as $f ) {
		$zip->addFile( $f, $folder . '/vault/' . ltrim( str_replace( $dir, '', $f ), '/' ) );
	}

	// 3. Linked releases (signed certificates + scans + signatures).
	foreach ( bl_img_get_releases( $attachment_id ) as $rid ) {
		$rdir = bl_img_vault_dir( 'releases/' . (int) $rid );
		foreach ( (array) glob( $rdir . '/*' ) as $f ) {
			if ( is_file( $f ) && 'index.php' !== basename( $f ) && false === strpos( $f, '/uploads/' ) ) {
				$zip->addFile( $f, $folder . '/releases/release-' . (int) $rid . '/' . basename( $f ) );
			}
		}
	}

	// 4. The served web file (as it is right now) + its hash.
	$file = get_attached_file( $attachment_id );
	if ( $file && file_exists( $file ) ) {
		$zip->addFile( $file, $folder . '/served-file/' . basename( $file ) );
	}

	// 5. Ledger rows for this item + the FULL ledger (needed to verify the chain) + OTS receipts.
	$rows = bl_img_ledger_for( $attachment_id );
	$zip->addFromString( $folder . '/ledger/entries-for-this-image.json', wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	global $wpdb;
	$all = $wpdb->get_results( 'SELECT id, created_utc, event, attachment_id, release_id, data_sha256, prev_hash, entry_hash FROM ' . bl_img_ledger_table() . ' ORDER BY id ASC', ARRAY_A );
	$csv = "id,created_utc,event,attachment_id,release_id,data_sha256,prev_hash,entry_hash\n";
	foreach ( $all as $r ) {
		$csv .= implode( ',', array_map( function ( $v ) { return '"' . str_replace( '"', '""', $v ) . '"'; }, $r ) ) . "\n";
	}
	$zip->addFromString( $folder . '/ledger/full-chain.csv', $csv );
	foreach ( (array) glob( bl_img_vault_dir( 'ledger/ots' ) . '/*.receipt' ) as $f ) {
		$zip->addFile( $f, $folder . '/ledger/opentimestamps/' . basename( $f ) );
	}
	$v = bl_img_ledger_verify();
	$zip->addFromString( $folder . '/ledger/VERIFY.txt', "Chain verification at " . gmdate( 'c' ) . ": " . $v['message'] . "\n\nHow to verify independently:\nFor each row in full-chain.csv, entry_hash must equal\nSHA-256( prev_hash + '|' + created_utc + '|' + event + '|' + attachment_id + '|' + release_id + '|' + data_sha256 )\nand prev_hash must equal the previous row's entry_hash (the first row's prev_hash is 64 zeros).\ndata_sha256 is the SHA-256 of the row's canonical JSON 'data' (see entries-for-this-image.json).\n" );

	$zip->close();
	bl_img_ledger_append( 'evidence_export', array( 'note' => 'Evidence package downloaded.' ), $attachment_id );
	return $tmp;
}

/** Printable one-page provenance certificate. */
function bl_img_certificate_html( $id ) {
	$p    = get_post( $id );
	$g    = function ( $k ) use ( $id ) { return (string) get_post_meta( $id, $k, true ); };
	$rows = array(
		'Media Library ID'        => $id,
		'Title'                   => $p ? $p->post_title : '',
		'Alt text'                => $g( '_wp_attachment_image_alt' ),
		'Caption (credit line)'   => $p ? $p->post_excerpt : '',
		'Full attribution'        => $g( '_bl_img_attribution' ),
		'Tier'                    => bl_img_tier_label( $g( '_bl_img_tier' ) ),
		'License'                 => $g( '_bl_img_license_name' ) . ' — ' . $g( '_bl_img_license_url' ),
		'Creator'                 => $g( '_bl_img_creator' ),
		'Obtained from'           => $g( '_bl_img_source_name' ) . ' — ' . $g( '_bl_img_source_url' ),
		'Original file URL'       => $g( '_bl_img_file_url' ),
		'How obtained'            => $g( '_bl_img_origin' ),
		'Obtained at (UTC)'       => $g( '_bl_img_imported_at' ),
		'Obtained by'             => $g( '_bl_img_imported_by_name' ) . ' (' . $g( '_bl_img_imported_by_login' ) . ')',
		'SHA-256 of original'     => $g( '_bl_img_source_sha256' ),
		'SHA-256 of served WebP'  => $g( '_bl_img_file_sha256' ),
		'Wayback Machine capture' => $g( '_bl_img_wayback_url' ) ? $g( '_bl_img_wayback_url' ) : '(' . ( $g( '_bl_img_wayback_status' ) ? $g( '_bl_img_wayback_status' ) : 'n/a' ) . ')',
		'People in image'         => bl_img_people_label( $g( '_bl_img_people' ) ),
		'Ledger entry'            => '#' . $g( '_bl_img_ledger_id' ) . ' · ' . $g( '_bl_img_ledger_hash' ),
	);
	$tr = '';
	foreach ( $rows as $k => $v ) {
		$tr .= '<tr><th>' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
	}
	$rels = '';
	foreach ( bl_img_get_releases( $id ) as $rid ) {
		$rels .= '<li>' . esc_html( bl_img_release_summary( (int) $rid ) ) . '</li>';
	}
	$thumb = wp_get_attachment_image_url( $id, 'medium' );
	return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Provenance certificate — ' . esc_html( $p ? $p->post_title : $id ) . '</title><style>body{font:14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;color:#1c2333;max-width:860px;margin:30px auto;padding:0 20px}h1{font-size:22px;border-bottom:3px solid #C82500;padding-bottom:8px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #e3e7ec;padding:7px 10px;text-align:left;vertical-align:top;word-break:break-word}th{background:#f4f6f9;width:220px}.muted{color:#5b6573;font-size:12.5px}</style></head><body>'
		. '<h1>' . esc_html( bl_img_setting( 'org_name' ) ) . ' — Image Provenance Certificate</h1>'
		. ( $thumb ? '<p><img src="' . esc_url( $thumb ) . '" style="max-width:320px;border-radius:6px"></p>' : '' )
		. '<table>' . $tr . '</table>'
		. ( $rels ? '<h2 style="font-size:16px">Releases on file</h2><ul>' . $rels . '</ul>' : '' )
		. '<p class="muted">Generated ' . esc_html( gmdate( 'c' ) ) . ' by (BL) Images ' . esc_html( BL_IMG_VERSION ) . '. The ledger entry hash above is part of a SHA-256 hash chain anchored daily with OpenTimestamps; see /ledger in this package for independent verification.</p>'
		. '</body></html>';
}
