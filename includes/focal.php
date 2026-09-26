<?php
/**
 * Focal point per image. Set once (click the subject), and every <img> WordPress
 * or Elementor prints for that image carries object-position at that point — so
 * any cover-cropped slot (sliders, cards, banners) crops around the subject.
 *
 * Other plugins: bl_images_focal( $id ) → "38% 22%"  (or the JS summary's .focal).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function bl_img_get_focal( $id ) {
	$v = (string) get_post_meta( $id, '_bl_img_focal', true );
	if ( preg_match( '/^(\d{1,3}(?:\.\d+)?),(\d{1,3}(?:\.\d+)?)$/', $v, $m ) ) {
		return array( 'x' => (float) $m[1], 'y' => (float) $m[2], 'set' => true );
	}
	return array( 'x' => 50, 'y' => 50, 'set' => false );
}

function bl_img_set_focal( $id, $x, $y ) {
	$x = round( min( 100, max( 0, (float) $x ) ), 1 );
	$y = round( min( 100, max( 0, (float) $y ) ), 1 );
	update_post_meta( $id, '_bl_img_focal', $x . ',' . $y );
	bl_img_purge_page_cache();
	return bl_img_get_focal( $id );
}

/** Public helper for other plugins/themes. */
function bl_images_focal( $id ) {
	$f = bl_img_get_focal( $id );
	return $f['x'] . '% ' . $f['y'] . '%';
}

add_filter( 'wp_get_attachment_image_attributes', function ( $attr, $attachment ) {
	$f = bl_img_get_focal( $attachment->ID );
	if ( $f['set'] ) {
		$attr['style']      = trim( ( isset( $attr['style'] ) ? rtrim( $attr['style'], '; ' ) . '; ' : '' ) . 'object-position:' . $f['x'] . '% ' . $f['y'] . '%' );
		$attr['data-focal'] = $f['x'] . ',' . $f['y'];
	}
	return $attr;
}, 10, 2 );

/* Images inside post content (blocks / classic editor). */
add_filter( 'wp_content_img_tag', function ( $html, $context, $attachment_id ) {
	if ( ! $attachment_id ) {
		return $html;
	}
	$f = bl_img_get_focal( $attachment_id );
	if ( ! $f['set'] || false !== strpos( $html, 'data-focal=' ) ) {
		return $html;
	}
	$pos = 'object-position:' . $f['x'] . '% ' . $f['y'] . '%';
	if ( preg_match( '/\sstyle="([^"]*)"/', $html ) ) {
		$html = preg_replace( '/\sstyle="([^"]*)"/', ' style="$1;' . $pos . '"', $html, 1 );
	} else {
		$html = str_replace( '<img ', '<img style="' . $pos . '" ', $html );
	}
	return str_replace( '<img ', '<img data-focal="' . $f['x'] . ',' . $f['y'] . '" ', $html );
}, 10, 3 );
