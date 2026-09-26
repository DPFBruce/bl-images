<?php
/**
 * Releases — how real organizations document permission, done digitally.
 *
 *   likeness     Photo & Likeness Release (model release). Signed by the person
 *                pictured — or a parent/guardian for a minor.
 *   contributor  Photo Contribution License. Signed by the photographer /
 *                copyright owner who GIVES DPF photos; they can upload the
 *                photos on the signing page, so the license is bound to the
 *                exact files (by SHA-256).
 *   event        Event Photography Notice record (Awards Dinner etc.): the
 *                notice text, how it was given (registration terms, signage,
 *                announcement), evidence (signage photo), and opt-outs.
 *
 * E-signatures follow the U.S. ESIGN Act / UETA elements: consent to sign
 * electronically, the exact text shown (snapshot + hash), intent (typed legal
 * name + drawn signature), attribution (unique emailed link, IP, device,
 * timestamp) and a retained copy (emailed to the signer + vault certificate).
 * Paper releases are scanned and attached the same way.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BL_IMG_RELEASE_CPT', 'bl_img_release' );

add_action( 'init', function () {
	register_post_type( BL_IMG_RELEASE_CPT, array(
		'label'           => 'Image Releases',
		'public'          => false,
		'show_ui'         => false,
		'show_in_rest'    => false,
		'supports'        => array( 'title' ),
		'capability_type' => 'post',
		'map_meta_cap'    => true,
	) );
} );

function bl_img_release_types() {
	return array(
		'likeness'    => 'Photo & Likeness Release',
		'contributor' => 'Photo Contribution License',
		'event'       => 'Event Photography Notice',
	);
}

/* ------------------------------------------------------------------ *
 *  Templates (versioned; every edit is ledgered with its hash)
 * ------------------------------------------------------------------ */

function bl_img_default_templates() {
	return array(
		'likeness' => array( 'version' => 1, 'text' =>
"PHOTOGRAPH & LIKENESS RELEASE

I grant {org} (\"{short}\"), its staff, volunteers, contractors and those acting with its permission, the right to photograph and record me, and to use, reproduce, edit, publish and distribute my name, image, likeness and voice as captured in the photographs and recordings described in this release (the \"Materials\"), in any medium now known or later developed — including websites, social media, email, print, video, advertising, fundraising, reports and presentations — worldwide and without time limit, for purposes related to {short}'s mission and activities.

{short} will not use the Materials in a way that is defamatory or that falsely states or implies facts about me. I waive any right to inspect or approve finished uses, and I release {short} from claims arising from uses consistent with this release, including claims for invasion of privacy, right of publicity or defamation. I will not receive payment.

Although this release is irrevocable, {short} will honor a written request sent to {contact} to stop making NEW uses of the Materials. That request will not require {short} to remove uses already published, printed or distributed.

Materials covered: {scope}
Limitations I am placing on this release (if any): {limitations}

By signing, I confirm that I am 18 years of age or older and have read and understand this release — or that I am the parent or legal guardian of the minor named on this release and sign on the minor's behalf, and have the authority to do so." ),
		'contributor' => array( 'version' => 1, 'text' =>
"PHOTO CONTRIBUTION LICENSE

I am the photographer and copyright owner of the photograph(s) I am providing to {org} (\"{short}\") under this license (the \"Photos\"), or I am authorized by the copyright owner to grant this license.

1. License. I grant {short} a non-exclusive, perpetual, irrevocable, worldwide, royalty-free license to use, reproduce, edit, crop, adapt, publish, display and distribute the Photos in any medium now known or later developed, for any purpose related to {short}'s mission and activities — including websites, social media, email, print, video, advertising, fundraising, reports and presentations — and to allow {short}'s partners and news media to use them when reporting on {short}. I keep my copyright.

2. Credit. {short} will credit me as \"{credit_as}\" where reasonably practical. An accidental omission of credit is not a breach of this license.

3. My promises. (a) The Photos are my original work, or I am authorized to license them. (b) To my knowledge they do not infringe anyone's copyright, trademark, privacy or publicity rights. (c) Every identifiable person in the Photos agreed to be photographed and to this kind of use, or appeared in a public or newsworthy setting. (d) I will promptly tell {short} at {contact} if I learn of any problem with the Photos.

4. No payment. I will not receive payment for this license.

Photos covered: {scope}

By signing, I confirm that I am 18 years of age or older and have read and understand this license." ),
		'event' => array( 'version' => 1, 'text' =>
"PHOTOGRAPHY & RECORDING NOTICE

{event} will be photographed and recorded by {org}. By attending, you consent to being photographed and recorded, and to {org}'s use of those images and recordings in any media, worldwide, without payment, for purposes related to its mission and activities.

If you prefer not to be photographed, please tell a staff member at check-in. You will receive an identifying sticker or lanyard, and we will make reasonable efforts to keep you out of published images. Questions: {contact}." ),
	);
}

function bl_img_templates() {
	$t = get_option( 'bl_img_release_templates', array() );
	return wp_parse_args( is_array( $t ) ? $t : array(), bl_img_default_templates() );
}

function bl_img_save_template( $type, $text ) {
	$all = bl_img_templates();
	if ( ! isset( $all[ $type ] ) ) {
		return new WP_Error( 'type', 'Unknown template.' );
	}
	$text = trim( wp_kses( (string) $text, array() ) );
	if ( $text === $all[ $type ]['text'] ) {
		return $all[ $type ];
	}
	$all[ $type ] = array( 'version' => (int) $all[ $type ]['version'] + 1, 'text' => $text );
	update_option( 'bl_img_release_templates', $all, false );
	bl_img_ledger_append( 'template_update', array( 'type' => $type, 'version' => $all[ $type ]['version'], 'text_sha256' => hash( 'sha256', $text ), 'text' => $text ) );
	return $all[ $type ];
}

function bl_img_render_template( $type, $vars ) {
	$t   = bl_img_templates();
	$txt = $t[ $type ]['text'];
	$s   = bl_img_settings();
	$rep = array(
		'{org}'         => $s['org_name'],
		'{short}'       => $s['org_short'],
		'{contact}'     => $s['contact_email'],
		'{scope}'       => ! empty( $vars['scope'] ) ? $vars['scope'] : 'the photographs and recordings shown on this page',
		'{limitations}' => ! empty( $vars['limitations'] ) ? $vars['limitations'] : 'None',
		'{credit_as}'   => ! empty( $vars['credit_as'] ) ? $vars['credit_as'] : ( ! empty( $vars['signer'] ) ? $vars['signer'] : 'the photographer' ),
		'{event}'       => ! empty( $vars['event'] ) ? $vars['event'] : 'This event',
		'{signer}'      => ! empty( $vars['signer'] ) ? $vars['signer'] : '',
	);
	return array( 'text' => strtr( $txt, $rep ), 'version' => (int) $t[ $type ]['version'] );
}

/* ------------------------------------------------------------------ *
 *  Records
 * ------------------------------------------------------------------ */

function bl_img_release_fields() {
	return array( 'type', 'method', 'status', 'signer_name', 'signer_email', 'signer_phone', 'is_minor', 'minor_name', 'guardian_relationship', 'credit_as', 'scope', 'limitations', 'event_name', 'event_date', 'event_venue', 'notice_methods', 'opt_outs', 'notes', 'token', 'token_expires', 'signed_at', 'signed_ip', 'signed_ua', 'document_sha256', 'template_version', 'certificate', 'signature_file', 'revoked_at', 'revoked_reason', 'attachments', 'uploads', 'scans', 'created_by' );
}

function bl_img_release_get( $id ) {
	$p = get_post( $id );
	if ( ! $p || BL_IMG_RELEASE_CPT !== $p->post_type ) {
		return null;
	}
	$r = array( 'id' => (int) $id, 'title' => $p->post_title, 'created' => $p->post_date_gmt );
	foreach ( bl_img_release_fields() as $f ) {
		$r[ $f ] = get_post_meta( $id, '_bl_rel_' . $f, true );
	}
	$r['attachments'] = array_values( array_map( 'intval', (array) $r['attachments'] ) );
	$r['uploads']     = is_array( $r['uploads'] ) ? $r['uploads'] : array();
	$r['scans']       = is_array( $r['scans'] ) ? $r['scans'] : array();
	if ( 'draft' === $r['status'] && $r['token_expires'] && strtotime( $r['token_expires'] ) < time() ) {
		$r['status'] = 'expired';
	}
	return $r;
}

function bl_img_release_set( $id, $data ) {
	foreach ( $data as $k => $v ) {
		if ( in_array( $k, bl_img_release_fields(), true ) ) {
			update_post_meta( $id, '_bl_rel_' . $k, $v );
		}
	}
}

function bl_img_release_summary( $id ) {
	$r = bl_img_release_get( $id );
	if ( ! $r ) {
		return 'Release #' . $id . ' (missing)';
	}
	$types = bl_img_release_types();
	$who   = 'event' === $r['type'] ? $r['event_name'] : $r['signer_name'] . ( $r['is_minor'] ? ' (guardian for ' . $r['minor_name'] . ')' : '' );
	$when  = $r['signed_at'] ? ' · signed ' . substr( $r['signed_at'], 0, 10 ) . ( 'paper' === $r['method'] ? ' (paper, scanned)' : ( 'esign' === $r['method'] ? ' (e-signature)' : '' ) ) : ' · ' . $r['status'];
	return '#' . $id . ' · ' . $types[ $r['type'] ] . ' · ' . $who . $when . ( $r['revoked_at'] ? ' · REVOKED for new uses ' . substr( $r['revoked_at'], 0, 10 ) : '' );
}

function bl_img_release_public( $r ) {
	$types = bl_img_release_types();
	$r['type_label']  = isset( $types[ $r['type'] ] ) ? $types[ $r['type'] ] : $r['type'];
	$r['summary']     = bl_img_release_summary( $r['id'] );
	$r['link']        = $r['token'] && 'draft' === $r['status'] ? bl_img_release_link( $r['token'] ) : '';
	$r['images']      = array_values( array_filter( array_map( function ( $aid ) {
		$u = wp_get_attachment_image_url( $aid, 'thumbnail' );
		return $u ? array( 'id' => $aid, 'thumb' => $u, 'title' => get_the_title( $aid ) ) : null;
	}, $r['attachments'] ) ) );
	$r['uploads_public'] = array_map( function ( $u ) { return array( 'name' => $u['name'], 'sha256' => $u['sha256'], 'bytes' => $u['bytes'], 'imported' => isset( $u['imported'] ) ? (int) $u['imported'] : 0 ); }, $r['uploads'] );
	unset( $r['token'], $r['uploads'] );
	return $r;
}

function bl_img_release_link( $token ) {
	return add_query_arg( 'bl-img-release', rawurlencode( $token ), home_url( '/' ) );
}

function bl_img_release_create( $in ) {
	$types = bl_img_release_types();
	$type  = isset( $in['type'] ) && isset( $types[ $in['type'] ] ) ? $in['type'] : '';
	if ( ! $type ) {
		return new WP_Error( 'type', 'Choose a release type.' );
	}
	$method = isset( $in['method'] ) && in_array( $in['method'], array( 'esign', 'paper', 'record' ), true ) ? $in['method'] : ( 'event' === $type ? 'record' : 'esign' );
	$name   = sanitize_text_field( isset( $in['signer_name'] ) ? $in['signer_name'] : '' );
	$event  = sanitize_text_field( isset( $in['event_name'] ) ? $in['event_name'] : '' );
	if ( 'event' === $type && ! $event ) {
		return new WP_Error( 'event', 'Enter the event name.' );
	}
	if ( 'event' !== $type && ! $name ) {
		return new WP_Error( 'name', 'Enter the signer\'s name.' );
	}
	$id = wp_insert_post( array( 'post_type' => BL_IMG_RELEASE_CPT, 'post_status' => 'publish', 'post_title' => 'event' === $type ? $event : $name ), true );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$token = strtolower( wp_generate_password( 40, false, false ) );
	$data  = array(
		'type'          => $type,
		'method'        => $method,
		'status'        => 'event' === $type ? 'active' : 'draft',
		'signer_name'   => $name,
		'signer_email'  => sanitize_email( isset( $in['signer_email'] ) ? $in['signer_email'] : '' ),
		'signer_phone'  => sanitize_text_field( isset( $in['signer_phone'] ) ? $in['signer_phone'] : '' ),
		'is_minor'      => ! empty( $in['is_minor'] ) ? 1 : 0,
		'minor_name'    => sanitize_text_field( isset( $in['minor_name'] ) ? $in['minor_name'] : '' ),
		'credit_as'     => sanitize_text_field( isset( $in['credit_as'] ) ? $in['credit_as'] : $name ),
		'scope'         => sanitize_textarea_field( isset( $in['scope'] ) ? $in['scope'] : '' ),
		'limitations'   => sanitize_textarea_field( isset( $in['limitations'] ) ? $in['limitations'] : '' ),
		'event_name'    => $event,
		'event_date'    => sanitize_text_field( isset( $in['event_date'] ) ? $in['event_date'] : '' ),
		'event_venue'   => sanitize_text_field( isset( $in['event_venue'] ) ? $in['event_venue'] : '' ),
		'notice_methods'=> array_map( 'sanitize_text_field', (array) ( isset( $in['notice_methods'] ) ? $in['notice_methods'] : array() ) ),
		'opt_outs'      => sanitize_textarea_field( isset( $in['opt_outs'] ) ? $in['opt_outs'] : '' ),
		'notes'         => sanitize_textarea_field( isset( $in['notes'] ) ? $in['notes'] : '' ),
		'token'         => 'esign' === $method ? $token : '',
		'token_expires' => 'esign' === $method ? gmdate( 'c', time() + DAY_IN_SECONDS * max( 1, (int) bl_img_setting( 'release_expiry' ) ) ) : '',
		'attachments'   => array(),
		'uploads'       => array(),
		'scans'         => array(),
		'created_by'    => get_current_user_id(),
	);
	if ( 'event' === $type ) {
		$tpl                      = bl_img_render_template( 'event', array( 'event' => $event ) );
		$data['certificate']      = '';
		$data['template_version'] = $tpl['version'];
		$data['signed_at']        = bl_img_now_utc();
		bl_img_vault_put( 'releases/' . $id, 'notice-text-v' . $tpl['version'] . '.txt', $tpl['text'] );
	}
	bl_img_release_set( $id, $data );
	if ( ! empty( $in['attachments'] ) ) {
		foreach ( (array) $in['attachments'] as $aid ) {
			bl_img_link_releases( (int) $aid, array( $id ), false );
		}
	}
	bl_img_ledger_append( 'release_created', array( 'type' => $type, 'method' => $method, 'signer' => $name, 'event' => $event ), 0, $id );
	return bl_img_release_get( $id );
}

/** Link releases to an image (adds; $replace=true sets exactly). Updates people status. */
function bl_img_link_releases( $attachment_id, $release_ids, $replace = true ) {
	$attachment_id = (int) $attachment_id;
	$cur           = $replace ? array() : bl_img_get_releases( $attachment_id );
	$new           = array_values( array_unique( array_filter( array_merge( $cur, array_map( 'intval', (array) $release_ids ) ) ) ) );
	$old           = bl_img_get_releases( $attachment_id );
	update_post_meta( $attachment_id, '_bl_img_releases', $new );
	foreach ( array_unique( array_merge( $old, $new ) ) as $rid ) {
		$atts = array_map( 'intval', (array) get_post_meta( $rid, '_bl_rel_attachments', true ) );
		if ( in_array( $rid, $new, true ) ) {
			$atts[] = $attachment_id;
		} else {
			$atts = array_diff( $atts, array( $attachment_id ) );
		}
		update_post_meta( $rid, '_bl_rel_attachments', array_values( array_unique( $atts ) ) );
	}
	// People status follows the strongest coverage on file.
	$status = get_post_meta( $attachment_id, '_bl_img_people', true );
	$has_l  = false;
	$has_e  = false;
	foreach ( $new as $rid ) {
		$r = bl_img_release_get( $rid );
		if ( $r && 'likeness' === $r['type'] && 'signed' === $r['status'] ) {
			$has_l = true;
		}
		if ( $r && 'event' === $r['type'] ) {
			$has_e = true;
		}
	}
	if ( $has_l ) {
		update_post_meta( $attachment_id, '_bl_img_people', 'released' );
	} elseif ( $has_e && in_array( $status, array( '', 'unknown' ), true ) ) {
		update_post_meta( $attachment_id, '_bl_img_people', 'event' );
	}
	if ( $old !== $new && bl_img_is_image_attachment( $attachment_id ) ) {
		bl_img_ledger_append( 'release_link', array( 'releases' => $new, 'previous' => $old ), $attachment_id );
	}
	return $new;
}

/** Email the signing link. */
function bl_img_release_send_link( $id ) {
	$r = bl_img_release_get( $id );
	if ( ! $r || 'draft' !== $r['status'] || ! $r['signer_email'] ) {
		return new WP_Error( 'cant', 'This release needs a signer email and must be unsigned.' );
	}
	$s     = bl_img_settings();
	$types = bl_img_release_types();
	$link  = bl_img_release_link( $r['token'] );
	$body  = '<p>Hello ' . esc_html( $r['signer_name'] ) . ',</p><p>' . esc_html( $s['org_name'] ) . ' is asking you to review and sign a <strong>' . esc_html( $types[ $r['type'] ] ) . '</strong>' . ( 'contributor' === $r['type'] ? ' for photos you are sharing with us' : '' ) . '. It takes about two minutes:</p>'
		. '<p><a href="' . esc_url( $link ) . '" style="display:inline-block;background:#C82500;color:#fff;padding:11px 20px;border-radius:6px;text-decoration:none;font-weight:600">Review &amp; sign</a></p>'
		. '<p style="color:#5b6573;font-size:13px">This personal link expires on ' . esc_html( substr( $r['token_expires'], 0, 10 ) ) . '. Questions? Reply to ' . esc_html( $s['contact_email'] ) . '.</p>';
	$ok = wp_mail( $r['signer_email'], $s['org_name'] . ': please review and sign — ' . $types[ $r['type'] ], $body, array( 'Content-Type: text/html; charset=UTF-8', 'Reply-To: ' . $s['contact_email'] ) );
	if ( ! $ok ) {
		return new WP_Error( 'mail', 'WordPress could not send the email. Copy the link and send it yourself.' );
	}
	update_post_meta( $id, '_bl_rel_link_sent', bl_img_now_utc() );
	bl_img_ledger_append( 'release_link_sent', array( 'to' => $r['signer_email'] ), 0, $id );
	return true;
}

function bl_img_release_by_token( $token ) {
	$token = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $token ) );
	if ( strlen( $token ) < 30 ) {
		return null;
	}
	$q = get_posts( array( 'post_type' => BL_IMG_RELEASE_CPT, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_bl_rel_token', 'meta_value' => $token ) );
	return $q ? bl_img_release_get( $q[0] ) : null;
}

/* ------------------------------------------------------------------ *
 *  Public signing page:  /?bl-img-release=<token>
 * ------------------------------------------------------------------ */

add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['bl-img-release'] ) ) {
		return;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'Referrer-Policy: no-referrer' );
	$r = bl_img_release_by_token( wp_unslash( $_GET['bl-img-release'] ) );
	bl_img_render_sign_page( $r, sanitize_text_field( wp_unslash( $_GET['bl-img-release'] ) ) );
	exit;
} );

function bl_img_render_sign_page( $r, $token ) {
	$s      = bl_img_settings();
	$types  = bl_img_release_types();
	$state  = ! $r ? 'invalid' : ( 'signed' === $r['status'] ? 'signed' : ( 'draft' !== $r['status'] ? 'closed' : 'open' ) );
	$doc    = $r ? bl_img_render_template( $r['type'], array( 'scope' => $r['scope'], 'limitations' => $r['limitations'], 'credit_as' => $r['credit_as'], 'signer' => $r['signer_name'] ) ) : null;
	$images = array();
	if ( $r ) {
		foreach ( $r['attachments'] as $aid ) {
			$u = wp_get_attachment_image_url( $aid, 'medium' );
			if ( $u ) {
				$images[] = $u;
			}
		}
	}
	$cfg = array(
		'token'   => $token,
		'api'     => esc_url_raw( rest_url( 'bl-images/v1/public/release/' ) ),
		'state'   => $state,
		'type'    => $r ? $r['type'] : '',
		'minor'   => $r ? (bool) $r['is_minor'] : false,
		'name'    => $r ? $r['signer_name'] : '',
		'email'   => $r ? $r['signer_email'] : '',
		'uploads' => $r ? array_map( function ( $u ) { return array( 'name' => $u['name'], 'bytes' => $u['bytes'] ); }, $r['uploads'] ) : array(),
	);
	?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( ( $r ? $types[ $r['type'] ] : 'Release' ) . ' — ' . $s['org_name'] ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( BL_IMG_URL . 'assets/sign.css?v=' . BL_IMG_VERSION ); ?>">
</head><body>
<main class="bls">
	<header class="bls-h"><div class="bls-org"><?php echo esc_html( $s['org_name'] ); ?></div>
	<h1><?php echo esc_html( $r ? $types[ $r['type'] ] : 'Release' ); ?></h1></header>
	<?php if ( 'invalid' === $state ) : ?>
		<p class="bls-msg">This link isn't valid. Please check the link in your email, or contact <?php echo esc_html( $s['contact_email'] ); ?>.</p>
	<?php elseif ( 'signed' === $state ) : ?>
		<p class="bls-msg bls-ok">Thank you — this was signed on <?php echo esc_html( substr( $r['signed_at'], 0, 10 ) ); ?>. A copy was emailed to you.</p>
	<?php elseif ( 'closed' === $state ) : ?>
		<p class="bls-msg">This link has expired or is no longer active. Please contact <?php echo esc_html( $s['contact_email'] ); ?> for a new one.</p>
	<?php else : ?>
		<?php if ( $images ) : ?>
			<section class="bls-imgs"><p class="bls-lbl">Images this <?php echo 'contributor' === $r['type'] ? 'license' : 'release'; ?> covers</p><div class="bls-grid"><?php foreach ( $images as $u ) : ?><img src="<?php echo esc_url( $u ); ?>" alt=""><?php endforeach; ?></div></section>
		<?php endif; ?>
		<?php if ( 'contributor' === $r['type'] ) : ?>
			<section class="bls-up"><p class="bls-lbl">Your photos</p>
				<p class="bls-help">Add the photo files you're giving us (JPG, PNG, WebP, HEIC — up to 25 MB each). Each file's fingerprint is written into the signed license.</p>
				<label class="bls-drop"><input type="file" id="bls-files" accept="image/*" multiple><span>Choose photos…</span></label>
				<ul id="bls-uplist"></ul>
			</section>
		<?php endif; ?>
		<section class="bls-doc"><p class="bls-lbl">Please read</p><div class="bls-text"><?php echo esc_html( $doc['text'] ); ?></div><p class="bls-ver">Document version <?php echo (int) $doc['version']; ?></p></section>
		<form id="bls-form" class="bls-form" autocomplete="on">
			<?php if ( $r['is_minor'] ) : ?>
				<p class="bls-help">You are signing as the <strong>parent or legal guardian</strong> of <strong><?php echo esc_html( $r['minor_name'] ); ?></strong>.</p>
				<label>Relationship to the minor<input name="guardian_relationship" required placeholder="e.g. Mother, Legal guardian"></label>
			<?php endif; ?>
			<label>Your full legal name<input name="name" required value="<?php echo esc_attr( $r['signer_name'] ); ?>" autocomplete="name"></label>
			<label>Email (a signed copy will be sent here)<input name="email" type="email" required value="<?php echo esc_attr( $r['signer_email'] ); ?>" autocomplete="email"></label>
			<?php if ( 'contributor' === $r['type'] ) : ?>
				<label>Credit me as<input name="credit_as" value="<?php echo esc_attr( $r['credit_as'] ); ?>" placeholder="e.g. Jane Smith Photography"></label>
			<?php endif; ?>
			<?php if ( 'likeness' === $r['type'] ) : ?>
				<label>Limitations you want to place on this release (optional)<textarea name="limitations" rows="2" placeholder="Leave blank for none"></textarea></label>
			<?php endif; ?>
			<div class="bls-sigwrap"><p class="bls-lbl">Sign here</p><canvas id="bls-sig" width="600" height="160" aria-label="Signature pad"></canvas><button type="button" id="bls-clear" class="bls-link">Clear</button></div>
			<label class="bls-chk"><input type="checkbox" name="consent_electronic" required> I agree to sign this document electronically, and that my electronic signature is as valid as a handwritten one.</label>
			<label class="bls-chk"><input type="checkbox" name="agree" required> I have read and agree to the <?php echo 'contributor' === $r['type'] ? 'license' : 'release'; ?> above<?php echo $r['is_minor'] ? ', on the minor\'s behalf' : ''; ?>.</label>
			<button type="submit" class="bls-btn">Sign</button>
			<p id="bls-err" class="bls-err" hidden></p>
		</form>
		<p class="bls-foot">Your name, email, the time, your IP address and device are recorded with your signature to verify it. Questions: <?php echo esc_html( $s['contact_email'] ); ?></p>
	<?php endif; ?>
</main>
<script>window.BLS=<?php echo wp_json_encode( $cfg ); ?>;</script>
<script src="<?php echo esc_url( BL_IMG_URL . 'assets/sign.js?v=' . BL_IMG_VERSION ); ?>"></script>
</body></html>
	<?php
}

/* ------------------------------------------------------------------ *
 *  Public endpoints (token-authorized): upload + sign
 * ------------------------------------------------------------------ */

function bl_img_public_release_upload( WP_REST_Request $req ) {
	$r = bl_img_release_by_token( $req['token'] );
	if ( ! $r || 'draft' !== $r['status'] || 'contributor' !== $r['type'] ) {
		return new WP_Error( 'invalid', 'This link is not accepting uploads.', array( 'status' => 403 ) );
	}
	if ( count( $r['uploads'] ) >= 40 ) {
		return new WP_Error( 'limit', 'Upload limit reached (40 photos). Contact us for more.', array( 'status' => 400 ) );
	}
	$files = $req->get_file_params();
	if ( empty( $files['file'] ) || ! empty( $files['file']['error'] ) ) {
		return new WP_Error( 'nofile', 'No file received.', array( 'status' => 400 ) );
	}
	$f = $files['file'];
	if ( $f['size'] > 25 * 1048576 ) {
		return new WP_Error( 'big', 'That file is over 25 MB.', array( 'status' => 400 ) );
	}
	$probe = bl_img_probe_image( $f['tmp_name'] );
	if ( is_wp_error( $probe ) ) {
		return new WP_Error( 'type', 'That file is not a supported image.', array( 'status' => 400 ) );
	}
	$sha = hash_file( 'sha256', $f['tmp_name'] );
	foreach ( $r['uploads'] as $u ) {
		if ( $u['sha256'] === $sha ) {
			return array( 'ok' => true, 'name' => $u['name'], 'dup' => true );
		}
	}
	$ext  = str_replace( array( 'image/', 'jpeg' ), array( '', 'jpg' ), $probe[2] );
	$name = sanitize_file_name( $f['name'] );
	$dir  = bl_img_vault_dir( 'releases/' . $r['id'] . '/uploads' );
	$stored = substr( $sha, 0, 16 ) . '.' . $ext;
	move_uploaded_file( $f['tmp_name'], $dir . '/' . $stored );
	$r['uploads'][] = array( 'name' => $name, 'stored' => $stored, 'sha256' => $sha, 'bytes' => (int) $f['size'], 'mime' => $probe[2], 'width' => $probe[0], 'height' => $probe[1], 'at' => bl_img_now_utc(), 'ip' => bl_img_client_ip() );
	update_post_meta( $r['id'], '_bl_rel_uploads', $r['uploads'] );
	return array( 'ok' => true, 'name' => $name, 'bytes' => (int) $f['size'] );
}

/**
 * The final document text, with the signer's own entries and the exact files
 * (by SHA-256) / images (by URL) it covers. Used by BOTH preview and sign so
 * what is signed is byte-for-byte what was shown.
 */
function bl_img_release_final_doc( $r, $p ) {
	$name        = sanitize_text_field( isset( $p['name'] ) ? $p['name'] : $r['signer_name'] );
	$limitations = sanitize_textarea_field( isset( $p['limitations'] ) ? $p['limitations'] : $r['limitations'] );
	$credit_as   = sanitize_text_field( ! empty( $p['credit_as'] ) ? $p['credit_as'] : ( $r['credit_as'] ? $r['credit_as'] : $name ) );
	$scope       = $r['scope'];
	if ( 'contributor' === $r['type'] && $r['uploads'] ) {
		$list = array();
		foreach ( $r['uploads'] as $u ) {
			$list[] = $u['name'] . ' (SHA-256 ' . $u['sha256'] . ')';
		}
		$scope = trim( ( $scope ? $scope . '; ' : '' ) . implode( '; ', $list ) );
	}
	if ( $r['attachments'] ) {
		$list = array();
		foreach ( $r['attachments'] as $aid ) {
			$list[] = get_the_title( $aid ) . ' (' . wp_get_attachment_url( $aid ) . ')';
		}
		$scope = trim( ( $scope ? $scope . '; ' : '' ) . implode( '; ', $list ) );
	}
	$doc = bl_img_render_template( $r['type'], array( 'scope' => $scope, 'limitations' => $limitations, 'credit_as' => $credit_as, 'signer' => $name ) );
	if ( $r['is_minor'] ) {
		$doc['text'] .= "\n\nMinor: " . $r['minor_name'] . "\nSigned by parent/guardian: " . $name . ' (' . sanitize_text_field( isset( $p['guardian_relationship'] ) ? $p['guardian_relationship'] : '' ) . ')';
	}
	return array( $doc, $limitations, $credit_as );
}

function bl_img_public_release_preview( WP_REST_Request $req ) {
	$r = bl_img_release_by_token( $req['token'] );
	if ( ! $r || 'draft' !== $r['status'] ) {
		return new WP_Error( 'invalid', 'This link is no longer active.', array( 'status' => 403 ) );
	}
	list( $doc ) = bl_img_release_final_doc( $r, $req->get_json_params() );
	return array( 'text' => $doc['text'], 'sha256' => hash( 'sha256', $doc['text'] ), 'version' => $doc['version'] );
}

function bl_img_public_release_sign( WP_REST_Request $req ) {
	$r = bl_img_release_by_token( $req['token'] );
	if ( ! $r || 'draft' !== $r['status'] ) {
		return new WP_Error( 'invalid', 'This link is no longer active.', array( 'status' => 403 ) );
	}
	$p     = $req->get_json_params();
	$name  = sanitize_text_field( isset( $p['name'] ) ? $p['name'] : '' );
	$email = sanitize_email( isset( $p['email'] ) ? $p['email'] : '' );
	$sig   = isset( $p['signature'] ) ? (string) $p['signature'] : '';
	if ( mb_strlen( $name ) < 3 || ! is_email( $email ) ) {
		return new WP_Error( 'fields', 'Please enter your full legal name and a valid email.', array( 'status' => 400 ) );
	}
	if ( empty( $p['consent_electronic'] ) || empty( $p['agree'] ) ) {
		return new WP_Error( 'consent', 'Please tick both boxes to sign.', array( 'status' => 400 ) );
	}
	if ( 0 !== strpos( $sig, 'data:image/png;base64,' ) || strlen( $sig ) < 800 ) {
		return new WP_Error( 'sig', 'Please sign in the box.', array( 'status' => 400 ) );
	}
	if ( $r['is_minor'] && empty( $p['guardian_relationship'] ) ) {
		return new WP_Error( 'guardian', 'Please enter your relationship to the minor.', array( 'status' => 400 ) );
	}
	if ( 'contributor' === $r['type'] && ! $r['uploads'] && ! $r['attachments'] ) {
		return new WP_Error( 'photos', 'Please add at least one photo before signing.', array( 'status' => 400 ) );
	}

	$png = base64_decode( substr( $sig, 22 ), true );
	if ( ! $png || "\x89PNG" !== substr( $png, 0, 4 ) || strlen( $png ) > 400000 ) {
		return new WP_Error( 'sig', 'Signature image was not valid.', array( 'status' => 400 ) );
	}
	list( $doc, $limitations, $credit_as ) = bl_img_release_final_doc( $r, $p );
	// The signer must have been SHOWN exactly this text (preview step) before signing.
	if ( empty( $p['doc_sha256'] ) || ! hash_equals( hash( 'sha256', $doc['text'] ), (string) $p['doc_sha256'] ) ) {
		return new WP_Error( 'changed', 'The document changed since you reviewed it. Please review it again.', array( 'status' => 409 ) );
	}

	list( $sig_rel, $sig_sha ) = bl_img_vault_put( 'releases/' . $r['id'], 'signature.png', $png );
	$signed_at = bl_img_now_utc();
	$ua        = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 300 ) : '';
	$record    = array(
		'release_id'            => $r['id'],
		'type'                  => $r['type'],
		'document_text'         => $doc['text'],
		'document_text_sha256'  => hash( 'sha256', $doc['text'] ),
		'template_version'      => $doc['version'],
		'signer_name'           => $name,
		'signer_email'          => $email,
		'link_sent_to'          => $r['signer_email'],
		'is_minor'              => (bool) $r['is_minor'],
		'minor_name'            => $r['minor_name'],
		'guardian_relationship' => sanitize_text_field( isset( $p['guardian_relationship'] ) ? $p['guardian_relationship'] : '' ),
		'limitations'           => $limitations,
		'credit_as'             => $credit_as,
		'consent_electronic'    => 'I agree to sign this document electronically, and that my electronic signature is as valid as a handwritten one.',
		'signature_png_sha256'  => $sig_sha,
		'uploads'               => $r['uploads'],
		'attachments'           => $r['attachments'],
		'signed_at_utc'         => $signed_at,
		'ip'                    => bl_img_client_ip(),
		'user_agent'            => $ua,
		'token_sha256'          => hash( 'sha256', $r['token'] ),
		'site'                  => home_url( '/' ),
	);
	list( $rec_rel, $rec_sha ) = bl_img_vault_put_json( 'releases/' . $r['id'], 'signed-record.json', $record );
	$cert = bl_img_release_certificate_html( $r['id'], $record, $png, $rec_sha );
	bl_img_vault_put( 'releases/' . $r['id'], 'signed-certificate.html', $cert );

	bl_img_release_set( $r['id'], array(
		'status'           => 'signed',
		'signer_name'      => $name,
		'signer_email'     => $email,
		'guardian_relationship' => $record['guardian_relationship'],
		'limitations'      => $limitations,
		'credit_as'        => $credit_as,
		'signed_at'        => $signed_at,
		'signed_ip'        => $record['ip'],
		'signed_ua'        => $ua,
		'document_sha256'  => $rec_sha,
		'template_version' => $doc['version'],
		'certificate'      => 'releases/' . $r['id'] . '/signed-certificate.html',
		'signature_file'   => $sig_rel,
		'token'            => '', // single use
	) );
	wp_update_post( array( 'ID' => $r['id'], 'post_title' => $name ) );
	bl_img_ledger_append( 'release_signed', array( 'record_sha256' => $rec_sha, 'document_text_sha256' => $record['document_text_sha256'], 'signature_png_sha256' => $sig_sha, 'uploads' => wp_list_pluck( $r['uploads'], 'sha256' ), 'attachments' => $r['attachments'] ), 0, $r['id'], array( 'user_id' => 0, 'user_login' => 'signer', 'user_name' => $name . ' <' . $email . '>', 'ip' => $record['ip'], 'ua' => $ua ) );

	// Linked images now have a signed likeness release.
	foreach ( $r['attachments'] as $aid ) {
		bl_img_link_releases( $aid, array( $r['id'] ), false );
	}

	// Retained copy to the signer + notice to DPF.
	$s = bl_img_settings();
	wp_mail( $email, 'Your signed copy — ' . $s['org_name'], $cert, array( 'Content-Type: text/html; charset=UTF-8', 'Reply-To: ' . $s['contact_email'] ) );
	wp_mail( $s['contact_email'], '(BL) Images: release signed by ' . $name, '<p>' . esc_html( bl_img_release_summary( $r['id'] ) ) . '</p><p><a href="' . esc_url( admin_url( 'admin.php?page=bl-images-releases' ) ) . '">Open Releases</a></p>', array( 'Content-Type: text/html; charset=UTF-8' ) );
	return array( 'ok' => true );
}

function bl_img_release_certificate_html( $id, $rec, $png, $rec_sha ) {
	$s     = bl_img_settings();
	$types = bl_img_release_types();
	$rows  = array(
		'Release ID'              => '#' . $id,
		'Signed by'               => $rec['signer_name'] . ' <' . $rec['signer_email'] . '>',
		'On behalf of (minor)'    => $rec['is_minor'] ? $rec['minor_name'] . ' — ' . $rec['guardian_relationship'] : '—',
		'Signed at (UTC)'         => $rec['signed_at_utc'],
		'IP address'              => $rec['ip'],
		'Device'                  => $rec['user_agent'],
		'Link originally sent to' => $rec['link_sent_to'] ? $rec['link_sent_to'] : '—',
		'Document version'        => $rec['template_version'],
		'Document SHA-256'        => $rec['document_text_sha256'],
		'Signature SHA-256'       => $rec['signature_png_sha256'],
		'Record SHA-256'          => $rec_sha,
	);
	$tr = '';
	foreach ( $rows as $k => $v ) {
		$tr .= '<tr><th style="text-align:left;background:#f4f6f9;padding:6px 9px;border:1px solid #e3e7ec;width:190px">' . esc_html( $k ) . '</th><td style="padding:6px 9px;border:1px solid #e3e7ec;word-break:break-all">' . esc_html( $v ) . '</td></tr>';
	}
	return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html( $types[ $rec['type'] ] ) . ' #' . (int) $id . '</title></head><body style="font:14px/1.55 -apple-system,Segoe UI,Roboto,sans-serif;color:#1c2333;max-width:780px;margin:24px auto;padding:0 18px">'
		. '<p style="color:#9C7C3A;font-weight:700;letter-spacing:.06em;text-transform:uppercase;font-size:12px;margin:0">' . esc_html( $s['org_name'] ) . '</p>'
		. '<h1 style="font-size:21px;border-bottom:3px solid #C82500;padding-bottom:8px;margin-top:4px">' . esc_html( $types[ $rec['type'] ] ) . ' — signed copy</h1>'
		. '<div style="background:#fbfaf7;border:1px solid #e3e7ec;border-radius:8px;padding:14px 16px;white-space:pre-wrap">' . esc_html( $rec['document_text'] ) . '</div>'
		. '<p style="margin:18px 0 4px;font-weight:600">Signature</p><img alt="Signature" style="max-width:360px;border-bottom:1px solid #1c2333" src="data:image/png;base64,' . base64_encode( $png ) . '">'
		. '<p style="margin:2px 0 18px">' . esc_html( $rec['signer_name'] ) . '</p>'
		. '<table style="border-collapse:collapse;width:100%;font-size:12.5px">' . $tr . '</table>'
		. '<p style="color:#5b6573;font-size:12px">Signed electronically. The signer consented to electronic signature. This record is stored in ' . esc_html( $s['org_name'] ) . '\'s tamper-evident provenance ledger.</p></body></html>';
}

/* ------------------------------------------------------------------ *
 *  Admin actions: paper scans, revoke, import contributor uploads
 * ------------------------------------------------------------------ */

function bl_img_release_attach_scan( $id, $file, $fields ) {
	$r = bl_img_release_get( $id );
	if ( ! $r ) {
		return new WP_Error( 'nf', 'Release not found.' );
	}
	$ft = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
	if ( ! in_array( $ft['type'], array( 'application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic' ), true ) ) {
		return new WP_Error( 'type', 'Upload a PDF or photo of the signed form.' );
	}
	$name = gmdate( 'Ymd-His' ) . '-' . sanitize_file_name( $file['name'] );
	$dir  = bl_img_vault_dir( 'releases/' . $id );
	move_uploaded_file( $file['tmp_name'], $dir . '/' . $name );
	$sha            = hash_file( 'sha256', $dir . '/' . $name );
	$r['scans'][]   = array( 'name' => $name, 'sha256' => $sha, 'at' => bl_img_now_utc(), 'by' => get_current_user_id(), 'kind' => sanitize_key( isset( $fields['kind'] ) ? $fields['kind'] : 'signed-form' ) );
	$upd            = array( 'scans' => $r['scans'] );
	if ( 'event' !== $r['type'] && ( empty( $fields['kind'] ) || 'signed-form' === $fields['kind'] ) ) {
		$upd['method']    = 'paper';
		$upd['status']    = 'signed';
		$upd['signed_at'] = ! empty( $fields['signed_date'] ) ? sanitize_text_field( $fields['signed_date'] ) : bl_img_now_utc();
		$upd['token']     = '';
	}
	bl_img_release_set( $id, $upd );
	bl_img_ledger_append( 'release_scan', array( 'file' => $name, 'sha256' => $sha, 'kind' => $r['scans'][ count( $r['scans'] ) - 1 ]['kind'] ), 0, $id );
	if ( ! empty( $upd['status'] ) ) {
		foreach ( $r['attachments'] as $aid ) {
			bl_img_link_releases( $aid, array( $id ), false );
		}
	}
	return bl_img_release_get( $id );
}

function bl_img_release_revoke( $id, $reason ) {
	$r = bl_img_release_get( $id );
	if ( ! $r ) {
		return new WP_Error( 'nf', 'Release not found.' );
	}
	bl_img_release_set( $id, array( 'status' => 'revoked', 'revoked_at' => bl_img_now_utc(), 'revoked_reason' => sanitize_textarea_field( $reason ), 'token' => '' ) );
	bl_img_ledger_append( 'release_revoked', array( 'reason' => sanitize_textarea_field( $reason ), 'attachments' => $r['attachments'] ), 0, $id );
	foreach ( $r['attachments'] as $aid ) {
		update_post_meta( $aid, '_bl_img_people', 'unknown' );
	}
	return bl_img_release_get( $id );
}

/** Stage + commit one contributor upload into the Media Library. */
function bl_img_release_import_upload( $id, $sha ) {
	$r = bl_img_release_get( $id );
	if ( ! $r || 'contributor' !== $r['type'] || 'signed' !== $r['status'] ) {
		return new WP_Error( 'state', 'Only signed contributor licenses can be imported.' );
	}
	foreach ( $r['uploads'] as $i => $u ) {
		if ( $u['sha256'] !== $sha ) {
			continue;
		}
		if ( ! empty( $u['imported'] ) && get_post( $u['imported'] ) ) {
			return bl_img_attachment_summary( $u['imported'] );
		}
		$path = bl_img_vault_dir( 'releases/' . $id . '/uploads' ) . '/' . $u['stored'];
		$st   = bl_img_stage_upload( $path, $u['name'], array(
			'basis'               => 'contributor',
			'creator'             => $r['credit_as'] ? $r['credit_as'] : $r['signer_name'],
			'holder'              => $r['signer_name'],
			'contributor_release' => $id,
			'agreed'              => true,
		) );
		if ( is_wp_error( $st ) ) {
			return $st;
		}
		if ( ! empty( $st['duplicate'] ) ) {
			$att = $st['duplicate'];
			bl_img_link_releases( $att, array( $id ), false );
		} else {
			bl_img_stage_describe( $st['token'] );
			$sum = bl_img_stage_commit( $st['token'] );
			if ( is_wp_error( $sum ) ) {
				return $sum;
			}
			$att = $sum['id'];
		}
		$r['uploads'][ $i ]['imported'] = (int) $att;
		update_post_meta( $id, '_bl_rel_uploads', $r['uploads'] );
		return bl_img_attachment_summary( $att );
	}
	return new WP_Error( 'nf', 'Upload not found.' );
}
