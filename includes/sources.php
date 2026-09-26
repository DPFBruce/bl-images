<?php
/**
 * Sources: Openverse (WordPress.org's open-license image search — Smithsonian,
 * The Met, NASA, Cleveland Museum, Brooklyn Museum, Europeana, rawpixel PD…)
 * and Wikimedia Commons (direct — richer license + "Restrictions" metadata).
 *
 * Both normalize to the same item shape so search, import and provenance never
 * care where an image came from. Licenses outside our two tiers are filtered
 * out here AND re-checked at import.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BL_IMG_OV_API', 'https://api.openverse.org/v1/images/' );
define( 'BL_IMG_WM_API', 'https://commons.wikimedia.org/w/api.php' );
define( 'BL_IMG_WM_EXT', 'ImageDescription|Artist|Credit|LicenseShortName|LicenseUrl|License|UsageTerms|ObjectName|Attribution|AttributionRequired|Copyrighted|Restrictions|DateTimeOriginal' );

/** Openverse sources we never search: uploader-asserted or irrelevant (3D, species). */
function bl_img_ov_excluded_sources() {
	$ex = array( 'inaturalist', 'sketchfab', 'thingiverse', 'WoRMS', 'bio_diversity', 'animaldiversity', 'phylopic', 'svgsilh' );
	if ( ! bl_img_setting( 'ov_include_flickr' ) ) {
		$ex[] = 'flickr';
	}
	if ( bl_img_setting( 'src_wikimedia' ) ) {
		$ex[] = 'wikimedia'; // searched directly instead (better metadata)
	}
	return $ex;
}

function bl_img_provider_name( $slug ) {
	$map = array(
		'met'                   => 'The Metropolitan Museum of Art',
		'nasa'                  => 'NASA',
		'wikimedia'             => 'Wikimedia Commons',
		'flickr'                => 'Flickr',
		'europeana'             => 'Europeana',
		'rawpixel'              => 'rawpixel',
		'clevelandmuseum'       => 'Cleveland Museum of Art',
		'brooklynmuseum'        => 'Brooklyn Museum',
		'rijksmuseum'           => 'Rijksmuseum',
		'sciencemuseum'         => 'Science Museum Group',
		'wellcome_collection'   => 'Wellcome Collection',
		'smk'                   => 'SMK – National Gallery of Denmark',
		'stocksnap'             => 'StockSnap',
		'wordpress'             => 'WordPress Photo Directory',
		'geographorguk'         => 'Geograph Britain and Ireland',
		'digitaltmuseum'        => 'DigitaltMuseum',
		'finnish_heritage_agency' => 'Finnish Heritage Agency',
		'museumsvictoria'       => 'Museums Victoria',
		'nappy'                 => 'nappy',
	);
	if ( isset( $map[ $slug ] ) ) {
		return $map[ $slug ];
	}
	if ( 0 === strpos( $slug, 'smithsonian' ) ) {
		$rest = trim( str_replace( array( 'smithsonian_', '_' ), array( '', ' ' ), $slug ) );
		return 'Smithsonian' . ( $rest ? ' — ' . ucwords( $rest ) : '' );
	}
	return ucwords( str_replace( '_', ' ', $slug ) );
}

/* ================================================================== *
 *  Search (both sources, merged)
 * ================================================================== */

function bl_img_search( $args ) {
	$args = wp_parse_args( $args, array( 'q' => '', 'page' => 1, 'tier' => 'all', 'orientation' => '', 'category' => '', 'source' => 'all' ) );
	$q    = trim( (string) $args['q'] );
	if ( '' === $q ) {
		return array( 'items' => array(), 'more' => false, 'errors' => array() );
	}
	$items  = array();
	$errors = array();
	$more   = false;
	$hidden = 0;

	if ( bl_img_setting( 'src_openverse' ) && in_array( $args['source'], array( 'all', 'openverse' ), true ) ) {
		$r = bl_img_ov_search( $args );
		if ( is_wp_error( $r ) ) {
			$errors[] = 'Openverse: ' . $r->get_error_message();
		} else {
			$items  = array_merge( $items, $r['items'] );
			$more   = $more || $r['more'];
			$hidden += $r['hidden'];
		}
	}
	if ( bl_img_setting( 'src_wikimedia' ) && in_array( $args['source'], array( 'all', 'wikimedia' ), true ) ) {
		$r = bl_img_wm_search( $args );
		if ( is_wp_error( $r ) ) {
			$errors[] = 'Wikimedia: ' . $r->get_error_message();
		} else {
			$items  = array_merge( $items, $r['items'] );
			$more   = $more || $r['more'];
			$hidden += $r['hidden'];
		}
	}

	// Interleave so neither source buries the other.
	$a = array_values( array_filter( $items, function ( $i ) { return 'openverse' === $i['source']; } ) );
	$b = array_values( array_filter( $items, function ( $i ) { return 'wikimedia' === $i['source']; } ) );
	$mix = array();
	for ( $i = 0; $i < max( count( $a ), count( $b ) ); $i++ ) {
		if ( isset( $a[ $i ] ) ) { $mix[] = $a[ $i ]; }
		if ( isset( $b[ $i ] ) ) { $mix[] = $b[ $i ]; }
	}

	// Mark what's already in the Media Library (by source id).
	foreach ( $mix as &$it ) {
		$it['existing'] = bl_img_find_by_source( $it['source'], $it['source_id'] );
	}
	unset( $it );

	return array( 'items' => $mix, 'more' => $more, 'hidden' => $hidden, 'errors' => $errors );
}

function bl_img_find_by_source( $source, $source_id ) {
	global $wpdb;
	$uid = $source . ':' . $source_id;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bl_img_source_uid' AND meta_value = %s LIMIT 1", $uid ) );
}

/* ================================================================== *
 *  Openverse
 * ================================================================== */

function bl_img_ov_licenses_param( $tier ) {
	$cc_by = bl_img_setting( 'allow_cc_by' );
	if ( 'pd' === $tier || ! $cc_by ) {
		return 'cc0,pdm';
	}
	if ( 'credit' === $tier ) {
		return 'by';
	}
	return 'cc0,pdm,by';
}

function bl_img_ov_search( $args ) {
	$params = array(
		'q'               => $args['q'],
		'license'         => bl_img_ov_licenses_param( $args['tier'] ),
		'page'            => max( 1, (int) $args['page'] ),
		'page_size'       => 20,
		'mature'          => 'false',
		'excluded_source' => implode( ',', bl_img_ov_excluded_sources() ),
		'extension'       => 'jpg,jpeg,png,webp,gif,tif,tiff',
	);
	if ( in_array( $args['orientation'], array( 'wide', 'tall', 'square' ), true ) ) {
		$params['aspect_ratio'] = $args['orientation'];
	}
	if ( in_array( $args['category'], array( 'photograph', 'illustration', 'digitized_artwork' ), true ) ) {
		$params['category'] = $args['category'];
	}
	$resp = bl_img_http_get( BL_IMG_OV_API . '?' . http_build_query( $params ) );
	if ( is_wp_error( $resp ) ) {
		return $resp;
	}
	$code = wp_remote_retrieve_response_code( $resp );
	$data = json_decode( wp_remote_retrieve_body( $resp ), true );
	if ( 200 !== $code || ! is_array( $data ) ) {
		return new WP_Error( 'ov_http', 'HTTP ' . $code . ( 429 === $code ? ' — rate limited, try again in a minute' : '' ) );
	}
	$items  = array();
	$hidden = 0;
	foreach ( (array) ( isset( $data['results'] ) ? $data['results'] : array() ) as $r ) {
		$it = bl_img_ov_normalize( $r );
		if ( ! $it || ! bl_img_license_allowed( $it['license'] ) ) {
			$hidden++;
			continue;
		}
		unset( $it['raw'] );
		$items[] = $it;
	}
	$more = isset( $data['page_count'] ) && (int) $data['page_count'] > (int) $params['page'];
	return array( 'items' => $items, 'more' => $more, 'hidden' => $hidden );
}

function bl_img_ov_normalize( $r ) {
	if ( empty( $r['id'] ) || empty( $r['url'] ) ) {
		return null;
	}
	$ft = strtolower( isset( $r['filetype'] ) ? (string) $r['filetype'] : pathinfo( parse_url( $r['url'], PHP_URL_PATH ), PATHINFO_EXTENSION ) );
	if ( in_array( $ft, array( 'svg', 'pdf', 'djvu' ), true ) ) {
		return null;
	}
	$lic = bl_img_normalize_license( isset( $r['license'] ) ? $r['license'] : '', isset( $r['license_version'] ) ? $r['license_version'] : '', '', isset( $r['license_url'] ) ? $r['license_url'] : '' );
	$provider = isset( $r['source'] ) ? $r['source'] : ( isset( $r['provider'] ) ? $r['provider'] : '' );
	return array(
		'uid'          => 'openverse:' . $r['id'],
		'source'       => 'openverse',
		'source_id'    => (string) $r['id'],
		'provider'     => $provider,
		'provider_name'=> bl_img_provider_name( $provider ),
		'title'        => isset( $r['title'] ) ? wp_strip_all_tags( (string) $r['title'] ) : '',
		'creator'      => isset( $r['creator'] ) ? bl_img_clean_creator( $r['creator'] ) : '',
		'creator_url'  => isset( $r['creator_url'] ) ? (string) $r['creator_url'] : '',
		'landing_url'  => isset( $r['foreign_landing_url'] ) ? (string) $r['foreign_landing_url'] : '',
		'file_url'     => (string) $r['url'],
		'thumb'        => isset( $r['thumbnail'] ) ? (string) $r['thumbnail'] : (string) $r['url'],
		'width'        => isset( $r['width'] ) ? (int) $r['width'] : 0,
		'height'       => isset( $r['height'] ) ? (int) $r['height'] : 0,
		'filetype'     => $ft,
		'filesize'     => isset( $r['filesize'] ) ? (int) $r['filesize'] : 0,
		'license'      => $lic,
		'tier'         => $lic['tier'],
		'description'  => '',
		'restrictions' => array(),
		'category'     => isset( $r['category'] ) ? (string) $r['category'] : '',
		'tags'         => isset( $r['tags'] ) && is_array( $r['tags'] ) ? array_slice( array_filter( array_map( function ( $t ) { return isset( $t['name'] ) ? $t['name'] : ''; }, $r['tags'] ) ), 0, 12 ) : array(),
		'attribution_override' => '',
		'raw'          => $r,
	);
}

function bl_img_ov_detail( $id ) {
	$id   = preg_replace( '/[^a-f0-9\-]/i', '', $id );
	$url  = BL_IMG_OV_API . $id . '/';
	$resp = bl_img_http_get( $url );
	if ( is_wp_error( $resp ) ) {
		return $resp;
	}
	$code = wp_remote_retrieve_response_code( $resp );
	$body = wp_remote_retrieve_body( $resp );
	$data = json_decode( $body, true );
	if ( 200 !== $code || ! is_array( $data ) ) {
		return new WP_Error( 'ov_http', 'Openverse returned HTTP ' . $code . ' for this image.' );
	}
	$it = bl_img_ov_normalize( $data );
	if ( ! $it ) {
		return new WP_Error( 'ov_type', 'Unsupported file type (vector/document).' );
	}
	$it['api_request']  = $url;
	$it['api_response'] = $body;
	$it['api_headers']  = bl_img_headers_array( $resp );
	return $it;
}

/* ================================================================== *
 *  Wikimedia Commons
 * ================================================================== */

function bl_img_wm_search( $args ) {
	$page   = max( 1, (int) $args['page'] );
	$q      = $args['q'] . ' filetype:bitmap';
	$params = array(
		'action'                => 'query',
		'generator'             => 'search',
		'gsrsearch'             => $q,
		'gsrnamespace'          => 6,
		'gsrlimit'              => 20,
		'gsroffset'             => ( $page - 1 ) * 20,
		'prop'                  => 'imageinfo',
		'iiprop'                => 'url|size|mime|extmetadata',
		'iiurlwidth'            => 480,
		'iiextmetadatalanguage' => 'en',
		'iiextmetadatafilter'   => BL_IMG_WM_EXT,
		'format'                => 'json',
		'formatversion'         => 2,
	);
	$resp = bl_img_http_get( BL_IMG_WM_API . '?' . http_build_query( $params ) );
	if ( is_wp_error( $resp ) ) {
		return $resp;
	}
	$data = json_decode( wp_remote_retrieve_body( $resp ), true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'wm_json', 'Bad response.' );
	}
	$pages = isset( $data['query']['pages'] ) ? $data['query']['pages'] : array();
	usort( $pages, function ( $a, $b ) { return ( isset( $a['index'] ) ? $a['index'] : 0 ) - ( isset( $b['index'] ) ? $b['index'] : 0 ); } );
	$items  = array();
	$hidden = 0;
	foreach ( $pages as $p ) {
		$it = bl_img_wm_normalize( $p );
		if ( ! $it ) {
			continue;
		}
		if ( ! bl_img_license_allowed( $it['license'] ) ) {
			$hidden++;
			continue;
		}
		if ( 'pd' === $args['tier'] && 'pd' !== $it['tier'] ) { continue; }
		if ( 'credit' === $args['tier'] && 'credit' !== $it['tier'] ) { continue; }
		if ( $args['orientation'] && $it['width'] && $it['height'] ) {
			$r = $it['width'] / $it['height'];
			if ( ( 'wide' === $args['orientation'] && $r < 1.15 ) || ( 'tall' === $args['orientation'] && $r > 0.87 ) || ( 'square' === $args['orientation'] && ( $r < 0.87 || $r > 1.15 ) ) ) {
				continue;
			}
		}
		unset( $it['raw'] );
		$items[] = $it;
	}
	return array( 'items' => $items, 'more' => isset( $data['continue'] ), 'hidden' => $hidden );
}

function bl_img_wm_ext( $ext, $k ) {
	return isset( $ext[ $k ]['value'] ) ? (string) $ext[ $k ]['value'] : '';
}

function bl_img_wm_normalize( $p ) {
	$ii = isset( $p['imageinfo'][0] ) ? $p['imageinfo'][0] : null;
	if ( ! $ii || empty( $ii['url'] ) ) {
		return null;
	}
	$mime = isset( $ii['mime'] ) ? $ii['mime'] : '';
	if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/tiff' ), true ) ) {
		return null;
	}
	$ext   = isset( $ii['extmetadata'] ) ? $ii['extmetadata'] : array();
	$lic   = bl_img_normalize_license( '', '', bl_img_wm_ext( $ext, 'LicenseShortName' ), bl_img_wm_ext( $ext, 'LicenseUrl' ) );
	if ( 'pd' === $lic['key'] && ! $lic['url'] && ! empty( $ii['descriptionurl'] ) ) {
		$lic['url'] = $ii['descriptionurl']; // the file page states the PD rationale
	}
	$title = bl_img_wm_ext( $ext, 'ObjectName' );
	if ( ! $title ) {
		$title = preg_replace( array( '/^File:/', '/\.[a-z0-9]+$/i' ), '', $p['title'] );
		$title = str_replace( '_', ' ', $title );
	}
	$restr = array_filter( array_map( 'trim', explode( '|', bl_img_wm_ext( $ext, 'Restrictions' ) ) ) );
	return array(
		'uid'          => 'wikimedia:' . $p['title'],
		'source'       => 'wikimedia',
		'source_id'    => (string) $p['title'],
		'provider'     => 'wikimedia',
		'provider_name'=> 'Wikimedia Commons',
		'title'        => wp_strip_all_tags( html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) ),
		'creator'      => bl_img_clean_creator( bl_img_wm_ext( $ext, 'Artist' ) ),
		'creator_url'  => bl_img_first_href( bl_img_wm_ext( $ext, 'Artist' ) ),
		'landing_url'  => isset( $ii['descriptionurl'] ) ? $ii['descriptionurl'] : '',
		'file_url'     => $ii['url'],
		'thumb'        => isset( $ii['thumburl'] ) ? $ii['thumburl'] : $ii['url'],
		'thumb_big'    => isset( $ii['thumburl'] ) ? $ii['thumburl'] : '',
		'width'        => isset( $ii['width'] ) ? (int) $ii['width'] : 0,
		'height'       => isset( $ii['height'] ) ? (int) $ii['height'] : 0,
		'filetype'     => str_replace( 'image/', '', $mime ),
		'filesize'     => isset( $ii['size'] ) ? (int) $ii['size'] : 0,
		'license'      => $lic,
		'tier'         => $lic['tier'],
		'description'  => trim( wp_strip_all_tags( html_entity_decode( bl_img_wm_ext( $ext, 'ImageDescription' ), ENT_QUOTES, 'UTF-8' ) ) ),
		'restrictions' => array_values( $restr ),
		'category'     => '',
		'tags'         => array(),
		'attribution_override' => bl_img_clean_creator( bl_img_wm_ext( $ext, 'Attribution' ) ),
		'credit_raw'   => trim( wp_strip_all_tags( html_entity_decode( bl_img_wm_ext( $ext, 'Credit' ), ENT_QUOTES, 'UTF-8' ) ) ),
		'usage_terms'  => wp_strip_all_tags( bl_img_wm_ext( $ext, 'UsageTerms' ) ),
		'date_original'=> wp_strip_all_tags( bl_img_wm_ext( $ext, 'DateTimeOriginal' ) ),
		'sha1'         => isset( $ii['sha1'] ) ? $ii['sha1'] : '',
		'raw'          => $p,
	);
}

function bl_img_first_href( $html ) {
	if ( preg_match( '/href="([^"]+)"/', (string) $html, $m ) ) {
		$u = html_entity_decode( $m[1] );
		return 0 === strpos( $u, '//' ) ? 'https:' . $u : $u;
	}
	return '';
}

function bl_img_wm_detail( $title ) {
	$params = array(
		'action'                => 'query',
		'titles'                => $title,
		'prop'                  => 'imageinfo',
		'iiprop'                => 'url|size|mime|extmetadata|sha1|timestamp|user',
		'iiurlwidth'            => (int) bl_img_setting( 'max_edge' ),
		'iiextmetadatalanguage' => 'en',
		'iiextmetadatafilter'   => BL_IMG_WM_EXT,
		'format'                => 'json',
		'formatversion'         => 2,
	);
	$url  = BL_IMG_WM_API . '?' . http_build_query( $params );
	$resp = bl_img_http_get( $url );
	if ( is_wp_error( $resp ) ) {
		return $resp;
	}
	$body = wp_remote_retrieve_body( $resp );
	$data = json_decode( $body, true );
	$p    = isset( $data['query']['pages'][0] ) ? $data['query']['pages'][0] : null;
	if ( ! $p || ! empty( $p['missing'] ) ) {
		return new WP_Error( 'wm_missing', 'File not found on Wikimedia Commons.' );
	}
	$it = bl_img_wm_normalize( $p );
	if ( ! $it ) {
		return new WP_Error( 'wm_type', 'Unsupported file type.' );
	}
	$it['api_request']  = $url;
	$it['api_response'] = $body;
	$it['api_headers']  = bl_img_headers_array( $resp );
	return $it;
}

/** Fetch full, fresh detail for import (the response itself becomes evidence). */
function bl_img_source_detail( $source, $source_id ) {
	if ( 'openverse' === $source ) {
		return bl_img_ov_detail( $source_id );
	}
	if ( 'wikimedia' === $source ) {
		return bl_img_wm_detail( $source_id );
	}
	return new WP_Error( 'bad_source', 'Unknown source.' );
}

function bl_img_headers_array( $resp ) {
	$h   = wp_remote_retrieve_headers( $resp );
	$out = array();
	if ( is_object( $h ) && method_exists( $h, 'getAll' ) ) {
		$h = $h->getAll();
	}
	foreach ( (array) $h as $k => $v ) {
		if ( preg_match( '/^(date|etag|last-modified|content-length|content-type|server|x-cache|age|x-request-id)$/i', $k ) ) {
			$out[ strtolower( $k ) ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
		}
	}
	$out['http_status'] = (int) wp_remote_retrieve_response_code( $resp );
	return $out;
}
