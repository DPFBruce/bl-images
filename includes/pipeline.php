<?php
/**
 * The import pipeline — one path for every image, however it arrives.
 *
 *   1. STAGE     fetch fresh source detail (the API response IS evidence),
 *                re-check the license server-side, download the ORIGINAL
 *                (vault master, for print + proof), SHA-256 it, snapshot the
 *                source landing page, make the optimized WebP.
 *   2. DESCRIBE  Claude writes alt/title/description, flags people/minors.
 *   3. COMMIT    Media Library attachment (WebP + WebP sub-sizes), XMP rights
 *                embedded in the file, all metadata, evidence moved to
 *                vault/items/<id>/, ledger entry, Wayback capture queued.
 *
 * Three short requests instead of one long one = no shared-host timeouts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BL_IMG_ATTEST_VERSION', '1' );

function bl_img_attestation_text( $name ) {
	$org = bl_img_setting( 'org_name' );
	return 'I, ' . $name . ', confirm that the information I have provided about this image is true and complete to the best of my knowledge, and that ' . $org . ' has the legal right to use it as described. I understand that it is my responsibility to have confirmed the copyright and permission status before uploading, and that my name, account, IP address and the date and time of this upload are permanently recorded in this image\'s provenance record so that I can be contacted with any questions about it.';
}

function bl_img_people_label( $k ) {
	$map = array(
		'none'      => 'No identifiable people',
		'released'  => 'Release(s) on file',
		'event'     => 'Covered by event photography notice',
		'editorial' => 'Editorial / newsworthy use only',
		'unknown'   => 'Identifiable people — needs a release or a decision',
	);
	return isset( $map[ $k ] ) ? $map[ $k ] : 'Not reviewed';
}

/* ------------------------------------------------------------------ *
 *  Stage helpers
 * ------------------------------------------------------------------ */

function bl_img_stage_dir( $token ) {
	return bl_img_vault_dir( 'staging/' . preg_replace( '/[^a-z0-9]/', '', $token ) );
}

function bl_img_stage_load( $token ) {
	$f = bl_img_stage_dir( $token ) . '/stage.json';
	if ( ! $token || ! file_exists( $f ) ) {
		return new WP_Error( 'no_stage', 'This import expired or was already completed. Please start it again.', array( 'status' => 404 ) );
	}
	$s = json_decode( file_get_contents( $f ), true );
	if ( ! is_array( $s ) ) {
		return new WP_Error( 'bad_stage', 'Corrupt staging record.', array( 'status' => 500 ) );
	}
	if ( (int) $s['actor']['user_id'] !== get_current_user_id() && ! current_user_can( BL_IMG_CAP_ADMIN ) ) {
		return new WP_Error( 'not_yours', 'This import belongs to another user.', array( 'status' => 403 ) );
	}
	return $s;
}

function bl_img_stage_save( $s ) {
	file_put_contents( bl_img_stage_dir( $s['token'] ) . '/stage.json', wp_json_encode( $s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
}

/** Stream a URL to a temp file, capturing response headers (evidence) and enforcing a size cap. */
function bl_img_download( $url, $max_bytes ) {
	$tmp = wp_tempnam( 'bl-img-dl' );
	$r   = wp_safe_remote_get( $url, array(
		'timeout'             => 120,
		'stream'              => true,
		'filename'            => $tmp,
		'user-agent'          => bl_img_user_agent_header(),
		'limit_response_size' => $max_bytes + 1,
		'headers'             => array( 'Accept' => 'image/*' ),
	) );
	if ( is_wp_error( $r ) ) {
		@unlink( $tmp );
		return $r;
	}
	$code = wp_remote_retrieve_response_code( $r );
	if ( 200 !== $code ) {
		@unlink( $tmp );
		return new WP_Error( 'dl_http', 'Download failed (HTTP ' . $code . ').' );
	}
	clearstatcache( true, $tmp );
	if ( filesize( $tmp ) > $max_bytes ) {
		@unlink( $tmp );
		return new WP_Error( 'dl_big', 'File is larger than the ' . round( $max_bytes / 1048576 ) . ' MB limit.' );
	}
	return array( 'path' => $tmp, 'headers' => bl_img_headers_array( $r ), 'url' => $url );
}

/** Verify bytes are a real raster image. Returns [w,h,mime] or WP_Error. */
function bl_img_probe_image( $path ) {
	$info = @getimagesize( $path );
	if ( ! $info || empty( $info[0] ) ) {
		return new WP_Error( 'not_image', 'The file is not a readable image.' );
	}
	$ok = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/tiff', 'image/avif', 'image/heic' );
	if ( ! in_array( $info['mime'], $ok, true ) ) {
		return new WP_Error( 'bad_type', 'Unsupported image type: ' . $info['mime'] );
	}
	return array( (int) $info[0], (int) $info[1], $info['mime'] );
}

/** Encode the single web master as WebP (one lossy generation), respecting EXIF rotation. */
function bl_img_make_webp( $src, $dest, $graphic ) {
	if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
		return new WP_Error( 'no_webp', 'This server\'s image library cannot write WebP.' );
	}
	$ed = wp_get_image_editor( $src );
	if ( is_wp_error( $ed ) ) {
		return $ed;
	}
	if ( method_exists( $ed, 'maybe_exif_rotate' ) ) {
		$ed->maybe_exif_rotate();
	}
	$max  = (int) bl_img_setting( 'max_edge' );
	$size = $ed->get_size();
	if ( max( $size['width'], $size['height'] ) > $max ) {
		$ed->resize( $max, $max, false );
	}
	$ed->set_quality( (int) bl_img_setting( $graphic ? 'q_graphic' : 'q_photo' ) );
	$saved = $ed->save( $dest, 'image/webp' );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	return array( 'path' => $saved['path'], 'width' => (int) $saved['width'], 'height' => (int) $saved['height'], 'bytes' => filesize( $saved['path'] ) );
}

function bl_img_new_stage( $origin ) {
	bl_img_raise_limits();
	$token = strtolower( wp_generate_password( 24, false, false ) );
	return array(
		'token'   => $token,
		'created' => bl_img_now_utc(),
		'origin'  => $origin,
		'actor'   => bl_img_current_actor(),
		'plugin'  => BL_IMG_VERSION,
		'site'    => home_url( '/' ),
		'evidence'=> array(),
	);
}

/** Find an existing attachment with the same original bytes. */
function bl_img_find_by_hash( $sha ) {
	global $wpdb;
	if ( ! $sha ) {
		return 0;
	}
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_bl_img_source_sha256','_bl_img_file_sha256','_bl_img_hash_any') AND meta_value = %s LIMIT 1", $sha ) );
}

/* ================================================================== *
 *  1a. STAGE — remote (Openverse / Wikimedia)
 * ================================================================== */

function bl_img_stage_remote( $source, $source_id ) {
	$existing = bl_img_find_by_source( $source, $source_id );
	if ( $existing && get_post( $existing ) ) {
		return array( 'duplicate' => $existing, 'message' => 'Already in the Media Library.' );
	}

	$it = bl_img_source_detail( $source, $source_id );
	if ( is_wp_error( $it ) ) {
		return $it;
	}
	// Server-side license gate — the only one that counts.
	if ( ! bl_img_license_allowed( $it['license'] ) ) {
		return new WP_Error( 'license_blocked', 'Blocked: "' . $it['license']['name'] . '" is not a Safe-anywhere or Safe-with-credit license.', array( 'status' => 403 ) );
	}

	$s    = bl_img_new_stage( 'remote' );
	$dir  = bl_img_stage_dir( $s['token'] );
	$max  = (int) bl_img_setting( 'master_max_mb' ) * 1048576;

	// Evidence: the exact API response that stated the license, at this moment.
	list( $rel, $sha ) = bl_img_vault_put( 'staging/' . $s['token'], 'source-api-response.json', $it['api_response'] );
	$s['evidence']['api_response'] = array( 'file' => 'source-api-response.json', 'sha256' => $sha, 'request' => $it['api_request'], 'headers' => $it['api_headers'] );

	// Download the original (print master + proof). Wikimedia: fall back to the
	// max-edge rendition when the original is huge or not GD-readable (TIFF).
	$use_url     = $it['file_url'];
	$is_original = true;
	if ( ! empty( $it['filesize'] ) && $it['filesize'] > $max && ! empty( $it['thumb_big'] ) ) {
		$use_url     = $it['thumb_big'];
		$is_original = false;
	}
	$dl = bl_img_download( $use_url, $max );
	if ( is_wp_error( $dl ) && $is_original && ! empty( $it['thumb_big'] ) ) {
		$use_url     = $it['thumb_big'];
		$is_original = false;
		$dl          = bl_img_download( $use_url, $max );
	}
	if ( is_wp_error( $dl ) ) {
		bl_img_rmdir( $dir );
		return $dl;
	}
	$probe = bl_img_probe_image( $dl['path'] );
	if ( is_wp_error( $probe ) ) {
		@unlink( $dl['path'] );
		bl_img_rmdir( $dir );
		return $probe;
	}
	$ext         = str_replace( array( 'image/', 'jpeg' ), array( '', 'jpg' ), $probe[2] );
	$master_name = 'original.' . $ext;
	rename( $dl['path'], $dir . '/' . $master_name );
	$src_sha = hash_file( 'sha256', $dir . '/' . $master_name );

	$dup = bl_img_find_by_hash( $src_sha );
	if ( $dup && get_post( $dup ) ) {
		bl_img_rmdir( $dir );
		return array( 'duplicate' => $dup, 'message' => 'Identical file already in the Media Library.' );
	}

	// Wikimedia publishes its own SHA-1 of the original — cross-check it.
	$sha1_match = null;
	if ( $is_original && ! empty( $it['sha1'] ) ) {
		$sha1_match = hash_equals( strtolower( $it['sha1'] ), sha1_file( $dir . '/' . $master_name ) );
	}

	// Web version: from the master when GD can read it and it isn't enormous;
	// otherwise from the provider's max-edge rendition.
	$web_src = $dir . '/' . $master_name;
	if ( in_array( $probe[2], array( 'image/tiff', 'image/heic', 'image/avif' ), true ) || $probe[0] * $probe[1] > 36000000 ) {
		if ( ! empty( $it['thumb_big'] ) && $use_url !== $it['thumb_big'] ) {
			$t2 = bl_img_download( $it['thumb_big'], $max );
			if ( ! is_wp_error( $t2 ) ) {
				rename( $t2['path'], $dir . '/rendition.jpg' );
				$web_src = $dir . '/rendition.jpg';
			}
		}
	}
	$graphic = in_array( $probe[2], array( 'image/png', 'image/gif' ), true );
	$webp    = bl_img_make_webp( $web_src, $dir . '/web.webp', $graphic );
	if ( is_wp_error( $webp ) ) {
		bl_img_rmdir( $dir );
		return $webp;
	}

	// Evidence: snapshot of the human-readable source page as it stood today.
	if ( ! empty( $it['landing_url'] ) ) {
		$lp = wp_safe_remote_get( $it['landing_url'], array( 'timeout' => 20, 'user-agent' => bl_img_user_agent_header(), 'limit_response_size' => 4194304 ) );
		if ( ! is_wp_error( $lp ) ) {
			$html = wp_remote_retrieve_body( $lp );
			bl_img_vault_put( 'staging/' . $s['token'], 'source-page.html.gz', gzencode( $html, 9 ) );
			$s['evidence']['landing_page'] = array( 'file' => 'source-page.html.gz', 'url' => $it['landing_url'], 'sha256_uncompressed' => hash( 'sha256', $html ), 'headers' => bl_img_headers_array( $lp ) );
		}
	}

	$api_resp = $it['api_response'];
	unset( $it['api_response'], $it['raw'] );
	$s['item']    = $it;
	$s['license'] = $it['license'];
	$s['tier']    = $it['license']['tier'];
	$s['master']  = array( 'file' => $master_name, 'sha256' => $src_sha, 'bytes' => filesize( $dir . '/' . $master_name ), 'mime' => $probe[2], 'width' => $probe[0], 'height' => $probe[1], 'is_original' => $is_original, 'downloaded_from' => $use_url, 'download_headers' => $dl['headers'], 'wikimedia_sha1_match' => $sha1_match );
	$s['web']     = array( 'file' => 'web.webp', 'width' => $webp['width'], 'height' => $webp['height'], 'bytes' => $webp['bytes'], 'derived_from' => basename( $web_src ) );
	$s['people_hint'] = in_array( 'personality', array_map( 'strtolower', (array) $it['restrictions'] ), true );
	bl_img_stage_save( $s );
	unset( $api_resp );
	return bl_img_stage_public( $s );
}

/* ================================================================== *
 *  1b. STAGE — local upload (with attestation)
 * ================================================================== */

/** Shared rules for every attestation (uploads, replacements, legacy images). */
function bl_img_validate_attest( $attest ) {
	$basis = isset( $attest['basis'] ) ? $attest['basis'] : '';
	if ( ! in_array( $basis, array( 'own', 'licensed', 'pd', 'cc_by', 'contributor' ), true ) ) {
		return new WP_Error( 'basis', 'Choose how ' . bl_img_setting( 'org_short' ) . ' has the right to use this image.', array( 'status' => 400 ) );
	}
	if ( 'contributor' !== $basis && empty( $attest['agreed'] ) ) {
		return new WP_Error( 'attest', 'Please confirm the responsibility statement.', array( 'status' => 400 ) );
	}
	if ( '' === trim( (string) ( isset( $attest['creator'] ) ? $attest['creator'] : '' ) ) ) {
		return new WP_Error( 'creator', 'Enter the photographer / creator (use "Unknown" only if truly unknown).', array( 'status' => 400 ) );
	}
	if ( in_array( $basis, array( 'pd', 'cc_by' ), true ) && empty( $attest['source_url'] ) ) {
		return new WP_Error( 'source', 'Public-domain and CC BY images need the source URL where the license is stated.', array( 'status' => 400 ) );
	}
	if ( 'cc_by' === $basis && ! bl_img_setting( 'allow_cc_by' ) ) {
		return new WP_Error( 'cc_off', 'CC BY images are turned off in Settings.', array( 'status' => 400 ) );
	}
	if ( 'licensed' === $basis && empty( $attest['permission_note'] ) && empty( $attest['release_ids'] ) ) {
		return new WP_Error( 'perm', 'Describe the permission (who granted it, when, how) or link a signed contributor license.', array( 'status' => 400 ) );
	}
	return true;
}

/**
 * $attest: basis (own|licensed|pd|cc_by|contributor), creator, holder, source_url,
 *          license_version, permission_note, people, release_ids[], agreed(bool),
 *          contributor_release (int, contributor flow only)
 */
function bl_img_stage_upload( $tmp_path, $orig_name, $attest ) {
	$valid = bl_img_validate_attest( $attest );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}
	$basis   = $attest['basis'];
	$creator = trim( (string) $attest['creator'] );

	$probe = bl_img_probe_image( $tmp_path );
	if ( is_wp_error( $probe ) ) {
		return $probe;
	}
	$s   = bl_img_new_stage( 'contributor' === $basis ? 'contributor' : 'upload' );
	$dir = bl_img_stage_dir( $s['token'] );
	$ext = str_replace( array( 'image/', 'jpeg' ), array( '', 'jpg' ), $probe[2] );
	copy( $tmp_path, $dir . '/original.' . $ext );
	$src_sha = hash_file( 'sha256', $dir . '/original.' . $ext );

	$dup = bl_img_find_by_hash( $src_sha );
	if ( $dup && get_post( $dup ) ) {
		bl_img_rmdir( $dir );
		return array( 'duplicate' => $dup, 'message' => 'This exact file is already in the Media Library.' );
	}

	$webp = bl_img_make_webp( $dir . '/original.' . $ext, $dir . '/web.webp', in_array( $probe[2], array( 'image/png', 'image/gif' ), true ) );
	if ( is_wp_error( $webp ) ) {
		bl_img_rmdir( $dir );
		return $webp;
	}

	if ( 'pd' === $basis ) {
		$lic = bl_img_normalize_license( 'pd', '', 'Public domain', esc_url_raw( $attest['source_url'] ) );
	} elseif ( 'cc_by' === $basis ) {
		if ( ! bl_img_setting( 'allow_cc_by' ) ) {
			bl_img_rmdir( $dir );
			return new WP_Error( 'cc_off', 'CC BY images are turned off in Settings.' );
		}
		$lic = bl_img_normalize_license( 'by', isset( $attest['license_version'] ) ? $attest['license_version'] : '4.0', '', '' );
	} else {
		$lic = array( 'key' => 'dpf', 'version' => '', 'tier' => 'dpf', 'short' => 'Licensed to ' . bl_img_setting( 'org_short' ), 'name' => 'contributor' === $basis ? 'Contributor license to ' . bl_img_setting( 'org_name' ) : 'Owned by / licensed to ' . bl_img_setting( 'org_name' ), 'url' => '', 'legal_url' => '' );
	}

	$actor     = $s['actor'];
	$name      = $actor['user_name'] ? $actor['user_name'] : $actor['user_login'];
	$att_text  = 'contributor' === $basis ? '' : bl_img_attestation_text( $name );
	$title     = preg_replace( '/\.[a-z0-9]+$/i', '', sanitize_file_name( $orig_name ) );
	$title     = ucfirst( trim( str_replace( array( '-', '_' ), ' ', $title ) ) );

	$s['item'] = array(
		'uid' => '', 'source' => 'upload', 'source_id' => '', 'provider' => '', 'provider_name' => 'pd' === $basis || 'cc_by' === $basis ? (string) wp_parse_url( $attest['source_url'], PHP_URL_HOST ) : bl_img_setting( 'org_name' ),
		'title' => $title, 'creator' => $creator, 'creator_url' => '', 'landing_url' => isset( $attest['source_url'] ) ? esc_url_raw( $attest['source_url'] ) : '',
		'file_url' => '', 'description' => '', 'restrictions' => array(), 'tags' => array(), 'attribution_override' => '', 'original_filename' => sanitize_file_name( $orig_name ),
	);
	$s['license'] = $lic;
	$s['tier']    = $lic['tier'];
	$s['attest']  = array(
		'basis'           => $basis,
		'creator'         => $creator,
		'holder'          => sanitize_text_field( isset( $attest['holder'] ) ? $attest['holder'] : '' ),
		'source_url'      => isset( $attest['source_url'] ) ? esc_url_raw( $attest['source_url'] ) : '',
		'permission_note' => sanitize_textarea_field( isset( $attest['permission_note'] ) ? $attest['permission_note'] : '' ),
		'release_ids'     => array_values( array_filter( array_map( 'intval', (array) ( isset( $attest['release_ids'] ) ? $attest['release_ids'] : array() ) ) ) ),
		'people'          => isset( $attest['people'] ) ? sanitize_key( $attest['people'] ) : '',
		'contributor_release' => isset( $attest['contributor_release'] ) ? (int) $attest['contributor_release'] : 0,
		'statement'       => $att_text,
		'statement_sha256'=> $att_text ? hash( 'sha256', $att_text ) : '',
		'statement_version' => BL_IMG_ATTEST_VERSION,
		'agreed_at'       => bl_img_now_utc(),
	);
	$s['master'] = array( 'file' => 'original.' . $ext, 'sha256' => $src_sha, 'bytes' => filesize( $dir . '/original.' . $ext ), 'mime' => $probe[2], 'width' => $probe[0], 'height' => $probe[1], 'is_original' => true, 'downloaded_from' => 'local upload: ' . sanitize_file_name( $orig_name ) );
	$s['web']    = array( 'file' => 'web.webp', 'width' => $webp['width'], 'height' => $webp['height'], 'bytes' => $webp['bytes'], 'derived_from' => 'original.' . $ext );
	bl_img_stage_save( $s );
	return bl_img_stage_public( $s );
}

/** What the browser gets back from a stage (no filesystem paths). */
function bl_img_stage_public( $s ) {
	return array(
		'token'   => $s['token'],
		'title'   => $s['item']['title'],
		'tier'    => $s['tier'],
		'tier_label' => bl_img_tier_label( $s['tier'] ),
		'license' => $s['license']['short'],
		'web'     => $s['web'],
		'master'  => array( 'bytes' => $s['master']['bytes'], 'width' => $s['master']['width'], 'height' => $s['master']['height'], 'is_original' => $s['master']['is_original'] ),
		'people_hint' => ! empty( $s['people_hint'] ),
		'restrictions' => isset( $s['item']['restrictions'] ) ? $s['item']['restrictions'] : array(),
		'meta'    => isset( $s['proposed'] ) ? $s['proposed'] : null,
	);
}

/* ================================================================== *
 *  2. DESCRIBE — Claude
 * ================================================================== */

function bl_img_stage_describe( $token, $hint = '' ) {
	$s = bl_img_stage_load( $token );
	if ( is_wp_error( $s ) ) {
		return $s;
	}
	$it  = $s['item'];
	$ctx = array(
		'title'       => $it['title'],
		'description' => $it['description'],
		'creator'     => $it['creator'],
		'source'      => $it['provider_name'],
		'date'        => isset( $it['date_original'] ) ? $it['date_original'] : '',
		'tags'        => $it['tags'],
		'hint'        => $hint,
	);
	$ai   = bl_img_ai_describe( bl_img_stage_dir( $token ) . '/web.webp', $ctx );
	$note = '';
	if ( is_wp_error( $ai ) ) {
		$note = $ai->get_error_message();
		$ai   = bl_img_fallback_describe( $ctx );
	}
	$s['ai'] = $ai + array( 'at' => bl_img_now_utc(), 'error' => $note );

	// People status: the uploader's answer wins; otherwise Claude's read.
	$people = ! empty( $s['attest']['people'] ) ? $s['attest']['people'] : '';
	if ( ! empty( $s['attest']['release_ids'] ) ) {
		$people = 'released';
	}
	if ( ! $people ) {
		$people = ( ! empty( $ai['people']['identifiable'] ) || ! empty( $ai['people']['unchecked'] ) || ! empty( $s['people_hint'] ) ) ? 'unknown' : 'none';
	}
	$s['proposed'] = array(
		'alt'         => $ai['alt'],
		'title'       => $ai['title'] ? $ai['title'] : $it['title'],
		'description' => $ai['description'],
		'keywords'    => $ai['keywords'],
		'people'      => $people,
		'people_ai'   => $ai['people'],
		'sensitive'   => $ai['sensitive'],
		'ai_note'     => $note,
	);
	$s['proposed'] += bl_img_stage_credit( $s, $s['proposed']['title'] );
	bl_img_stage_save( $s );
	return bl_img_stage_public( $s );
}

function bl_img_stage_credit( $s, $title ) {
	$it = $s['item'];
	if ( 'dpf' === $s['tier'] ) {
		return bl_img_build_credit_dpf( $s['attest']['creator'], $s['attest']['holder'], 'contributor' === $s['attest']['basis'], $title );
	}
	$kind = ( isset( $it['category'] ) && in_array( $it['category'], array( 'illustration', 'digitized_artwork' ), true ) ) ? 'Image' : 'Photo';
	return bl_img_build_credit( array(
		'title'                => $it['title'] ? $it['title'] : $title,
		'creator'              => $it['creator'],
		'creator_url'          => $it['creator_url'],
		'source_name'          => $it['provider_name'],
		'source_url'           => $it['landing_url'],
		'license'              => $s['license'],
		'attribution_override' => $it['attribution_override'],
		'copyright_notice'     => '',
		'kind'                 => $kind,
	) );
}

/* ================================================================== *
 *  3. COMMIT — into the Media Library
 * ================================================================== */

function bl_img_stage_commit( $token, $over = array(), $parent = 0 ) {
	$s = bl_img_stage_load( $token );
	if ( is_wp_error( $s ) ) {
		return $s;
	}
	if ( empty( $s['proposed'] ) ) {
		$d = bl_img_stage_describe( $token );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$s = bl_img_stage_load( $token );
	}
	bl_img_raise_limits();
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$p     = $s['proposed'];
	$alt   = isset( $over['alt'] ) && '' !== trim( $over['alt'] ) ? bl_img_clean_alt( $over['alt'] ) : $p['alt'];
	$title = isset( $over['title'] ) && '' !== trim( $over['title'] ) ? sanitize_text_field( $over['title'] ) : $p['title'];
	$desc  = isset( $over['description'] ) ? sanitize_textarea_field( $over['description'] ) : $p['description'];
	$kw    = isset( $over['keywords'] ) ? array_filter( array_map( 'sanitize_text_field', (array) $over['keywords'] ) ) : $p['keywords'];
	$ppl   = isset( $over['people'] ) ? sanitize_key( $over['people'] ) : $p['people'];
	$cred  = bl_img_stage_credit( $s, $title );

	// 1. Place the WebP in uploads with a descriptive filename.
	$dir   = bl_img_stage_dir( $token );
	$up    = wp_upload_dir();
	if ( ! empty( $up['error'] ) ) {
		return new WP_Error( 'uploads', $up['error'] );
	}
	$slug  = sanitize_title( mb_substr( $title, 0, 70 ) );
	$slug  = $slug ? $slug : 'image-' . gmdate( 'Ymd-His' );
	$fname = wp_unique_filename( $up['path'], $slug . '.webp' );
	$dest  = trailingslashit( $up['path'] ) . $fname;
	if ( ! copy( $dir . '/web.webp', $dest ) ) {
		return new WP_Error( 'copy', 'Could not write to the uploads folder.' );
	}

	// 2. Embed XMP rights (creator, license, credit) in the file itself.
	$prov_id = wp_generate_uuid4();
	$xmp_m   = array(
		'title'            => $title,
		'description'      => $desc,
		'alt'              => $alt,
		'creator'          => $cred['creator'],
		'credit_line'      => $cred['credit_line'],
		'rights'           => $cred['rights'],
		'source'           => $s['item']['provider_name'],
		'source_url'       => $s['item']['landing_url'],
		'license_url'      => $s['license']['url'],
		'usage_terms'      => $cred['usage_terms'],
		'attribution_name' => $cred['creator'],
		'attribution_url'  => $s['item']['landing_url'],
		'marked'           => 'pd' !== $s['tier'],
		'keywords'         => $kw,
		'provenance_id'    => $prov_id,
		'source_sha256'    => $s['master']['sha256'],
		'tier'             => $s['tier'],
	);
	bl_img_webp_embed_xmp( $dest, bl_img_build_xmp( $xmp_m ) );
	$web_sha = hash_file( 'sha256', $dest );

	// 3. Attachment + WebP sub-sizes.
	$long = trim( ( $desc ? $desc . "\n\n" : '' ) . $cred['attribution'] );
	$id   = wp_insert_attachment( array(
		'post_mime_type' => 'image/webp',
		'post_title'     => $title,
		'post_content'   => $long,
		'post_excerpt'   => $cred['credit_line'],
		'post_status'    => 'inherit',
		'guid'           => trailingslashit( $up['url'] ) . $fname,
	), $dest, (int) $parent, true );
	if ( is_wp_error( $id ) ) {
		@unlink( $dest );
		return $id;
	}
	$meta = wp_generate_attachment_metadata( $id, $dest );
	wp_update_attachment_metadata( $id, $meta );
	// XMP in the larger renditions too (the ones that get downloaded/shared).
	if ( ! empty( $meta['sizes'] ) ) {
		foreach ( $meta['sizes'] as $sz ) {
			if ( ! empty( $sz['file'] ) && (int) $sz['width'] >= 768 && 'image/webp' === ( isset( $sz['mime-type'] ) ? $sz['mime-type'] : 'image/webp' ) ) {
				bl_img_webp_embed_xmp( trailingslashit( dirname( $dest ) ) . $sz['file'], bl_img_build_xmp( $xmp_m ) );
			}
		}
	}

	// 4. Move evidence from staging → vault/items/<id>/.
	$item_dir = bl_img_vault_dir( 'items/' . $id );
	$files    = array();
	foreach ( bl_img_list_files( $dir ) as $f ) {
		$bn = basename( $f );
		if ( 'stage.json' === $bn || 'web.webp' === $bn ) {
			continue;
		}
		rename( $f, $item_dir . '/' . $bn );
		$files[ $bn ] = hash_file( 'sha256', $item_dir . '/' . $bn );
	}

	// 5. Postmeta — the fast, queryable copy of the provenance.
	$actor = $s['actor'];
	$it    = $s['item'];
	$lic   = $s['license'];
	$origin_label = array(
		'remote'      => 'openverse' === $it['source'] ? 'Imported via Openverse search (' . $it['provider_name'] . ')' : 'Imported from Wikimedia Commons',
		'upload'      => 'Local upload with signed attestation (' . ( isset( $s['attest']['basis'] ) ? $s['attest']['basis'] : '' ) . ')',
		'contributor' => 'Contributor upload under signed release #' . ( isset( $s['attest']['contributor_release'] ) ? (int) $s['attest']['contributor_release'] : 0 ),
	);
	$m = array(
		'_wp_attachment_image_alt'   => $alt,
		'_bl_img_provenance_id'      => $prov_id,
		'_bl_img_source_uid'         => $it['uid'],
		'_bl_img_source'             => $it['source'],
		'_bl_img_source_id'          => $it['source_id'],
		'_bl_img_provider'           => $it['provider'],
		'_bl_img_source_name'        => $it['provider_name'],
		'_bl_img_source_url'         => $it['landing_url'],
		'_bl_img_file_url'           => $it['file_url'],
		'_bl_img_tier'               => $s['tier'],
		'_bl_img_license_key'        => $lic['key'],
		'_bl_img_license_version'    => $lic['version'],
		'_bl_img_license_name'       => $lic['name'],
		'_bl_img_license_short'      => $lic['short'],
		'_bl_img_license_url'        => $lic['url'],
		'_bl_img_license_legal_url'  => $lic['legal_url'],
		'_bl_img_creator'            => $cred['creator'],
		'_bl_img_creator_url'        => $it['creator_url'],
		'_bl_img_credit_line'        => $cred['credit_line'],
		'_bl_img_attribution'        => $cred['attribution'],
		'_bl_img_attribution_html'   => $cred['attrib_html'],
		'_bl_img_rights'             => $cred['rights'],
		'_bl_img_origin'             => $origin_label[ $s['origin'] ],
		'_bl_img_imported_at'        => $s['created'],
		'_bl_img_imported_by'        => (int) $actor['user_id'],
		'_bl_img_imported_by_login'  => $actor['user_login'],
		'_bl_img_imported_by_name'   => $actor['user_name'],
		'_bl_img_source_sha256'      => $s['master']['sha256'],
		'_bl_img_file_sha256'        => $web_sha,
		'_bl_img_master'             => 'items/' . $id . '/' . $s['master']['file'],
		'_bl_img_master_is_original' => $s['master']['is_original'] ? 1 : 0,
		'_bl_img_people'             => $ppl,
		'_bl_img_keywords'           => implode( ', ', $kw ),
		'_bl_img_sensitive'          => ! empty( $p['sensitive'] ) ? 1 : 0,
	);
	foreach ( $m as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	update_post_meta( $id, '_bl_img_restrictions', (array) $it['restrictions'] );
	update_post_meta( $id, '_bl_img_people_ai', $p['people_ai'] );
	update_post_meta( $id, '_bl_img_evidence_files', $files );
	if ( ! empty( $s['attest'] ) ) {
		update_post_meta( $id, '_bl_img_attest', $s['attest'] );
	}
	$rels = ! empty( $s['attest']['release_ids'] ) ? $s['attest']['release_ids'] : array();
	if ( ! empty( $s['attest']['contributor_release'] ) ) {
		$rels[] = (int) $s['attest']['contributor_release'];
	}
	if ( $rels ) {
		bl_img_link_releases( $id, $rels, false );
	}

	// 6. The full provenance record → vault (JSON) + ledger (hash-chained).
	$record = array(
		'provenance_id' => $prov_id,
		'attachment_id' => $id,
		'site'          => $s['site'],
		'plugin'        => 'BL Images ' . $s['plugin'],
		'origin'        => $s['origin'],
		'staged_at_utc' => $s['created'],
		'committed_at_utc' => bl_img_now_utc(),
		'actor'         => $actor,
		'source'        => $it,
		'license'       => $lic,
		'tier'          => $s['tier'],
		'credit'        => $cred,
		'master'        => $s['master'],
		'web'           => array( 'path' => _wp_relative_upload_path( $dest ), 'url' => wp_get_attachment_url( $id ), 'sha256' => $web_sha, 'bytes' => filesize( $dest ), 'width' => $s['web']['width'], 'height' => $s['web']['height'], 'xmp_embedded' => true ),
		'evidence'      => $s['evidence'],
		'evidence_files'=> $files,
		'attestation'   => isset( $s['attest'] ) ? $s['attest'] : null,
		'ai'            => isset( $s['ai'] ) ? $s['ai'] : null,
		'metadata'      => array( 'alt' => $alt, 'title' => $title, 'caption' => $cred['credit_line'], 'description' => $long, 'keywords' => $kw, 'people' => $ppl ),
	);
	list( $rec_rel, $rec_sha ) = bl_img_vault_put_json( 'items/' . $id, 'provenance-record.json', $record );
	$event = 'remote' === $s['origin'] ? 'import' : ( 'contributor' === $s['origin'] ? 'contributor_import' : 'upload' );
	$l     = bl_img_ledger_append( $event, array(
		'provenance_id'        => $prov_id,
		'record_file_sha256'   => $rec_sha,
		'source_uid'           => $it['uid'],
		'source_page'          => $it['landing_url'],
		'license'              => $lic['short'] . ( $lic['url'] ? ' ' . $lic['url'] : '' ),
		'tier'                 => $s['tier'],
		'creator'              => $cred['creator'],
		'original_sha256'      => $s['master']['sha256'],
		'served_webp_sha256'   => $web_sha,
		'evidence_files'       => $files,
		'attestation_sha256'   => ! empty( $s['attest']['statement_sha256'] ) ? $s['attest']['statement_sha256'] : '',
	), $id, 0, $actor );
	update_post_meta( $id, '_bl_img_ledger_id', $l['id'] );
	update_post_meta( $id, '_bl_img_ledger_hash', $l['hash'] );

	bl_img_rmdir( $dir );
	if ( $it['landing_url'] && 'remote' === $s['origin'] ) {
		bl_img_wayback_queue( $id, $it['landing_url'] );
	}
	do_action( 'bl_images_imported', $id, $record );
	return bl_img_attachment_summary( $id );
}

/** Compact JSON for the UI. */
function bl_img_attachment_summary( $id ) {
	$p = get_post( $id );
	if ( ! $p ) {
		return null;
	}
	$g = function ( $k ) use ( $id ) { return get_post_meta( $id, $k, true ); };
	$full = wp_get_attachment_image_src( $id, 'full' );
	$med  = wp_get_attachment_image_src( $id, 'medium_large' );
	return array(
		'id'          => (int) $id,
		'title'       => $p->post_title,
		'alt'         => (string) $g( '_wp_attachment_image_alt' ),
		'caption'     => $p->post_excerpt,
		'description' => $p->post_content,
		'url'         => $full ? $full[0] : '',
		'thumb'       => $med ? $med[0] : ( $full ? $full[0] : '' ),
		'width'       => $full ? (int) $full[1] : 0,
		'height'      => $full ? (int) $full[2] : 0,
		'mime'        => $p->post_mime_type,
		'date'        => $p->post_date,
		'edit'        => get_edit_post_link( $id, 'raw' ),
		'tier'        => (string) $g( '_bl_img_tier' ),
		'tier_label'  => bl_img_tier_label( $g( '_bl_img_tier' ) ),
		'license'     => (string) $g( '_bl_img_license_short' ),
		'license_url' => (string) $g( '_bl_img_license_url' ),
		'creator'     => (string) $g( '_bl_img_creator' ),
		'source_name' => (string) $g( '_bl_img_source_name' ),
		'source_url'  => (string) $g( '_bl_img_source_url' ),
		'origin'      => (string) $g( '_bl_img_origin' ),
		'imported_at' => (string) $g( '_bl_img_imported_at' ),
		'imported_by' => (string) $g( '_bl_img_imported_by_name' ),
		'attribution' => (string) $g( '_bl_img_attribution' ),
		'people'      => (string) $g( '_bl_img_people' ),
		'people_label'=> bl_img_people_label( $g( '_bl_img_people' ) ),
		'releases'    => bl_img_get_releases( $id ),
		'focal'       => bl_img_get_focal( $id ),
		'wayback'     => (string) $g( '_bl_img_wayback_url' ),
		'wayback_status' => (string) $g( '_bl_img_wayback_status' ),
		'source_sha256'  => (string) $g( '_bl_img_source_sha256' ),
		'file_sha256'    => (string) $g( '_bl_img_file_sha256' ),
		'ledger_id'      => (int) $g( '_bl_img_ledger_id' ),
		'has_master'     => (bool) $g( '_bl_img_master' ),
		'restrictions'   => (array) $g( '_bl_img_restrictions' ),
		'keywords'       => (string) $g( '_bl_img_keywords' ),
		'has_provenance' => (bool) $g( '_bl_img_ledger_id' ),
	);
}

/* ================================================================== *
 *  Public PHP API for other plugins
 * ================================================================== */

/**
 * Import one image from a source in a single call.
 * bl_images_import_remote( 'openverse', '<uuid>' ) or ( 'wikimedia', 'File:Foo.jpg' )
 * Returns the attachment summary array, or WP_Error.
 */
function bl_images_import_remote( $source, $source_id, $args = array() ) {
	$st = bl_img_stage_remote( $source, $source_id );
	if ( is_wp_error( $st ) ) {
		return $st;
	}
	if ( ! empty( $st['duplicate'] ) ) {
		return bl_img_attachment_summary( $st['duplicate'] );
	}
	bl_img_stage_describe( $st['token'], isset( $args['hint'] ) ? $args['hint'] : '' );
	return bl_img_stage_commit( $st['token'], isset( $args['meta'] ) ? $args['meta'] : array(), isset( $args['parent'] ) ? (int) $args['parent'] : 0 );
}

/** Search from PHP. Same args as the REST search. */
function bl_images_search( $args ) {
	return bl_img_search( $args );
}

/** Cleanup: abandoned staging folders older than 2 days. */
add_action( 'bl_img_daily', function () {
	foreach ( (array) glob( bl_img_vault_dir( 'staging' ) . '/*', GLOB_ONLYDIR ) as $d ) {
		if ( filemtime( $d ) < time() - 2 * DAY_IN_SECONDS ) {
			bl_img_rmdir( $d );
		}
	}
} );
