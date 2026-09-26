<?php
/**
 * Replace in place: swap an image's file while keeping its Media Library ID,
 * Alt/Title/Caption/Description, releases, focal point — and every page that
 * uses it.
 *
 *   mode "same_url"  (default) — the new WebP is written at the SAME filename,
 *                    so the full-size URL never changes. Sub-size URLs are
 *                    re-pointed automatically if their dimensions change.
 *   mode "new_url"   — cache-proof: new filename, every reference rewritten.
 *
 * The replaced file is kept in the vault (items/<id>/history/) with its hash,
 * and the ledger records old hash → new hash + who/why.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * $stage_token: a completed upload stage (bl_img_stage_upload) for the new file.
 */
function bl_img_replace_commit( $attachment_id, $stage_token, $mode = 'same_url', $reason = '' ) {
	$attachment_id = (int) $attachment_id;
	if ( ! bl_img_is_image_attachment( $attachment_id ) ) {
		return new WP_Error( 'not_image', 'Not an image attachment.' );
	}
	$s = bl_img_stage_load( $stage_token );
	if ( is_wp_error( $s ) ) {
		return $s;
	}
	bl_img_raise_limits();
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$old_file = get_attached_file( $attachment_id );
	$old_meta = wp_get_attachment_metadata( $attachment_id );
	$old_urls = bl_img_attachment_urls( $attachment_id );
	$old_sha  = $old_file && file_exists( $old_file ) ? hash_file( 'sha256', $old_file ) : '';
	$dir      = dirname( $old_file );

	// 1. Archive the current file into the vault (evidence of what was shown before).
	$hist = bl_img_vault_dir( 'items/' . $attachment_id . '/history' );
	$stamp = gmdate( 'Ymd-His' );
	if ( $old_file && file_exists( $old_file ) ) {
		copy( $old_file, $hist . '/' . $stamp . '-' . basename( $old_file ) );
	}

	// 2. Remove old sub-size files (they'd otherwise keep showing the old picture).
	if ( ! empty( $old_meta['sizes'] ) ) {
		foreach ( $old_meta['sizes'] as $sz ) {
			if ( ! empty( $sz['file'] ) ) {
				@unlink( trailingslashit( $dir ) . $sz['file'] );
			}
		}
	}
	if ( ! empty( $old_meta['original_image'] ) ) {
		@unlink( trailingslashit( $dir ) . $old_meta['original_image'] );
	}

	// 3. Decide the new path.
	$stem     = preg_replace( '/-(scaled|rotated)$/', '', pathinfo( $old_file, PATHINFO_FILENAME ) );
	$was_webp = 'webp' === strtolower( pathinfo( $old_file, PATHINFO_EXTENSION ) );
	if ( 'same_url' === $mode && $was_webp ) {
		$new_file = trailingslashit( $dir ) . $stem . '.webp';
		if ( $new_file !== $old_file ) {
			@unlink( $old_file );
		}
	} else {
		// New filename (cache-proof), or the old file wasn't WebP. The old
		// full-size file stays on disk so outside links (emails, social) survive.
		$base     = 'new_url' === $mode ? $stem . '-v' . gmdate( 'ymdHi' ) : $stem;
		$new_file = trailingslashit( $dir ) . wp_unique_filename( $dir, $base . '.webp' );
	}
	$sdir = bl_img_stage_dir( $stage_token );
	copy( $sdir . '/web.webp', $new_file );

	// 4. Re-embed the image's EXISTING rights metadata (provenance record stays), plus the replacement note.
	$post = get_post( $attachment_id );
	$g    = function ( $k ) use ( $attachment_id ) { return (string) get_post_meta( $attachment_id, $k, true ); };
	bl_img_webp_embed_xmp( $new_file, bl_img_build_xmp( array(
		'title' => $post->post_title, 'description' => '', 'alt' => $g( '_wp_attachment_image_alt' ),
		'creator' => $s['attest']['creator'], 'credit_line' => $post->post_excerpt, 'rights' => $g( '_bl_img_rights' ) ? $g( '_bl_img_rights' ) : '© ' . $s['attest']['creator'],
		'source' => $g( '_bl_img_source_name' ), 'source_url' => $g( '_bl_img_source_url' ), 'license_url' => $g( '_bl_img_license_url' ),
		'usage_terms' => 'Replacement file supplied by ' . $s['actor']['user_name'] . ' on ' . gmdate( 'Y-m-d' ) . '.', 'attribution_name' => $s['attest']['creator'], 'attribution_url' => '',
		'marked' => true, 'keywords' => array(), 'provenance_id' => $g( '_bl_img_provenance_id' ), 'source_sha256' => $s['master']['sha256'], 'tier' => $g( '_bl_img_tier' ),
	) ) );
	$new_sha = hash_file( 'sha256', $new_file );

	// 5. Point the attachment at the new file and regenerate sizes.
	update_attached_file( $attachment_id, $new_file );
	wp_update_post( array( 'ID' => $attachment_id, 'post_mime_type' => 'image/webp' ) );
	$new_meta = wp_generate_attachment_metadata( $attachment_id, $new_file );
	wp_update_attachment_metadata( $attachment_id, $new_meta );
	$new_urls = bl_img_attachment_urls( $attachment_id );

	// 6. Re-point every reference whose URL changed.
	$url_map = bl_img_build_url_map( $old_urls, $new_urls, $new_meta );
	$rw      = array( 'changed' => 0, 'undo' => '' );
	if ( $url_map ) {
		$rw = bl_img_rewrite_references( array( $attachment_id ), $url_map, array(), 'replace #' . $attachment_id );
	}

	// 7. Keep the new master for print + proof.
	$mname = $stamp . '-replacement-' . $s['master']['file'];
	rename( $sdir . '/' . $s['master']['file'], bl_img_vault_dir( 'items/' . $attachment_id ) . '/' . $mname );
	update_post_meta( $attachment_id, '_bl_img_master', 'items/' . $attachment_id . '/' . $mname );
	update_post_meta( $attachment_id, '_bl_img_file_sha256', $new_sha );
	update_post_meta( $attachment_id, '_bl_img_hash_any', $s['master']['sha256'] );
	delete_post_meta( $attachment_id, '_bl_img_dhash' );

	$l = bl_img_ledger_append( 'replace', array(
		'mode'               => $mode,
		'reason'             => sanitize_text_field( $reason ),
		'old_file'           => _wp_relative_upload_path( $old_file ),
		'old_sha256'         => $old_sha,
		'new_file'           => _wp_relative_upload_path( $new_file ),
		'new_sha256'         => $new_sha,
		'new_original_sha256'=> $s['master']['sha256'],
		'attestation'        => $s['attest'],
		'references_changed' => $rw['changed'],
		'undo'               => $rw['undo'],
	), $attachment_id, 0, $s['actor'] );
	update_post_meta( $attachment_id, '_bl_img_ledger_hash', $l['hash'] );

	bl_img_rmdir( $sdir );
	bl_img_purge_page_cache();
	clean_attachment_cache( $attachment_id );
	return array( 'summary' => bl_img_attachment_summary( $attachment_id ), 'references_changed' => $rw['changed'], 'url_changed' => wp_get_attachment_url( $attachment_id ) !== $old_urls['full'] );
}
