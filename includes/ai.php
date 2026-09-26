<?php
/**
 * Claude describes each image (alt text, title, description, keywords) and
 * flags identifiable people / minors — via the same Cloudflare Worker (BL)
 * Slide Editor uses, so the Anthropic key never touches WordPress or a browser.
 * Worker mode: "describe".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param string $image_path local image (any GD-readable format)
 * @param array  $ctx        title, description, creator, source, date, tags[]
 * @return array|WP_Error    alt, title, description, keywords[], people{identifiable,count,minors_possible}, sensitive, model
 */
function bl_img_ai_describe( $image_path, $ctx ) {
	if ( ! bl_img_setting( 'ai_alt' ) ) {
		return new WP_Error( 'ai_off', 'AI descriptions are turned off in Settings.' );
	}
	$w = bl_img_worker();
	if ( ! $w['url'] ) {
		return new WP_Error( 'no_worker', 'No Claude Worker configured (Settings → Claude Worker, or (BL) Slide Editor settings).' );
	}

	// Send a 1024px preview — plenty for description, cheap and fast.
	bl_img_raise_limits();
	$ed = wp_get_image_editor( $image_path );
	if ( is_wp_error( $ed ) ) {
		return $ed;
	}
	$ed->resize( 1024, 1024, false );
	$ed->set_quality( 80 );
	$tmp   = wp_tempnam( 'bl-img-ai.jpg' );
	$saved = $ed->save( $tmp . '.jpg', 'image/jpeg' );
	@unlink( $tmp );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	$b64 = base64_encode( file_get_contents( $saved['path'] ) );
	@unlink( $saved['path'] );

	$headers = array( 'content-type' => 'application/json' );
	if ( $w['token'] ) {
		$headers['x-dpf-token'] = $w['token'];
	}
	$resp = wp_remote_post( $w['url'], array(
		'timeout' => 90,
		'headers' => $headers,
		'body'    => wp_json_encode( array(
			'mode'       => 'describe',
			'image_b64'  => $b64,
			'media_type' => 'image/jpeg',
			'context'    => array(
				'org'         => bl_img_setting( 'org_name' ),
				'title'       => (string) ( isset( $ctx['title'] ) ? $ctx['title'] : '' ),
				'description' => mb_substr( (string) ( isset( $ctx['description'] ) ? $ctx['description'] : '' ), 0, 1500 ),
				'creator'     => (string) ( isset( $ctx['creator'] ) ? $ctx['creator'] : '' ),
				'source'      => (string) ( isset( $ctx['source'] ) ? $ctx['source'] : '' ),
				'date'        => (string) ( isset( $ctx['date'] ) ? $ctx['date'] : '' ),
				'tags'        => isset( $ctx['tags'] ) ? array_slice( (array) $ctx['tags'], 0, 15 ) : array(),
				'hint'        => (string) ( isset( $ctx['hint'] ) ? $ctx['hint'] : '' ),
			),
		) ),
	) );
	if ( is_wp_error( $resp ) ) {
		return new WP_Error( 'worker_unreachable', $resp->get_error_message() );
	}
	$data = json_decode( wp_remote_retrieve_body( $resp ), true );
	if ( ! is_array( $data ) || empty( $data['ok'] ) || empty( $data['result'] ) ) {
		$err = is_array( $data ) && ! empty( $data['error'] ) ? $data['error'] : 'HTTP ' . wp_remote_retrieve_response_code( $resp );
		if ( false !== stripos( $err, 'mode must be' ) ) {
			$err = 'The Claude Worker needs the "describe" update deployed (see Help → Claude Worker).';
		}
		return new WP_Error( 'worker_error', $err );
	}
	$r   = $data['result'];
	$out = array(
		'alt'         => bl_img_clean_alt( isset( $r['alt'] ) ? $r['alt'] : '' ),
		'title'       => mb_substr( trim( wp_strip_all_tags( isset( $r['title'] ) ? $r['title'] : '' ) ), 0, 120 ),
		'description' => trim( wp_strip_all_tags( isset( $r['description'] ) ? $r['description'] : '' ) ),
		'keywords'    => array_slice( array_values( array_filter( array_map( 'sanitize_text_field', (array) ( isset( $r['keywords'] ) ? $r['keywords'] : array() ) ) ) ), 0, 12 ),
		'people'      => array(
			'identifiable'    => ! empty( $r['people']['identifiable'] ),
			'count'           => isset( $r['people']['count'] ) ? (int) $r['people']['count'] : 0,
			'minors_possible' => ! empty( $r['people']['minors_possible'] ),
		),
		'sensitive'   => ! empty( $r['sensitive'] ),
		'model'       => isset( $data['model'] ) ? (string) $data['model'] : '',
	);
	if ( ! $out['alt'] ) {
		return new WP_Error( 'ai_empty', 'Claude returned no alt text.' );
	}
	return $out;
}

/** Alt-text hygiene: no "image of", no trailing file-ish junk, ≤ 150 chars on a word boundary. */
function bl_img_clean_alt( $s ) {
	$s = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $s ) ) );
	$s = preg_replace( '/^(an?\s+)?(image|photo|photograph|picture)\s+(of|showing)\s+/i', '', $s );
	$s = ucfirst( $s );
	if ( mb_strlen( $s ) > 150 ) {
		$s = mb_substr( $s, 0, 150 );
		$s = preg_replace( '/\s+\S*$/', '', $s );
		$s = rtrim( $s, ' ,;:' ) . '…';
	}
	return $s;
}

/** Metadata-only fallback when Claude is unavailable (never blocks an import). */
function bl_img_fallback_describe( $ctx ) {
	$title = trim( (string) ( isset( $ctx['title'] ) ? $ctx['title'] : '' ) );
	$desc  = trim( (string) ( isset( $ctx['description'] ) ? $ctx['description'] : '' ) );
	$alt   = $desc ? $desc : $title;
	return array(
		'alt'         => bl_img_clean_alt( mb_substr( $alt, 0, 150 ) ),
		'title'       => $title,
		'description' => $desc,
		'keywords'    => isset( $ctx['tags'] ) ? array_slice( (array) $ctx['tags'], 0, 12 ) : array(),
		'people'      => array( 'identifiable' => false, 'count' => 0, 'minors_possible' => false, 'unchecked' => true ),
		'sensitive'   => false,
		'model'       => '',
	);
}
