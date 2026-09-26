<?php
/**
 * Credits: the [bl_image_credits] page + on-image credit on hover.
 *
 * CC BY requires credit "in any reasonable manner based on the medium".
 * Belt and braces: every CC BY image carries its credit in the Caption, in the
 * file's XMP, on hover wherever it appears, and on a site-wide credits page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode( 'bl_image_credits', function ( $atts ) {
	$a = shortcode_atts( array( 'used' => 'no', 'thumbs' => 'yes' ), $atts, 'bl_image_credits' );
	if ( get_the_ID() ) {
		update_option( 'bl_img_credits_page', (int) get_the_ID(), false );
	}
	$q = new WP_Query( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
		'meta_key'       => '_bl_img_tier',
		'meta_compare'   => 'EXISTS',
		'no_found_rows'  => true,
	) );
	$groups = array( 'credit' => array(), 'pd' => array(), 'dpf' => array() );
	foreach ( $q->posts as $p ) {
		if ( 'yes' === $a['used'] && ! bl_img_usage_count( $p->ID ) ) {
			continue;
		}
		$tier = get_post_meta( $p->ID, '_bl_img_tier', true );
		if ( isset( $groups[ $tier ] ) ) {
			$groups[ $tier ][] = $p;
		}
	}
	$heads = array(
		'credit' => 'Images used under Creative Commons Attribution licenses',
		'pd'     => 'Public-domain images',
		'dpf'    => 'Photos contributed to ' . bl_img_setting( 'org_name' ),
	);
	ob_start();
	echo '<div class="blic">';
	foreach ( $groups as $tier => $list ) {
		if ( ! $list ) {
			continue;
		}
		echo '<h2 class="blic-h">' . esc_html( $heads[ $tier ] ) . '</h2><ul class="blic-list">';
		foreach ( $list as $p ) {
			$html = get_post_meta( $p->ID, '_bl_img_attribution_html', true );
			if ( ! $html ) {
				$html = esc_html( get_post_meta( $p->ID, '_bl_img_attribution', true ) );
			}
			echo '<li class="blic-i" id="credit-' . (int) $p->ID . '">';
			if ( 'yes' === $a['thumbs'] ) {
				$t = wp_get_attachment_image_url( $p->ID, 'thumbnail' );
				if ( $t ) {
					echo '<span class="blic-t" style="background-image:url(' . esc_url( $t ) . ')" role="img" aria-label="' . esc_attr( get_post_meta( $p->ID, '_wp_attachment_image_alt', true ) ) . '"></span>';
				}
			}
			echo '<span class="blic-a">' . wp_kses_post( $html ) . '</span></li>';
		}
		echo '</ul>';
	}
	echo '</div>';
	echo '<style>.blic-h{font-size:1.15em;margin:1.6em 0 .6em}.blic-list{list-style:none;margin:0;padding:0}.blic-i{display:flex;gap:14px;align-items:flex-start;padding:10px 0;border-bottom:1px solid rgba(0,0,0,.08);font-size:.92em;line-height:1.5}.blic-t{flex:0 0 64px;height:64px;border-radius:6px;background:#eee center/cover no-repeat;display:block}.blic-a{display:block}.blic-a .bl-img-mod{opacity:.7}</style>';
	return ob_get_clean();
} );

/* ------------------------------------------------------------------ *
 *  Credit on hover (front end)
 * ------------------------------------------------------------------ */

function bl_img_credit_attr( $id ) {
	$tier = get_post_meta( $id, '_bl_img_tier', true );
	if ( ! $tier || ! bl_img_setting( 'credit_tooltip' ) ) {
		return '';
	}
	return (string) get_post_meta( $id, '_bl_img_credit_line', true );
}

add_filter( 'wp_get_attachment_image_attributes', function ( $attr, $attachment ) {
	$c = bl_img_credit_attr( $attachment->ID );
	if ( $c ) {
		$attr['data-bl-credit'] = $c;
		$attr['data-bl-cid']    = $attachment->ID;
	}
	return $attr;
}, 11, 2 );

add_filter( 'wp_content_img_tag', function ( $html, $context, $attachment_id ) {
	$c = $attachment_id ? bl_img_credit_attr( $attachment_id ) : '';
	if ( $c && false === strpos( $html, 'data-bl-credit' ) ) {
		$html = str_replace( '<img ', '<img data-bl-credit="' . esc_attr( $c ) . '" data-bl-cid="' . (int) $attachment_id . '" ', $html );
	}
	return $html;
}, 11, 3 );

add_action( 'wp_enqueue_scripts', function () {
	if ( ! bl_img_setting( 'credit_tooltip' ) ) {
		return;
	}
	wp_enqueue_script( 'bl-img-credit', BL_IMG_URL . 'assets/credit.js', array(), BL_IMG_VERSION, true );
	$page = (int) get_option( 'bl_img_credits_page' );
	wp_localize_script( 'bl-img-credit', 'BLIMG_CREDIT', array( 'page' => $page && 'publish' === get_post_status( $page ) ? get_permalink( $page ) : '' ) );
} );
