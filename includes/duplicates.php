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

function bl_img_hamming( $a, $b ) {
	static $pc = null;
	if ( null === $pc ) {
		$pc = array();
		for ( $i = 0; $i < 16; $i++ ) {
			$pc[ dechex( $i ) ] = substr_count( decbin( $i ), '1' );
		}
	}
	$d = 0;
	for ( $i = 0; $i < 16; $i++ ) {
		$d += $pc[ dechex( hexdec( $a[ $i ] ) ^ hexdec( $b[ $i ] ) ) ];
	}
	return $d;
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
	$n = count( $rows );
	for ( $i = 0; $i < $n; $i++ ) {
		for ( $j = $i + 1; $j < $n; $j++ ) {
			$a = $rows[ $i ];
			$b = $rows[ $j ];
			if ( $ar[ $a['ID'] ] && $ar[ $b['ID'] ] && abs( $ar[ $a['ID'] ] - $ar[ $b['ID'] ] ) / $ar[ $a['ID'] ] > 0.03 ) {
				continue;
			}
			if ( $a['dh'] === $b['dh'] || bl_img_hamming( $a['dh'], $b['dh'] ) <= $threshold ) {
				$union( $a['ID'], $b['ID'], 'similar' );
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
		wp_delete_attachment( $d, true );
		bl_img_ledger_append( 'remove_duplicate', array( 'removed' => $d, 'keeper' => $keeper, 'archived_files' => $files ), $keeper );
		$removed[] = $d;
	}
	bl_img_purge_page_cache();
	return array( 'removed' => $removed, 'skipped' => $skipped );
}
