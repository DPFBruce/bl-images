<?php
/**
 * XMP rights metadata INSIDE the WebP file (IPTC Photo Metadata Standard +
 * Creative Commons ccREL + XMP Rights Management).
 *
 * Why it matters legally: 17 U.S.C. §1202 protects "copyright management
 * information" (creator, license, terms). Converting an image usually STRIPS
 * that — we write it back, so the file itself always carries its author and
 * license wherever it travels (downloads, print shops, social re-posts).
 *
 * GD can't write XMP, so we edit the WebP RIFF container directly:
 * promote simple VP8/VP8L files to the extended (VP8X) layout, set the XMP
 * flag and append an 'XMP ' chunk. Pure PHP, no Imagick needed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function bl_img_xmp_esc( $s ) {
	return htmlspecialchars( (string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

/** $m keys: title, description, alt, creator, credit_line, rights, source, source_url, license_url, usage_terms, attribution_name, attribution_url, marked(bool), keywords[], provenance_id, source_sha256, tier */
function bl_img_build_xmp( $m ) {
	$e   = 'bl_img_xmp_esc';
	$lang = function ( $v ) use ( $e ) {
		return '<rdf:Alt><rdf:li xml:lang="x-default">' . $e( $v ) . '</rdf:li></rdf:Alt>';
	};
	$kw = '';
	if ( ! empty( $m['keywords'] ) ) {
		$kw = '<dc:subject><rdf:Bag>';
		foreach ( (array) $m['keywords'] as $k ) {
			$kw .= '<rdf:li>' . $e( $k ) . '</rdf:li>';
		}
		$kw .= '</rdf:Bag></dc:subject>';
	}
	$org = bl_img_setting( 'org_name' );
	$x   = '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>'
		. '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
		. '<rdf:Description rdf:about=""'
		. ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
		. ' xmlns:xmpRights="http://ns.adobe.com/xap/1.0/rights/"'
		. ' xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/"'
		. ' xmlns:Iptc4xmpCore="http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/"'
		. ' xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/"'
		. ' xmlns:cc="http://creativecommons.org/ns#"'
		. ' xmlns:xmp="http://ns.adobe.com/xap/1.0/"'
		. ' xmlns:blimg="https://deathpenalty.org/ns/bl-images/1.0/">'
		. '<dc:title>' . $lang( $m['title'] ) . '</dc:title>'
		. ( ! empty( $m['description'] ) ? '<dc:description>' . $lang( $m['description'] ) . '</dc:description>' : '' )
		. ( ! empty( $m['creator'] ) ? '<dc:creator><rdf:Seq><rdf:li>' . $e( $m['creator'] ) . '</rdf:li></rdf:Seq></dc:creator>' : '' )
		. '<dc:rights>' . $lang( $m['rights'] ) . '</dc:rights>'
		. $kw
		. ( ! empty( $m['alt'] ) ? '<Iptc4xmpCore:AltTextAccessibility>' . $lang( $m['alt'] ) . '</Iptc4xmpCore:AltTextAccessibility>' : '' )
		. '<photoshop:Credit>' . $e( $m['credit_line'] ) . '</photoshop:Credit>'
		. '<photoshop:Source>' . $e( $m['source'] ) . '</photoshop:Source>'
		. '<xmpRights:Marked>' . ( ! empty( $m['marked'] ) ? 'True' : 'False' ) . '</xmpRights:Marked>'
		. ( ! empty( $m['license_url'] ) ? '<xmpRights:WebStatement>' . $e( $m['license_url'] ) . '</xmpRights:WebStatement><cc:license rdf:resource="' . $e( $m['license_url'] ) . '"/>' : '' )
		. '<xmpRights:UsageTerms>' . $lang( $m['usage_terms'] ) . '</xmpRights:UsageTerms>'
		. ( ! empty( $m['attribution_name'] ) ? '<cc:attributionName>' . $e( $m['attribution_name'] ) . '</cc:attributionName>' : '' )
		. ( ! empty( $m['attribution_url'] ) ? '<cc:attributionURL rdf:resource="' . $e( $m['attribution_url'] ) . '"/>' : '' )
		. ( ! empty( $m['source_url'] ) ? '<Iptc4xmpExt:ImageSupplierImageID>' . $e( $m['source_url'] ) . '</Iptc4xmpExt:ImageSupplierImageID>' : '' )
		. '<xmp:MetadataDate>' . gmdate( 'c' ) . '</xmp:MetadataDate>'
		. '<xmp:CreatorTool>' . $e( '(BL) Images ' . BL_IMG_VERSION . ' — ' . $org ) . '</xmp:CreatorTool>'
		. '<blimg:ProvenanceId>' . $e( $m['provenance_id'] ) . '</blimg:ProvenanceId>'
		. '<blimg:SourceSHA256>' . $e( $m['source_sha256'] ) . '</blimg:SourceSHA256>'
		. '<blimg:Tier>' . $e( $m['tier'] ) . '</blimg:Tier>'
		. '</rdf:Description></rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
	return $x;
}

/** Embed (or replace) the XMP chunk in a WebP file. Returns true on success. */
function bl_img_webp_embed_xmp( $path, $xmp ) {
	$b = @file_get_contents( $path );
	if ( ! $b || strlen( $b ) < 20 || 'RIFF' !== substr( $b, 0, 4 ) || 'WEBP' !== substr( $b, 8, 4 ) ) {
		return false;
	}
	// Parse chunks.
	$chunks = array();
	$pos    = 12;
	$len    = strlen( $b );
	while ( $pos + 8 <= $len ) {
		$fourcc = substr( $b, $pos, 4 );
		$size   = unpack( 'V', substr( $b, $pos + 4, 4 ) )[1];
		$data   = substr( $b, $pos + 8, $size );
		$chunks[] = array( $fourcc, $data );
		$pos += 8 + $size + ( $size & 1 );
	}
	if ( ! $chunks ) {
		return false;
	}

	if ( 'VP8X' !== $chunks[0][0] ) {
		// Simple format → build a VP8X header. Need canvas size (+ alpha for VP8L).
		$first = $chunks[0];
		$alpha = false;
		if ( 'VP8L' === $first[0] && strlen( $first[1] ) >= 5 ) {
			$v      = unpack( 'V', substr( $first[1], 1, 4 ) )[1];
			$w      = ( $v & 0x3FFF ) + 1;
			$h      = ( ( $v >> 14 ) & 0x3FFF ) + 1;
			$alpha  = (bool) ( ( $v >> 28 ) & 1 );
		} elseif ( 'VP8 ' === $first[0] && strlen( $first[1] ) >= 10 ) {
			$w = unpack( 'v', substr( $first[1], 6, 2 ) )[1] & 0x3FFF;
			$h = unpack( 'v', substr( $first[1], 8, 2 ) )[1] & 0x3FFF;
		} else {
			return false;
		}
		$flags = ( $alpha ? 0x10 : 0 );
		$vp8x  = chr( $flags ) . "\0\0\0" . substr( pack( 'V', $w - 1 ), 0, 3 ) . substr( pack( 'V', $h - 1 ), 0, 3 );
		array_unshift( $chunks, array( 'VP8X', $vp8x ) );
	}

	// Set the XMP flag (bit 2) and drop any existing XMP chunk.
	$chunks[0][1][0] = chr( ord( $chunks[0][1][0] ) | 0x04 );
	$chunks          = array_values( array_filter( $chunks, function ( $c ) { return 'XMP ' !== $c[0]; } ) );
	$chunks[]        = array( 'XMP ', $xmp );

	$body = 'WEBP';
	foreach ( $chunks as $c ) {
		$sz    = strlen( $c[1] );
		$body .= $c[0] . pack( 'V', $sz ) . $c[1] . ( $sz & 1 ? "\0" : '' );
	}
	$out = 'RIFF' . pack( 'V', strlen( $body ) ) . $body;
	return false !== file_put_contents( $path, $out );
}

/** Read the XMP packet back out of a WebP (used by the audit "verify file" check). */
function bl_img_webp_read_xmp( $path ) {
	$b = @file_get_contents( $path );
	if ( ! $b || 'RIFF' !== substr( $b, 0, 4 ) ) {
		return '';
	}
	$pos = 12;
	$len = strlen( $b );
	while ( $pos + 8 <= $len ) {
		$fourcc = substr( $b, $pos, 4 );
		$size   = unpack( 'V', substr( $b, $pos + 4, 4 ) )[1];
		if ( 'XMP ' === $fourcc ) {
			return substr( $b, $pos + 8, $size );
		}
		$pos += 8 + $size + ( $size & 1 );
	}
	return '';
}
