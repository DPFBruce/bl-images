<?php
/**
 * License audit: every image in the Media Library, its provenance status and
 * any issue a lawyer (or the Board) would ask about — plus the tools to fix
 * them: adopt older (BL) Slide Editor Wikimedia imports, attest provenance for
 * legacy images, have Claude write missing alt text, verify files against
 * their recorded hashes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function bl_img_audit_row( $id ) {
	$p   = get_post( $id );
	$g   = function ( $k ) use ( $id ) { return get_post_meta( $id, $k, true ); };
	$tier = (string) $g( '_bl_img_tier' );
	$prov = (bool) $g( '_bl_img_ledger_id' );
	$people = (string) $g( '_bl_img_people' );
	$issues = array();
	$warn   = array();

	if ( ! $prov ) {
		$issues[] = 'No provenance record';
	}
	if ( $g( '_bl_img_blocked_license' ) ) {
		$issues[] = 'License outside the safe tiers: ' . $g( '_bl_img_blocked_license' );
	}
	if ( 'unknown' === $people ) {
		$issues[] = 'Identifiable people — no release or decision';
	}
	foreach ( bl_img_get_releases( $id ) as $rid ) {
		$r = bl_img_release_get( (int) $rid );
		if ( $r && 'revoked' === $r['status'] ) {
			$issues[] = 'Release #' . $rid . ' revoked — no NEW uses';
		}
	}
	if ( 'credit' === $tier && $p && false === strpos( (string) $p->post_excerpt, (string) $g( '_bl_img_license_short' ) ) ) {
		$issues[] = 'CC BY credit missing from caption';
	}
	if ( ! $g( '_wp_attachment_image_alt' ) ) {
		$warn[] = 'No alt text';
	}
	if ( $p && 'image/webp' !== $p->post_mime_type && 'image/svg+xml' !== $p->post_mime_type ) {
		$warn[] = 'Not WebP';
	}
	if ( $prov && 'failed' === $g( '_bl_img_wayback_status' ) ) {
		$warn[] = 'Wayback capture failed';
	}
	if ( $g( '_dpf_wm_source_url' ) && ! $prov ) {
		$warn[] = 'Slide Editor import — can be adopted';
	}
	$th = wp_get_attachment_image_src( $id, 'thumbnail' );
	return array(
		'id'        => (int) $id,
		'title'     => $p ? $p->post_title : '',
		'thumb'     => $th ? $th[0] : '',
		'date'      => $p ? substr( $p->post_date, 0, 10 ) : '',
		'mime'      => $p ? str_replace( 'image/', '', $p->post_mime_type ) : '',
		'status'    => $prov ? ( 'dpf' === $tier ? 'Owned / licensed' : 'Verified' ) : 'Unverified',
		'tier'      => $tier,
		'tier_label'=> bl_img_tier_label( $tier ),
		'license'   => (string) $g( '_bl_img_license_short' ),
		'source'    => (string) $g( '_bl_img_source_name' ),
		'creator'   => (string) $g( '_bl_img_creator' ),
		'people'    => $people,
		'used'      => bl_img_usage_count( $id ),
		'issues'    => $issues,
		'warnings'  => $warn,
		'edit'      => get_edit_post_link( $id, 'raw' ),
	);
}

/** Paged audit. filter: all|issues|unverified|people|unused|notwebp */
function bl_img_audit( $args ) {
	global $wpdb;
	$page   = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );
	$per    = min( 200, max( 10, (int) ( isset( $args['per'] ) ? $args['per'] : 60 ) ) );
	$filter = isset( $args['filter'] ) ? $args['filter'] : 'all';
	$s      = isset( $args['s'] ) ? trim( (string) $args['s'] ) : '';

	$where = "p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'";
	$join  = '';
	if ( 'unverified' === $filter || 'issues' === $filter ) {
		$join .= " LEFT JOIN {$wpdb->postmeta} lg ON lg.post_id = p.ID AND lg.meta_key = '_bl_img_ledger_id'";
	}
	if ( 'unverified' === $filter ) {
		$where .= ' AND lg.meta_id IS NULL';
	}
	if ( 'people' === $filter || 'issues' === $filter ) {
		$join .= " LEFT JOIN {$wpdb->postmeta} pp ON pp.post_id = p.ID AND pp.meta_key = '_bl_img_people'";
	}
	if ( 'people' === $filter ) {
		$where .= " AND pp.meta_value = 'unknown'";
	}
	if ( 'issues' === $filter ) {
		$where .= " AND ( lg.meta_id IS NULL OR pp.meta_value = 'unknown' OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} b WHERE b.post_id = p.ID AND b.meta_key = '_bl_img_blocked_license') )";
	}
	if ( 'notwebp' === $filter ) {
		$where .= " AND p.post_mime_type NOT IN ('image/webp','image/svg+xml')";
	}
	if ( 'unused' === $filter ) {
		$where .= ' AND NOT EXISTS (SELECT 1 FROM ' . bl_img_usage_table() . ' u WHERE u.attachment_id = p.ID)';
	}
	if ( 'noalt' === $filter ) {
		$where .= " AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} a WHERE a.post_id = p.ID AND a.meta_key = '_wp_attachment_image_alt' AND a.meta_value <> '')";
	}
	if ( '' !== $s ) {
		$like   = '%' . $wpdb->esc_like( $s ) . '%';
		$where .= $wpdb->prepare( " AND ( p.post_title LIKE %s OR p.post_excerpt LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} sm WHERE sm.post_id = p.ID AND sm.meta_key IN ('_wp_attachment_image_alt','_bl_img_creator','_bl_img_keywords') AND sm.meta_value LIKE %s) )", $like, $like, $like );
	}
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p $join WHERE $where" );
	$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p $join WHERE $where ORDER BY p.post_date DESC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ) );

	$counts = array(
		'all'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" ),
		'verified'   => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_bl_img_ledger_id'" ),
		'people'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_bl_img_people' AND meta_value = 'unknown'" ),
		'adoptable'  => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->postmeta} l ON l.post_id = m.post_id AND l.meta_key = '_bl_img_ledger_id' WHERE m.meta_key = '_dpf_wm_source_url' AND l.meta_id IS NULL" ),
		'index_built'=> (string) get_option( 'bl_img_index_built' ),
	);
	return array( 'rows' => array_map( 'bl_img_audit_row', $ids ), 'total' => $total, 'page' => $page, 'per' => $per, 'counts' => $counts );
}

/** Stream the full audit as CSV. */
function bl_img_audit_csv() {
	global $wpdb;
	$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' ORDER BY post_date DESC" );
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="image-license-audit-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'ID', 'Title', 'URL', 'Status', 'Tier', 'License', 'License URL', 'Creator', 'Source', 'Source URL', 'Credit line', 'Obtained (UTC)', 'Obtained by', 'Original SHA-256', 'Served SHA-256', 'Wayback', 'People', 'Releases', 'Times used', 'Issues', 'Warnings', 'Ledger entry' ) );
	foreach ( $ids as $id ) {
		$r = bl_img_audit_row( $id );
		$g = function ( $k ) use ( $id ) { return (string) get_post_meta( $id, $k, true ); };
		fputcsv( $out, array(
			$id, $r['title'], wp_get_attachment_url( $id ), $r['status'], $r['tier_label'], $r['license'], $g( '_bl_img_license_url' ), $r['creator'], $r['source'], $g( '_bl_img_source_url' ),
			get_post_field( 'post_excerpt', $id ), $g( '_bl_img_imported_at' ), $g( '_bl_img_imported_by_name' ), $g( '_bl_img_source_sha256' ), $g( '_bl_img_file_sha256' ), $g( '_bl_img_wayback_url' ),
			bl_img_people_label( $r['people'] ), implode( ' | ', array_map( 'bl_img_release_summary', bl_img_get_releases( $id ) ) ),
			$r['used'], implode( '; ', $r['issues'] ), implode( '; ', $r['warnings'] ), $g( '_bl_img_ledger_id' ) ? '#' . $g( '_bl_img_ledger_id' ) . ' ' . $g( '_bl_img_ledger_hash' ) : '',
		) );
	}
	fclose( $out );
	bl_img_ledger_append( 'audit_export', array( 'rows' => count( $ids ) ) );
}

/* ------------------------------------------------------------------ *
 *  Adopt (BL) Slide Editor Wikimedia imports
 * ------------------------------------------------------------------ */

function bl_img_adopt_batch( $size = 10 ) {
	global $wpdb;
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->postmeta} l ON l.post_id = m.post_id AND l.meta_key = '_bl_img_ledger_id' LEFT JOIN {$wpdb->postmeta} x ON x.post_id = m.post_id AND x.meta_key = '_bl_img_blocked_license' WHERE m.meta_key = '_dpf_wm_source_url' AND l.meta_id IS NULL AND x.meta_id IS NULL LIMIT %d", $size ) );
	$out = array();
	foreach ( $ids as $id ) {
		$out[] = bl_img_adopt_one( (int) $id );
	}
	return array( 'results' => $out, 'remaining' => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->postmeta} l ON l.post_id = m.post_id AND l.meta_key = '_bl_img_ledger_id' LEFT JOIN {$wpdb->postmeta} x ON x.post_id = m.post_id AND x.meta_key = '_bl_img_blocked_license' WHERE m.meta_key = '_dpf_wm_source_url' AND l.meta_id IS NULL AND x.meta_id IS NULL" ) );
}

function bl_img_adopt_one( $id ) {
	$g     = function ( $k ) use ( $id ) { return (string) get_post_meta( $id, $k, true ); };
	$title = $g( '_dpf_wm_source_file' );
	// Re-read today's Commons record as fresh evidence (the original response wasn't kept).
	$it    = $title ? bl_img_wm_detail( $title ) : new WP_Error( 'nt', 'No source file recorded.' );
	$lic   = is_wp_error( $it ) ? bl_img_normalize_license( '', '', $g( '_dpf_wm_license' ), $g( '_dpf_wm_license_url' ) ) : $it['license'];
	if ( ! bl_img_license_allowed( $lic ) ) {
		update_post_meta( $id, '_bl_img_blocked_license', $lic['name'] );
		bl_img_ledger_append( 'adopt_blocked', array( 'license' => $lic['name'], 'source' => $g( '_dpf_wm_source_url' ) ), $id );
		return array( 'id' => $id, 'status' => 'blocked', 'why' => 'License "' . $lic['name'] . '" is outside the safe tiers — replace this image.' );
	}
	$file    = get_attached_file( $id );
	$cur_sha = $file && file_exists( $file ) ? hash_file( 'sha256', $file ) : '';
	$prov_id = wp_generate_uuid4();
	$item    = is_wp_error( $it ) ? array( 'title' => get_the_title( $id ), 'creator' => $g( '_dpf_wm_author' ), 'creator_url' => '', 'provider_name' => 'Wikimedia Commons', 'landing_url' => $g( '_dpf_wm_source_url' ), 'attribution_override' => '', 'uid' => 'wikimedia:' . $title, 'file_url' => $g( '_dpf_wm_download_url' ), 'restrictions' => array() ) : $it;
	$cred    = bl_img_build_credit( array( 'title' => $item['title'], 'creator' => $item['creator'], 'creator_url' => $item['creator_url'], 'source_name' => 'Wikimedia Commons', 'source_url' => $item['landing_url'], 'license' => $lic, 'attribution_override' => $item['attribution_override'], 'copyright_notice' => '', 'kind' => 'Photo' ) );
	$files   = array();
	if ( ! is_wp_error( $it ) ) {
		list( $rel, $sha ) = bl_img_vault_put( 'items/' . $id, 'source-api-response-at-adoption.json', $it['api_response'] );
		$files['source-api-response-at-adoption.json'] = $sha;
	}
	$meta = array(
		'_bl_img_provenance_id' => $prov_id, '_bl_img_source_uid' => 'wikimedia:' . $title, '_bl_img_source' => 'wikimedia', '_bl_img_source_id' => $title, '_bl_img_provider' => 'wikimedia',
		'_bl_img_source_name' => 'Wikimedia Commons', '_bl_img_source_url' => $item['landing_url'], '_bl_img_file_url' => $item['file_url'], '_bl_img_tier' => $lic['tier'],
		'_bl_img_license_key' => $lic['key'], '_bl_img_license_version' => $lic['version'], '_bl_img_license_name' => $lic['name'], '_bl_img_license_short' => $lic['short'], '_bl_img_license_url' => $lic['url'], '_bl_img_license_legal_url' => $lic['legal_url'],
		'_bl_img_creator' => $cred['creator'], '_bl_img_creator_url' => $item['creator_url'], '_bl_img_credit_line' => $cred['credit_line'], '_bl_img_attribution' => $cred['attribution'], '_bl_img_attribution_html' => $cred['attrib_html'], '_bl_img_rights' => $cred['rights'],
		'_bl_img_origin' => 'Imported by (BL) Slide Editor on ' . substr( $g( '_dpf_wm_imported_at' ), 0, 10 ) . '; adopted by (BL) Images', '_bl_img_imported_at' => $g( '_dpf_wm_imported_at' ), '_bl_img_imported_by' => (int) $g( '_dpf_wm_imported_by' ), '_bl_img_imported_by_login' => $g( '_dpf_wm_imported_by_login' ),
		'_bl_img_imported_by_name' => $g( '_dpf_wm_imported_by_login' ), '_bl_img_source_sha256' => $g( '_dpf_wm_hash_sha256' ), '_bl_img_file_sha256' => $cur_sha,
		'_bl_img_people' => in_array( 'personality', array_map( 'strtolower', (array) $item['restrictions'] ), true ) ? 'unknown' : ( $g( '_bl_img_people' ) ? $g( '_bl_img_people' ) : 'unknown' ),
	);
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	update_post_meta( $id, '_bl_img_evidence_files', $files );
	wp_update_post( array( 'ID' => $id, 'post_excerpt' => $cred['credit_line'] ) );
	$l = bl_img_ledger_append( 'adopt', array(
		'provenance_id'            => $prov_id,
		'from'                     => '(BL) Slide Editor Wikimedia importer',
		'original_import_utc'      => $g( '_dpf_wm_imported_at' ),
		'original_importer'        => $g( '_dpf_wm_imported_by_login' ),
		'original_license_recorded'=> $g( '_dpf_wm_license' ),
		'license_today'            => $lic['short'],
		'source_page'              => $item['landing_url'],
		'download_sha256_recorded' => $g( '_dpf_wm_hash_sha256' ),
		'file_sha256_now'          => $cur_sha,
		'file_unchanged'           => $cur_sha && $cur_sha === $g( '_dpf_wm_hash_sha256' ),
		'evidence_files'           => $files,
	), $id );
	update_post_meta( $id, '_bl_img_ledger_id', $l['id'] );
	update_post_meta( $id, '_bl_img_ledger_hash', $l['hash'] );
	bl_img_wayback_queue( $id, $item['landing_url'] );
	return array( 'id' => $id, 'status' => 'adopted', 'license' => $lic['short'] );
}

/* ------------------------------------------------------------------ *
 *  Attest provenance for a legacy image already in the library
 * ------------------------------------------------------------------ */

function bl_img_attest_existing( $id, $attest, $write_caption = true ) {
	if ( ! bl_img_is_image_attachment( $id ) ) {
		return new WP_Error( 'ni', 'Not an image.' );
	}
	$file = get_attached_file( $id );
	if ( ! $file || ! file_exists( $file ) ) {
		return new WP_Error( 'nf', 'File missing on disk.' );
	}
	$valid = bl_img_validate_attest( $attest );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}
	$actor = bl_img_current_actor();
	$name  = $actor['user_name'] ? $actor['user_name'] : $actor['user_login'];
	$basis = $attest['basis'];
	if ( 'pd' === $basis ) {
		$lic = bl_img_normalize_license( 'pd', '', 'Public domain', esc_url_raw( $attest['source_url'] ) );
	} elseif ( 'cc_by' === $basis ) {
		$lic = bl_img_normalize_license( 'by', isset( $attest['license_version'] ) ? $attest['license_version'] : '4.0', '', '' );
	} else {
		$lic = array( 'key' => 'dpf', 'version' => '', 'tier' => 'dpf', 'short' => 'Licensed to ' . bl_img_setting( 'org_short' ), 'name' => 'Owned by / licensed to ' . bl_img_setting( 'org_name' ), 'url' => '', 'legal_url' => '' );
	}
	$title = get_the_title( $id );
	if ( 'dpf' === $lic['tier'] ) {
		$cred = bl_img_build_credit_dpf( $attest['creator'], isset( $attest['holder'] ) ? $attest['holder'] : '', false, $title );
	} else {
		$host = (string) wp_parse_url( $attest['source_url'], PHP_URL_HOST );
		$cred = bl_img_build_credit( array( 'title' => $title, 'creator' => $attest['creator'], 'creator_url' => '', 'source_name' => $host, 'source_url' => esc_url_raw( $attest['source_url'] ), 'license' => $lic, 'attribution_override' => '', 'copyright_notice' => '', 'kind' => 'Photo' ) );
	}
	$sha      = hash_file( 'sha256', $file );
	$stmt     = bl_img_attestation_text( $name );
	$prov_id  = wp_generate_uuid4();
	$att_rec  = array( 'basis' => $basis, 'creator' => sanitize_text_field( $attest['creator'] ), 'holder' => sanitize_text_field( isset( $attest['holder'] ) ? $attest['holder'] : '' ), 'source_url' => isset( $attest['source_url'] ) ? esc_url_raw( $attest['source_url'] ) : '', 'permission_note' => sanitize_textarea_field( isset( $attest['permission_note'] ) ? $attest['permission_note'] : '' ), 'statement' => $stmt, 'statement_sha256' => hash( 'sha256', $stmt ), 'statement_version' => BL_IMG_ATTEST_VERSION, 'agreed_at' => bl_img_now_utc(), 'retroactive' => true );
	$meta = array(
		'_bl_img_provenance_id' => $prov_id, '_bl_img_source' => 'upload', '_bl_img_source_name' => 'dpf' === $lic['tier'] ? bl_img_setting( 'org_name' ) : (string) wp_parse_url( $att_rec['source_url'], PHP_URL_HOST ),
		'_bl_img_source_url' => $att_rec['source_url'], '_bl_img_tier' => $lic['tier'], '_bl_img_license_key' => $lic['key'], '_bl_img_license_version' => $lic['version'], '_bl_img_license_name' => $lic['name'], '_bl_img_license_short' => $lic['short'],
		'_bl_img_license_url' => $lic['url'], '_bl_img_license_legal_url' => $lic['legal_url'], '_bl_img_creator' => $cred['creator'], '_bl_img_credit_line' => $cred['credit_line'], '_bl_img_attribution' => $cred['attribution'], '_bl_img_attribution_html' => $cred['attrib_html'], '_bl_img_rights' => $cred['rights'],
		'_bl_img_origin' => 'Pre-existing library image; provenance attested retroactively', '_bl_img_imported_at' => bl_img_now_utc(), '_bl_img_imported_by' => $actor['user_id'], '_bl_img_imported_by_login' => $actor['user_login'], '_bl_img_imported_by_name' => $actor['user_name'],
		'_bl_img_source_sha256' => $sha, '_bl_img_file_sha256' => $sha,
	);
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	update_post_meta( $id, '_bl_img_attest', $att_rec );
	if ( ! empty( $attest['people'] ) ) {
		update_post_meta( $id, '_bl_img_people', sanitize_key( $attest['people'] ) );
	}
	if ( ! empty( $attest['release_ids'] ) ) {
		bl_img_link_releases( $id, (array) $attest['release_ids'], false );
	}
	if ( $write_caption ) {
		wp_update_post( array( 'ID' => $id, 'post_excerpt' => $cred['credit_line'] ) );
	}
	bl_img_vault_put_json( 'items/' . $id, 'attestation-' . gmdate( 'Ymd-His' ) . '.json', array( 'attestation' => $att_rec, 'actor' => $actor, 'file' => _wp_relative_upload_path( $file ), 'sha256' => $sha ) );
	$l = bl_img_ledger_append( 'attest', array( 'provenance_id' => $prov_id, 'basis' => $basis, 'license' => $lic['short'], 'creator' => $cred['creator'], 'file_sha256' => $sha, 'statement_sha256' => $att_rec['statement_sha256'] ), $id, 0, $actor );
	update_post_meta( $id, '_bl_img_ledger_id', $l['id'] );
	update_post_meta( $id, '_bl_img_ledger_hash', $l['hash'] );
	return bl_img_attachment_summary( $id );
}

/* ------------------------------------------------------------------ *
 *  Claude alt text for existing images
 * ------------------------------------------------------------------ */

function bl_img_ai_fill_alt( $id, $overwrite = false ) {
	if ( ! $overwrite && get_post_meta( $id, '_wp_attachment_image_alt', true ) ) {
		return array( 'id' => $id, 'status' => 'skip' );
	}
	$file = get_attached_file( $id );
	$meta = wp_get_attachment_metadata( $id );
	if ( ! empty( $meta['sizes']['large']['file'] ) ) {
		$file = trailingslashit( dirname( $file ) ) . $meta['sizes']['large']['file'];
	}
	if ( ! $file || ! file_exists( $file ) ) {
		return array( 'id' => $id, 'status' => 'error', 'why' => 'File missing.' );
	}
	$p  = get_post( $id );
	$ai = bl_img_ai_describe( $file, array( 'title' => $p->post_title, 'description' => wp_strip_all_tags( $p->post_content ), 'creator' => get_post_meta( $id, '_bl_img_creator', true ), 'source' => get_post_meta( $id, '_bl_img_source_name', true ) ) );
	if ( is_wp_error( $ai ) ) {
		return array( 'id' => $id, 'status' => 'error', 'why' => $ai->get_error_message() );
	}
	update_post_meta( $id, '_wp_attachment_image_alt', $ai['alt'] );
	if ( ! get_post_meta( $id, '_bl_img_people', true ) ) {
		update_post_meta( $id, '_bl_img_people', $ai['people']['identifiable'] ? 'unknown' : 'none' );
	}
	update_post_meta( $id, '_bl_img_people_ai', $ai['people'] );
	if ( $ai['keywords'] && ! get_post_meta( $id, '_bl_img_keywords', true ) ) {
		update_post_meta( $id, '_bl_img_keywords', implode( ', ', $ai['keywords'] ) );
	}
	return array( 'id' => $id, 'status' => 'done', 'alt' => $ai['alt'] );
}

function bl_img_ai_alt_batch( $size = 3 ) {
	global $wpdb;
	$sql = "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%' AND p.post_mime_type <> 'image/svg+xml' AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} a WHERE a.post_id = p.ID AND a.meta_key = '_wp_attachment_image_alt' AND a.meta_value <> '') AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} f WHERE f.post_id = p.ID AND f.meta_key = '_bl_img_alt_failed')";
	$ids = $wpdb->get_col( $sql . $wpdb->prepare( ' ORDER BY p.ID DESC LIMIT %d', $size ) );
	$out = array();
	foreach ( $ids as $id ) {
		$r = bl_img_ai_fill_alt( (int) $id );
		if ( 'error' === $r['status'] ) {
			update_post_meta( $id, '_bl_img_alt_failed', $r['why'] );
		}
		$out[] = $r;
	}
	return array( 'results' => $out, 'remaining' => (int) $wpdb->get_var( str_replace( 'SELECT p.ID', 'SELECT COUNT(*)', $sql ) ) );
}

/** Re-hash the served file and compare with the ledger. */
function bl_img_verify_file( $id ) {
	$file = get_attached_file( $id );
	$now  = $file && file_exists( $file ) ? hash_file( 'sha256', $file ) : '';
	$rec  = (string) get_post_meta( $id, '_bl_img_file_sha256', true );
	return array( 'recorded' => $rec, 'now' => $now, 'match' => $rec && $now && hash_equals( $rec, $now ), 'xmp' => 'webp' === strtolower( pathinfo( (string) $file, PATHINFO_EXTENSION ) ) && '' !== bl_img_webp_read_xmp( $file ) );
}
