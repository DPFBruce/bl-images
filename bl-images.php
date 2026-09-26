<?php
/**
 * Plugin Name:       (BL) Images
 * Plugin URI:        https://deathpenalty.org/
 * Description:       Find and import legally safe images (public domain, CC0 and CC BY only) from Openverse and Wikimedia Commons, or upload your own. Every image is converted to optimized WebP, gets professional Alt/Title/Caption/credit metadata (Claude writes the alt text), and carries a tamper-evident provenance record: license, source snapshot, SHA-256 hashes, Wayback Machine capture and a hash-chained ledger. Also: signed model/contributor releases, replace-in-place, where-used, duplicate consolidation, focal points, a credits page, WebP conversion and a license audit.
 * Version:           1.0.0
 * Author:            Bruce Lisker
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Text Domain:       bl-images
 *
 * ── HOW IT FITS TOGETHER ──────────────────────────────────────────────────────
 *   • Every image goes through ONE pipeline: stage (download or upload → vault
 *     master + SHA-256 → WebP) → describe (Claude alt text/title) → commit
 *     (Media Library attachment + metadata + embedded XMP rights + ledger entry).
 *   • Provenance lives in three places at once: attachment postmeta (fast), the
 *     private vault folder (original bytes, source-API snapshot, landing-page
 *     snapshot, certificates), and the hash-chained ledger table (tamper-evident,
 *     anchored daily to Bitcoin via OpenTimestamps).
 *   • Other plugins: a "(BL) Images" tab appears inside every wp.media picker,
 *     plus BLImages.open() in JS and bl_images_import_remote() in PHP.
 * ──────────────────────────────────────────────────────────────────────────────
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BL_IMG_VERSION', '1.0.0' );
define( 'BL_IMG_DIR', plugin_dir_path( __FILE__ ) );
define( 'BL_IMG_URL', plugin_dir_url( __FILE__ ) );
define( 'BL_IMG_FILE', __FILE__ );
define( 'BL_IMG_DB_VERSION', '1' );

require_once BL_IMG_DIR . 'includes/core.php';
require_once BL_IMG_DIR . 'includes/licenses.php';
require_once BL_IMG_DIR . 'includes/sources.php';
require_once BL_IMG_DIR . 'includes/xmp.php';
require_once BL_IMG_DIR . 'includes/ledger.php';
require_once BL_IMG_DIR . 'includes/ai.php';
require_once BL_IMG_DIR . 'includes/pipeline.php';
require_once BL_IMG_DIR . 'includes/usage.php';
require_once BL_IMG_DIR . 'includes/replace.php';
require_once BL_IMG_DIR . 'includes/duplicates.php';
require_once BL_IMG_DIR . 'includes/convert.php';
require_once BL_IMG_DIR . 'includes/releases.php';
require_once BL_IMG_DIR . 'includes/focal.php';
require_once BL_IMG_DIR . 'includes/credits.php';
require_once BL_IMG_DIR . 'includes/audit.php';
require_once BL_IMG_DIR . 'includes/library-search.php';
require_once BL_IMG_DIR . 'includes/rest.php';
require_once BL_IMG_DIR . 'includes/admin.php';

/* ------------------------------------------------------------------ *
 *  Auto-updates from GitHub  (public repo: DPFBruce/bl-images)
 * ------------------------------------------------------------------ *
 * Each Release must have the built plugin .zip attached as an asset (top
 * folder "(BL) Images/"). The launcher pushes source BEFORE tagging so the
 * Version header at the tag matches (see the "up to date" trap).
 */
if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
	$bl_img_puc_loader = BL_IMG_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
	if ( is_readable( $bl_img_puc_loader ) ) {
		require_once $bl_img_puc_loader;
		if ( class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
			$bl_img_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
				'https://github.com/DPFBruce/bl-images/',
				BL_IMG_FILE,
				'bl-images'
			);
			$bl_img_update_checker->getVcsApi()->enableReleaseAssets();
		}
	}
}

register_activation_hook( __FILE__, 'bl_img_activate' );
register_deactivation_hook( __FILE__, 'bl_img_deactivate' );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=bl-images' ) ) . '">Find images</a>';
	return $links;
} );
