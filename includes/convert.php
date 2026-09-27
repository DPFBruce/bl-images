<?php
/**
 * Convert the existing Media Library to WebP.
 *
 * Per image: encode a WebP from the best source on disk (the pre-"-scaled"
 * original when WordPress kept one), regenerate every size as WebP, point the
 * attachment at it, and rewrite every reference (content, Elementor, options).
 * The OLD JPG/PNG files are left on disk, so links in old emails, social posts
 * and search results keep working. Skips animated GIFs, SVGs, and images
 * where the WebP would be larger than the original.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function bl_img_convert_candidates_count() {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_bl_img_convert_skip' WHERE p.post_type = 'attachment' AND p.post_mime_type IN ('image/jpeg','image/png','image/gif') AND s.meta_id IS NULL" );
}

function bl_img_is_animated_gif( $file ) {
	$b = @file_get_contents( $file, false, null, 0, 1024 * 1024 );
	return $b && preg_match_all( '#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $b ) > 1;
}

/** $force: convert even when the WebP would be a little larger (used for duplicate keepers, which must end up WebP). */
function bl_img_convert_one( $id, $force = false ) {
	$id = (int) $id;
	bl_img_raise_limits();
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$p = get_post( $id );
	if ( ! $p || ! in_array( $p->post_mime_type, array( 'image/jpeg', 'image/png', 'image/gif' ), true ) ) {
		return array( 'id' => $id, 'status' => 'skip', 'why' => 'Not a JPG/PNG/GIF.' );
	}
	$file = get_attached_file( $id );
	if ( ! $file || ! file_exists( $file ) ) {
		update_post_meta( $id, '_bl_img_convert_skip', 'missing' );
		return array( 'id' => $id, 'status' => 'skip', 'why' => 'File missing on disk.' );
	}
	if ( 'image/gif' === $p->post_mime_type && bl_img_is_animated_gif( $file ) ) {
		update_post_meta( $id, '_bl_img_convert_skip', 'animated' );
		return array( 'id' => $id, 'status' => 'skip', 'why' => 'Animated GIF.' );
	}
	$meta   = wp_get_attachment_metadata( $id );
	$source = $file;
	if ( ! empty( $meta['original_image'] ) && file_exists( dirname( $file ) . '/' . $meta['original_image'] ) ) {
		$source = dirname( $file ) . '/' . $meta['original_image'];
	}
	$stem = preg_replace( '/-(scaled|rotated)$/', '', pathinfo( $file, PATHINFO_FILENAME ) );
	$dir  = dirname( $file );
	$new  = trailingslashit( $dir ) . wp_unique_filename( $dir, $stem . '.webp' );
	$w    = bl_img_make_webp( $source, $new, 'image/jpeg' !== $p->post_mime_type );
	if ( is_wp_error( $w ) ) {
		update_post_meta( $id, '_bl_img_convert_skip', 'error' );
		return array( 'id' => $id, 'status' => 'error', 'why' => $w->get_error_message() );
	}
	$old_bytes = filesize( $file );
	if ( ! $force && $w['bytes'] >= $old_bytes && 'image/png' !== $p->post_mime_type ) {
		@unlink( $new );
		update_post_meta( $id, '_bl_img_convert_skip', 'larger' );
		return array( 'id' => $id, 'status' => 'skip', 'why' => 'WebP would be larger.' );
	}

	$old_urls = bl_img_attachment_urls( $id );
	$old_sha  = hash_file( 'sha256', $file );

	// Carry any existing rights metadata into the file.
	$g = function ( $k ) use ( $id ) { return (string) get_post_meta( $id, $k, true ); };
	if ( $g( '_bl_img_tier' ) ) {
		bl_img_webp_embed_xmp( $new, bl_img_build_xmp( array(
			'title' => $p->post_title, 'description' => '', 'alt' => $g( '_wp_attachment_image_alt' ), 'creator' => $g( '_bl_img_creator' ),
			'credit_line' => $p->post_excerpt, 'rights' => $g( '_bl_img_rights' ), 'source' => $g( '_bl_img_source_name' ), 'source_url' => $g( '_bl_img_source_url' ),
			'license_url' => $g( '_bl_img_license_url' ), 'usage_terms' => '', 'attribution_name' => $g( '_bl_img_creator' ), 'attribution_url' => $g( '_bl_img_source_url' ),
			'marked' => 'pd' !== $g( '_bl_img_tier' ), 'keywords' => array(), 'provenance_id' => $g( '_bl_img_provenance_id' ), 'source_sha256' => $g( '_bl_img_source_sha256' ), 'tier' => $g( '_bl_img_tier' ),
		) ) );
	}

	update_attached_file( $id, $new );
	wp_update_post( array( 'ID' => $id, 'post_mime_type' => 'image/webp' ) );
	$new_meta = wp_generate_attachment_metadata( $id, $new );
	wp_update_attachment_metadata( $id, $new_meta );
	$new_urls = bl_img_attachment_urls( $id );

	$rw = bl_img_rewrite_references( array( $id ), bl_img_build_url_map( $old_urls, $new_urls, $new_meta ), array(), 'webp convert #' . $id );
	$new_sha = hash_file( 'sha256', $new );
	update_post_meta( $id, '_bl_img_cur_sha256', $new_sha );
	update_post_meta( $id, '_bl_img_converted_from', _wp_relative_upload_path( $file ) );
	delete_post_meta( $id, '_bl_img_dhash' );
	if ( $g( '_bl_img_ledger_id' ) ) {
		update_post_meta( $id, '_bl_img_file_sha256', $new_sha );
	}
	bl_img_ledger_append( 'convert_webp', array( 'from' => _wp_relative_upload_path( $file ), 'from_sha256' => $old_sha, 'from_bytes' => $old_bytes, 'to' => _wp_relative_upload_path( $new ), 'to_sha256' => $new_sha, 'to_bytes' => $w['bytes'], 'references_changed' => $rw['changed'], 'undo' => $rw['undo'] ), $id );
	clean_attachment_cache( $id );
	return array( 'id' => $id, 'status' => 'done', 'saved' => $old_bytes - $w['bytes'], 'refs' => $rw['changed'], 'title' => $p->post_title );
}

function bl_img_convert_batch( $size = 3 ) {
	global $wpdb;
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_bl_img_convert_skip' WHERE p.post_type = 'attachment' AND p.post_mime_type IN ('image/jpeg','image/png','image/gif') AND s.meta_id IS NULL ORDER BY p.ID DESC LIMIT %d", $size ) );
	$out = array();
	foreach ( $ids as $id ) {
		$out[] = bl_img_convert_one( $id );
	}
	return array( 'results' => $out, 'remaining' => bl_img_convert_candidates_count() );
}
