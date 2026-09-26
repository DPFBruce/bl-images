<?php
/**
 * Better search inside WordPress's own Media Library (grid, list and every
 * wp.media picker): also matches Alt text, credit line, creator, source,
 * license and Claude's keywords — not just title/filename. Every word must
 * match somewhere (AND), so "prison 1950 guard" narrows instead of widening.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'posts_search', function ( $search, $q ) {
	global $wpdb;
	$s = $q->get( 's' );
	if ( '' === (string) $s || 'attachment' !== $q->get( 'post_type' ) ) {
		return $search;
	}
	$is_media = ( is_admin() && ( wp_doing_ajax() || ( isset( $GLOBALS['pagenow'] ) && 'upload.php' === $GLOBALS['pagenow'] ) ) ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	if ( ! $is_media ) {
		return $search;
	}
	$keys  = "'_wp_attachment_image_alt','_bl_img_credit_line','_bl_img_creator','_bl_img_source_name','_bl_img_license_short','_bl_img_keywords','_wp_attached_file'";
	$terms = array_filter( preg_split( '/\s+/', trim( wp_unslash( $s ) ) ) );
	$and   = array();
	foreach ( array_slice( $terms, 0, 8 ) as $t ) {
		$like  = '%' . $wpdb->esc_like( $t ) . '%';
		$and[] = $wpdb->prepare( "({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s OR {$wpdb->posts}.post_content LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} blm WHERE blm.post_id = {$wpdb->posts}.ID AND blm.meta_key IN ($keys) AND blm.meta_value LIKE %s))", $like, $like, $like, $like );
	}
	return $and ? ' AND (' . implode( ' AND ', $and ) . ') ' : $search;
}, 20, 2 );
