<?php
$req = wpa11y_dispatch_request( 'tfscharff/wpa11y', 'scan.yml', 'main', 'tok', WT_SITE . '/page-10/' );
check( 'request: url', $req['url'], 'https://api.github.com/repos/tfscharff/wpa11y/actions/workflows/scan.yml/dispatches' );
check( 'request: auth', $req['args']['headers']['Authorization'], 'Bearer tok' );
check( 'request: body', json_decode( $req['args']['body'], true ), array( 'ref' => 'main', 'inputs' => array( 'url' => WT_SITE . '/page-10/' ) ) );

wt_reset();
check( 'dispatch: no token', wpa11y_dispatch( 'u' )->get_error_code(), 'wpa11y_no_token' );
check( 'dispatch: no call without token', count( $GLOBALS['wt_http']['calls'] ), 0 );
update_option( 'wpa11y_github_token', 'tok' );
check( 'dispatch: 204 is success', wpa11y_dispatch( 'u' ), true );
check( 'dispatch: default repo and workflow', $GLOBALS['wt_http']['calls'][0]['url'], 'https://api.github.com/repos/tfscharff/wpa11y/actions/workflows/scan.yml/dispatches' );
$GLOBALS['wt_http']['response'] = array( 'response' => array( 'code' => 401 ), 'body' => '{"message":"Bad credentials"}' );
check( 'dispatch: GitHub error is readable', wpa11y_dispatch( 'u' )->get_error_message(), 'Rescan could not start: GitHub answered 401 (Bad credentials).' );
$GLOBALS['wt_http']['response'] = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
check( 'dispatch: transport error is readable', wpa11y_dispatch( 'u' )->get_error_message(), 'Rescan could not start: cURL error 28: timed out' );
check( 'actions url', wpa11y_actions_url(), 'https://github.com/tfscharff/wpa11y/actions/workflows/scan.yml' );

$secret = wpa11y_new_secret();
check( 'secret: 64 hex chars', 1 === preg_match( '/^[0-9a-f]{64}$/', $secret ), true );
check( 'secret: only the hash is stored', get_option( 'wpa11y_secret_hash' ), hash( 'sha256', $secret ) );
check( 'secret: works as bearer', wpa11y_bearer_ok( 'Bearer ' . $secret, get_option( 'wpa11y_secret_hash' ) ), true );
check( 'secret: new one each time', wpa11y_new_secret() !== $secret, true );

$base = array( 'repo' => ' tfscharff/wpa11y ', 'workflow' => 'scan.yml', 'ref' => 'main', 'token' => '' );
list( $out, $errors ) = wpa11y_clean_settings( $base );
check( 'settings: valid', $out, array( 'wpa11y_github_repo' => 'tfscharff/wpa11y', 'wpa11y_github_workflow' => 'scan.yml', 'wpa11y_github_ref' => 'main' ) );
check( 'settings: no errors', $errors, array() );
list( $out, $errors ) = wpa11y_clean_settings( array_merge( $base, array( 'token' => ' github_pat_ABC_123 ' ) ) );
check( 'settings: new token saved', $out['wpa11y_github_token'], 'github_pat_ABC_123' );
list( $out, $errors ) = wpa11y_clean_settings( array_merge( $base, array( 'remove_token' => '1', 'token' => 'x' ) ) );
check( 'settings: remove token wins', $out['wpa11y_github_token'], '' );
list( $out, $errors ) = wpa11y_clean_settings( array_merge( $base, array( 'repo' => 'not a repo', 'workflow' => 'scan.sh', 'ref' => 'a b', 'token' => 'abc def' ) ) );
check( 'settings: bad values not saved', $out, array() );
check( 'settings: one error each', count( $errors ), 4 );

wt_reset();
check( 'notice: both missing, admin', strpos( wpa11y_setup_notice_html( true ), 'admin.php?page=wpa11y-settings' ) !== false, true );
check( 'notice: editor asked to contact admin', strpos( wpa11y_setup_notice_html( false ), 'Ask a site administrator' ) !== false, true );
update_option( 'wpa11y_secret_hash', str_repeat( 'a', 64 ) );
update_option( 'wpa11y_github_token', 'tok' );
check( 'notice: nothing when set up', wpa11y_setup_notice_html( true ), '' );

wt_reset();
$html = wpa11y_render_settings( 'abc123', array( 'Repository must look like owner/name.' ), 'saved' );
check( 'settings page: shows new secret once', strpos( $html, 'value="abc123"' ) !== false, true );
check( 'settings page: secret field labelled', strpos( $html, '<label for="wpa11y-secret">' ) !== false, true );
check( 'settings page: error listed, no success notice', array( strpos( $html, 'Repository must look like' ) !== false, strpos( $html, 'Settings saved.' ) ), array( true, false ) );
check( 'settings page: token never echoed', strpos( wpa11y_render_settings( '', array(), '' ), 'name="token" value' ), false );
check( 'settings page: token field refuses password autofill', strpos( wpa11y_render_settings( '', array(), '' ), 'name="token" autocomplete="new-password"' ) !== false, true );

// The note beside "Replace secret" is about what replacing would do, not a current problem.
wt_reset();
update_option( 'wpa11y_secret_hash', str_repeat( 'a', 64 ) );
$html = wpa11y_render_settings( '', array(), '' );
check( 'settings page: replace note is conditional', strpos( $html, 'If you replace it, the daily scan stops until you paste the new secret into GitHub as WPA11Y_SECRET.' ) !== false, true );
check( 'settings page: no alarming status-like note', strpos( $html, 'fails until GitHub has the new secret' ), false );
