<?php
// Email notifications: one notice per page when a scan finds new, undismissed issues.
$post_body = function ( $issues, $extra = array() ) {
	return array_merge( array( 'post_id' => 10, 'url' => WT_SITE . '/page-10/', 'scanned_at' => '2026-10-28T10:00:00Z', 'issues' => $issues ), $extra );
};
$post_scan = function ( $body ) {
	return wpa11y_rest_results( new WP_REST_Request( array(), array( 'Authorization' => 'Bearer s3cret' ), $body ) );
};
$setup = function ( $options ) {
	wt_reset( array( 'posts' => array( array( 'ID' => 10, 'post_title' => 'Floor &amp; Maps' ) ) ) );
	update_option( 'wpa11y_secret_hash', hash( 'sha256', 's3cret' ) );
	update_option( 'wpa11y_last_full_scan', '2026-10-27T09:17:00Z' );
	foreach ( $options as $k => $v ) { update_option( $k, $v ); }
};
$old = array( wt_issue( 'warning', 'color-contrast', '#a' ) );
$new = array(
	wt_issue( 'warning', 'color-contrast', '#a' ),
	wt_issue( 'warning', 'color-contrast', '#b' ),
	wt_issue( 'warning', 'color-contrast', '#c' ),
	wt_issue( 'error', 'image-alt', 'img' ),
);

// Off by default.
$setup( array() );
wpa11y_save_result( 10, wt_result( $old ) );
$post_scan( $post_body( $new ) );
check( 'notify: off by default', array( $GLOBALS['wt_mail'], $GLOBALS['wt_actions'] ), array( array(), array() ) );

// One address.
$setup( array( 'wpa11y_notify' => 'email', 'wpa11y_notify_email' => 'web@example.edu' ) );
wpa11y_save_result( 10, wt_result( $old ) );
wpa11y_add_dismissal( 10, 'color-contrast', '#c', 'fine', 5 );
$post_scan( $post_body( $new ) );
check( 'email: one message', count( $GLOBALS['wt_mail'] ), 1 );
$m = $GLOBALS['wt_mail'][0];
check( 'email: to the address', $m['to'], 'web@example.edu' );
check( 'email: subject names site, count and page', $m['subject'], '[Library & Archives] Accessibility: 2 new issues on Floor & Maps' );
check( 'email: body names the page and its URL', array( strpos( $m['message'], 'scan of "Floor & Maps" found 2 new issues.' ) !== false, strpos( $m['message'], 'Page: ' . WT_SITE . '/page-10/' ) !== false ), array( true, true ) );
check( 'email: new items by rule; old and dismissed left out', array( strpos( $m['message'], '(image-alt): 1' ) !== false, strpos( $m['message'], '(color-contrast): 1' ) !== false ), array( true, true ) );
check( 'email: errors before warnings', strpos( $m['message'], 'Errors' ) < strpos( $m['message'], 'Warnings to review' ), true );
check( 'email: links to the detail screen', strpos( $m['message'], WT_SITE . '/wp-admin/admin.php?page=wpa11y&post=10' ) !== false, true );

$post_scan( $post_body( $new ) );
check( 'email: unchanged rescan sends nothing', count( $GLOBALS['wt_mail'] ), 1 );
$post_scan( $post_body( array(), array( 'error' => 'Navigation timeout' ) ) );
check( 'email: failed scan sends nothing', count( $GLOBALS['wt_mail'] ), 1 );
$post_scan( $post_body( $new ) );
check( 'email: issues kept through a failed scan are not new', count( $GLOBALS['wt_mail'] ), 1 );

$setup( array( 'wpa11y_notify' => 'email', 'wpa11y_notify_email' => 'web@example.edu' ) );
$post_scan( $post_body( $new ) );
check( 'email: a new page is reported', count( $GLOBALS['wt_mail'] ), 1 );
$setup( array( 'wpa11y_notify' => 'email', 'wpa11y_notify_email' => 'web@example.edu', 'wpa11y_last_full_scan' => '' ) );
$post_scan( $post_body( $new ) );
check( 'email: nothing on the site’s first scan', count( $GLOBALS['wt_mail'] ), 0 );
$setup( array( 'wpa11y_notify' => 'email', 'wpa11y_notify_email' => '' ) );
$post_scan( $post_body( $new ) );
check( 'email: no address, nothing sent', count( $GLOBALS['wt_mail'] ), 0 );

// Page owners via wpshare.
$setup( array( 'wpa11y_notify' => 'wpshare', 'wpa11y_notify_email' => 'web@example.edu' ) );
$post_scan( $post_body( $new ) );
check( 'wpshare: inactive, nothing sent', array( $GLOBALS['wt_actions'], $GLOBALS['wt_mail'] ), array( array(), array() ) );
$setup( array( 'wpa11y_notify' => 'wpshare' ) );
$GLOBALS['wt_listen']['wpshare_notify'] = true;
$post_scan( $post_body( $new ) );
check( 'wpshare: one action, no direct mail', array( count( $GLOBALS['wt_actions'] ), count( $GLOBALS['wt_mail'] ) ), array( 1, 0 ) );
list( $hook, $args ) = $GLOBALS['wt_actions'][0];
check( 'wpshare: hook and post', array( $hook, $args[0] ), array( 'wpshare_notify', 10 ) );
check( 'wpshare: contract fields', array_keys( $args[1] ), array( 'source', 'subject', 'message', 'url' ) );
check( 'wpshare: source and url', array( $args[1]['source'], $args[1]['url'] ), array( 'wpa11y', WT_SITE . '/wp-admin/admin.php?page=wpa11y&post=10' ) );
check( 'wpshare: subject without site prefix', $args[1]['subject'], 'Accessibility: 4 new issues on Floor & Maps' );

// Settings.
$base = array( 'repo' => 'tfscharff/wpa11y', 'workflow' => 'scan.yml', 'ref' => 'main' );
list( $out, $errors ) = wpa11y_clean_settings( $base + array( 'notify' => 'email', 'notify_email' => ' web@example.edu ' ) );
check( 'settings: email mode saved', array( $out['wpa11y_notify'], $out['wpa11y_notify_email'], $errors ), array( 'email', 'web@example.edu', array() ) );
list( $out, $errors ) = wpa11y_clean_settings( $base + array( 'notify' => 'email', 'notify_email' => 'not an address' ) );
check( 'settings: bad address refused, mode kept as it was', array( isset( $out['wpa11y_notify'] ), isset( $out['wpa11y_notify_email'] ), count( $errors ) ), array( false, false, 1 ) );
list( $out, $errors ) = wpa11y_clean_settings( $base + array( 'notify' => 'email', 'notify_email' => '' ) );
check( 'settings: email mode needs an address', array( isset( $out['wpa11y_notify'] ), $errors ), array( false, array( 'Enter the address to send notifications to.' ) ) );
list( $out, $errors ) = wpa11y_clean_settings( $base + array( 'notify' => 'wpshare' ) );
check( 'settings: owners mode needs no address', array( $out['wpa11y_notify'], $errors ), array( 'wpshare', array() ) );
list( $out, $errors ) = wpa11y_clean_settings( $base + array( 'notify' => 'everyone' ) );
check( 'settings: unknown mode is off', $out['wpa11y_notify'], 'off' );

$setup( array( 'wpa11y_notify' => 'wpshare' ) );
$html = wpa11y_render_settings( '', array(), '' );
check( 'settings page: inactive wpshare warned and linked', array( strpos( $html, 'none are being sent' ) !== false, strpos( $html, 'href="' . WPA11Y_SHARE_URL . '"' ) !== false ), array( true, true ) );
check( 'settings page: current mode checked', strpos( $html, 'value="wpshare" checked' ) !== false, true );
check( 'settings page: radios grouped with a legend', strpos( $html, '<legend class="screen-reader-text">Send notifications to</legend>' ) !== false, true );
check( 'settings page: address labelled', strpos( $html, '<label for="wpa11y-notify-email">' ) !== false, true );
$GLOBALS['wt_listen']['wpshare_notify'] = true;
$html = wpa11y_render_settings( '', array(), '' );
check( 'settings page: active wpshare, no warning', array( strpos( $html, 'none are being sent' ), strpos( $html, 'is active' ) !== false ), array( false, true ) );
