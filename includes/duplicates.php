<?php
/**
 * Duplicate detection + consolidation.
 *
 *   exact    — identical bytes (SHA-256 of the attached file)
 *   similar  — same picture at a different size/format/quality: 64-bit
 *              difference hash (dHash) within a small Hamming distance and the
 *              same aspect ratio. Always shown for a human to confirm.
 *
 * Consolidation is two deliberate steps:
 *   1. Re-point — every reference to the duplicates (content, Elementor,
 *      featured images, options) is rewritten to the keeper. Fully undoable.
 *   2. Remove  — duplicates with zero remaining references are archived to the
 *      vault (files + their metadata) and then deleted from the Media Library.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** 64-bit dHash of an attachment (hex), from its medium rendition. */
function bl_img_dhash( $attachment_id ) {
	$src = wp_get_attachment_image_src( $attachment_id, 'medium' );
	$file = get_attached_file( $attachment_id );
	if ( $src && ! empty( $src[0] ) ) {
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['sizes']['medium']['file'] ) ) {
			$file = trailingslashit( dirname( $file ) ) . $meta['sizes']['medium']['file'];
		}
	}
	if ( ! $file || ! file_exists( $file ) || ! function_exists( 'imagecreatefromstring' ) ) {
		return '';
	}
	$im = @imagecreatefromstring( file_get_contents( $file ) );
	if ( ! $im ) {
		return '';
	}
	$small = imagecreatetruecolor( 9, 8 );
	imagecopyresampled( $small, $im, 0, 0, 0, 0, 9, 8, imagesx( $im ), imagesy( $im ) );
	$bits = '';
	for ( $y = 0; $y < 8; $y++ ) {
		$prev = null;
		for ( $x = 0; $x < 9; $x++ ) {
			$c = imagecolorat( $small, $x, $y );
			$l = ( ( $c >> 16 ) & 255 ) * 0.299 + ( ( $c >> 8 ) & 255 ) * 0.587 + ( $c & 255 ) * 0.114;
			if ( null !== $prev ) {
				$bits .= $l > $prev ? '1' : '0';
			}
			$prev = $l;
		}
	}
	$hex = '';
	foreach ( str_split( $bits, 4 ) as $nib ) {
		$hex .= dechex( bindec( $nib ) );
	}
	return $hex;
}

/** Batch: hash every image that lacks a current hash. */
function bl_img_hash_batch( $size = 40 ) {
	global $wpdb;
	bl_img_raise_limits();
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_bl_img_dhash' WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%%' AND p.post_mime_type <> 'image/svg+xml' AND m.meta_id IS NULL LIMIT %d", $size ) );
	foreach ( $ids as $id ) {
		$f = get_attached_file( $id );
		update_post_meta( $id, '_bl_img_cur_sha256', $f && file_exists( $f ) ? hash_file( 'sha256', $f ) : '' );
		$dh = bl_img_dhash( $id );
		update_post_meta( $id, '_bl_img_dhash', $dh ? $dh : 'none' );
	}
	$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_bl_img_dhash' WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%' AND p.post_mime_type <> 'image/svg+xml' AND m.meta_id IS NULL" );
	return array( 'hashed' => count( $ids ), 'remaining' => $left );
}

/** Build duplicate groups from stored hashes. */
function bl_img_find_duplicates( $threshold = 5 ) {
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT p.ID, p.post_date, sh.meta_value AS sha, dh.meta_value AS dh FROM {$wpdb->posts} p
		JOIN {$wpdb->postmeta} dh ON dh.post_id = p.ID AND dh.meta_key = '_bl_img_dhash'
		LEFT JOIN {$wpdb->postmeta} sh ON sh.post_id = p.ID AND sh.meta_key = '_bl_img_cur_sha256'
		WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%' AND dh.meta_value <> 'none'", ARRAY_A );

	bl_img_raise_limits();
	update_meta_cache( 'post', wp_list_pluck( $rows, 'ID' ) ); // one query instead of 2,000

	// Aspect ratios (from metadata) to guard similar-matches.
	$ar = array();
	foreach ( $rows as $r ) {
		$m               = wp_get_attachment_metadata( $r['ID'] );
		$ar[ $r['ID'] ]  = ! empty( $m['width'] ) && ! empty( $m['height'] ) ? $m['width'] / $m['height'] : 0;
	}

	// Union-find over exact + near matches.
	$parent = array();
	$find   = function ( $x ) use ( &$parent, &$find ) {
		if ( ! isset( $parent[ $x ] ) || $parent[ $x ] === $x ) {
			return $x;
		}
		return $parent[ $x ] = $find( $parent[ $x ] );
	};
	$kind  = array();
	$union = function ( $a, $b, $k ) use ( &$parent, $find, &$kind ) {
		$ra = $find( $a );
		$rb = $find( $b );
		if ( $ra !== $rb ) {
			$parent[ $rb ] = $ra;
		}
		$kind[ $a ] = isset( $kind[ $a ] ) && 'exact' === $kind[ $a ] ? 'exact' : $k;
		$kind[ $b ] = isset( $kind[ $b ] ) && 'exact' === $kind[ $b ] ? 'exact' : $k;
	};
	foreach ( $rows as $r ) {
		$parent[ $r['ID'] ] = $r['ID'];
	}
	$by_sha = array();
	foreach ( $rows as $r ) {
		if ( $r['sha'] ) {
			if ( isset( $by_sha[ $r['sha'] ] ) ) {
				$union( $by_sha[ $r['sha'] ], $r['ID'], 'exact' );
			} else {
				$by_sha[ $r['sha'] ] = $r['ID'];
			}
		}
	}
	// Similar-matching, fast: hashes become two 32-bit ints; images are sorted by
	// aspect ratio so each one is only compared with neighbours within 3% (instead
	// of every other image). Near-blank images (almost no edges → hash ~all 0s or
	// all 1s) are skipped — they'd all "match" each other falsely.
	static $pc8 = null;
	if ( null === $pc8 ) {
		$pc8 = array();
		for ( $i = 0; $i < 256; $i++ ) {
			$pc8[ $i ] = substr_count( decbin( $i ), '1' );
		}
	}
	$bits = function ( $x ) use ( $pc8 ) {
		return $pc8[ $x & 255 ] + $pc8[ ( $x >> 8 ) & 255 ] + $pc8[ ( $x >> 16 ) & 255 ] + $pc8[ ( $x >> 24 ) & 255 ];
	};
	$cand = array();
	foreach ( $rows as $r ) {
		if ( ! $ar[ $r['ID'] ] || 16 !== strlen( $r['dh'] ) ) {
			continue;
		}
		$hi  = hexdec( substr( $r['dh'], 0, 8 ) );
		$lo  = hexdec( substr( $r['dh'], 8, 8 ) );
		$pop = $bits( $hi ) + $bits( $lo );
		if ( $pop < 6 || $pop > 58 ) {
			continue;
		}
		$cand[] = array( 'id' => $r['ID'], 'ar' => $ar[ $r['ID'] ], 'hi' => $hi, 'lo' => $lo );
	}
	usort( $cand, function ( $a, $b ) { return $a['ar'] < $b['ar'] ? -1 : ( $a['ar'] > $b['ar'] ? 1 : 0 ); } );
	$n = count( $cand );
	for ( $i = 0; $i < $n; $i++ ) {
		$a   = $cand[ $i ];
		$max = $a['ar'] * 1.03;
		for ( $j = $i + 1; $j < $n && $cand[ $j ]['ar'] <= $max; $j++ ) {
			$b = $cand[ $j ];
			if ( $bits( $a['hi'] ^ $b['hi'] ) + $bits( $a['lo'] ^ $b['lo'] ) <= $threshold ) {
				$union( $a['id'], $b['id'], 'similar' );
			}
		}
	}
	$groups = array();
	foreach ( $rows as $r ) {
		$groups[ $find( $r['ID'] ) ][] = (int) $r['ID'];
	}
	$out = array();
	foreach ( $groups as $members ) {
		if ( count( $members ) < 2 ) {
			continue;
		}
		$items = array_map( 'bl_img_dup_member', $members );
		usort( $items, 'bl_img_keeper_cmp' );
		$is_exact = true;
		foreach ( $members as $m ) {
			$is_exact = $is_exact && isset( $kind[ $m ] ) && 'exact' === $kind[ $m ];
		}
		$out[] = array( 'kind' => $is_exact ? 'exact' : 'similar', 'keeper' => $items[0]['id'], 'members' => $items );
	}
	usort( $out, function ( $a, $b ) { return strcmp( $a['kind'], $b['kind'] ); } );
	return $out;
}

function bl_img_dup_member( $id ) {
	$m    = wp_get_attachment_metadata( $id );
	$file = get_attached_file( $id );
	$p    = get_post( $id );
	$th   = wp_get_attachment_image_src( $id, 'medium' );
	return array(
		'id'         => (int) $id,
		'title'      => $p ? $p->post_title : '',
		'thumb'      => $th ? $th[0] : '',
		'file'       => $file ? basename( $file ) : '',
		'width'      => isset( $m['width'] ) ? (int) $m['width'] : 0,
		'height'     => isset( $m['height'] ) ? (int) $m['height'] : 0,
		'bytes'      => $file && file_exists( $file ) ? filesize( $file ) : 0,
		'mime'       => $p ? $p->post_mime_type : '',
		'date'       => $p ? $p->post_date : '',
		'used'       => bl_img_usage_count( $id ),
		'provenance' => (bool) get_post_meta( $id, '_bl_img_ledger_id', true ),
		'alt'        => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		'edit'       => get_edit_post_link( $id, 'raw' ),
	);
}

/** Keeper preference: has provenance → most used → most pixels → WebP → oldest. */
function bl_img_keeper_cmp( $a, $b ) {
	if ( $a['provenance'] !== $b['provenance'] ) {
		return $a['provenance'] ? -1 : 1;
	}
	if ( $a['used'] !== $b['used'] ) {
		return $b['used'] - $a['used'];
	}
	$pa = $a['width'] * $a['height'];
	$pb = $b['width'] * $b['height'];
	if ( $pa !== $pb ) {
		return $pb - $pa;
	}
	$wa = 'image/webp' === $a['mime'];
	$wb = 'image/webp' === $b['mime'];
	if ( $wa !== $wb ) {
		return $wa ? -1 : 1;
	}
	return strcmp( $a['date'], $b['date'] );
}

/** Step 1: re-point every reference from $dups to $keeper. */
function bl_img_consolidate( $keeper, $dups ) {
	$keeper = (int) $keeper;
	$dups   = array_values( array_diff( array_map( 'intval', (array) $dups ), array( $keeper ) ) );
	if ( ! bl_img_is_image_attachment( $keeper ) || ! $dups ) {
		return new WP_Error( 'bad', 'Pick a keeper and at least one duplicate.' );
	}
	$k_urls = bl_img_attachment_urls( $keeper );
	$k_meta = wp_get_attachment_metadata( $keeper );
	$url_map = array();
	$id_map  = array();
	foreach ( $dups as $d ) {
		if ( ! bl_img_is_image_attachment( $d ) ) {
			continue;
		}
		$url_map += bl_img_build_url_map( bl_img_attachment_urls( $d ), $k_urls, $k_meta );
		$id_map[ $d ] = $keeper;
	}
	$rw = bl_img_rewrite_references( array_keys( $id_map ), $url_map, $id_map, 'consolidate into #' . $keeper );

	// Carry over anything the keeper lacks.
	$k_alt = get_post_meta( $keeper, '_wp_attachment_image_alt', true );
	$rels  = bl_img_get_releases( $keeper );
	foreach ( array_keys( $id_map ) as $d ) {
		if ( ! $k_alt ) {
			$k_alt = get_post_meta( $d, '_wp_attachment_image_alt', true );
			if ( $k_alt ) {
				update_post_meta( $keeper, '_wp_attachment_image_alt', $k_alt );
			}
		}
		$rels = array_merge( $rels, bl_img_get_releases( $d ) );
		update_post_meta( $d, '_bl_img_consolidated_into', $keeper );
	}
	$rels = array_values( array_unique( array_filter( array_map( 'intval', $rels ) ) ) );
	if ( $rels ) {
		bl_img_link_releases( $keeper, $rels, false );
	}

	bl_img_ledger_append( 'consolidate', array( 'keeper' => $keeper, 'duplicates' => array_keys( $id_map ), 'references_changed' => $rw['changed'], 'undo' => $rw['undo'] ), $keeper );
	return array( 'changed' => $rw['changed'], 'undo' => $rw['undo'], 'remaining' => array_map( function ( $d ) { return array( 'id' => $d, 'used' => bl_img_usage_count( $d ) ); }, array_keys( $id_map ) ) );
}

/**
 * One-shot consolidation of a whole group (used by "Consolidate all"):
 *   1. re-point every reference from the duplicates to the keeper (undoable),
 *   2. make sure the keeper is WebP (convert it if not — references follow),
 *   3. archive + delete every duplicate that no longer has any reference.
 */
function bl_img_consolidate_group( $keeper, $dups ) {
	$keeper = (int) $keeper;
	$dups   = array_values( array_filter( array_diff( array_map( 'intval', (array) $dups ), array( $keeper ) ), 'bl_img_is_image_attachment' ) );
	if ( ! bl_img_is_image_attachment( $keeper ) ) {
		return new WP_Error( 'keeper', 'Keeper #' . $keeper . ' is not an image.' );
	}
	$out = array( 'keeper' => $keeper, 'changed' => 0, 'undo' => '', 'webp' => 'already', 'webp_note' => '', 'removed' => array(), 'skipped' => array() );

	if ( $dups ) {
		$c = bl_img_consolidate( $keeper, $dups );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$out['changed'] = $c['changed'];
		$out['undo']    = $c['undo'];
	}

	if ( 'image/webp' !== get_post_mime_type( $keeper ) ) {
		delete_post_meta( $keeper, '_bl_img_convert_skip' );
		$cv = bl_img_convert_one( $keeper, true );
		$out['webp']      = 'done' === $cv['status'] ? 'converted' : 'not converted';
		$out['webp_note'] = isset( $cv['why'] ) ? $cv['why'] : '';
		if ( 'done' === $cv['status'] ) {
			$out['changed'] += (int) $cv['refs'];
		}
	}

	if ( $dups ) {
		$rm             = bl_img_remove_duplicates( $dups );
		$out['removed'] = $rm['removed'];
		$out['skipped'] = $rm['skipped'];
	}
	return $out;
}

/* ------------------------------------------------------------------ *
 *  301 redirects for removed duplicates
 * ------------------------------------------------------------------ *
 * Every URL a removed duplicate had (full + each size) is stored with the
 * keeper's matching URL. When a request for one of those files 404s, WordPress
 * answers with a permanent redirect to the keeper instead.
 */
function bl_img_redirect_key( $url ) {
	$path = wp_parse_url( $url, PHP_URL_PATH );
	return $path ? substr( strtolower( rawurldecode( $path ) ), 0, 191 ) : '';
}

function bl_img_record_redirects( $dup, $keeper ) {
	global $wpdb;
	$map = bl_img_build_url_map( bl_img_attachment_urls( $dup ), bl_img_attachment_urls( $keeper ), wp_get_attachment_metadata( $keeper ) );
	$n   = 0;
	foreach ( $map as $from => $to ) {
		if ( 0 !== strpos( $from, 'http' ) ) {
			continue; // relative variants share the same path key
		}
		$key = bl_img_redirect_key( $from );
		if ( ! $key ) {
			continue;
		}
		$wpdb->replace( $wpdb->prefix . 'bl_img_redirects', array( 'from_path' => $key, 'to_url' => esc_url_raw( $to ), 'attachment_id' => (int) $keeper, 'created_utc' => bl_img_now_utc() ) );
		$n++;
	}
	return $n;
}

/* If a keeper is itself later removed/merged, follow the chain (max 5 hops). */
function bl_img_redirect_target( $path ) {
	global $wpdb;
	$t   = $wpdb->prefix . 'bl_img_redirects';
	$to  = '';
	$key = $path;
	for ( $i = 0; $i < 5; $i++ ) {
		$next = $wpdb->get_var( $wpdb->prepare( "SELECT to_url FROM $t WHERE from_path = %s", $key ) );
		if ( ! $next ) {
			break;
		}
		$to  = $next;
		$key = bl_img_redirect_key( $next );
	}
	return $to;
}

/*
 * Apache on DPF's host answers requests for missing upload files itself (a
 * static 404 — WordPress never runs). This marked block in uploads/.htaccess
 * hands ONLY missing image files to WordPress, which then 301s known old
 * duplicate URLs to their keeper and returns a fast 404 for everything else.
 * Existing files are untouched (RewriteCond !-f). Removed on deactivation.
 */
function bl_img_uploads_htaccess( $enable = true ) {
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	$up   = wp_upload_dir( null, false );
	$file = trailingslashit( $up['basedir'] ) . '.htaccess';
	if ( ( file_exists( $file ) && ! is_writable( $file ) ) || ( ! file_exists( $file ) && ! is_writable( $up['basedir'] ) ) ) {
		return false;
	}
	$lines = array();
	if ( $enable ) {
		$index = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$lines = array(
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'RewriteCond %{REQUEST_FILENAME} !-f',
			'RewriteRule ^(.+\.(?:jpe?g|png|gif|webp|avif))$ ' . ( $index ? $index : '/' ) . 'index.php?bl_img_missing=$1 [L,QSA,NC]',
			'</IfModule>',
		);
	}
	return insert_with_markers( $file, '(BL) Images redirects', $lines );
}

add_action( 'plugins_loaded', function () {
	if ( '1' !== get_option( 'bl_img_htaccess_v' ) && is_admin() ) {
		if ( bl_img_uploads_htaccess( true ) ) {
			update_option( 'bl_img_htaccess_v', '1', false );
		}
	}
}, 20 );
register_deactivation_hook( BL_IMG_FILE, function () {
	bl_img_uploads_htaccess( false );
	delete_option( 'bl_img_htaccess_v' );
} );

/* Fast path for missing images routed here by the .htaccess block. */
add_action( 'init', function () {
	if ( empty( $_GET['bl_img_missing'] ) ) {
		return;
	}
	$rel = ltrim( (string) wp_unslash( $_GET['bl_img_missing'] ), '/' );
	$up  = wp_upload_dir( null, false );
	$to  = bl_img_redirect_target( bl_img_redirect_key( trailingslashit( $up['baseurl'] ) . $rel ) );
	if ( $to ) {
		wp_redirect( $to, 301, '(BL) Images' );
		exit;
	}
	status_header( 404 );
	nocache_headers();
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo 'Not found';
	exit;
}, 0 );

add_action( 'template_redirect', function () {
	if ( ! is_404() || empty( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}
	$uri = wp_unslash( $_SERVER['REQUEST_URI'] );
	if ( false === stripos( $uri, '/uploads/' ) || ! preg_match( '/\.(jpe?g|png|gif|webp|avif|tiff?)(\?|$)/i', $uri ) ) {
		return;
	}
	$to = bl_img_redirect_target( bl_img_redirect_key( $uri ) );
	if ( $to ) {
		wp_redirect( $to, 301, '(BL) Images' );
		exit;
	}
}, 1 );

/** Step 2: archive + delete duplicates that no longer have any references. */
function bl_img_remove_duplicates( $dups ) {
	$removed = array();
	$skipped = array();
	foreach ( array_map( 'intval', (array) $dups ) as $d ) {
		if ( ! bl_img_is_image_attachment( $d ) || ! get_post_meta( $d, '_bl_img_consolidated_into', true ) ) {
			$skipped[] = array( 'id' => $d, 'why' => 'Not consolidated yet.' );
			continue;
		}
		bl_img_live_scan( $d );
		if ( bl_img_usage_count( $d ) > 0 ) {
			$skipped[] = array( 'id' => $d, 'why' => 'Still referenced somewhere.' );
			continue;
		}
		// Archive: every file + the full post/meta record.
		$arch  = bl_img_vault_dir( 'consolidated/' . $d );
		$file  = get_attached_file( $d );
		$meta  = wp_get_attachment_metadata( $d );
		$files = array();
		if ( $file && file_exists( $file ) ) {
			copy( $file, $arch . '/' . basename( $file ) );
			$files[ basename( $file ) ] = hash_file( 'sha256', $file );
		}
		if ( ! empty( $meta['original_image'] ) && file_exists( dirname( $file ) . '/' . $meta['original_image'] ) ) {
			copy( dirname( $file ) . '/' . $meta['original_image'], $arch . '/' . $meta['original_image'] );
		}
		$post = get_post( $d, ARRAY_A );
		bl_img_vault_put_json( 'consolidated/' . $d, 'record.json', array( 'post' => $post, 'meta' => get_post_meta( $d ), 'files' => $files, 'keeper' => (int) get_post_meta( $d, '_bl_img_consolidated_into', true ) ) );
		$keeper = (int) get_post_meta( $d, '_bl_img_consolidated_into', true );
		// Provenance of a removed duplicate lives on under the keeper's vault folder too.
		$old_items = bl_img_vault_dir() . '/items/' . $d;
		if ( is_dir( $old_items ) ) {
			rename( $old_items, bl_img_vault_dir( 'items/' . $keeper ) . '/merged-from-' . $d );
		}
		// Safety: never delete a file another library item also points to.
		global $wpdb;
		$rel_file = get_post_meta( $d, '_wp_attached_file', true );
		$sharer   = $rel_file ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s AND post_id <> %d LIMIT 1", $rel_file, $d ) ) : 0;
		if ( $sharer ) {
			$skipped[] = array( 'id' => $d, 'why' => 'Shares its file with #' . $sharer . ' — not deleted.' );
			continue;
		}
		// Outside links (old emails, social posts, search engines) → 301 to the keeper.
		$redirects = bl_img_record_redirects( $d, $keeper );
		wp_delete_attachment( $d, true );
		bl_img_ledger_append( 'remove_duplicate', array( 'removed' => $d, 'keeper' => $keeper, 'archived_files' => $files, 'redirects' => $redirects ), $keeper );
		$removed[] = $d;
	}
	bl_img_purge_page_cache();
	return array( 'removed' => $removed, 'skipped' => $skipped );
}

/* ================================================================== *
 *  Repair: references that still point at a REMOVED duplicate
 * ================================================================== *
 * Builds removed-ID → keeper-ID from the ledger (remove_duplicate events)
 * and the vault's consolidated/<id>/record.json files, follows chains to an
 * image that still exists, then repoints featured images, ACF image/file/
 * gallery fields, term thumbnails, site logo/icon, and (in batches) every
 * wp-image-N class, block id and old file URL in content, Elementor and options.
 */
function bl_img_removed_map() {
	global $wpdb;
	$map  = array();
	$rows = $wpdb->get_col( "SELECT data FROM " . bl_img_ledger_table() . " WHERE event = 'remove_duplicate'" );
	foreach ( $rows as $json ) {
		$d = json_decode( $json, true );
		if ( ! empty( $d['removed'] ) && ! empty( $d['keeper'] ) ) {
			$map[ (int) $d['removed'] ] = (int) $d['keeper'];
		}
	}
	foreach ( (array) glob( bl_img_vault_dir() . '/consolidated/*/record.json' ) as $f ) {
		$id = (int) basename( dirname( $f ) );
		if ( $id && ! isset( $map[ $id ] ) ) {
			$d = json_decode( file_get_contents( $f ), true );
			if ( ! empty( $d['keeper'] ) ) {
				$map[ $id ] = (int) $d['keeper'];
			}
		}
	}
	// Follow chains (A→B where B was itself removed → C) and keep only live targets.
	$out = array();
	foreach ( $map as $from => $to ) {
		if ( bl_img_is_image_attachment( $from ) ) {
			continue; // not actually gone
		}
		$hops = 0;
		while ( isset( $map[ $to ] ) && ! bl_img_is_image_attachment( $to ) && $hops++ < 6 ) {
			$to = $map[ $to ];
		}
		if ( bl_img_is_image_attachment( $to ) ) {
			$out[ $from ] = $to;
		}
	}
	return $out;
}

/** URL map (old duplicate file URLs → keeper URLs) from the redirects table. */
function bl_img_removed_url_map() {
	global $wpdb;
	$host = untrailingslashit( home_url() );
	$map  = array();
	foreach ( $wpdb->get_results( "SELECT from_path, to_url FROM {$wpdb->prefix}bl_img_redirects", ARRAY_A ) as $r ) {
		$map[ $host . $r['from_path'] ] = $r['to_url'];
	}
	uksort( $map, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
	return $map;
}

/** Step A (fast, one call): ID-based references. */
function bl_img_repair_ids() {
	global $wpdb;
	$map = bl_img_removed_map();
	if ( ! $map ) {
		return array( 'fixed' => 0, 'details' => array(), 'removed_known' => 0 );
	}
	$in      = implode( ',', array_map( 'intval', array_keys( $map ) ) );
	$fixed   = 0;
	$details = array();
	$undo    = array();

	// 1. Featured images + ACF single image/file fields (bare numeric meta).
	$rows = $wpdb->get_results( "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_value IN ('" . str_replace( ',', "','", $in ) . "')", ARRAY_A );
	foreach ( $rows as $r ) {
		$is_thumb = '_thumbnail_id' === $r['meta_key'];
		$is_acf   = false;
		if ( ! $is_thumb && '_' !== substr( $r['meta_key'], 0, 1 ) ) {
			$fk = get_post_meta( $r['post_id'], '_' . $r['meta_key'], true );
			if ( is_string( $fk ) && 0 === strpos( $fk, 'field_' ) ) {
				$type   = function_exists( 'acf_get_field' ) ? ( ( $f = acf_get_field( $fk ) ) ? $f['type'] : '' ) : 'image';
				$is_acf = in_array( $type, array( 'image', 'file' ), true );
			}
		}
		if ( ! $is_thumb && ! $is_acf ) {
			continue;
		}
		$new = (string) $map[ (int) $r['meta_value'] ];
		$undo[] = array( 'type' => 'meta', 'meta_id' => (int) $r['meta_id'], 'post_id' => (int) $r['post_id'], 'meta_value' => $r['meta_value'] );
		$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $new ), array( 'meta_id' => $r['meta_id'] ) );
		wp_cache_delete( (int) $r['post_id'], 'post_meta' );
		clean_post_cache( (int) $r['post_id'] );
		$fixed++;
		$details[] = get_the_title( $r['post_id'] ) . ' (' . get_post_type( $r['post_id'] ) . ' #' . $r['post_id'] . ', ' . ( $is_thumb ? 'featured image' : $r['meta_key'] ) . '): #' . $r['meta_value'] . ' → #' . $new;
	}

	// 2. ACF galleries (serialized ID arrays).
	$gal = $wpdb->get_results( "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE 'a:%' AND meta_key NOT LIKE '\\_%'", ARRAY_A );
	foreach ( $gal as $r ) {
		$arr = @unserialize( $r['meta_value'], array( 'allowed_classes' => false ) );
		if ( ! is_array( $arr ) || ! $arr ) {
			continue;
		}
		$changed = false;
		foreach ( $arr as $k => $v ) {
			if ( is_scalar( $v ) && ctype_digit( (string) $v ) && isset( $map[ (int) $v ] ) ) {
				$arr[ $k ] = is_int( $v ) ? $map[ (int) $v ] : (string) $map[ (int) $v ];
				$changed   = true;
			}
		}
		if ( $changed ) {
			$fk = get_post_meta( $r['post_id'], '_' . $r['meta_key'], true );
			if ( ! ( is_string( $fk ) && 0 === strpos( $fk, 'field_' ) ) ) {
				continue; // only ACF-managed lists; never guess at unrelated numeric arrays
			}
			$undo[] = array( 'type' => 'meta', 'meta_id' => (int) $r['meta_id'], 'post_id' => (int) $r['post_id'], 'meta_value' => $r['meta_value'] );
			$wpdb->update( $wpdb->postmeta, array( 'meta_value' => serialize( $arr ) ), array( 'meta_id' => $r['meta_id'] ) );
			wp_cache_delete( (int) $r['post_id'], 'post_meta' );
			$fixed++;
			$details[] = get_the_title( $r['post_id'] ) . ' (gallery ' . $r['meta_key'] . ')';
		}
	}

	// 3. Term thumbnails, site icon/logo, theme logo.
	foreach ( $wpdb->get_results( "SELECT meta_id, term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE meta_key IN ('thumbnail_id','_thumbnail_id','image') AND meta_value IN ('" . str_replace( ',', "','", $in ) . "')", ARRAY_A ) as $r ) {
		$undo[] = array( 'type' => 'term', 'term_id' => (int) $r['term_id'], 'key' => $r['meta_key'], 'value' => $r['meta_value'] );
		update_term_meta( $r['term_id'], $r['meta_key'], (string) $map[ (int) $r['meta_value'] ] );
		$fixed++;
		$details[] = 'Term #' . $r['term_id'] . ' ' . $r['meta_key'];
	}
	foreach ( array( 'site_icon', 'site_logo' ) as $opt ) {
		$v = (int) get_option( $opt );
		if ( $v && isset( $map[ $v ] ) ) {
			$undo[] = array( 'type' => 'option', 'name' => $opt, 'option_value' => (string) $v );
			update_option( $opt, $map[ $v ] );
			$fixed++;
			$details[] = 'Site setting ' . $opt;
		}
	}
	$tm = get_theme_mods();
	if ( ! empty( $tm['custom_logo'] ) && isset( $map[ (int) $tm['custom_logo'] ] ) ) {
		set_theme_mod( 'custom_logo', $map[ (int) $tm['custom_logo'] ] );
		$fixed++;
		$details[] = 'Theme logo';
	}

	$undo_id = '';
	if ( $undo ) {
		$undo_id = gmdate( 'Ymd-His' ) . '-repair';
		bl_img_vault_put_json( 'undo', $undo_id . '.json', array( 'reason' => 'repair: repoint removed duplicates (IDs)', 'at' => bl_img_now_utc(), 'by' => bl_img_current_actor(), 'url_map' => array(), 'id_map' => $map, 'rows' => $undo ) );
	}
	if ( $fixed ) {
		bl_img_ledger_append( 'repair_references', array( 'step' => 'ids', 'fixed' => $fixed, 'details' => $details, 'undo' => $undo_id ) );
		bl_img_purge_page_cache();
	}
	return array( 'fixed' => $fixed, 'details' => $details, 'removed_known' => count( $map ), 'undo' => $undo_id );
}

/** Step B (batched): content, Elementor data and other meta — wp-image-N, block ids, old URLs. */
function bl_img_repair_content_batch( $offset, $size = 120 ) {
	global $wpdb;
	bl_img_raise_limits();
	$map  = bl_img_removed_map();
	$urls = bl_img_removed_url_map();
	$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment','customize_changeset','oembed_cache') AND post_status NOT IN ('auto-draft','trash') ORDER BY ID ASC LIMIT %d OFFSET %d", $size, $offset ) );
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment','customize_changeset','oembed_cache') AND post_status NOT IN ('auto-draft','trash')" );
	$fixed = 0;
	$undo  = array();
	$touched = array();
	if ( $map || $urls ) {
		foreach ( $ids as $pid ) {
			$p = get_post( $pid );
			$nc = bl_img_rewrite_value( $p->post_content, $urls, $map );
			if ( $nc !== $p->post_content ) {
				$undo[] = array( 'type' => 'post', 'id' => $p->ID, 'post_content' => $p->post_content, 'post_excerpt' => $p->post_excerpt );
				$wpdb->update( $wpdb->posts, array( 'post_content' => $nc ), array( 'ID' => $p->ID ) );
				clean_post_cache( $p->ID );
				$touched[ $p->ID ] = 1;
				$fixed++;
			}
			$metas = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND ( meta_key = '_elementor_data' OR meta_value LIKE %s ) AND meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata','_elementor_css')", $pid, '%uploads%' ), ARRAY_A );
			foreach ( $metas as $m ) {
				$nv = bl_img_rewrite_value( $m['meta_value'], $urls, $map, $m['meta_key'] );
				if ( $nv !== $m['meta_value'] ) {
					$undo[] = array( 'type' => 'meta', 'meta_id' => (int) $m['meta_id'], 'post_id' => (int) $pid, 'meta_value' => $m['meta_value'] );
					$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $nv ), array( 'meta_id' => $m['meta_id'] ) );
					wp_cache_delete( (int) $pid, 'post_meta' );
					$touched[ $pid ] = 1;
					$fixed++;
				}
			}
		}
		foreach ( array_keys( $touched ) as $pid ) {
			delete_post_meta( $pid, '_elementor_css' );
			bl_img_index_post( $pid );
		}
	}
	$done = count( $ids ) < $size;
	if ( $done && $urls ) {
		// Options last (one pass).
		foreach ( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_value LIKE '%uploads%' AND option_name NOT LIKE '\\_%transient%'", ARRAY_A ) as $o ) {
			$nv = bl_img_rewrite_value( $o['option_value'], $urls, $map );
			if ( $nv !== $o['option_value'] ) {
				$undo[] = array( 'type' => 'option', 'name' => $o['option_name'], 'option_value' => $o['option_value'] );
				$wpdb->update( $wpdb->options, array( 'option_value' => $nv ), array( 'option_name' => $o['option_name'] ) );
				wp_cache_delete( $o['option_name'], 'options' );
				$fixed++;
			}
		}
		wp_cache_delete( 'alloptions', 'options' );
	}
	if ( $undo ) {
		bl_img_vault_put_json( 'undo', gmdate( 'Ymd-His' ) . '-repair-' . (int) $offset . '.json', array( 'reason' => 'repair: repoint removed duplicates (content, from #' . (int) $offset . ')', 'at' => bl_img_now_utc(), 'by' => bl_img_current_actor(), 'url_map' => array(), 'id_map' => $map, 'rows' => $undo ) );
		bl_img_ledger_append( 'repair_references', array( 'step' => 'content', 'offset' => (int) $offset, 'fixed' => $fixed ) );
	}
	if ( $done ) {
		bl_img_purge_page_cache();
	}
	return array( 'next' => $offset + count( $ids ), 'total' => $total, 'done' => $done, 'fixed' => $fixed );
}
