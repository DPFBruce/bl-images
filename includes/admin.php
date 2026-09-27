<?php
/**
 * Admin: the (BL) Images menu (one app, six views), the "(BL) Images" tab in
 * every wp.media picker, and provenance surfaces on WordPress's own media
 * screens (column, row actions, attachment-details panel, edit-screen box).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function bl_img_views() {
	return array(
		'bl-images'          => array( 'find', 'Find & Import', BL_IMG_CAP_IMPORT ),
		'bl-images-library'  => array( 'library', 'Library', BL_IMG_CAP_IMPORT ),
		'bl-images-releases' => array( 'releases', 'Releases', BL_IMG_CAP_ADMIN ),
		'bl-images-audit'    => array( 'audit', 'License Audit', BL_IMG_CAP_ADMIN ),
		'bl-images-tools'    => array( 'tools', 'Tools', BL_IMG_CAP_ADMIN ),
		'bl-images-settings' => array( 'settings', 'Settings', BL_IMG_CAP_ADMIN ),
	);
}

add_action( 'admin_menu', function () {
	add_menu_page( '(BL) Images', '(BL) Images', BL_IMG_CAP_IMPORT, 'bl-images', 'bl_img_render_app', 'dashicons-format-gallery', 11 );
	foreach ( bl_img_views() as $slug => $v ) {
		add_submenu_page( 'bl-images', '(BL) Images — ' . $v[1], $v[1], $v[2], $slug, 'bl_img_render_app' );
	}
} );

function bl_img_render_app() {
	$page  = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : 'bl-images';
	$views = bl_img_views();
	$view  = isset( $views[ $page ] ) ? $views[ $page ][0] : 'find';
	echo '<div class="wrap bl-img-wrap"><div class="bl-img-chrome"><span class="bl-img-pill">(BL) Images <b>v' . esc_html( BL_IMG_VERSION ) . '</b></span>';
	echo '<nav class="bl-img-tabs">';
	foreach ( $views as $slug => $v ) {
		if ( current_user_can( $v[2] ) ) {
			echo '<a class="' . ( $slug === $page ? 'is-on' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $v[1] ) . '</a>';
		}
	}
	echo '</nav><button type="button" class="button bl-img-help-btn">Help &amp; Guide</button></div>';
	echo '<div id="bl-img-app" data-view="' . esc_attr( $view ) . '"><p class="bl-img-loading">Loading…</p></div>';
	echo '<div id="bl-img-help" class="bl-img-modal" hidden><div class="bl-img-modal__in bl-img-modal__in--doc"><button type="button" class="bl-img-modal__x" aria-label="Close">&times;</button><iframe src="' . esc_url( add_query_arg( 'v', BL_IMG_VERSION, BL_IMG_URL . 'assets/manual.html' ) ) . '" title="Guide"></iframe></div></div>';
	echo '</div>';
}

/** Data the JS app needs everywhere (admin pages AND media modals). */
function bl_img_js_config() {
	$s = bl_img_settings();
	return array(
		'root'      => esc_url_raw( rest_url( 'bl-images/v1/' ) ),
		'nonce'     => wp_create_nonce( 'wp_rest' ),
		'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
		'version'   => BL_IMG_VERSION,
		'isAdmin'   => current_user_can( BL_IMG_CAP_ADMIN ),
		'user'      => wp_get_current_user()->display_name,
		'org'       => $s['org_name'],
		'orgShort'  => $s['org_short'],
		'ccBy'      => (bool) $s['allow_cc_by'],
		'reviewFirst' => (bool) $s['review_first'],
		'maxUpload' => wp_max_upload_size(),
		'attestText'=> bl_img_attestation_text( wp_get_current_user()->display_name ),
		'csv'       => wp_nonce_url( admin_url( 'admin-post.php?action=bl_img_audit_csv' ), 'bl_img_audit_csv' ),
		'adminUrl'  => admin_url(),
		'types'     => bl_img_release_types(),
		'itemParam' => isset( $_GET['item'] ) ? (int) $_GET['item'] : 0,
	);
}

function bl_img_enqueue_app() {
	wp_enqueue_style( 'bl-img-admin', BL_IMG_URL . 'assets/admin.css', array(), BL_IMG_VERSION );
	wp_enqueue_script( 'bl-img-app', BL_IMG_URL . 'assets/app.js', array(), BL_IMG_VERSION, true );
	wp_localize_script( 'bl-img-app', 'BLIMG', bl_img_js_config() );
}

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
	if ( isset( bl_img_views()[ $page ] ) ) {
		wp_enqueue_media();
		bl_img_enqueue_app();
	}
	if ( in_array( $hook, array( 'upload.php', 'post.php' ), true ) ) {
		wp_enqueue_style( 'bl-img-admin', BL_IMG_URL . 'assets/admin.css', array(), BL_IMG_VERSION );
	}
} );

/* Every wp.media frame (Gutenberg, Elementor, Slide Editor, Awards Dinners…) gets the tab. */
add_action( 'wp_enqueue_media', function () {
	if ( ! current_user_can( BL_IMG_CAP_IMPORT ) ) {
		return;
	}
	bl_img_enqueue_app();
	wp_enqueue_script( 'bl-img-media', BL_IMG_URL . 'assets/media-modal.js', array( 'media-views', 'bl-img-app' ), BL_IMG_VERSION, true );
} );

/* ------------------------------------------------------------------ *
 *  WordPress media screens
 * ------------------------------------------------------------------ */

function bl_img_item_url( $id ) {
	return admin_url( 'admin.php?page=bl-images-library&item=' . (int) $id );
}

add_filter( 'manage_media_columns', function ( $cols ) {
	$cols['bl_img_rights'] = 'Rights';
	return $cols;
} );
add_action( 'manage_media_custom_column', function ( $col, $id ) {
	if ( 'bl_img_rights' !== $col || ! bl_img_is_image_attachment( $id ) ) {
		return;
	}
	$tier = get_post_meta( $id, '_bl_img_tier', true );
	$ppl  = get_post_meta( $id, '_bl_img_people', true );
	echo '<a class="bl-img-badge bl-img-badge--' . esc_attr( $tier ? $tier : 'none' ) . '" href="' . esc_url( bl_img_item_url( $id ) ) . '">' . esc_html( bl_img_tier_label( $tier ) ) . '</a>';
	if ( 'unknown' === $ppl ) {
		echo '<br><span class="bl-img-flag">People: needs release</span>';
	}
}, 10, 2 );

add_filter( 'media_row_actions', function ( $actions, $post ) {
	if ( bl_img_is_image_attachment( $post->ID ) && current_user_can( 'edit_post', $post->ID ) ) {
		$actions['bl_img'] = '<a href="' . esc_url( bl_img_item_url( $post->ID ) ) . '">Provenance · Replace · Where used</a>';
	}
	return $actions;
}, 10, 2 );

/* Compact panel inside the media modal's Attachment Details. */
add_filter( 'attachment_fields_to_edit', function ( $fields, $post ) {
	if ( ! bl_img_is_image_attachment( $post->ID ) ) {
		return $fields;
	}
	$tier = get_post_meta( $post->ID, '_bl_img_tier', true );
	$html = '<div class="bl-img-af"><span class="bl-img-badge bl-img-badge--' . esc_attr( $tier ? $tier : 'none' ) . '">' . esc_html( bl_img_tier_label( $tier ) ) . '</span>';
	if ( $tier ) {
		$html .= '<div class="bl-img-af__credit">' . esc_html( get_post_meta( $post->ID, '_bl_img_credit_line', true ) ) . '</div>';
		$html .= '<div class="bl-img-af__people">' . esc_html( bl_img_people_label( get_post_meta( $post->ID, '_bl_img_people', true ) ) ) . '</div>';
	}
	$html .= '<a href="' . esc_url( bl_img_item_url( $post->ID ) ) . '" target="_blank" rel="noopener">Provenance, focal point, replace, where used ↗</a></div>';
	$fields['bl_img'] = array( 'label' => '(BL) Images', 'input' => 'html', 'html' => $html );
	return $fields;
}, 10, 2 );

/* Box on the full attachment edit screen. */
add_action( 'add_meta_boxes_attachment', function ( $post ) {
	if ( ! bl_img_is_image_attachment( $post->ID ) ) {
		return;
	}
	add_meta_box( 'bl-img-box', '(BL) Images — Rights & Provenance', function ( $post ) {
		$s = bl_img_attachment_summary( $post->ID );
		echo '<p><span class="bl-img-badge bl-img-badge--' . esc_attr( $s['tier'] ? $s['tier'] : 'none' ) . '">' . esc_html( $s['tier_label'] ) . '</span></p>';
		if ( $s['tier'] ) {
			echo '<p><strong>License:</strong> ' . esc_html( $s['license'] ) . '<br><strong>Creator:</strong> ' . esc_html( $s['creator'] ) . '<br><strong>Source:</strong> ' . esc_html( $s['source_name'] ) . '<br><strong>Obtained:</strong> ' . esc_html( substr( $s['imported_at'], 0, 10 ) ) . ' by ' . esc_html( $s['imported_by'] ) . '<br><strong>People:</strong> ' . esc_html( $s['people_label'] ) . '</p>';
		}
		echo '<p><a class="button button-primary" href="' . esc_url( bl_img_item_url( $post->ID ) ) . '">Open in (BL) Images</a></p>';
	}, 'attachment', 'side', 'high' );
} );

/* Extra link on the Plugins screen. */
add_filter( 'plugin_row_meta', function ( $meta, $file ) {
	if ( plugin_basename( BL_IMG_FILE ) === $file ) {
		$meta[] = '<a href="' . esc_url( admin_url( 'admin.php?page=bl-images-audit' ) ) . '">License audit</a>';
	}
	return $meta;
}, 10, 2 );
