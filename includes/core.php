<?php
/**
 * Core: settings, capabilities, the private vault, DB tables, shared helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BL_IMG_OPT_SETTINGS', 'bl_img_settings' );
define( 'BL_IMG_CAP_IMPORT', 'upload_files' );     // search / import / upload / edit own metadata
define( 'BL_IMG_CAP_ADMIN', 'manage_options' );    // releases, tools, settings, consolidation

/* ------------------------------------------------------------------ *
 *  Settings
 * ------------------------------------------------------------------ */

function bl_img_default_settings() {
	return array(
		'org_name'          => 'Death Penalty Focus',
		'org_short'         => 'DPF',
		'contact_email'     => get_option( 'admin_email' ),
		'src_openverse'     => 1,
		'src_wikimedia'     => 1,
		'allow_cc_by'       => 1,
		'ov_include_flickr' => 0,   // uploader-asserted licenses; highest laundering risk
		'max_edge'          => 2560,
		'q_photo'           => 82,
		'q_graphic'         => 90,
		'keep_master'       => 1,
		'master_max_mb'     => 60,
		'ai_alt'            => 1,
		'worker_url'        => '',  // blank = borrow (BL) Slide Editor's worker settings
		'worker_token'      => '',
		'wayback'           => 1,
		'ots'               => 1,
		'credit_tooltip'    => 1,
		'review_first'      => 0,
		'release_expiry'    => 30,  // days a signing link stays valid
	);
}

function bl_img_settings() {
	$s = get_option( BL_IMG_OPT_SETTINGS, array() );
	return wp_parse_args( is_array( $s ) ? $s : array(), bl_img_default_settings() );
}

function bl_img_setting( $key ) {
	$s = bl_img_settings();
	return isset( $s[ $key ] ) ? $s[ $key ] : null;
}

function bl_img_save_settings( $in ) {
	$def = bl_img_default_settings();
	$cur = bl_img_settings();
	$out = $cur;
	foreach ( $def as $k => $d ) {
		if ( ! array_key_exists( $k, $in ) ) {
			continue;
		}
		$v = $in[ $k ];
		if ( is_int( $d ) ) {
			$out[ $k ] = (int) $v;
		} elseif ( 'contact_email' === $k ) {
			$out[ $k ] = sanitize_email( $v );
		} elseif ( 'worker_url' === $k ) {
			$out[ $k ] = esc_url_raw( trim( $v ) );
		} else {
			$out[ $k ] = sanitize_text_field( $v );
		}
	}
	// Keep a secret token when the form sends the masked placeholder back.
	if ( isset( $in['worker_token'] ) && '••••••' === $in['worker_token'] ) {
		$out['worker_token'] = $cur['worker_token'];
	}
	$out['max_edge']  = min( 4096, max( 1200, (int) $out['max_edge'] ) );
	$out['q_photo']   = min( 95, max( 60, (int) $out['q_photo'] ) );
	$out['q_graphic'] = min( 100, max( 70, (int) $out['q_graphic'] ) );
	update_option( BL_IMG_OPT_SETTINGS, $out, false );
	return $out;
}

/** Claude Worker URL + token: our own setting, else (BL) Slide Editor's. */
function bl_img_worker() {
	$s     = bl_img_settings();
	$url   = $s['worker_url'];
	$token = $s['worker_token'];
	if ( ! $url ) {
		$se = get_option( 'dpf_slider_settings', array() );
		if ( is_array( $se ) && ! empty( $se['workerUrl'] ) ) {
			$url   = $se['workerUrl'];
			$token = isset( $se['workerToken'] ) ? $se['workerToken'] : '';
		}
	}
	return array( 'url' => $url, 'token' => $token );
}

/* ------------------------------------------------------------------ *
 *  Activation: tables + vault
 * ------------------------------------------------------------------ */

function bl_img_activate() {
	bl_img_install_tables();
	bl_img_vault_dir();
	if ( false === get_option( BL_IMG_OPT_SETTINGS, false ) ) {
		add_option( BL_IMG_OPT_SETTINGS, bl_img_default_settings(), '', false );
	}
	if ( ! wp_next_scheduled( 'bl_img_daily' ) ) {
		wp_schedule_event( time() + 600, 'daily', 'bl_img_daily' );
	}
}

function bl_img_deactivate() {
	wp_clear_scheduled_hook( 'bl_img_daily' );
}

add_action( 'plugins_loaded', function () {
	if ( get_option( 'bl_img_db_version' ) !== BL_IMG_DB_VERSION ) {
		bl_img_install_tables();
	}
} );

function bl_img_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();

	dbDelta( "CREATE TABLE {$wpdb->prefix}bl_img_ledger (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		created_utc varchar(32) NOT NULL,
		event varchar(40) NOT NULL,
		attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
		release_id bigint(20) unsigned NOT NULL DEFAULT 0,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		user_login varchar(60) NOT NULL DEFAULT '',
		user_name varchar(250) NOT NULL DEFAULT '',
		ip varchar(64) NOT NULL DEFAULT '',
		data longtext NOT NULL,
		data_sha256 char(64) NOT NULL,
		prev_hash char(64) NOT NULL,
		entry_hash char(64) NOT NULL,
		PRIMARY KEY  (id),
		KEY attachment_id (attachment_id),
		KEY release_id (release_id),
		KEY event (event)
	) $c;" );

	dbDelta( "CREATE TABLE {$wpdb->prefix}bl_img_usage (
		attachment_id bigint(20) unsigned NOT NULL,
		object_type varchar(20) NOT NULL,
		object_id bigint(20) unsigned NOT NULL DEFAULT 0,
		object_key varchar(191) NOT NULL DEFAULT '',
		how varchar(20) NOT NULL DEFAULT '',
		PRIMARY KEY  (attachment_id,object_type,object_id,object_key),
		KEY obj (object_type,object_id)
	) $c;" );

	update_option( 'bl_img_db_version', BL_IMG_DB_VERSION );
}

/* ------------------------------------------------------------------ *
 *  The vault — private, web-denied storage for evidence
 * ------------------------------------------------------------------ *
 * uploads/bl-images-vault-<random>/  with deny-all .htaccess + web.config +
 * index.php. The random suffix is defense-in-depth for servers that ignore
 * .htaccess. Files here are served ONLY through capability-checked endpoints.
 */
function bl_img_vault_dir( $sub = '' ) {
	$salt = get_option( 'bl_img_vault_salt' );
	if ( ! $salt ) {
		$salt = strtolower( wp_generate_password( 20, false, false ) );
		update_option( 'bl_img_vault_salt', $salt, false );
	}
	$up   = wp_upload_dir( null, false );
	$root = trailingslashit( $up['basedir'] ) . 'bl-images-vault-' . $salt;
	if ( ! is_dir( $root ) ) {
		wp_mkdir_p( $root );
	}
	if ( ! file_exists( $root . '/.htaccess' ) ) {
		@file_put_contents( $root . '/.htaccess', "# (BL) Images evidence vault — never web-served\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\nOptions -Indexes\n" );
		@file_put_contents( $root . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>' );
		@file_put_contents( $root . '/index.php', "<?php\n// Silence.\n" );
	}
	$dir = $sub ? $root . '/' . trim( $sub, '/' ) : $root;
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		@file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" );
	}
	return $dir;
}

/** Vault path relative to the vault root (what we store in meta). */
function bl_img_vault_rel( $abs ) {
	$root = bl_img_vault_dir();
	return ltrim( str_replace( $root, '', $abs ), '/' );
}

function bl_img_vault_abs( $rel ) {
	$rel = str_replace( array( '..', "\0" ), '', (string) $rel );
	return bl_img_vault_dir() . '/' . ltrim( $rel, '/' );
}

/** Write a JSON evidence file (pretty, stable key order) and return [rel, sha256]. */
function bl_img_vault_put_json( $sub, $name, $data ) {
	$dir  = bl_img_vault_dir( $sub );
	$path = $dir . '/' . sanitize_file_name( $name );
	$json = wp_json_encode( bl_img_ksort_deep( $data ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	file_put_contents( $path, $json );
	return array( bl_img_vault_rel( $path ), hash( 'sha256', $json ) );
}

function bl_img_vault_put( $sub, $name, $bytes ) {
	$dir  = bl_img_vault_dir( $sub );
	$path = $dir . '/' . sanitize_file_name( $name );
	file_put_contents( $path, $bytes );
	return array( bl_img_vault_rel( $path ), hash( 'sha256', $bytes ) );
}

/** Every real file under a directory (recursive, skipping our index.php guards). */
function bl_img_list_files( $dir ) {
	$out = array();
	if ( ! is_dir( $dir ) ) {
		return $out;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( $f->isFile() && 'index.php' !== $f->getFilename() ) {
			$out[] = $f->getPathname();
		}
	}
	sort( $out );
	return $out;
}

/** Remove a directory tree (staging cleanup only — never used on evidence). */
function bl_img_rmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
	}
	@rmdir( $dir );
}

/* ------------------------------------------------------------------ *
 *  Helpers
 * ------------------------------------------------------------------ */

function bl_img_ksort_deep( $a ) {
	if ( ! is_array( $a ) ) {
		return $a;
	}
	$is_list = array_keys( $a ) === range( 0, count( $a ) - 1 );
	if ( ! $is_list ) {
		ksort( $a, SORT_STRING );
	}
	foreach ( $a as $k => $v ) {
		$a[ $k ] = bl_img_ksort_deep( $v );
	}
	return $a;
}

/** Canonical JSON: sorted keys, unescaped slashes/unicode — stable for hashing. */
function bl_img_canonical_json( $data ) {
	return wp_json_encode( bl_img_ksort_deep( $data ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

function bl_img_now_utc() {
	$t = microtime( true );
	return gmdate( 'Y-m-d\TH:i:s', (int) $t ) . sprintf( '.%06dZ', ( $t - floor( $t ) ) * 1000000 );
}

function bl_img_client_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

function bl_img_user_agent_header() {
	$s = bl_img_settings();
	return 'BL-Images/' . BL_IMG_VERSION . ' (' . home_url( '/' ) . '; ' . $s['contact_email'] . ') WordPress';
}

function bl_img_http_get( $url, $args = array() ) {
	return wp_remote_get( $url, wp_parse_args( $args, array(
		'timeout'    => 25,
		'user-agent' => bl_img_user_agent_header(),
		'headers'    => array( 'Accept' => 'application/json' ),
	) ) );
}

function bl_img_current_actor() {
	$u = wp_get_current_user();
	return array(
		'user_id'    => (int) $u->ID,
		'user_login' => $u->ID ? $u->user_login : '',
		'user_name'  => $u->ID ? trim( $u->display_name ) : '',
		'user_email' => $u->ID ? $u->user_email : '',
		'ip'         => bl_img_client_ip(),
		'ua'         => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 300 ) : '',
	);
}

/** Purge page caches after anything that changes what the front end shows. */
function bl_img_purge_page_cache() {
	if ( function_exists( 'wpo_cache_flush' ) ) {
		wpo_cache_flush();
	} elseif ( function_exists( 'WP_Optimize' ) ) {
		$wpo = WP_Optimize();
		if ( is_object( $wpo ) && method_exists( $wpo, 'get_page_cache' ) ) {
			$pc = $wpo->get_page_cache();
			if ( is_object( $pc ) && method_exists( $pc, 'purge' ) ) {
				$pc->purge();
			}
		}
	}
	if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); }
	if ( function_exists( 'w3tc_flush_all' ) ) { w3tc_flush_all(); }
	if ( function_exists( 'wp_cache_clear_cache' ) ) { wp_cache_clear_cache(); }
	do_action( 'litespeed_purge_all' );
	// Elementor bakes image URLs into its generated CSS files.
	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
}

/** Give long-running image work as much room as the host allows. */
function bl_img_raise_limits() {
	// REST / cron requests don't load these admin helpers (wp_tempnam, sideload, sub-sizes).
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	if ( function_exists( 'wp_raise_memory_limit' ) ) {
		wp_raise_memory_limit( 'image' );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 );
	}
}

/** Release IDs linked to an image (clean ints; never [0] from an empty meta). */
function bl_img_get_releases( $id ) {
	return array_values( array_filter( array_map( 'intval', (array) get_post_meta( $id, '_bl_img_releases', true ) ) ) );
}

function bl_img_is_image_attachment( $id ) {
	$p = get_post( $id );
	return $p && 'attachment' === $p->post_type && 0 === strpos( (string) $p->post_mime_type, 'image/' );
}

/** Upload-relative "stem" of an attachment: 2024/05/photo (no extension, no -scaled). */
function bl_img_attachment_stem( $id ) {
	$f = get_post_meta( $id, '_wp_attached_file', true );
	if ( ! $f ) {
		return '';
	}
	$f = preg_replace( '/\.[a-z0-9]+$/i', '', $f );
	return preg_replace( '/-(scaled|rotated)$/', '', $f );
}

/** All URLs (full + every generated size) for an attachment, keyed by size name. */
function bl_img_attachment_urls( $id ) {
	$out  = array();
	$full = wp_get_attachment_url( $id );
	if ( ! $full ) {
		return $out;
	}
	$out['full'] = $full;
	$meta        = wp_get_attachment_metadata( $id );
	$base        = trailingslashit( dirname( $full ) );
	if ( ! empty( $meta['sizes'] ) ) {
		foreach ( $meta['sizes'] as $name => $s ) {
			if ( ! empty( $s['file'] ) ) {
				$out[ $name ] = $base . $s['file'];
			}
		}
	}
	if ( ! empty( $meta['original_image'] ) ) {
		$out['original_image'] = $base . $meta['original_image'];
	}
	return $out;
}
