<?php
/**
 * REST API — namespace bl-images/v1. Cookie + X-WP-Nonce auth.
 *   import-level  (upload_files):   search, stage, describe, commit, library, item
 *   admin-level   (manage_options): releases, audit, tools, settings
 *   public        (token):          release upload / preview / sign
 * Downloads (evidence ZIP, CSV, vault files) go through admin-post.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function bl_img_can_import() {
	return current_user_can( BL_IMG_CAP_IMPORT );
}
function bl_img_can_admin() {
	return current_user_can( BL_IMG_CAP_ADMIN );
}
function bl_img_can_edit_item( WP_REST_Request $r ) {
	return current_user_can( 'edit_post', (int) $r['id'] ) && bl_img_is_image_attachment( (int) $r['id'] );
}

add_action( 'rest_api_init', function () {
	$ns = 'bl-images/v1';
	$R  = function ( $route, $method, $cb, $perm ) use ( $ns ) {
		register_rest_route( $ns, $route, array( 'methods' => $method, 'callback' => $cb, 'permission_callback' => $perm ) );
	};

	/* ---------- search + import pipeline ---------- */
	$R( '/search', 'GET', function ( $r ) {
		return bl_img_search( array(
			'q'           => sanitize_text_field( (string) $r['q'] ),
			'page'        => (int) $r['page'],
			'tier'        => in_array( $r['tier'], array( 'pd', 'credit' ), true ) ? $r['tier'] : 'all',
			'orientation' => sanitize_key( (string) $r['orientation'] ),
			'category'    => sanitize_key( (string) $r['category'] ),
			'source'      => in_array( $r['source'], array( 'openverse', 'wikimedia' ), true ) ? $r['source'] : 'all',
		) );
	}, 'bl_img_can_import' );

	$R( '/stage', 'POST', function ( $r ) {
		$p = $r->get_json_params();
		return bl_img_stage_remote( sanitize_key( $p['source'] ), sanitize_text_field( $p['source_id'] ) );
	}, 'bl_img_can_import' );

	$R( '/stage-upload', 'POST', function ( $r ) {
		$f = $r->get_file_params();
		if ( empty( $f['file'] ) || ! empty( $f['file']['error'] ) ) {
			return new WP_Error( 'nofile', 'No file received (it may exceed the server upload limit of ' . size_format( wp_max_upload_size() ) . ').', array( 'status' => 400 ) );
		}
		$attest = json_decode( (string) $r['attest'], true );
		return bl_img_stage_upload( $f['file']['tmp_name'], $f['file']['name'], is_array( $attest ) ? $attest : array() );
	}, 'bl_img_can_import' );

	$R( '/describe', 'POST', function ( $r ) {
		$p = $r->get_json_params();
		return bl_img_stage_describe( sanitize_key( $p['token'] ), sanitize_text_field( isset( $p['hint'] ) ? $p['hint'] : '' ) );
	}, 'bl_img_can_import' );

	$R( '/commit', 'POST', function ( $r ) {
		$p = $r->get_json_params();
		return bl_img_stage_commit( sanitize_key( $p['token'] ), isset( $p['meta'] ) && is_array( $p['meta'] ) ? $p['meta'] : array(), isset( $p['parent'] ) ? (int) $p['parent'] : 0 );
	}, 'bl_img_can_import' );

	/* ---------- library + item ---------- */
	$R( '/library', 'GET', function ( $r ) {
		return bl_img_audit( array( 'page' => (int) $r['page'], 'per' => (int) $r['per'], 'filter' => sanitize_key( (string) $r['filter'] ), 's' => sanitize_text_field( (string) $r['s'] ) ) );
	}, 'bl_img_can_import' );

	register_rest_route( $ns, '/item/(?P<id>\d+)', array(
		array( 'methods' => 'GET', 'permission_callback' => 'bl_img_can_edit_item', 'callback' => function ( $r ) {
			$id  = (int) $r['id'];
			$sum = bl_img_attachment_summary( $id );
			$sum['audit']    = bl_img_audit_row( $id );
			$sum['ledger']   = array_map( function ( $row ) { $row['data'] = json_decode( $row['data'], true ); return $row; }, bl_img_ledger_for( $id ) );
			$sum['usage']    = bl_img_where_used( $id );
			$sum['verify']   = bl_img_verify_file( $id );
			$sum['release_list'] = array_map( function ( $rid ) { return array( 'id' => $rid, 'summary' => bl_img_release_summary( $rid ) ); }, $sum['releases'] );
			$sum['evidence'] = wp_nonce_url( admin_url( 'admin-post.php?action=bl_img_evidence&id=' . $id ), 'bl_img_evidence_' . $id );
			$sum['master_dl'] = $sum['has_master'] ? wp_nonce_url( admin_url( 'admin-post.php?action=bl_img_master&id=' . $id ), 'bl_img_master_' . $id ) : '';
			return $sum;
		} ),
		array( 'methods' => 'POST', 'permission_callback' => 'bl_img_can_edit_item', 'callback' => function ( $r ) {
			$id     = (int) $r['id'];
			$p      = $r->get_json_params();
			$before = array( 'alt' => get_post_meta( $id, '_wp_attachment_image_alt', true ), 'title' => get_the_title( $id ), 'description' => get_post_field( 'post_content', $id ), 'people' => get_post_meta( $id, '_bl_img_people', true ), 'keywords' => get_post_meta( $id, '_bl_img_keywords', true ) );
			if ( isset( $p['alt'] ) ) {
				update_post_meta( $id, '_wp_attachment_image_alt', bl_img_clean_alt( $p['alt'] ) );
			}
			$upd = array( 'ID' => $id );
			if ( isset( $p['title'] ) && '' !== trim( $p['title'] ) ) {
				$upd['post_title'] = sanitize_text_field( $p['title'] );
			}
			if ( isset( $p['description'] ) ) {
				$upd['post_content'] = sanitize_textarea_field( $p['description'] );
			}
			if ( count( $upd ) > 1 ) {
				wp_update_post( $upd );
			}
			if ( isset( $p['people'] ) && in_array( $p['people'], array( 'none', 'released', 'event', 'editorial', 'unknown' ), true ) ) {
				update_post_meta( $id, '_bl_img_people', $p['people'] );
			}
			if ( isset( $p['keywords'] ) ) {
				update_post_meta( $id, '_bl_img_keywords', sanitize_text_field( $p['keywords'] ) );
			}
			$after = array( 'alt' => get_post_meta( $id, '_wp_attachment_image_alt', true ), 'title' => get_the_title( $id ), 'description' => get_post_field( 'post_content', $id ), 'people' => get_post_meta( $id, '_bl_img_people', true ), 'keywords' => get_post_meta( $id, '_bl_img_keywords', true ) );
			if ( $before !== $after ) {
				bl_img_ledger_append( 'metadata_edit', array( 'before' => $before, 'after' => $after ), $id );
			}
			bl_img_purge_page_cache();
			return bl_img_attachment_summary( $id );
		} ),
	) );

	register_rest_route( $ns, '/item/(?P<id>\d+)/focal', array( 'methods' => 'POST', 'permission_callback' => 'bl_img_can_edit_item', 'callback' => function ( $r ) {
		$p = $r->get_json_params();
		return bl_img_set_focal( (int) $r['id'], $p['x'], $p['y'] );
	} ) );
	register_rest_route( $ns, '/item/(?P<id>\d+)/releases', array( 'methods' => 'POST', 'permission_callback' => 'bl_img_can_edit_item', 'callback' => function ( $r ) {
		$p = $r->get_json_params();
		bl_img_link_releases( (int) $r['id'], isset( $p['ids'] ) ? (array) $p['ids'] : array(), true );
		return bl_img_attachment_summary( (int) $r['id'] );
	} ) );
	register_rest_route( $ns, '/item/(?P<id>\d+)/usage', array( 'methods' => 'GET', 'permission_callback' => 'bl_img_can_edit_item', 'callback' => function ( $r ) {
		return bl_img_where_used( (int) $r['id'], ! empty( $r['scan'] ) );
	} ) );
	register_rest_route( $ns, '/item/(?P<id>\d+)/replace', array( 'methods' => 'POST', 'permission_callback' => 'bl_img_can_edit_item', 'callback' => function ( $r ) {
		$p = $r->get_json_params();
		return bl_img_replace_commit( (int) $r['id'], sanitize_key( $p['token'] ), 'new_url' === $p['mode'] ? 'new_url' : 'same_url', isset( $p['reason'] ) ? $p['reason'] : '' );
	} ) );
	register_rest_route( $ns, '/item/(?P<id>\d+)/attest', array( 'methods' => 'POST', 'permission_callback' => 'bl_img_can_edit_item', 'callback' => function ( $r ) {
		$p = $r->get_json_params();
		return bl_img_attest_existing( (int) $r['id'], isset( $p['attest'] ) ? (array) $p['attest'] : array(), ! empty( $p['write_caption'] ) );
	} ) );
	register_rest_route( $ns, '/item/(?P<id>\d+)/ai-alt', array( 'methods' => 'POST', 'permission_callback' => 'bl_img_can_edit_item', 'callback' => function ( $r ) {
		$res = bl_img_ai_fill_alt( (int) $r['id'], true );
		if ( 'error' === $res['status'] ) {
			return new WP_Error( 'ai', $res['why'], array( 'status' => 502 ) );
		}
		bl_img_ledger_append( 'metadata_edit', array( 'ai_alt' => $res['alt'] ), (int) $r['id'] );
		return bl_img_attachment_summary( (int) $r['id'] );
	} ) );

	/* ---------- releases (admin) ---------- */
	$R( '/releases', 'GET', function ( $r ) {
		$q   = get_posts( array( 'post_type' => BL_IMG_RELEASE_CPT, 'post_status' => 'publish', 'posts_per_page' => 500, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC' ) );
		$out = array();
		foreach ( $q as $id ) {
			$rel = bl_img_release_get( $id );
			if ( $r['type'] && $rel['type'] !== $r['type'] ) {
				continue;
			}
			$out[] = bl_img_release_public( $rel );
		}
		return array( 'releases' => $out, 'types' => bl_img_release_types() );
	}, 'bl_img_can_import' ); // importers need the list to link releases

	$R( '/releases', 'POST', function ( $r ) {
		$rel = bl_img_release_create( $r->get_json_params() );
		return is_wp_error( $rel ) ? $rel : bl_img_release_public( $rel );
	}, 'bl_img_can_admin' );

	register_rest_route( $ns, '/releases/(?P<id>\d+)', array(
		array( 'methods' => 'GET', 'permission_callback' => 'bl_img_can_admin', 'callback' => function ( $r ) {
			$rel = bl_img_release_get( (int) $r['id'] );
			if ( ! $rel ) {
				return new WP_Error( 'nf', 'Not found.', array( 'status' => 404 ) );
			}
			$pub           = bl_img_release_public( $rel );
			$pub['ledger'] = array_map( function ( $row ) { $row['data'] = json_decode( $row['data'], true ); return $row; }, bl_img_ledger_for( 0, (int) $r['id'] ) );
			$pub['files']  = array();
			foreach ( bl_img_list_files( bl_img_vault_dir( 'releases/' . (int) $r['id'] ) ) as $f ) {
				$rel_path       = bl_img_vault_rel( $f );
				$pub['files'][] = array( 'name' => ltrim( str_replace( 'releases/' . (int) $r['id'], '', $rel_path ), '/' ), 'url' => wp_nonce_url( admin_url( 'admin-post.php?action=bl_img_vault&f=' . rawurlencode( $rel_path ) ), 'bl_img_vault_' . $rel_path ) );
			}
			$pub['link_full'] = $rel['token'] && 'draft' === $rel['status'] ? bl_img_release_link( $rel['token'] ) : '';
			return $pub;
		} ),
		array( 'methods' => 'POST', 'permission_callback' => 'bl_img_can_admin', 'callback' => function ( $r ) {
			$id  = (int) $r['id'];
			$rel = bl_img_release_get( $id );
			if ( ! $rel ) {
				return new WP_Error( 'nf', 'Not found.', array( 'status' => 404 ) );
			}
			$p = $r->get_json_params();
			if ( isset( $p['attachments'] ) ) {
				$want = array_map( 'intval', (array) $p['attachments'] );
				foreach ( array_diff( $rel['attachments'], $want ) as $aid ) {
					$cur = array_diff( bl_img_get_releases( $aid ), array( $id ) );
					bl_img_link_releases( $aid, $cur, true );
				}
				foreach ( array_diff( $want, $rel['attachments'] ) as $aid ) {
					bl_img_link_releases( $aid, array( $id ), false );
				}
			}
			// Text fields are editable only before signing (a signed record is frozen).
			if ( in_array( $rel['status'], array( 'draft', 'expired', 'active' ), true ) ) {
				$upd = array();
				foreach ( array( 'signer_name', 'signer_email', 'signer_phone', 'minor_name', 'credit_as', 'event_name', 'event_date', 'event_venue', 'notes' ) as $k ) {
					if ( isset( $p[ $k ] ) ) {
						$upd[ $k ] = sanitize_text_field( $p[ $k ] );
					}
				}
				foreach ( array( 'scope', 'limitations', 'opt_outs' ) as $k ) {
					if ( isset( $p[ $k ] ) ) {
						$upd[ $k ] = sanitize_textarea_field( $p[ $k ] );
					}
				}
				if ( isset( $p['is_minor'] ) ) {
					$upd['is_minor'] = $p['is_minor'] ? 1 : 0;
				}
				if ( isset( $p['notice_methods'] ) ) {
					$upd['notice_methods'] = array_map( 'sanitize_text_field', (array) $p['notice_methods'] );
				}
				if ( ! empty( $p['renew'] ) && 'esign' === $rel['method'] ) {
					$upd['token']         = strtolower( wp_generate_password( 40, false, false ) );
					$upd['token_expires'] = gmdate( 'c', time() + DAY_IN_SECONDS * max( 1, (int) bl_img_setting( 'release_expiry' ) ) );
					$upd['status']        = 'draft';
				}
				bl_img_release_set( $id, $upd );
				if ( $upd ) {
					bl_img_ledger_append( 'release_edit', array( 'fields' => array_keys( $upd ) ), 0, $id );
				}
			} elseif ( isset( $p['notes'] ) ) {
				bl_img_release_set( $id, array( 'notes' => sanitize_textarea_field( $p['notes'] ) ) );
			}
			return bl_img_release_public( bl_img_release_get( $id ) );
		} ),
	) );
	register_rest_route( $ns, '/releases/(?P<id>\d+)/send', array( 'methods' => 'POST', 'permission_callback' => 'bl_img_can_admin', 'callback' => function ( $r ) {
		$ok = bl_img_release_send_link( (int) $r['id'] );
		return is_wp_error( $ok ) ? $ok : array( 'ok' => true );
	} ) );
	register_rest_route( $ns, '/releases/(?P<id>\d+)/revoke', array( 'methods' => 'POST', 'permission_callback' => 'bl_img_can_admin', 'callback' => function ( $r ) {
		$p   = $r->get_json_params();
		$rel = bl_img_release_revoke( (int) $r['id'], isset( $p['reason'] ) ? $p['reason'] : '' );
		return is_wp_error( $rel ) ? $rel : bl_img_release_public( $rel );
	} ) );
	register_rest_route( $ns, '/releases/(?P<id>\d+)/scan', array( 'methods' => 'POST', 'permission_callback' => 'bl_img_can_admin', 'callback' => function ( $r ) {
		$f = $r->get_file_params();
		if ( empty( $f['file'] ) || ! empty( $f['file']['error'] ) ) {
			return new WP_Error( 'nofile', 'No file received.', array( 'status' => 400 ) );
		}
		$rel = bl_img_release_attach_scan( (int) $r['id'], $f['file'], array( 'kind' => $r['kind'], 'signed_date' => $r['signed_date'] ) );
		return is_wp_error( $rel ) ? $rel : bl_img_release_public( $rel );
	} ) );
	register_rest_route( $ns, '/releases/(?P<id>\d+)/import', array( 'methods' => 'POST', 'permission_callback' => 'bl_img_can_admin', 'callback' => function ( $r ) {
		$p = $r->get_json_params();
		return bl_img_release_import_upload( (int) $r['id'], preg_replace( '/[^a-f0-9]/', '', (string) $p['sha'] ) );
	} ) );
	$R( '/templates', 'GET', function () {
		return bl_img_templates();
	}, 'bl_img_can_admin' );
	$R( '/templates', 'POST', function ( $r ) {
		$p = $r->get_json_params();
		return bl_img_save_template( sanitize_key( $p['type'] ), (string) $p['text'] );
	}, 'bl_img_can_admin' );

	/* ---------- tools (admin) ---------- */
	$R( '/tools/index', 'POST', function ( $r ) {
		$p = $r->get_json_params();
		return bl_img_index_batch( (int) $p['offset'] );
	}, 'bl_img_can_admin' );
	$R( '/tools/hash', 'POST', function () {
		return bl_img_hash_batch();
	}, 'bl_img_can_admin' );
	$R( '/tools/duplicates', 'GET', function ( $r ) {
		return array( 'groups' => bl_img_find_duplicates( $r['threshold'] ? (int) $r['threshold'] : 5 ) );
	}, 'bl_img_can_admin' );
	$R( '/tools/consolidate', 'POST', function ( $r ) {
		$p = $r->get_json_params();
		return bl_img_consolidate( (int) $p['keeper'], (array) $p['dups'] );
	}, 'bl_img_can_admin' );
	$R( '/tools/consolidate-group', 'POST', function ( $r ) {
		$p = $r->get_json_params();
		return bl_img_consolidate_group( (int) $p['keeper'], (array) $p['dups'] );
	}, 'bl_img_can_admin' );
	$R( '/tools/remove-duplicates', 'POST', function ( $r ) {
		$p = $r->get_json_params();
		return bl_img_remove_duplicates( (array) $p['dups'] );
	}, 'bl_img_can_admin' );
	$R( '/tools/undo', 'GET', function () {
		$out = array();
		foreach ( (array) glob( bl_img_vault_dir( 'undo' ) . '/*.json' ) as $f ) {
			$d     = json_decode( file_get_contents( $f ), true );
			$out[] = array( 'id' => basename( $f, '.json' ), 'reason' => $d['reason'], 'at' => $d['at'], 'rows' => count( $d['rows'] ), 'by' => isset( $d['by']['user_name'] ) ? $d['by']['user_name'] : '' );
		}
		return array_reverse( $out );
	}, 'bl_img_can_admin' );
	$R( '/tools/undo', 'POST', function ( $r ) {
		$p = $r->get_json_params();
		return bl_img_undo_rewrite( (string) $p['id'] );
	}, 'bl_img_can_admin' );
	$R( '/tools/convert', 'GET', function () {
		return array( 'remaining' => bl_img_convert_candidates_count(), 'webp' => wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) );
	}, 'bl_img_can_admin' );
	$R( '/tools/convert', 'POST', function () {
		return bl_img_convert_batch();
	}, 'bl_img_can_admin' );
	$R( '/tools/adopt', 'POST', function () {
		return bl_img_adopt_batch();
	}, 'bl_img_can_admin' );
	$R( '/tools/ai-alt', 'POST', function () {
		return bl_img_ai_alt_batch();
	}, 'bl_img_can_admin' );
	$R( '/tools/ledger', 'GET', function ( $r ) {
		global $wpdb;
		$page = max( 1, (int) $r['page'] );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_utc, event, attachment_id, release_id, user_name, user_login, ip, entry_hash FROM ' . bl_img_ledger_table() . ' ORDER BY id DESC LIMIT 50 OFFSET %d', ( $page - 1 ) * 50 ), ARRAY_A );
		return array( 'rows' => $rows, 'total' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . bl_img_ledger_table() ), 'ots_last' => get_option( 'bl_img_ots_last_head' ) );
	}, 'bl_img_can_admin' );
	$R( '/tools/ledger-verify', 'GET', function () {
		return bl_img_ledger_verify();
	}, 'bl_img_can_admin' );
	$R( '/tools/ots', 'POST', function () {
		return bl_img_ots_anchor( true );
	}, 'bl_img_can_admin' );

	/* ---------- settings (admin) ---------- */
	$R( '/settings', 'GET', function () {
		$s = bl_img_settings();
		$s['worker_token'] = $s['worker_token'] ? '••••••' : '';
		$w = bl_img_worker();
		$s['_worker_effective'] = $w['url'];
		$s['_webp']             = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		$s['_editor']           = _wp_image_editor_choose( array( 'mime_type' => 'image/webp' ) );
		$s['_max_upload']       = size_format( wp_max_upload_size() );
		return $s;
	}, 'bl_img_can_admin' );
	$R( '/settings', 'POST', function ( $r ) {
		$before = bl_img_settings();
		$after  = bl_img_save_settings( (array) $r->get_json_params() );
		$diff   = array();
		foreach ( $after as $k => $v ) {
			if ( 'worker_token' !== $k && ( ! isset( $before[ $k ] ) || $before[ $k ] !== $v ) ) {
				$diff[ $k ] = array( isset( $before[ $k ] ) ? $before[ $k ] : null, $v );
			}
		}
		if ( $diff ) {
			bl_img_ledger_append( 'settings_change', $diff );
		}
		return array( 'ok' => true );
	}, 'bl_img_can_admin' );

	/* ---------- public: release signing (token-authorized) ---------- */
	register_rest_route( $ns, '/public/release/(?P<token>[a-z0-9]{30,64})/upload', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'bl_img_public_release_upload' ) );
	register_rest_route( $ns, '/public/release/(?P<token>[a-z0-9]{30,64})/preview', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'bl_img_public_release_preview' ) );
	register_rest_route( $ns, '/public/release/(?P<token>[a-z0-9]{30,64})/sign', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'bl_img_public_release_sign' ) );
} );

/* ------------------------------------------------------------------ *
 *  Downloads (admin-post.php)
 * ------------------------------------------------------------------ */

add_action( 'admin_post_bl_img_evidence', function () {
	$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
	check_admin_referer( 'bl_img_evidence_' . $id );
	if ( ! current_user_can( 'edit_post', $id ) ) {
		wp_die( 'Not allowed.' );
	}
	$zip = bl_img_evidence_zip( $id );
	if ( is_wp_error( $zip ) ) {
		wp_die( esc_html( $zip->get_error_message() ) );
	}
	nocache_headers();
	header( 'Content-Type: application/zip' );
	header( 'Content-Disposition: attachment; filename="evidence-image-' . $id . '-' . gmdate( 'Ymd' ) . '.zip"' );
	header( 'Content-Length: ' . filesize( $zip ) );
	readfile( $zip );
	@unlink( $zip );
	exit;
} );

add_action( 'admin_post_bl_img_master', function () {
	$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
	check_admin_referer( 'bl_img_master_' . $id );
	if ( ! current_user_can( 'edit_post', $id ) ) {
		wp_die( 'Not allowed.' );
	}
	$f = bl_img_vault_abs( get_post_meta( $id, '_bl_img_master', true ) );
	if ( ! file_exists( $f ) ) {
		wp_die( 'Original not found.' );
	}
	nocache_headers();
	$slug = sanitize_title( get_the_title( $id ) );
	header( 'Content-Type: ' . ( wp_check_filetype( $f )['type'] ? wp_check_filetype( $f )['type'] : 'application/octet-stream' ) );
	header( 'Content-Disposition: attachment; filename="' . ( $slug ? $slug : 'image-' . $id ) . '-original.' . pathinfo( $f, PATHINFO_EXTENSION ) . '"' );
	header( 'Content-Length: ' . filesize( $f ) );
	readfile( $f );
	exit;
} );

add_action( 'admin_post_bl_img_vault', function () {
	$rel = isset( $_GET['f'] ) ? sanitize_text_field( wp_unslash( $_GET['f'] ) ) : '';
	check_admin_referer( 'bl_img_vault_' . $rel );
	if ( ! current_user_can( BL_IMG_CAP_ADMIN ) ) {
		wp_die( 'Not allowed.' );
	}
	$f = bl_img_vault_abs( $rel );
	if ( ! file_exists( $f ) || false !== strpos( $rel, '..' ) ) {
		wp_die( 'File not found.' );
	}
	nocache_headers();
	$type = wp_check_filetype( $f )['type'];
	$inline = in_array( $type, array( 'text/html', 'image/png', 'image/jpeg', 'application/pdf' ), true );
	header( 'Content-Type: ' . ( $type ? $type : 'application/octet-stream' ) );
	header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . basename( $f ) . '"' );
	header( 'X-Content-Type-Options: nosniff' );
	if ( 'text/html' === $type ) {
		header( "Content-Security-Policy: sandbox; default-src 'none'; img-src data: https:; style-src 'unsafe-inline'" );
	}
	readfile( $f );
	exit;
} );

add_action( 'admin_post_bl_img_audit_csv', function () {
	check_admin_referer( 'bl_img_audit_csv' );
	if ( ! current_user_can( BL_IMG_CAP_ADMIN ) ) {
		wp_die( 'Not allowed.' );
	}
	bl_img_audit_csv();
	exit;
} );
