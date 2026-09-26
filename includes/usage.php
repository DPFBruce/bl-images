<?php
/**
 * Where-used index + the reference-rewrite engine.
 *
 * INDEX: one pass over post content, postmeta (incl. Elementor's JSON) and
 * options extracts every uploads URL and image ID, maps it to its attachment,
 * and stores rows in {prefix}bl_img_usage. Kept fresh on save_post; rebuilt
 * in batches from Tools. Powers "where used", "unused", audit counts, and
 * tells the rewrite engine exactly which rows to touch.
 *
 * REWRITE: swap URLs (plain + JSON-escaped) and IDs (wp-image-N, block
 * attributes, Elementor {"id":N,"url":…}, _thumbnail_id, gallery ids,
 * theme_mods logos) — safely inside serialized PHP and JSON — and logs every
 * before-value so the change can be undone.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function bl_img_usage_table() {
	global $wpdb;
	return $wpdb->prefix . 'bl_img_usage';
}

/* ------------------------------------------------------------------ *
 *  Stem map: upload-relative stem → attachment ID
 * ------------------------------------------------------------------ */

function bl_img_stem_map( $refresh = false ) {
	static $map = null;
	if ( null !== $map && ! $refresh ) {
		return $map;
	}
	global $wpdb;
	$map  = array();
	$rows = $wpdb->get_results( "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_wp_attached_file' AND p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'", ARRAY_N );
	foreach ( $rows as $r ) {
		$stem = preg_replace( '/-(scaled|rotated)$/', '', preg_replace( '/\.[a-z0-9]+$/i', '', $r[1] ) );
		$map[ strtolower( $stem ) ] = (int) $r[0];
	}
	return $map;
}

/** Reduce any uploads URL/path to its stem: .../2024/05/foo-300x200.webp → 2024/05/foo */
function bl_img_url_to_stem( $rel ) {
	$rel = str_replace( '\\/', '/', $rel );
	$rel = preg_replace( '/\.[a-z0-9]+$/i', '', $rel );
	$rel = preg_replace( '/-\d+x\d+$/', '', $rel );
	$rel = preg_replace( '/-(scaled|rotated)$/', '', $rel );
	return strtolower( $rel );
}

/**
 * Pull every attachment reference out of a string. Returns [ attachment_id => how ].
 */
function bl_img_extract_refs( $text ) {
	$found = array();
	if ( ! is_string( $text ) || '' === $text ) {
		return $found;
	}
	$map = bl_img_stem_map();
	if ( false !== strpos( $text, 'uploads' ) ) {
		if ( preg_match_all( '#uploads(?:\\\\?/)((?:[^"\'\s<>()?\#]+?))\.(?:jpe?g|png|gif|webp|avif|svg|tiff?)#i', $text, $m ) ) {
			foreach ( $m[1] as $rel ) {
				$stem = bl_img_url_to_stem( $rel . '.x' );
				if ( isset( $map[ $stem ] ) ) {
					$found[ $map[ $stem ] ] = 'url';
				}
			}
		}
	}
	if ( preg_match_all( '/wp-image-(\d+)/', $text, $m ) ) {
		foreach ( $m[1] as $id ) {
			$found[ (int) $id ] = isset( $found[ (int) $id ] ) ? $found[ (int) $id ] : 'class';
		}
	}
	if ( preg_match_all( '/<!-- wp:(?:image|cover|media-text|gallery)[^>]*?"(?:id|mediaId)":(\d+)/', $text, $m ) ) {
		foreach ( $m[1] as $id ) {
			$found[ (int) $id ] = isset( $found[ (int) $id ] ) ? $found[ (int) $id ] : 'block';
		}
	}
	if ( preg_match_all( '/\[gallery[^\]]*ids="([\d,\s]+)"/', $text, $m ) ) {
		foreach ( $m[1] as $list ) {
			foreach ( preg_split( '/[\s,]+/', $list ) as $id ) {
				if ( $id ) {
					$found[ (int) $id ] = 'gallery';
				}
			}
		}
	}
	return $found;
}

/* ------------------------------------------------------------------ *
 *  Index maintenance
 * ------------------------------------------------------------------ */

function bl_img_index_post( $post_id ) {
	global $wpdb;
	$t = bl_img_usage_table();
	$p = get_post( $post_id );
	$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE object_type IN ('post','meta') AND object_id = %d", $post_id ) );
	if ( ! $p || in_array( $p->post_type, array( 'revision', 'attachment', 'customize_changeset', 'oembed_cache' ), true ) || 'auto-draft' === $p->post_status ) {
		return;
	}
	$rows = array();
	foreach ( bl_img_extract_refs( $p->post_content . ' ' . $p->post_excerpt ) as $aid => $how ) {
		$rows[] = array( $aid, 'post', $post_id, 'post_content', $how );
	}
	$metas = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND ( meta_key = '_thumbnail_id' OR meta_value LIKE %s OR meta_key = '_elementor_data' )", $post_id, '%uploads%' ), ARRAY_A );
	foreach ( $metas as $m ) {
		if ( '_thumbnail_id' === $m['meta_key'] ) {
			if ( (int) $m['meta_value'] ) {
				$rows[] = array( (int) $m['meta_value'], 'meta', $post_id, '_thumbnail_id', 'featured' );
			}
			continue;
		}
		if ( in_array( $m['meta_key'], array( '_wp_attached_file', '_wp_attachment_metadata', '_elementor_css' ), true ) ) {
			continue;
		}
		foreach ( bl_img_extract_refs( $m['meta_value'] ) as $aid => $how ) {
			$rows[] = array( $aid, 'meta', $post_id, substr( $m['meta_key'], 0, 191 ), '_elementor_data' === $m['meta_key'] ? 'elementor' : $how );
		}
	}
	bl_img_usage_insert( $rows );
}

function bl_img_usage_insert( $rows ) {
	global $wpdb;
	if ( ! $rows ) {
		return;
	}
	$t    = bl_img_usage_table();
	$vals = array();
	foreach ( $rows as $r ) {
		$vals[] = $wpdb->prepare( '(%d,%s,%d,%s,%s)', $r[0], $r[1], $r[2], $r[3], $r[4] );
	}
	foreach ( array_chunk( $vals, 200 ) as $chunk ) {
		$wpdb->query( "INSERT IGNORE INTO $t (attachment_id,object_type,object_id,object_key,how) VALUES " . implode( ',', $chunk ) );
	}
}

function bl_img_index_options() {
	global $wpdb;
	$t = bl_img_usage_table();
	$wpdb->query( "DELETE FROM $t WHERE object_type IN ('option','term')" );
	$rows = array();
	$opts = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name NOT LIKE '\_transient%' AND option_name NOT LIKE '\_site\_transient%' AND ( option_value LIKE '%uploads%' OR option_name IN ('site_icon','site_logo') OR option_name LIKE 'theme\_mods\_%' )", ARRAY_A );
	foreach ( $opts as $o ) {
		if ( in_array( $o['option_name'], array( 'site_icon', 'site_logo' ), true ) ) {
			if ( (int) $o['option_value'] ) {
				$rows[] = array( (int) $o['option_value'], 'option', 0, $o['option_name'], 'id' );
			}
			continue;
		}
		if ( 0 === strpos( $o['option_name'], 'theme_mods_' ) ) {
			$tm = maybe_unserialize( $o['option_value'] );
			if ( is_array( $tm ) && ! empty( $tm['custom_logo'] ) ) {
				$rows[] = array( (int) $tm['custom_logo'], 'option', 0, $o['option_name'], 'custom_logo' );
			}
		}
		foreach ( bl_img_extract_refs( $o['option_value'] ) as $aid => $how ) {
			$rows[] = array( $aid, 'option', 0, substr( $o['option_name'], 0, 191 ), $how );
		}
	}
	$terms = $wpdb->get_results( "SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE meta_key IN ('thumbnail_id','image','_thumbnail_id') OR meta_value LIKE '%uploads%'", ARRAY_A );
	foreach ( $terms as $tm ) {
		if ( ctype_digit( (string) $tm['meta_value'] ) ) {
			$rows[] = array( (int) $tm['meta_value'], 'term', (int) $tm['term_id'], $tm['meta_key'], 'id' );
		} else {
			foreach ( bl_img_extract_refs( $tm['meta_value'] ) as $aid => $how ) {
				$rows[] = array( $aid, 'term', (int) $tm['term_id'], $tm['meta_key'], $how );
			}
		}
	}
	bl_img_usage_insert( $rows );
}

/** Batch rebuild: call with offset 0, keep calling with the returned next until done. */
function bl_img_index_batch( $offset, $size = 150 ) {
	global $wpdb;
	if ( 0 === (int) $offset ) {
		$wpdb->query( 'TRUNCATE TABLE ' . bl_img_usage_table() );
		bl_img_index_options();
	}
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment','customize_changeset','oembed_cache','nav_menu_item') AND post_status NOT IN ('auto-draft','trash') ORDER BY ID ASC LIMIT %d OFFSET %d", $size, $offset ) );
	foreach ( $ids as $id ) {
		bl_img_index_post( (int) $id );
	}
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment','customize_changeset','oembed_cache','nav_menu_item') AND post_status NOT IN ('auto-draft','trash')" );
	$next  = $offset + count( $ids );
	$done  = count( $ids ) < $size;
	if ( $done ) {
		update_option( 'bl_img_index_built', gmdate( 'c' ), false );
	}
	return array( 'next' => $next, 'total' => $total, 'done' => $done );
}

add_action( 'save_post', function ( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || ! get_option( 'bl_img_index_built' ) ) {
		return;
	}
	bl_img_index_post( $post_id );
}, 99 );
add_action( 'updated_post_meta', function ( $mid, $post_id, $key ) {
	if ( in_array( $key, array( '_elementor_data', '_thumbnail_id' ), true ) && get_option( 'bl_img_index_built' ) ) {
		bl_img_index_post( $post_id );
	}
}, 99, 3 );
add_action( 'deleted_post', function ( $post_id ) {
	global $wpdb;
	$t = bl_img_usage_table();
	$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE (object_type IN ('post','meta') AND object_id = %d) OR attachment_id = %d", $post_id, $post_id ) );
} );

/* ------------------------------------------------------------------ *
 *  Where used
 * ------------------------------------------------------------------ */

function bl_img_where_used( $attachment_id, $live_scan = false ) {
	global $wpdb;
	$t = bl_img_usage_table();
	if ( $live_scan || ! get_option( 'bl_img_index_built' ) ) {
		bl_img_live_scan( $attachment_id );
	}
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE attachment_id = %d ORDER BY object_type, object_id", $attachment_id ), ARRAY_A );
	$out  = array();
	$seen = array();
	foreach ( $rows as $r ) {
		$k = $r['object_type'] . ':' . $r['object_id'] . ':' . ( 'option' === $r['object_type'] || 'term' === $r['object_type'] ? $r['object_key'] : '' );
		if ( isset( $seen[ $k ] ) ) {
			$seen[ $k ]['how'][] = $r['how'];
			continue;
		}
		$item = array( 'type' => $r['object_type'], 'id' => (int) $r['object_id'], 'key' => $r['object_key'], 'how' => array( $r['how'] ), 'title' => '', 'edit' => '', 'view' => '', 'status' => '' );
		if ( in_array( $r['object_type'], array( 'post', 'meta' ), true ) ) {
			$p = get_post( $r['object_id'] );
			if ( ! $p ) {
				continue;
			}
			$pto           = get_post_type_object( $p->post_type );
			$item['title'] = $p->post_title ? $p->post_title : '(untitled)';
			$item['kind']  = $pto ? $pto->labels->singular_name : $p->post_type;
			$item['status']= $p->post_status;
			$item['edit']  = get_edit_post_link( $p->ID, 'raw' );
			$item['view']  = 'publish' === $p->post_status ? get_permalink( $p ) : '';
			if ( get_post_meta( $p->ID, '_elementor_edit_mode', true ) ) {
				$item['elementor'] = admin_url( 'post.php?post=' . $p->ID . '&action=elementor' );
			}
			$k = 'post:' . $p->ID . ':';
			if ( isset( $seen[ $k ] ) ) {
				$seen[ $k ]['how'][] = $r['how'];
				continue;
			}
		} elseif ( 'option' === $r['object_type'] ) {
			$item['title'] = 'Site setting: ' . $r['object_key'];
			$item['kind']  = 'Option';
		} else {
			$term          = get_term( $r['object_id'] );
			$item['title'] = $term && ! is_wp_error( $term ) ? $term->name : 'Term #' . $r['object_id'];
			$item['kind']  = 'Term';
			$item['edit']  = $term && ! is_wp_error( $term ) ? get_edit_term_link( $term ) : '';
		}
		$seen[ $k ] = $item;
	}
	foreach ( $seen as $s ) {
		$s['how'] = array_values( array_unique( $s['how'] ) );
		$out[]    = $s;
	}
	return $out;
}

/** Targeted scan for ONE attachment (no index needed). */
function bl_img_live_scan( $attachment_id ) {
	global $wpdb;
	$t    = bl_img_usage_table();
	$stem = bl_img_attachment_stem( $attachment_id );
	if ( ! $stem ) {
		return;
	}
	$base  = basename( $stem );
	$likes = array( '%' . $wpdb->esc_like( $base ) . '%', '%wp-image-' . (int) $attachment_id . '%', '%"id":' . (int) $attachment_id . '%' );
	$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s )", $likes[0], $likes[1], $likes[2] ) );
	$mids  = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE ( meta_value LIKE %s AND meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata') ) OR ( meta_key = '_thumbnail_id' AND meta_value = %s )", $likes[0], (string) (int) $attachment_id ) );
	foreach ( array_unique( array_merge( $ids, $mids ) ) as $pid ) {
		if ( (int) $pid !== (int) $attachment_id ) {
			bl_img_index_post( (int) $pid );
		}
	}
	bl_img_index_options();
}

function bl_img_usage_count( $attachment_id ) {
	global $wpdb;
	$t = bl_img_usage_table();
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT object_type, object_id, object_key FROM $t WHERE attachment_id = %d", $attachment_id ), ARRAY_A );
	$seen = array();
	foreach ( $rows as $r ) {
		// A post's content + its meta count as ONE place; options/terms count per key.
		$type = 'meta' === $r['object_type'] ? 'post' : $r['object_type'];
		$seen[ $type . ':' . $r['object_id'] . ':' . ( in_array( $type, array( 'option', 'term' ), true ) ? $r['object_key'] : '' ) ] = 1;
	}
	return count( $seen );
}

/* ================================================================== *
 *  REWRITE ENGINE
 * ================================================================== */

/**
 * Build the URL map from an OLD attachment state to a NEW one.
 * $old_urls / $new_urls: [ size_name => url ] (see bl_img_attachment_urls()).
 * Missing sizes map to the closest-width new size, else the new full URL.
 */
function bl_img_build_url_map( $old_urls, $new_urls, $new_meta = null ) {
	$map = array();
	foreach ( $old_urls as $size => $u ) {
		if ( isset( $new_urls[ $size ] ) ) {
			$map[ $u ] = $new_urls[ $size ];
			continue;
		}
		$map[ $u ] = isset( $new_urls['full'] ) ? $new_urls['full'] : reset( $new_urls );
		if ( $new_meta && ! empty( $new_meta['sizes'] ) && preg_match( '/-(\d+)x(\d+)\.[a-z]+$/i', $u, $m ) ) {
			$want = (int) $m[1];
			$best = null;
			foreach ( $new_meta['sizes'] as $n => $s ) {
				if ( isset( $new_urls[ $n ] ) && ( null === $best || abs( $s['width'] - $want ) < abs( $new_meta['sizes'][ $best ]['width'] - $want ) ) ) {
					$best = $n;
				}
			}
			if ( $best ) {
				$map[ $u ] = $new_urls[ $best ];
			}
		}
	}
	unset( $map[''] );
	// Match both http/https and protocol-relative forms, longest first so
	// "foo-300x200.jpg" never gets clobbered by "foo.jpg".
	$full = array();
	foreach ( $map as $o => $n ) {
		if ( $o === $n ) {
			continue;
		}
		$full[ $o ] = $n;
		$alt        = 0 === strpos( $o, 'https:' ) ? 'http:' . substr( $o, 6 ) : ( 0 === strpos( $o, 'http:' ) ? 'https:' . substr( $o, 5 ) : '' );
		if ( $alt ) {
			$full[ $alt ] = $n;
		}
		$rel = wp_make_link_relative( $o );
		if ( $rel && $rel !== $o ) {
			$full[ $rel ] = wp_make_link_relative( $n );
		}
	}
	uksort( $full, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
	return $full;
}

/** Apply URL + ID maps to a scalar string (handles JSON-escaped slashes). */
function bl_img_rewrite_string( $s, $url_map, $id_map ) {
	if ( ! is_string( $s ) || '' === $s ) {
		return $s;
	}
	if ( $url_map ) {
		$from = array();
		$to   = array();
		foreach ( $url_map as $o => $n ) {
			$from[] = $o;
			$to[]   = $n;
			$eo     = str_replace( '/', '\\/', $o );
			if ( $eo !== $o ) {
				$from[] = $eo;
				$to[]   = str_replace( '/', '\\/', $n );
			}
		}
		$s = str_replace( $from, $to, $s );
	}
	foreach ( $id_map as $old => $new ) {
		$old = (int) $old;
		$new = (int) $new;
		$s   = preg_replace( '/\bwp-image-' . $old . '\b/', 'wp-image-' . $new, $s );
		$s   = preg_replace_callback( '/<!-- wp:[^>]*?-->/', function ( $m ) use ( $old, $new ) {
			return preg_replace( '/"(id|mediaId)":' . $old . '(?=[,}\]])/', '"$1":' . $new, $m[0] );
		}, $s );
		$s   = preg_replace_callback( '/\[gallery[^\]]*ids="([\d,\s]+)"/', function ( $m ) use ( $old, $new ) {
			return str_replace( $m[1], preg_replace( '/(?<!\d)' . $old . '(?!\d)/', (string) $new, $m[1] ), $m[0] );
		}, $s );
		$s   = str_replace( 'attachment_id=' . $old . '"', 'attachment_id=' . $new . '"', $s );
	}
	return $s;
}

/** Walk decoded structures (serialized arrays / Elementor JSON) swapping ids next to urls. */
function bl_img_rewrite_walk( $v, $url_map, $id_map ) {
	if ( is_array( $v ) ) {
		$has_url = isset( $v['url'] ) && is_string( $v['url'] );
		foreach ( $v as $k => $x ) {
			$v[ $k ] = bl_img_rewrite_walk( $x, $url_map, $id_map );
		}
		if ( $has_url && isset( $v['id'] ) && isset( $id_map[ (int) $v['id'] ] ) ) {
			$v['id'] = is_string( $v['id'] ) ? (string) $id_map[ (int) $v['id'] ] : (int) $id_map[ (int) $v['id'] ];
		}
		// Elementor gallery: [{"id":N,"url":…}] handled above; also plain id lists.
		return $v;
	}
	if ( is_object( $v ) ) {
		return $v; // never touch serialized objects (class-dependent)
	}
	return is_string( $v ) ? bl_img_rewrite_string( $v, $url_map, array() ) : $v;
}

/** Rewrite one stored value of any shape. Returns the new raw value. */
function bl_img_rewrite_value( $raw, $url_map, $id_map, $key = '' ) {
	if ( ! is_string( $raw ) ) {
		return $raw;
	}
	if ( is_serialized( $raw ) ) {
		$u = @unserialize( $raw, array( 'allowed_classes' => false ) );
		if ( false === $u && 'b:0;' !== $raw ) {
			return $raw;
		}
		if ( is_array( $u ) && ! empty( $u['custom_logo'] ) && isset( $id_map[ (int) $u['custom_logo'] ] ) ) {
			$u['custom_logo'] = (int) $id_map[ (int) $u['custom_logo'] ];
		}
		return serialize( bl_img_rewrite_walk( $u, $url_map, $id_map ) );
	}
	if ( '_elementor_data' === $key || ( isset( $raw[0] ) && '[' === $raw[0] && false !== strpos( $raw, '"elType"' ) ) ) {
		$d = json_decode( $raw, true );
		if ( is_array( $d ) ) {
			return wp_json_encode( bl_img_rewrite_walk( $d, $url_map, $id_map ) );
		}
	}
	return bl_img_rewrite_string( $raw, $url_map, $id_map );
}

/**
 * Rewrite every stored reference for the given attachments.
 * $att_ids: attachments whose references we're moving (the OLD ones).
 * Returns [ changed => n, undo => undo-log id ].
 */
function bl_img_rewrite_references( $att_ids, $url_map, $id_map, $reason ) {
	global $wpdb;
	$att_ids = array_map( 'intval', (array) $att_ids );
	foreach ( $att_ids as $aid ) {
		bl_img_live_scan( $aid ); // make sure the index is current for these
	}
	$t    = bl_img_usage_table();
	$in   = implode( ',', $att_ids );
	$objs = $wpdb->get_results( "SELECT DISTINCT object_type, object_id, object_key FROM $t WHERE attachment_id IN ($in)", ARRAY_A );
	$undo = array();
	$n    = 0;
	$posts_touched = array();

	foreach ( $objs as $o ) {
		if ( 'post' === $o['object_type'] ) {
			$p = get_post( $o['object_id'] );
			if ( ! $p ) {
				continue;
			}
			$nc = bl_img_rewrite_value( $p->post_content, $url_map, $id_map );
			$ne = bl_img_rewrite_value( $p->post_excerpt, $url_map, $id_map );
			if ( $nc !== $p->post_content || $ne !== $p->post_excerpt ) {
				$undo[] = array( 'type' => 'post', 'id' => $p->ID, 'post_content' => $p->post_content, 'post_excerpt' => $p->post_excerpt );
				$wpdb->update( $wpdb->posts, array( 'post_content' => $nc, 'post_excerpt' => $ne ), array( 'ID' => $p->ID ) );
				clean_post_cache( $p->ID );
				$posts_touched[ $p->ID ] = true;
				$n++;
			}
		} elseif ( 'meta' === $o['object_type'] ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $o['object_id'], $o['object_key'] ), ARRAY_A );
			foreach ( $rows as $r ) {
				if ( '_thumbnail_id' === $r['meta_key'] ) {
					$nv = isset( $id_map[ (int) $r['meta_value'] ] ) ? (string) $id_map[ (int) $r['meta_value'] ] : $r['meta_value'];
				} else {
					$nv = bl_img_rewrite_value( $r['meta_value'], $url_map, $id_map, $r['meta_key'] );
				}
				if ( $nv !== $r['meta_value'] ) {
					$undo[] = array( 'type' => 'meta', 'meta_id' => (int) $r['meta_id'], 'post_id' => (int) $o['object_id'], 'meta_value' => $r['meta_value'] );
					$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $nv ), array( 'meta_id' => $r['meta_id'] ) );
					wp_cache_delete( (int) $o['object_id'], 'post_meta' );
					$posts_touched[ (int) $o['object_id'] ] = true;
					$n++;
				}
			}
		} elseif ( 'option' === $o['object_type'] ) {
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $o['object_key'] ) );
			if ( null === $raw ) {
				continue;
			}
			$nv = ( ctype_digit( (string) $raw ) && isset( $id_map[ (int) $raw ] ) ) ? (string) $id_map[ (int) $raw ] : bl_img_rewrite_value( $raw, $url_map, $id_map );
			if ( $nv !== $raw ) {
				$undo[] = array( 'type' => 'option', 'name' => $o['object_key'], 'option_value' => $raw );
				$wpdb->update( $wpdb->options, array( 'option_value' => $nv ), array( 'option_name' => $o['object_key'] ) );
				wp_cache_delete( $o['object_key'], 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				$n++;
			}
		} elseif ( 'term' === $o['object_type'] ) {
			$raw = get_term_meta( $o['object_id'], $o['object_key'], true );
			$nv  = ( ctype_digit( (string) $raw ) && isset( $id_map[ (int) $raw ] ) ) ? (string) $id_map[ (int) $raw ] : bl_img_rewrite_value( (string) $raw, $url_map, $id_map );
			if ( $nv !== (string) $raw ) {
				$undo[] = array( 'type' => 'term', 'term_id' => (int) $o['object_id'], 'key' => $o['object_key'], 'value' => $raw );
				update_term_meta( $o['object_id'], $o['object_key'], $nv );
				$n++;
			}
		}
	}

	// Elementor regenerates CSS per post; drop the stale copies.
	foreach ( array_keys( $posts_touched ) as $pid ) {
		delete_post_meta( $pid, '_elementor_css' );
		bl_img_index_post( $pid );
	}
	bl_img_index_options();

	$undo_id = '';
	if ( $undo ) {
		$undo_id = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 6, false, false ) );
		bl_img_vault_put_json( 'undo', $undo_id . '.json', array( 'reason' => $reason, 'at' => bl_img_now_utc(), 'by' => bl_img_current_actor(), 'url_map' => $url_map, 'id_map' => $id_map, 'rows' => $undo ) );
	}
	bl_img_purge_page_cache();
	return array( 'changed' => $n, 'undo' => $undo_id );
}

/** Restore every before-value from an undo log. */
function bl_img_undo_rewrite( $undo_id ) {
	global $wpdb;
	$undo_id = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $undo_id ) );
	$f       = bl_img_vault_abs( 'undo/' . $undo_id . '.json' );
	if ( ! $undo_id || ! file_exists( $f ) ) {
		return new WP_Error( 'no_undo', 'Undo record not found.' );
	}
	$log = json_decode( file_get_contents( $f ), true );
	$n   = 0;
	foreach ( array_reverse( (array) $log['rows'] ) as $r ) {
		if ( 'post' === $r['type'] ) {
			$wpdb->update( $wpdb->posts, array( 'post_content' => $r['post_content'], 'post_excerpt' => $r['post_excerpt'] ), array( 'ID' => $r['id'] ) );
			clean_post_cache( $r['id'] );
			delete_post_meta( $r['id'], '_elementor_css' );
		} elseif ( 'meta' === $r['type'] ) {
			$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $r['meta_value'] ), array( 'meta_id' => $r['meta_id'] ) );
			wp_cache_delete( $r['post_id'], 'post_meta' );
			delete_post_meta( $r['post_id'], '_elementor_css' );
		} elseif ( 'option' === $r['type'] ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $r['option_value'] ), array( 'option_name' => $r['name'] ) );
			wp_cache_delete( $r['name'], 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		} elseif ( 'term' === $r['type'] ) {
			update_term_meta( $r['term_id'], $r['key'], $r['value'] );
		}
		$n++;
	}
	rename( $f, $f . '.undone' );
	bl_img_purge_page_cache();
	bl_img_ledger_append( 'undo_rewrite', array( 'undo_id' => $undo_id, 'rows_restored' => $n, 'reason' => $log['reason'] ) );
	return array( 'restored' => $n );
}
