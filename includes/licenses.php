<?php
/**
 * Licenses: the ONLY two tiers (BL) Images will import, and the credit lines.
 *
 *   pd     "Safe anywhere"     CC0, Public Domain Mark, and public-domain works
 *                               (incl. U.S. federal government works). Credit is
 *                               still written on every image — good faith + good
 *                               practice — but not legally required.
 *   credit "Safe with credit"  CC BY (2.0 / 2.5 / 3.0 / 4.0) and Commons'
 *                               "Attribution"-only terms. Full TASL credit is
 *                               written into Caption, Description, XMP and the
 *                               credits page.
 *
 * Everything else — BY-SA, any NC, any ND, GFDL, "unknown", fair use — is
 * BLOCKED at search AND re-checked at import (never trust the client).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A third, upload-only tier — 'dpf' — covers images DPF owns or was directly
 * licensed (staff photos, contributor releases). It is never a search result.
 */
function bl_img_tier_label( $tier ) {
	$map = array( 'pd' => 'Safe anywhere', 'credit' => 'Safe with credit', 'dpf' => 'Owned / licensed to DPF' );
	return isset( $map[ $tier ] ) ? $map[ $tier ] : 'No provenance';
}

/**
 * Normalize any source's license description to our canonical form.
 * Returns [ key, version, tier, short, name, url, legal_url ] — tier '' = blocked.
 */
function bl_img_normalize_license( $raw_key, $version = '', $raw_name = '', $raw_url = '' ) {
	$k   = strtolower( trim( (string) $raw_key ) );
	$n   = trim( (string) $raw_name );
	$v   = preg_replace( '/[^0-9.]/', '', (string) $version );
	$hay = strtolower( $k . ' ' . $n . ' ' . $raw_url );

	$blocked = array( 'key' => $k ? $k : 'unknown', 'version' => $v, 'tier' => '', 'short' => $n ? $n : 'Unknown license', 'name' => $n ? $n : 'Unknown license', 'url' => $raw_url, 'legal_url' => '' );

	// Hard blocks first — any hint of NC / ND / SA / GFDL / non-free wins.
	if ( preg_match( '/(^|[\s\-_])(nc|nd|sa)([\s\-_.]|$)|noncommercial|non-commercial|noderiv|sharealike|share-alike|gfdl|gnu free|fair use|non-free|all rights reserved/', $hay ) ) {
		return $blocked;
	}

	if ( preg_match( '/\bcc0\b|cc-zero|publicdomain\/zero/', $hay ) ) {
		return array( 'key' => 'cc0', 'version' => '1.0', 'tier' => 'pd', 'short' => 'CC0 1.0', 'name' => 'CC0 1.0 Universal Public Domain Dedication', 'url' => 'https://creativecommons.org/publicdomain/zero/1.0/', 'legal_url' => 'https://creativecommons.org/publicdomain/zero/1.0/legalcode' );
	}
	if ( 'pdm' === $k || preg_match( '/publicdomain\/mark|public domain mark/', $hay ) ) {
		return array( 'key' => 'pdm', 'version' => '1.0', 'tier' => 'pd', 'short' => 'Public domain', 'name' => 'Public Domain Mark 1.0', 'url' => 'https://creativecommons.org/publicdomain/mark/1.0/', 'legal_url' => 'https://creativecommons.org/publicdomain/mark/1.0/' );
	}
	if ( preg_match( '/public\s*domain|^pd\b|\bpd-|pd-usgov|no known copyright/', $hay ) ) {
		return array( 'key' => 'pd', 'version' => '', 'tier' => 'pd', 'short' => 'Public domain', 'name' => $n && ! preg_match( '/^public domain$/i', $n ) ? 'Public domain (' . $n . ')' : 'Public domain', 'url' => $raw_url, 'legal_url' => '' );
	}
	if ( preg_match( '/^(cc[\s\-]?)?by$/', $k ) || preg_match( '/\bcc[\s\-]by\b|licenses\/by\//', $hay ) ) {
		if ( ! $v && preg_match( '/by[\s\-\/](\d\.\d)/', $hay, $m ) ) {
			$v = $m[1];
		}
		if ( ! in_array( $v, array( '1.0', '2.0', '2.5', '3.0', '4.0' ), true ) ) {
			$v = $v ? $v : '4.0';
		}
		return array( 'key' => 'by', 'version' => $v, 'tier' => 'credit', 'short' => 'CC BY ' . $v, 'name' => 'Creative Commons Attribution ' . $v . ( '4.0' === $v ? ' International' : ( '1.0' === $v ? ' Generic' : '' ) ), 'url' => 'https://creativecommons.org/licenses/by/' . $v . '/', 'legal_url' => 'https://creativecommons.org/licenses/by/' . $v . '/legalcode' );
	}
	// Wikimedia Commons "Attribution" template: free use, credit required, nothing else.
	if ( preg_match( '/^attribution$|copyrighted free use/', strtolower( $n ) ) ) {
		return array( 'key' => 'attribution', 'version' => '', 'tier' => 'credit', 'short' => 'Free use with attribution', 'name' => $n, 'url' => $raw_url, 'legal_url' => '' );
	}
	return $blocked;
}

function bl_img_license_allowed( $lic ) {
	if ( empty( $lic['tier'] ) ) {
		return false;
	}
	if ( 'credit' === $lic['tier'] && ! bl_img_setting( 'allow_cc_by' ) ) {
		return false;
	}
	return true;
}

/** Credit strings for DPF-owned / directly licensed images (uploads + contributor releases). */
function bl_img_build_credit_dpf( $creator, $holder, $contributed, $title ) {
	$org     = bl_img_setting( 'org_name' );
	$creator = bl_img_clean_creator( $creator );
	$holder  = trim( (string) $holder ) ? trim( (string) $holder ) : ( $creator ? $creator : $org );
	$caption = $contributed
		? 'Photo courtesy of ' . ( $creator ? $creator : $holder )
		: 'Photo: ' . ( $creator ? $creator . ' / ' : '' ) . $org;
	$plain   = ( $title ? '“' . $title . '”' : 'This image' ) . ( $creator ? ' by ' . $creator : '' ) . '. © ' . $holder . '. ' . ( $contributed ? 'Used by ' . $org . ' under a signed contributor license.' : 'Used by ' . $org . ' with permission of the rights holder.' );
	return array(
		'credit_line' => $caption,
		'attribution' => $plain,
		'attrib_html' => esc_html( $plain ),
		'rights'      => '© ' . $holder . '. All rights reserved. Used by ' . $org . ' with permission.',
		'creator'     => $creator,
		'usage_terms' => 'Licensed to ' . $org . ' only. Not licensed for reuse by third parties.',
	);
}

/** Strip "Unknown author" style placeholders so credit lines never lie. */
function bl_img_clean_creator( $s ) {
	$s = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ) ) ) );
	$s = preg_replace( '/^(photo(graph)?\s+by|by)\s+/i', '', $s );
	if ( preg_match( '/^(unknown|anonymous|unknown author|author unknown|n\/a|none)$/i', $s ) ) {
		return '';
	}
	return mb_substr( $s, 0, 200 );
}

/**
 * Build every credit string from one record. Format = the Creative Commons
 * "TASL" best practice (Title · Author · Source · License) + the news-industry
 * credit line ("Photo: Creator / Source"), + a modification notice (CC BY 4.0
 * §3(a)(1)(B) requires indicating changes; resizing/format conversion counts
 * as the cautious reading).
 *
 * $r keys: title, creator, creator_url, source_name, source_url, license (normalized),
 *          attribution_override, copyright_notice, kind ('Photo'|'Image')
 */
function bl_img_build_credit( $r ) {
	$lic     = $r['license'];
	$org     = bl_img_setting( 'org_name' );
	$title   = trim( (string) $r['title'] );
	$creator = bl_img_clean_creator( ! empty( $r['attribution_override'] ) ? $r['attribution_override'] : $r['creator'] );
	$src     = trim( (string) $r['source_name'] );
	$src_url = (string) $r['source_url'];
	$kind    = ! empty( $r['kind'] ) ? $r['kind'] : 'Photo';
	$mod     = 'Resized and converted to WebP by ' . $org . '.';

	// Caption (short, on-image credit line).
	$who = $creator ? $creator . ( $src ? ' / ' . $src : '' ) : $src;
	if ( 'pd' === $lic['tier'] ) {
		$caption = $kind . ': ' . ( $who ? $who . ' · ' : '' ) . ( 'cc0' === $lic['key'] ? 'CC0' : 'Public domain' );
	} else {
		$caption = $kind . ': ' . ( $who ? $who : 'Unknown creator' ) . ' · ' . $lic['short'];
	}

	// Full TASL attribution, plain text.
	$t = $title ? '“' . $title . '”' : 'This image';
	if ( 'pd' === $lic['tier'] ) {
		$plain = $t . ( $creator ? ' by ' . $creator : '' ) . ( $src ? ', via ' . $src : '' ) . ', is in the public domain'
			. ( 'cc0' === $lic['key'] ? ' (dedicated under CC0 1.0 Universal)' : ( 'pdm' === $lic['key'] ? ' (marked with the Public Domain Mark 1.0)' : ( 'Public domain' !== $lic['name'] ? ' — ' . preg_replace( '/^Public domain \((.*)\)$/', '$1', $lic['name'] ) : '' ) ) ) . '.';
	} else {
		$plain = $t . ' by ' . ( $creator ? $creator : 'an unknown creator' ) . ( $src ? ', via ' . $src : '' ) . ', is licensed under ' . $lic['name'] . ( $lic['url'] ? ' (' . $lic['url'] . ')' : '' ) . '.';
	}
	if ( $src_url ) {
		$plain .= ' Source: ' . $src_url . '.';
	}
	if ( ! empty( $r['copyright_notice'] ) ) {
		$plain .= ' ' . trim( $r['copyright_notice'] );
	}
	$plain .= ' ' . $mod;

	// HTML version (credits page, Description field).
	$h_title   = $src_url ? '<a href="' . esc_url( $src_url ) . '" rel="noopener">' . esc_html( $title ? $title : 'Image' ) . '</a>' : esc_html( $title ? $title : 'Image' );
	$h_creator = $creator ? ( ! empty( $r['creator_url'] ) ? '<a href="' . esc_url( $r['creator_url'] ) . '" rel="noopener">' . esc_html( $creator ) . '</a>' : esc_html( $creator ) ) : '';
	$h_lic     = $lic['url'] ? '<a href="' . esc_url( $lic['url'] ) . '" rel="license noopener">' . esc_html( $lic['name'] ) . '</a>' : esc_html( $lic['name'] );
	if ( 'pd' === $lic['tier'] ) {
		$html = '“' . $h_title . '”' . ( $h_creator ? ' by ' . $h_creator : '' ) . ( $src ? ', via ' . esc_html( $src ) : '' ) . '. ' . $h_lic . '.';
	} else {
		$html = '“' . $h_title . '” by ' . ( $h_creator ? $h_creator : 'an unknown creator' ) . ( $src ? ', via ' . esc_html( $src ) : '' ) . ', licensed under ' . $h_lic . '.';
	}
	$html .= ' <span class="bl-img-mod">' . esc_html( $mod ) . '</span>';

	// Rights statement for XMP dc:rights / IPTC Copyright Notice.
	if ( 'pd' === $lic['tier'] ) {
		$rights = 'Public domain' . ( $creator ? ' — created by ' . $creator : '' ) . ( 'cc0' === $lic['key'] ? ' (CC0 1.0)' : '' ) . '.';
	} else {
		$rights = ( $creator ? '© ' . $creator . '. ' : '' ) . 'Licensed under ' . $lic['short'] . '.';
	}

	return array(
		'credit_line' => mb_substr( $caption, 0, 300 ),
		'attribution' => $plain,
		'attrib_html' => $html,
		'rights'      => $rights,
		'creator'     => $creator,
		'usage_terms' => 'pd' === $lic['tier'] ? 'No known copyright restrictions. Credit given as a courtesy.' : 'Reuse requires attribution per ' . $lic['short'] . ': credit the creator, link the license, and indicate changes.',
	);
}
