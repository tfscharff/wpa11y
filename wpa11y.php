<?php
/**
 * Plugin Name:       wpa11y
 * Description:       Site accessibility dashboard for editors. A daily axe scan on GitHub Actions reports every published page; editors drill into issues, review and dismiss warnings, and rescan a page.
 * Version:           1.2.2
 * Author:            Madeleine Clark Wallace Library
 * License:           GPL-2.0+
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'WPA11Y_VERSION', '1.2.2' );
define( 'WPA11Y_META', '_wpa11y_result' );
define( 'WPA11Y_RESCAN_META', '_wpa11y_rescan_requested' );
define( 'WPA11Y_CAP', 'edit_pages' );
define( 'WPA11Y_MAX_ISSUES', 1000 );
define( 'WPA11Y_RESCAN_TIMEOUT', 600 );

/* ------------------------------------------------------------------ *
 *  Core helpers
 * ------------------------------------------------------------------ */

function wpa11y_opt( $name ) {
	$defaults = array(
		'wpa11y_github_repo'     => 'tfscharff/wpa11y',
		'wpa11y_github_workflow' => 'scan.yml',
		'wpa11y_github_ref'      => 'main',
	);
	$v = get_option( $name, $defaults[ $name ] ?? '' );
	return is_string( $v ) ? $v : '';
}

// Cuts by character, not byte, so multibyte text is never split mid-character.
function wpa11y_clip( $value, $max ) {
	$s = is_scalar( $value ) ? (string) $value : '';
	if ( function_exists( 'mb_substr' ) ) { return mb_substr( $s, 0, $max, 'UTF-8' ); }
	return implode( '', array_slice( preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY ), 0, $max ) );
}

function wpa11y_iso( $ts ) { return gmdate( 'Y-m-d\TH:i:s\Z', $ts ); }

// Accepts stored ISO times and the table's UTC 'Y-m-d H:i:s'.
function wpa11y_format_time( $when ) {
	$when = (string) $when;
	if ( '' === $when ) { return ''; }
	$ts = strtotime( false === strpos( $when, 'T' ) ? $when . ' UTC' : $when );
	return $ts ? wp_date( 'M j, Y g:i a', $ts ) : '';
}

// Same rules as normalizeUrl() in scanner/lib.mjs.
function wpa11y_normalize_url( $url ) {
	$p = wp_parse_url( (string) $url );
	if ( empty( $p['scheme'] ) || empty( $p['host'] ) ) { return ''; }
	$path = isset( $p['path'] ) && '' !== $p['path'] ? $p['path'] : '/';
	if ( '/' !== substr( $path, -1 ) ) { $path .= '/'; }
	$port  = isset( $p['port'] ) ? ':' . $p['port'] : '';
	$query = isset( $p['query'] ) ? '?' . $p['query'] : '';
	return strtolower( $p['scheme'] ) . '://' . strtolower( $p['host'] ) . $port . $path . $query;
}

// No stored hash (no secret generated yet) never matches anything.
function wpa11y_bearer_ok( $header, $stored_hash ) {
	if ( ! is_string( $stored_hash ) || 64 !== strlen( $stored_hash ) ) { return false; }
	if ( ! preg_match( '/^Bearer\s+(\S+)$/i', trim( (string) $header ), $m ) ) { return false; }
	return hash_equals( $stored_hash, hash( 'sha256', $m[1] ) );
}

function wpa11y_post_types() {
	$types = array();
	foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
		if ( 'attachment' !== $type && is_post_type_viewable( $type ) ) { $types[] = $type; }
	}
	return $types;
}

function wpa11y_scannable_posts() {
	return get_posts( array(
		'post_type'        => wpa11y_post_types(),
		'post_status'      => 'publish',
		'has_password'     => false,
		'numberposts'      => -1,
		'orderby'          => 'title',
		'order'            => 'ASC',
		'suppress_filters' => false,
	) );
}

function wpa11y_scannable_post( $post_id ) {
	$post = $post_id > 0 ? get_post( $post_id ) : null;
	if ( ! $post || 'publish' !== $post->post_status || '' !== (string) $post->post_password
		|| ! in_array( $post->post_type, wpa11y_post_types(), true ) ) {
		return new WP_Error( 'wpa11y_bad_post', __( 'That is not a published page the scanner checks.', 'wpa11y' ), array( 'status' => 404 ) );
	}
	return $post;
}

function wpa11y_title( $post ) {
	$t = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );
	return '' === $t ? __( '(no title)', 'wpa11y' ) : $t;
}

/* ------------------------------------------------------------------ *
 *  Scan results (post meta, latest only)
 * ------------------------------------------------------------------ */

function wpa11y_bad_request( $code, $message ) {
	return new WP_Error( $code, $message, array( 'status' => 400 ) );
}

// Validates one page's result from the scanner. Success: {scanned_at, url, issues}.
// Failure report: {scanned_at, url, error}.
function wpa11y_clean_result( $body, $permalink ) {
	if ( ! is_array( $body ) ) {
		return wpa11y_bad_request( 'wpa11y_bad_body', 'Body must be a JSON object.' );
	}
	$url = isset( $body['url'] ) ? (string) $body['url'] : '';
	if ( '' === $url || wpa11y_normalize_url( $url ) !== wpa11y_normalize_url( $permalink ) ) {
		return wpa11y_bad_request( 'wpa11y_url_mismatch', 'url does not match the post permalink.' );
	}
	$ts = isset( $body['scanned_at'] ) ? strtotime( (string) $body['scanned_at'] ) : false;
	if ( false === $ts ) {
		return wpa11y_bad_request( 'wpa11y_bad_time', 'scanned_at must be an ISO 8601 time.' );
	}
	$out = array( 'scanned_at' => wpa11y_iso( $ts ), 'url' => $url );
	if ( isset( $body['error'] ) && '' !== trim( (string) $body['error'] ) ) {
		$out['error'] = wpa11y_clip( $body['error'], 500 );
		return $out;
	}
	if ( ! isset( $body['issues'] ) || ! is_array( $body['issues'] ) || count( $body['issues'] ) > WPA11Y_MAX_ISSUES ) {
		return wpa11y_bad_request( 'wpa11y_bad_issues', sprintf( 'issues must be a list of at most %d items.', WPA11Y_MAX_ISSUES ) );
	}
	$issues = array();
	foreach ( $body['issues'] as $i ) {
		if ( ! is_array( $i ) || ! isset( $i['type'] ) || ! in_array( $i['type'], array( 'error', 'warning' ), true ) || empty( $i['code'] ) ) {
			return wpa11y_bad_request( 'wpa11y_bad_issue', 'Each issue needs type "error" or "warning" and a code.' );
		}
		$help     = wpa11y_clip( $i['help_url'] ?? '', 500 );
		$issues[] = array(
			'type'     => $i['type'],
			'code'     => wpa11y_clip( $i['code'], 100 ),
			'message'  => wpa11y_clip( $i['message'] ?? '', 500 ),
			'selector' => wpa11y_clip( $i['selector'] ?? '', 1000 ),
			'context'  => wpa11y_clip( $i['context'] ?? '', 2000 ),
			'help_url' => preg_match( '#^https://#', $help ) ? $help : '',
			'text'     => wpa11y_clip( $i['text'] ?? '', 300 ),
		);
	}
	$out['issues'] = $issues;
	return $out;
}

// A failed scan keeps the last good issues (shown as stale) and records the failure.
function wpa11y_merge_result( $previous, $clean ) {
	if ( isset( $clean['error'] ) ) {
		$r = is_array( $previous ) ? $previous : array( 'scanned_at' => null, 'url' => $clean['url'], 'issues' => array() );
		$r['error']    = $clean['error'];
		$r['error_at'] = $clean['scanned_at'];
		return $r;
	}
	return array(
		'scanned_at' => $clean['scanned_at'],
		'url'        => $clean['url'],
		'issues'     => $clean['issues'],
		'error'      => null,
		'error_at'   => null,
	);
}

function wpa11y_get_result( $post_id ) {
	$raw = get_post_meta( $post_id, WPA11Y_META, true );
	$r   = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
	return is_array( $r ) ? $r : null;
}

// update_post_meta() unslashes, so slash first or backslashes in HTML snippets are lost.
function wpa11y_save_result( $post_id, $result ) {
	update_post_meta( $post_id, WPA11Y_META, wp_slash( wp_json_encode( $result ) ) );
}

/* ------------------------------------------------------------------ *
 *  Dismissals (one table; rows are never deleted)
 * ------------------------------------------------------------------ */

function wpa11y_table() {
	global $wpdb;
	return $wpdb->prefix . 'wpa11y_dismissals';
}

function wpa11y_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( 'CREATE TABLE ' . wpa11y_table() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL,
  code varchar(100) NOT NULL,
  selector text NOT NULL,
  note text NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  undone_by bigint(20) unsigned DEFAULT NULL,
  undone_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY post_id (post_id)
) " . $wpdb->get_charset_collate() . ';' );
	update_option( 'wpa11y_db_version', WPA11Y_VERSION );
}
register_activation_hook( __FILE__, 'wpa11y_install' );

// "Upload → Replace current" skips the activation hook, so also check on load.
add_action( 'plugins_loaded', function () {
	if ( get_option( 'wpa11y_db_version' ) !== WPA11Y_VERSION ) { wpa11y_install(); }
} );

// Dismissals are few, so every row is read once per request and filtered in PHP.
function wpa11y_rows( $refresh = false ) {
	static $rows = null;
	if ( null === $rows || $refresh ) {
		global $wpdb;
		$r    = $wpdb->get_results( 'SELECT * FROM ' . wpa11y_table() . ' ORDER BY id DESC', ARRAY_A );
		$rows = is_array( $r ) ? $r : array();
	}
	return $rows;
}

function wpa11y_active_rows( $rows, $post_id = null ) {
	return array_values( array_filter( $rows, function ( $r ) use ( $post_id ) {
		return empty( $r['undone_at'] ) && ( null === $post_id || (int) $r['post_id'] === (int) $post_id );
	} ) );
}

function wpa11y_find_row( $rows, $id ) {
	foreach ( $rows as $r ) {
		if ( (int) $r['id'] === (int) $id ) { return $r; }
	}
	return null;
}

function wpa11y_add_dismissal( $post_id, $code, $selector, $note, $user_id ) {
	global $wpdb;
	foreach ( wpa11y_active_rows( wpa11y_rows( true ), $post_id ) as $r ) {
		if ( $r['code'] === $code && $r['selector'] === $selector ) { return (int) $r['id']; }
	}
	$wpdb->insert(
		wpa11y_table(),
		array(
			'post_id'    => (int) $post_id,
			'code'       => $code,
			'selector'   => $selector,
			'note'       => $note,
			'user_id'    => (int) $user_id,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		),
		array( '%d', '%s', '%s', '%s', '%d', '%s' )
	);
	$id = (int) $wpdb->insert_id;
	wpa11y_rows( true );
	return $id;
}

function wpa11y_undo_dismissal( $id, $user_id ) {
	global $wpdb;
	$row = wpa11y_find_row( wpa11y_rows( true ), $id );
	if ( ! $row || ! empty( $row['undone_at'] ) ) { return false; }
	$wpdb->update(
		wpa11y_table(),
		array( 'undone_by' => (int) $user_id, 'undone_at' => gmdate( 'Y-m-d H:i:s' ) ),
		array( 'id' => (int) $id ),
		array( '%d', '%s' ),
		array( '%d' )
	);
	wpa11y_rows( true );
	return (int) $row['post_id'];
}

// Restoring writes a new row (by the restorer, same note) so the log keeps both.
function wpa11y_restore_dismissal( $id, $user_id ) {
	$row = wpa11y_find_row( wpa11y_rows( true ), $id );
	if ( ! $row || empty( $row['undone_at'] ) ) { return false; }
	return wpa11y_add_dismissal( (int) $row['post_id'], $row['code'], $row['selector'], $row['note'], $user_id );
}

/* ------------------------------------------------------------------ *
 *  Sorting issues for display
 * ------------------------------------------------------------------ */

function wpa11y_issue_key( $code, $selector ) { return md5( $code . "\n" . $selector ); }

function wpa11y_find_issue( $issues, $key ) {
	foreach ( $issues as $i ) {
		if ( wpa11y_issue_key( $i['code'], $i['selector'] ) === $key ) { return $i; }
	}
	return null;
}

// Errors always stay errors. A warning is dismissed while an active row has the
// same code and selector; if the element changes, the warning comes back.
function wpa11y_split( $issues, $active_rows ) {
	$by_key = array();
	foreach ( $active_rows as $r ) { $by_key[ wpa11y_issue_key( $r['code'], $r['selector'] ) ] = $r; }
	$out = array( 'errors' => array(), 'warnings' => array(), 'dismissed' => array() );
	foreach ( $issues as $i ) {
		if ( 'error' === $i['type'] ) {
			$out['errors'][] = $i;
			continue;
		}
		$key = wpa11y_issue_key( $i['code'], $i['selector'] );
		if ( isset( $by_key[ $key ] ) ) {
			$i['dismissal']     = $by_key[ $key ];
			$out['dismissed'][] = $i;
		} else {
			$out['warnings'][] = $i;
		}
	}
	return $out;
}

function wpa11y_group( $issues ) {
	$groups = array();
	foreach ( $issues as $i ) { $groups[ $i['code'] ][] = $i; }
	return $groups;
}

function wpa11y_counts( $result, $active_rows ) {
	if ( ! is_array( $result ) ) {
		return array( 'errors' => 0, 'warnings' => 0, 'dismissed' => 0, 'state' => 'unscanned' );
	}
	$s = wpa11y_split( $result['issues'] ?? array(), $active_rows );
	$c = array( 'errors' => count( $s['errors'] ), 'warnings' => count( $s['warnings'] ), 'dismissed' => count( $s['dismissed'] ) );
	if ( ! empty( $result['error'] ) ) {
		$c['state'] = 'failed';
	} elseif ( 0 === $c['errors'] + $c['warnings'] ) {
		$c['state'] = 'clean';
	} else {
		$c['state'] = 'issues';
	}
	return $c;
}

// Both times are wpa11y_iso() strings, which sort correctly as text.
function wpa11y_attempted_at( $result ) {
	if ( ! is_array( $result ) ) { return ''; }
	$times = array_filter( array( (string) ( $result['scanned_at'] ?? '' ), (string) ( $result['error_at'] ?? '' ) ) );
	return $times ? max( $times ) : '';
}

function wpa11y_rescan_state( $requested, $attempted, $now ) {
	if ( '' === (string) $requested ) { return 'idle'; }
	$req = strtotime( $requested );
	if ( '' !== (string) $attempted && strtotime( $attempted ) >= $req ) { return 'idle'; }
	return ( $now - $req ) < WPA11Y_RESCAN_TIMEOUT ? 'pending' : 'timed_out';
}

/* ------------------------------------------------------------------ *
 *  REST API
 * ------------------------------------------------------------------ */

add_action( 'rest_api_init', 'wpa11y_register_routes' );

function wpa11y_register_routes() {
	// path, method, callback, permission
	$routes = array(
		array( '/pages', 'GET', 'wpa11y_rest_pages', 'wpa11y_scanner_permission' ),
		array( '/results', 'POST', 'wpa11y_rest_results', 'wpa11y_scanner_permission' ),
		array( '/scan-complete', 'POST', 'wpa11y_rest_scan_complete', 'wpa11y_scanner_permission' ),
		array( '/status', 'GET', 'wpa11y_rest_status', 'wpa11y_editor_permission' ),
		array( '/dismiss', 'POST', 'wpa11y_rest_dismiss', 'wpa11y_editor_permission' ),
		array( '/undismiss', 'POST', 'wpa11y_rest_undismiss', 'wpa11y_editor_permission' ),
		array( '/rescan', 'POST', 'wpa11y_rest_rescan', 'wpa11y_editor_permission' ),
	);
	foreach ( $routes as $r ) {
		register_rest_route( 'wpa11y/v1', $r[0], array(
			'methods'             => $r[1],
			'callback'            => $r[2],
			'permission_callback' => $r[3],
		) );
	}
}

function wpa11y_scanner_permission( $request ) {
	if ( wpa11y_bearer_ok( $request->get_header( 'authorization' ), wpa11y_opt( 'wpa11y_secret_hash' ) ) ) { return true; }
	return new WP_Error( 'wpa11y_unauthorized', 'Missing or wrong scanner secret.', array( 'status' => 401 ) );
}

// Cookie-authenticated requests without a valid X-WP-Nonce run as logged out, so this also enforces the nonce.
function wpa11y_editor_permission() {
	return current_user_can( WPA11Y_CAP );
}

function wpa11y_rest_pages() {
	$out = array();
	foreach ( wpa11y_scannable_posts() as $post ) {
		$out[] = array(
			'id'    => (int) $post->ID,
			'url'   => get_permalink( $post ),
			'type'  => $post->post_type,
			'title' => wpa11y_title( $post ),
		);
	}
	return $out;
}

function wpa11y_rest_results( $request ) {
	$body = $request->get_json_params();
	$post = wpa11y_scannable_post( is_array( $body ) && isset( $body['post_id'] ) ? (int) $body['post_id'] : 0 );
	if ( is_wp_error( $post ) ) { return $post; }
	$clean = wpa11y_clean_result( $body, get_permalink( $post ) );
	if ( is_wp_error( $clean ) ) { return $clean; }
	wpa11y_save_result( $post->ID, wpa11y_merge_result( wpa11y_get_result( $post->ID ), $clean ) );
	return array( 'ok' => true );
}

function wpa11y_rest_scan_complete( $request ) {
	update_option( 'wpa11y_last_full_scan', wpa11y_iso( time() ), false );
	return array( 'ok' => true );
}

/* ------------------------------------------------------------------ *
 *  GitHub (rescan)
 * ------------------------------------------------------------------ */

function wpa11y_dispatch_request( $repo, $workflow, $ref, $token, $url ) {
	return array(
		'url'  => 'https://api.github.com/repos/' . $repo . '/actions/workflows/' . rawurlencode( $workflow ) . '/dispatches',
		'args' => array(
			'headers' => array(
				'Accept'               => 'application/vnd.github+json',
				'Authorization'        => 'Bearer ' . $token,
				'X-GitHub-Api-Version' => '2022-11-28',
				'User-Agent'           => 'wpa11y',
				'Content-Type'         => 'application/json',
			),
			'body'    => wp_json_encode( array( 'ref' => $ref, 'inputs' => array( 'url' => $url ) ) ),
			'timeout' => 15,
		),
	);
}

function wpa11y_dispatch( $url ) {
	$token = wpa11y_opt( 'wpa11y_github_token' );
	if ( '' === $token ) {
		return new WP_Error( 'wpa11y_no_token', __( 'Rescan is not set up yet: an administrator needs to add a GitHub token under Accessibility → Settings.', 'wpa11y' ), array( 'status' => 400 ) );
	}
	$req = wpa11y_dispatch_request( wpa11y_opt( 'wpa11y_github_repo' ), wpa11y_opt( 'wpa11y_github_workflow' ), wpa11y_opt( 'wpa11y_github_ref' ), $token, $url );
	$res = wp_remote_post( $req['url'], $req['args'] );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'wpa11y_github', sprintf( __( 'Rescan could not start: %s', 'wpa11y' ), $res->get_error_message() ), array( 'status' => 502 ) );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( 204 !== $code ) {
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$why  = is_array( $body ) && isset( $body['message'] ) ? (string) $body['message'] : '';
		return new WP_Error( 'wpa11y_github', sprintf( __( 'Rescan could not start: GitHub answered %1$d (%2$s).', 'wpa11y' ), $code, $why ), array( 'status' => 502 ) );
	}
	return true;
}

function wpa11y_actions_url() {
	return 'https://github.com/' . wpa11y_opt( 'wpa11y_github_repo' ) . '/actions/workflows/' . wpa11y_opt( 'wpa11y_github_workflow' );
}

/* ------------------------------------------------------------------ *
 *  Settings
 * ------------------------------------------------------------------ */

// Only the hash is kept; the secret itself is shown once, then lives only in GitHub.
function wpa11y_new_secret() {
	$secret = bin2hex( random_bytes( 32 ) );
	update_option( 'wpa11y_secret_hash', hash( 'sha256', $secret ), false );
	update_option( 'wpa11y_secret_created', wpa11y_iso( time() ), false );
	return $secret;
}

// Returns array( option => value to save, list of error messages ). A blank token keeps the saved one.
function wpa11y_clean_settings( $input ) {
	$out    = array();
	$errors = array();
	$rules  = array(
		'repo'     => array( 'wpa11y_github_repo', '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', __( 'Repository must look like owner/name.', 'wpa11y' ) ),
		'workflow' => array( 'wpa11y_github_workflow', '/^[A-Za-z0-9_.-]+\.ya?ml$/', __( 'Workflow must be a file name like scan.yml.', 'wpa11y' ) ),
		'ref'      => array( 'wpa11y_github_ref', '#^[A-Za-z0-9_./-]+$#', __( 'Branch must be a branch name like main.', 'wpa11y' ) ),
	);
	foreach ( $rules as $field => $rule ) {
		$v = trim( (string) ( $input[ $field ] ?? '' ) );
		if ( preg_match( $rule[1], $v ) ) {
			$out[ $rule[0] ] = $v;
		} else {
			$errors[] = $rule[2];
		}
	}
	$token = trim( (string) ( $input['token'] ?? '' ) );
	if ( ! empty( $input['remove_token'] ) ) {
		$out['wpa11y_github_token'] = '';
	} elseif ( '' !== $token ) {
		if ( preg_match( '/^[A-Za-z0-9_]+$/', $token ) ) {
			$out['wpa11y_github_token'] = $token;
		} else {
			$errors[] = __( 'That token has unexpected characters. Paste it again.', 'wpa11y' );
		}
	}
	return array( $out, $errors );
}

function wpa11y_setup_notice_html( $is_admin ) {
	$missing = array();
	if ( '' === wpa11y_opt( 'wpa11y_secret_hash' ) ) { $missing[] = __( 'the scanner secret, so no results can arrive', 'wpa11y' ); }
	if ( '' === wpa11y_opt( 'wpa11y_github_token' ) ) { $missing[] = __( 'a GitHub token, so Rescan is off', 'wpa11y' ); }
	if ( ! $missing ) { return ''; }
	$fix = $is_admin
		? '<a href="' . esc_url( admin_url( 'admin.php?page=wpa11y-settings' ) ) . '">' . esc_html__( 'Finish setup', 'wpa11y' ) . '</a>'
		: esc_html__( 'Ask a site administrator to finish setup.', 'wpa11y' );
	return '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'Accessibility checks are not fully set up. Missing: %s.', 'wpa11y' ), implode( '; ', $missing ) ) ) . ' ' . $fix . '</p></div>';
}

add_action( 'admin_notices', function () {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || false === strpos( $screen->id, 'wpa11y' ) || false !== strpos( $screen->id, 'wpa11y-settings' ) ) { return; }
	echo wpa11y_setup_notice_html( current_user_can( 'manage_options' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
} );

add_action( 'admin_menu', 'wpa11y_admin_menu' );
function wpa11y_admin_menu() {
	add_menu_page( __( 'Accessibility', 'wpa11y' ), __( 'Accessibility', 'wpa11y' ), WPA11Y_CAP, 'wpa11y', 'wpa11y_page_overview', 'dashicons-universal-access-alt', 26 );
	add_submenu_page( 'wpa11y', __( 'Accessibility overview', 'wpa11y' ), __( 'Overview', 'wpa11y' ), WPA11Y_CAP, 'wpa11y', 'wpa11y_page_overview' );
	add_submenu_page( 'wpa11y', __( 'Dismissal log', 'wpa11y' ), __( 'Dismissal log', 'wpa11y' ), WPA11Y_CAP, 'wpa11y-log', 'wpa11y_page_log' );
	add_submenu_page( 'wpa11y', __( 'Accessibility settings', 'wpa11y' ), __( 'Settings', 'wpa11y' ), 'manage_options', 'wpa11y-settings', 'wpa11y_page_settings' );
}

function wpa11y_page_settings() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$uid    = get_current_user_id();
	$secret = get_transient( 'wpa11y_new_secret_' . $uid );
	delete_transient( 'wpa11y_new_secret_' . $uid );
	$errors = get_transient( 'wpa11y_settings_errors_' . $uid );
	delete_transient( 'wpa11y_settings_errors_' . $uid );
	$msg = isset( $_GET['wpa11y_msg'] ) ? sanitize_key( wp_unslash( $_GET['wpa11y_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
	echo wpa11y_render_settings( is_string( $secret ) ? $secret : '', is_array( $errors ) ? $errors : array(), $msg ); // phpcs:ignore WordPress.Security.EscapeOutput
}

function wpa11y_render_settings( $new_secret, $errors, $msg ) {
	$post_url  = esc_url( admin_url( 'admin-post.php' ) );
	$has_hash  = '' !== wpa11y_opt( 'wpa11y_secret_hash' );
	$has_token = '' !== wpa11y_opt( 'wpa11y_github_token' );

	$h = '<div class="wrap wpa11y"><h1>' . esc_html__( 'Accessibility settings', 'wpa11y' ) . '</h1>';
	if ( 'saved' === $msg && ! $errors ) {
		$h .= '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'wpa11y' ) . '</p></div>';
	}
	if ( $errors ) {
		$h .= '<div class="notice notice-error"><p>' . esc_html__( 'Some settings were not saved:', 'wpa11y' ) . '</p><ul>';
		foreach ( $errors as $e ) { $h .= '<li>' . esc_html( $e ) . '</li>'; }
		$h .= '</ul></div>';
	}

	$h .= '<h2>' . esc_html__( 'Scanner secret', 'wpa11y' ) . '</h2>';
	if ( '' !== $new_secret ) {
		$h .= '<div class="notice notice-warning inline"><p><label for="wpa11y-secret"><strong>' . esc_html__( 'New scanner secret.', 'wpa11y' ) . '</strong> '
			. esc_html__( 'Copy it now into GitHub (repository Settings → Secrets and variables → Actions) as WPA11Y_SECRET. It will not be shown again.', 'wpa11y' )
			. '</label></p><p><input type="text" id="wpa11y-secret" class="large-text code" readonly value="' . esc_attr( $new_secret ) . '"></p></div>';
	}
	$h .= '<p>' . ( $has_hash
		? esc_html( sprintf( __( 'A secret is set (created %s).', 'wpa11y' ), wpa11y_format_time( wpa11y_opt( 'wpa11y_secret_created' ) ) ) )
		: esc_html__( 'No secret yet. The scanner cannot send results until you generate one.', 'wpa11y' ) ) . '</p>';
	$h .= '<p>' . esc_html__( 'Also add a GitHub secret named WPA11Y_SITE with this value:', 'wpa11y' ) . ' <code>' . esc_html( home_url() ) . '</code></p>';
	$h .= '<form method="post" action="' . $post_url . '"><input type="hidden" name="action" value="wpa11y_secret">' . wp_nonce_field( 'wpa11y_secret', '_wpnonce', true, false )
		. '<button type="submit" class="button">' . ( $has_hash ? esc_html__( 'Replace secret', 'wpa11y' ) : esc_html__( 'Generate secret', 'wpa11y' ) ) . '</button>'
		. ( $has_hash ? ' <span class="description">' . esc_html__( 'If you replace it, the daily scan stops until you paste the new secret into GitHub as WPA11Y_SECRET.', 'wpa11y' ) . '</span>' : '' ) . '</form>';

	$h .= '<h2>' . esc_html__( 'Rescan (GitHub)', 'wpa11y' ) . '</h2>';
	$h .= '<p>' . esc_html__( 'Rescan starts the scan workflow on GitHub. It needs a fine-grained personal access token that can only access this repository, with the permission "Actions: Read and write".', 'wpa11y' ) . '</p>';
	$h .= '<form method="post" action="' . $post_url . '"><input type="hidden" name="action" value="wpa11y_settings">' . wp_nonce_field( 'wpa11y_settings', '_wpnonce', true, false );
	$h .= '<table class="form-table" role="presentation">';
	$fields = array(
		'repo'     => array( __( 'Repository', 'wpa11y' ), wpa11y_opt( 'wpa11y_github_repo' ) ),
		'workflow' => array( __( 'Workflow file', 'wpa11y' ), wpa11y_opt( 'wpa11y_github_workflow' ) ),
		'ref'      => array( __( 'Branch', 'wpa11y' ), wpa11y_opt( 'wpa11y_github_ref' ) ),
	);
	foreach ( $fields as $name => $f ) {
		$h .= '<tr><th scope="row"><label for="wpa11y-' . $name . '">' . esc_html( $f[0] ) . '</label></th><td><input type="text" class="regular-text" id="wpa11y-' . $name . '" name="' . $name . '" value="' . esc_attr( $f[1] ) . '"></td></tr>';
	}
	$h .= '<tr><th scope="row"><label for="wpa11y-token">' . esc_html__( 'GitHub token', 'wpa11y' ) . '</label></th><td>'
		. '<input type="password" class="regular-text" id="wpa11y-token" name="token" autocomplete="new-password" aria-describedby="wpa11y-token-help">'
		. '<p class="description" id="wpa11y-token-help">' . ( $has_token ? esc_html__( 'A token is saved. Leave blank to keep it.', 'wpa11y' ) : esc_html__( 'No token saved; Rescan is off.', 'wpa11y' ) ) . '</p>';
	if ( $has_token ) {
		$h .= '<p><label><input type="checkbox" name="remove_token" value="1"> ' . esc_html__( 'Remove the saved token', 'wpa11y' ) . '</label></p>';
	}
	$h .= '</td></tr></table><p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save settings', 'wpa11y' ) . '</button></p></form></div>';
	return $h;
}

add_action( 'admin_post_wpa11y_settings', function () {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wpa11y' ), 403 ); }
	check_admin_referer( 'wpa11y_settings' );
	list( $out, $errors ) = wpa11y_clean_settings( wp_unslash( $_POST ) );
	foreach ( $out as $name => $value ) { update_option( $name, $value, false ); }
	if ( $errors ) { set_transient( 'wpa11y_settings_errors_' . get_current_user_id(), $errors, 5 * MINUTE_IN_SECONDS ); }
	wp_safe_redirect( admin_url( 'admin.php?page=wpa11y-settings&wpa11y_msg=saved' ) );
	exit;
} );

add_action( 'admin_post_wpa11y_secret', function () {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wpa11y' ), 403 ); }
	check_admin_referer( 'wpa11y_secret' );
	set_transient( 'wpa11y_new_secret_' . get_current_user_id(), wpa11y_new_secret(), 5 * MINUTE_IN_SECONDS );
	wp_safe_redirect( admin_url( 'admin.php?page=wpa11y-settings' ) );
	exit;
} );

/* ------------------------------------------------------------------ *
 *  Page detail
 * ------------------------------------------------------------------ */

function wpa11y_detail_url( $post_id ) {
	return admin_url( 'admin.php?page=wpa11y&post=' . (int) $post_id );
}

function wpa11y_detail_state( $post_id, $now = null ) {
	$result    = wpa11y_get_result( $post_id );
	$requested = (string) get_post_meta( $post_id, WPA11Y_RESCAN_META, true );
	return array(
		'result'    => $result,
		'requested' => $requested,
		'rescan'    => wpa11y_rescan_state( $requested, wpa11y_attempted_at( $result ), null === $now ? time() : $now ),
	);
}

function wpa11y_summary( $result, $c ) {
	if ( 'unscanned' === $c['state'] ) { return __( 'Not scanned yet.', 'wpa11y' ); }
	$text = sprintf(
		'%1$s, %2$s.',
		sprintf( _n( '%d error', '%d errors', $c['errors'], 'wpa11y' ), $c['errors'] ),
		sprintf( _n( '%d warning needing review', '%d warnings needing review', $c['warnings'], 'wpa11y' ), $c['warnings'] )
	);
	if ( 'failed' === $c['state'] ) {
		$text = sprintf( __( 'Latest scan failed: %s', 'wpa11y' ), $result['error'] ) . ' ' . $text;
	}
	return $text;
}

function wpa11y_status_html( $state ) {
	$r     = $state['result'];
	$parts = array();
	if ( ! $r ) {
		$parts[] = esc_html__( 'Not scanned yet. The daily scan will include this page, or use Rescan.', 'wpa11y' );
	} else {
		if ( ! empty( $r['scanned_at'] ) ) {
			$parts[] = esc_html( sprintf( __( 'Last scanned %s.', 'wpa11y' ), wpa11y_format_time( $r['scanned_at'] ) ) );
		}
		if ( ! empty( $r['error'] ) ) {
			$parts[] = '<strong>' . esc_html( sprintf( __( 'The scan on %1$s failed: %2$s', 'wpa11y' ), wpa11y_format_time( $r['error_at'] ), $r['error'] ) ) . '</strong>';
			if ( ! empty( $r['scanned_at'] ) ) {
				$parts[] = esc_html__( 'The issues below are from the last successful scan.', 'wpa11y' );
			}
		}
	}
	if ( 'pending' === $state['rescan'] ) {
		$parts[] = esc_html( sprintf( __( 'Rescan requested %s; results usually arrive in 1–2 minutes.', 'wpa11y' ), wpa11y_format_time( $state['requested'] ) ) );
	} elseif ( 'timed_out' === $state['rescan'] ) {
		$parts[] = esc_html( sprintf( __( 'The rescan requested %s has not reported back.', 'wpa11y' ), wpa11y_format_time( $state['requested'] ) ) )
			. ' <a href="' . esc_url( wpa11y_actions_url() ) . '">' . esc_html__( 'Check the scan runs on GitHub', 'wpa11y' ) . '</a>.';
	}
	return implode( ' ', $parts );
}

function wpa11y_render_instance( $section, $i, $edit_url = '' ) {
	$key = wpa11y_issue_key( $i['code'], $i['selector'] );
	$fid = 'wpa11y-f-' . substr( $key, 0, 12 );
	$h   = '<li class="wpa11y-issue" data-key="' . esc_attr( $key ) . '">';
	$h  .= '<p><span class="wpa11y-label">' . esc_html__( 'Element:', 'wpa11y' ) . '</span> <code>' . esc_html( $i['selector'] ) . '</code></p>';
	if ( '' !== $i['context'] ) {
		$h .= '<pre class="wpa11y-context"><code>' . esc_html( $i['context'] ) . '</code></pre>';
	}
	if ( '' !== $edit_url && 'dismissed' !== $section ) {
		$h .= '<p><a class="button wpa11y-show-in-editor" href="' . esc_url( add_query_arg( array( 'wpa11y_find' => $key ), $edit_url ) ) . '">' . esc_html__( 'Show in editor', 'wpa11y' )
			. '<span class="screen-reader-text">' . esc_html( ': ' . $i['code'] . ', ' . $i['selector'] ) . '</span></a></p>';
	}
	if ( 'warnings' === $section ) {
		$h .= '<button type="button" class="button wpa11y-dismiss-open" aria-expanded="false" aria-controls="' . esc_attr( $fid ) . '">' . esc_html__( 'Dismiss…', 'wpa11y' ) . '</button>';
		$h .= '<div class="wpa11y-dismiss-form" id="' . esc_attr( $fid ) . '" hidden>';
		$h .= '<label for="' . esc_attr( $fid ) . '-note">' . esc_html__( 'Why is this OK? (optional note for the log)', 'wpa11y' ) . '</label>';
		$h .= '<textarea id="' . esc_attr( $fid ) . '-note" rows="2"></textarea>';
		$h .= '<button type="button" class="button button-primary wpa11y-dismiss-confirm">' . esc_html__( 'Confirm dismiss', 'wpa11y' ) . '</button> ';
		$h .= '<button type="button" class="button wpa11y-dismiss-cancel">' . esc_html__( 'Cancel', 'wpa11y' ) . '</button>';
		$h .= '</div>';
	}
	if ( 'dismissed' === $section ) {
		$d    = $i['dismissal'];
		$user = get_userdata( (int) $d['user_id'] );
		$who  = sprintf( __( 'Dismissed by %1$s on %2$s.', 'wpa11y' ), $user ? $user->display_name : sprintf( __( 'user #%d', 'wpa11y' ), (int) $d['user_id'] ), wpa11y_format_time( $d['created_at'] ) );
		if ( '' !== (string) $d['note'] ) { $who .= ' ' . sprintf( __( 'Note: %s', 'wpa11y' ), $d['note'] ); }
		$h .= '<p class="wpa11y-dismissed-by">' . esc_html( $who ) . '</p>';
		$h .= '<button type="button" class="button wpa11y-undo" data-id="' . (int) $d['id'] . '">' . esc_html__( 'Undo dismiss', 'wpa11y' ) . '</button>';
	}
	return $h . '</li>';
}

function wpa11y_render_section( $section, $label, $intro, $issues, $empty, $edit_url = '' ) {
	$h = '<h2 id="wpa11y-h-' . $section . '" tabindex="-1">' . esc_html( $label ) . ' <span class="wpa11y-count">(' . count( $issues ) . ')</span></h2>';
	if ( ! $issues ) { return $h . '<p>' . esc_html( $empty ) . '</p>'; }
	if ( '' !== $intro ) { $h .= '<p class="description">' . esc_html( $intro ) . '</p>'; }
	foreach ( wpa11y_group( $issues ) as $code => $list ) {
		$first = $list[0];
		$h    .= '<details class="wpa11y-group" id="' . esc_attr( 'wpa11y-g-' . $section . '-' . sanitize_html_class( $code ) ) . '">';
		$h    .= '<summary><span class="wpa11y-msg">' . esc_html( $first['message'] ) . '</span> <code>' . esc_html( $code ) . '</code> <span class="wpa11y-count">(' . count( $list ) . ')</span></summary>';
		if ( '' !== $first['help_url'] ) {
			$h .= '<p><a href="' . esc_url( $first['help_url'] ) . '">' . esc_html( sprintf( __( 'How to fix %s (Deque University)', 'wpa11y' ), $code ) ) . '</a></p>';
		}
		$h .= '<ol class="wpa11y-instances">';
		foreach ( $list as $i ) { $h .= wpa11y_render_instance( $section, $i, $edit_url ); }
		$h .= '</ol></details>';
	}
	return $h;
}

function wpa11y_render_detail( $post_id ) {
	$post   = get_post( $post_id );
	$state  = wpa11y_detail_state( $post_id );
	$result = $state['result'];
	$split  = wpa11y_split( $result ? $result['issues'] : array(), wpa11y_active_rows( wpa11y_rows(), $post_id ) );
	$busy   = 'pending' === $state['rescan'];

	// Null when this user can't edit the page: then no Edit or Show in editor links.
	$edit = (string) get_edit_post_link( $post_id, 'raw' );

	$h  = '<p class="wpa11y-links"><a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html__( 'View page', 'wpa11y' ) . '</a>'
		. ( '' !== $edit ? ' | <a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit page', 'wpa11y' ) . '</a>' : '' ) . '</p>';
	$h .= '<p class="wpa11y-scanned">' . wpa11y_status_html( $state ) . '</p>';
	// aria-disabled, not disabled, so the button keeps focus while a scan runs.
	$h .= '<p><button type="button" class="button" id="wpa11y-rescan"' . ( $busy ? ' aria-disabled="true"' : '' ) . '>'
		. ( $busy ? esc_html__( 'Scanning…', 'wpa11y' ) : esc_html__( 'Rescan this page', 'wpa11y' ) ) . '</button></p>';
	if ( ! $result ) { return $h; }

	$h .= wpa11y_render_section( 'errors', __( 'Errors', 'wpa11y' ), __( 'Definite failures. Fix them in the page editor; they clear on the next scan. Errors cannot be dismissed.', 'wpa11y' ), $split['errors'], __( 'No errors found.', 'wpa11y' ), $edit );
	$h .= wpa11y_render_section( 'warnings', __( 'Warnings needing review', 'wpa11y' ), __( 'The checker could not decide these. Look at each one; if it is fine, dismiss it with a note.', 'wpa11y' ), $split['warnings'], __( 'No warnings need review.', 'wpa11y' ), $edit );
	$h .= wpa11y_render_section( 'dismissed', __( 'Dismissed', 'wpa11y' ), '', $split['dismissed'], __( 'Nothing has been dismissed.', 'wpa11y' ) );
	return $h;
}

// What the editor script needs to find one issue's block; found:false when the key isn't in the latest scan.
function wpa11y_find_payload( $post_id, $key ) {
	$result = wpa11y_get_result( $post_id );
	$issue  = wpa11y_find_issue( $result ? $result['issues'] : array(), (string) $key );
	if ( ! $issue ) { return array( 'found' => false ); }
	return array(
		'found'    => true,
		'code'     => $issue['code'],
		'message'  => $issue['message'],
		'selector' => $issue['selector'],
		'context'  => $issue['context'],
		'text'     => (string) ( $issue['text'] ?? '' ),
	);
}

// Config for assets/editor.js. Keys are md5 hex; anything else finds nothing.
function wpa11y_editor_config( $post_id, $key ) {
	$key = preg_match( '/^[a-f0-9]{32}$/', (string) $key ) ? $key : '';
	return array(
		'issue'   => wpa11y_find_payload( $post_id, $key ),
		'strings' => array(
			/* translators: %s: the accessibility problem, e.g. "Images must have alternative text". */
			'found'    => __( 'Accessibility: the selected block has this problem: %s. To fix it in HTML, open the block toolbar’s Options menu (⋮) and choose Edit as HTML.', 'wpa11y' ),
			/* translators: %s: the accessibility problem. */
			'notFound' => __( 'Accessibility: could not find this problem in the page content (%s). It may come from the theme, a menu, a widget or a shortcode rather than this page’s blocks.', 'wpa11y' ),
			'stale'    => __( 'Accessibility: that issue is no longer in the latest scan. Rescan the page to refresh the results.', 'wpa11y' ),
		),
	);
}

// Only on the edit screen opened by a "Show in editor" link.
add_action( 'enqueue_block_editor_assets', function () {
	// phpcs:disable WordPress.Security.NonceVerification -- read-only lookup of the user's own scan result.
	$key     = isset( $_GET['wpa11y_find'] ) ? sanitize_key( wp_unslash( $_GET['wpa11y_find'] ) ) : '';
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
	// phpcs:enable
	if ( '' === $key || ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) { return; }
	wp_enqueue_script( 'wpa11y-editor', plugins_url( 'assets/editor.js', __FILE__ ), array( 'wp-blocks', 'wp-data', 'wp-dom-ready', 'wp-notices' ), WPA11Y_VERSION, true );
	wp_add_inline_script( 'wpa11y-editor', 'window.WPA11Y_FIND=' . wp_json_encode( wpa11y_editor_config( $post_id, $key ) ) . ';', 'before' );
} );

function wpa11y_status_payload( $post_id ) {
	$state = wpa11y_detail_state( $post_id );
	$c     = wpa11y_counts( $state['result'], wpa11y_active_rows( wpa11y_rows(), $post_id ) );
	return array(
		'html'    => wpa11y_render_detail( $post_id ),
		'pending' => 'pending' === $state['rescan'],
		'rescan'  => $state['rescan'],
		'counts'  => $c,
		'summary' => wpa11y_summary( $state['result'], $c ),
	);
}

function wpa11y_rest_status( $request ) {
	$post = wpa11y_scannable_post( (int) $request->get_param( 'post' ) );
	return is_wp_error( $post ) ? $post : wpa11y_status_payload( $post->ID );
}

// The key names one issue in the latest scan; only a current warning can be dismissed.
function wpa11y_rest_dismiss( $request ) {
	$post = wpa11y_scannable_post( (int) $request->get_param( 'post' ) );
	if ( is_wp_error( $post ) ) { return $post; }
	$result = wpa11y_get_result( $post->ID );
	$issue  = wpa11y_find_issue( $result ? $result['issues'] : array(), (string) $request->get_param( 'key' ) );
	if ( ! $issue ) {
		return new WP_Error( 'wpa11y_not_current', __( 'That issue is not in the latest scan. Reload the page.', 'wpa11y' ), array( 'status' => 409 ) );
	}
	if ( 'warning' !== $issue['type'] ) {
		return new WP_Error( 'wpa11y_error_not_dismissable', __( 'Errors cannot be dismissed. Fix them in the page editor; they clear on the next scan.', 'wpa11y' ), array( 'status' => 400 ) );
	}
	// Kept as typed (notes often name tags like <main>); every display escapes it.
	$note = wpa11y_clip( trim( wp_check_invalid_utf8( (string) $request->get_param( 'note' ) ) ), 1000 );
	wpa11y_add_dismissal( $post->ID, $issue['code'], $issue['selector'], $note, get_current_user_id() );
	return wpa11y_status_payload( $post->ID );
}

function wpa11y_rest_undismiss( $request ) {
	$post_id = wpa11y_undo_dismissal( (int) $request->get_param( 'id' ), get_current_user_id() );
	if ( false === $post_id ) {
		return new WP_Error( 'wpa11y_no_dismissal', __( 'That dismissal was not found or is already undone.', 'wpa11y' ), array( 'status' => 404 ) );
	}
	return wpa11y_status_payload( $post_id );
}

// Rescans use the post's own permalink, never a URL from the request.
function wpa11y_rest_rescan( $request ) {
	$post = wpa11y_scannable_post( (int) $request->get_param( 'post' ) );
	if ( is_wp_error( $post ) ) { return $post; }
	if ( 'pending' !== wpa11y_detail_state( $post->ID )['rescan'] ) {
		$sent = wpa11y_dispatch( get_permalink( $post ) );
		if ( is_wp_error( $sent ) ) { return $sent; }
		update_post_meta( $post->ID, WPA11Y_RESCAN_META, wpa11y_iso( time() ) );
	}
	return wpa11y_status_payload( $post->ID );
}

function wpa11y_page_detail( $post_id ) {
	$post = wpa11y_scannable_post( $post_id );
	echo '<div class="wrap wpa11y"><p><a href="' . esc_url( admin_url( 'admin.php?page=wpa11y' ) ) . '"><span aria-hidden="true">← </span>' . esc_html__( 'All pages', 'wpa11y' ) . '</a></p>';
	if ( is_wp_error( $post ) ) {
		echo '<h1>' . esc_html__( 'Accessibility', 'wpa11y' ) . '</h1><p>' . esc_html( $post->get_error_message() ) . '</p></div>';
		return;
	}
	$pending = 'pending' === wpa11y_detail_state( $post_id )['rescan'];
	echo '<h1>' . esc_html( sprintf( __( 'Accessibility: %s', 'wpa11y' ), wpa11y_title( $post ) ) ) . '</h1>';
	echo '<div id="wpa11y-error" role="alert"></div>';
	echo '<div id="wpa11y-live" class="screen-reader-text" aria-live="polite"></div>';
	echo '<div id="wpa11y-detail" data-post="' . (int) $post_id . '" data-pending="' . ( $pending ? '1' : '0' ) . '">';
	echo wpa11y_render_detail( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	echo '</div></div>';
}

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( false === strpos( (string) $hook, 'wpa11y' ) ) { return; }
	wp_enqueue_style( 'wpa11y-admin', plugins_url( 'assets/admin.css', __FILE__ ), array(), WPA11Y_VERSION );
	wp_enqueue_script( 'wpa11y-admin', plugins_url( 'assets/admin.js', __FILE__ ), array(), WPA11Y_VERSION, true );
	wp_add_inline_script( 'wpa11y-admin', 'window.WPA11Y=' . wp_json_encode( array(
		'root'    => esc_url_raw( rest_url( 'wpa11y/v1/' ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
		'strings' => array(
			'dismissed' => __( 'Dismissed.', 'wpa11y' ),
			'undone'    => __( 'Dismissal undone; the warning is back under review.', 'wpa11y' ),
			'scanning'  => __( 'Scanning. Results usually arrive in 1 to 2 minutes.', 'wpa11y' ),
			'scanDone'  => __( 'Scan finished.', 'wpa11y' ),
			'timedOut'  => __( 'The rescan has not reported back after 10 minutes. Check the scan runs on GitHub; the link is next to the Rescan button.', 'wpa11y' ),
			'failed'    => __( 'Request failed', 'wpa11y' ),
		),
	) ) . ';', 'before' );
} );

/* ------------------------------------------------------------------ *
 *  Overview and list-table column
 * ------------------------------------------------------------------ */

function wpa11y_filters() {
	return array(
		'all'       => __( 'All', 'wpa11y' ),
		'errors'    => __( 'Has errors', 'wpa11y' ),
		'warnings'  => __( 'Has warnings', 'wpa11y' ),
		'failed'    => __( 'Scan failed', 'wpa11y' ),
		'unscanned' => __( 'Not scanned', 'wpa11y' ),
	);
}

function wpa11y_valid_orderby( $orderby ) {
	return in_array( $orderby, array( 'title', 'type', 'errors', 'warnings', 'scanned' ), true ) ? $orderby : 'errors';
}

function wpa11y_item_matches( $item, $filter ) {
	switch ( $filter ) {
		case 'errors':
			return $item['errors'] > 0;
		case 'warnings':
			return $item['warnings'] > 0;
		case 'failed':
			return 'failed' === $item['state'];
		case 'unscanned':
			return 'unscanned' === $item['state'];
	}
	return true;
}

function wpa11y_overview_items() {
	$by_post = array();
	foreach ( wpa11y_active_rows( wpa11y_rows() ) as $r ) { $by_post[ (int) $r['post_id'] ][] = $r; }
	$items = array();
	foreach ( wpa11y_scannable_posts() as $post ) {
		$result  = wpa11y_get_result( $post->ID );
		$c       = wpa11y_counts( $result, $by_post[ (int) $post->ID ] ?? array() );
		$items[] = array(
			'id'         => (int) $post->ID,
			'title'      => wpa11y_title( $post ),
			'type'       => $post->post_type,
			'errors'     => $c['errors'],
			'warnings'   => $c['warnings'],
			'state'      => $c['state'],
			'scanned_at' => (string) ( $result['scanned_at'] ?? '' ),
		);
	}
	return $items;
}

function wpa11y_filter_counts( $items ) {
	$counts = array();
	foreach ( array_keys( wpa11y_filters() ) as $key ) {
		$counts[ $key ] = count( array_filter( $items, function ( $i ) use ( $key ) { return wpa11y_item_matches( $i, $key ); } ) );
	}
	return $counts;
}

function wpa11y_overview_rows( $items, $filter, $orderby, $order ) {
	if ( ! isset( wpa11y_filters()[ $filter ] ) ) { $filter = 'all'; }
	$orderby = wpa11y_valid_orderby( $orderby );
	$dir     = 'asc' === $order ? 1 : -1;
	$rows    = array_values( array_filter( $items, function ( $i ) use ( $filter ) { return wpa11y_item_matches( $i, $filter ); } ) );
	usort( $rows, function ( $a, $b ) use ( $orderby, $dir ) {
		switch ( $orderby ) {
			case 'title':
				$cmp = strcasecmp( $a['title'], $b['title'] );
				break;
			case 'type':
				$cmp = strcmp( $a['type'], $b['type'] );
				break;
			case 'scanned':
				$cmp = strcmp( $a['scanned_at'], $b['scanned_at'] );
				break;
			default:
				$cmp = $a[ $orderby ] - $b[ $orderby ];
		}
		return 0 !== $cmp ? $dir * $cmp : strcasecmp( $a['title'], $b['title'] );
	} );
	return $rows;
}

function wpa11y_counts_label( $c ) {
	switch ( $c['state'] ) {
		case 'unscanned':
			return __( 'Not scanned', 'wpa11y' );
		case 'failed':
			return __( 'Scan failed', 'wpa11y' );
		case 'clean':
			return __( 'No issues', 'wpa11y' );
	}
	return sprintf( _n( '%d error', '%d errors', $c['errors'], 'wpa11y' ), $c['errors'] ) . ' · '
		. sprintf( _n( '%d warning', '%d warnings', $c['warnings'], 'wpa11y' ), $c['warnings'] );
}

function wpa11y_scanned_label( $item ) {
	if ( 'unscanned' === $item['state'] ) { return __( 'Not scanned', 'wpa11y' ); }
	$when = wpa11y_format_time( $item['scanned_at'] );
	if ( 'failed' === $item['state'] ) {
		return '' === $when ? __( 'Scan failed', 'wpa11y' ) : sprintf( __( 'Scan failed (last good: %s)', 'wpa11y' ), $when );
	}
	return $when;
}

// A non-zero count links to that section of the page detail; the hidden text names the page for screen readers.
function wpa11y_count_link( $item, $section ) {
	$n = (int) $item[ $section ];
	if ( 0 === $n ) { return '0'; }
	$what = 'errors' === $section ? _n( 'error', 'errors', $n, 'wpa11y' ) : _n( 'warning', 'warnings', $n, 'wpa11y' );
	return '<a href="' . esc_url( wpa11y_detail_url( $item['id'] ) . '#wpa11y-h-' . $section ) . '">' . $n
		. '<span class="screen-reader-text">' . esc_html( sprintf( __( ' %1$s on %2$s', 'wpa11y' ), $what, $item['title'] ) ) . '</span></a>';
}

function wpa11y_render_overview( $items, $filter, $orderby, $order, $last_full ) {
	if ( ! isset( wpa11y_filters()[ $filter ] ) ) { $filter = 'all'; }
	$orderby = wpa11y_valid_orderby( $orderby );
	$order   = 'asc' === $order ? 'asc' : 'desc';
	$rows    = wpa11y_overview_rows( $items, $filter, $orderby, $order );
	$counts  = wpa11y_filter_counts( $items );
	$base    = admin_url( 'admin.php?page=wpa11y' );

	$h  = '<h1>' . esc_html__( 'Accessibility', 'wpa11y' ) . '</h1>';
	$h .= '<p class="wpa11y-summary">' . esc_html( sprintf( __( '%1$d pages checked. %2$d with errors. %3$d warnings awaiting review.', 'wpa11y' ), count( $items ), $counts['errors'], array_sum( array_column( $items, 'warnings' ) ) ) ) . ' ';
	$h .= '' !== $last_full
		? esc_html( sprintf( __( 'Last full scan: %s.', 'wpa11y' ), wpa11y_format_time( $last_full ) ) )
		: esc_html__( 'No full scan has reported yet.', 'wpa11y' );
	$h .= ' <a href="' . esc_url( wpa11y_actions_url() ) . '">' . esc_html__( 'Scan runs on GitHub', 'wpa11y' ) . '</a></p>';

	$links = array();
	foreach ( wpa11y_filters() as $key => $label ) {
		$url     = add_query_arg( array( 'filter' => $key, 'orderby' => $orderby, 'order' => $order ), $base );
		$links[] = '<li><a href="' . esc_url( $url ) . '"' . ( $key === $filter ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . ' <span class="count">(' . (int) $counts[ $key ] . ')</span></a>';
	}
	$h .= '<ul class="subsubsub">' . implode( ' |</li>', $links ) . '</li></ul>';

	$cols = array(
		'title'    => __( 'Page', 'wpa11y' ),
		'type'     => __( 'Type', 'wpa11y' ),
		'errors'   => __( 'Errors', 'wpa11y' ),
		'warnings' => __( 'Warnings to review', 'wpa11y' ),
		'scanned'  => __( 'Last scanned', 'wpa11y' ),
	);
	$h .= '<table class="wp-list-table widefat striped wpa11y-overview"><caption class="screen-reader-text">' . esc_html__( 'Pages and their accessibility issues', 'wpa11y' ) . '</caption><thead><tr>';
	foreach ( $cols as $key => $label ) {
		$sorted = $key === $orderby;
		$next   = $sorted ? ( 'asc' === $order ? 'desc' : 'asc' ) : ( in_array( $key, array( 'title', 'type' ), true ) ? 'asc' : 'desc' );
		$url    = add_query_arg( array( 'filter' => $filter, 'orderby' => $key, 'order' => $next ), $base );
		$h     .= '<th scope="col"' . ( $sorted ? ' aria-sort="' . ( 'asc' === $order ? 'ascending' : 'descending' ) . '"' : '' ) . '><a href="' . esc_url( $url ) . '">' . esc_html( $label )
			. ( $sorted ? ' <span aria-hidden="true">' . ( 'asc' === $order ? '▲' : '▼' ) . '</span>' : '' ) . '</a></th>';
	}
	$h .= '</tr></thead><tbody>';
	if ( ! $rows ) {
		$h .= '<tr><td colspan="5">' . esc_html__( 'No pages match this filter.', 'wpa11y' ) . '</td></tr>';
	}
	$none = '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Not scanned', 'wpa11y' ) . '</span>';
	foreach ( $rows as $r ) {
		$type    = get_post_type_object( $r['type'] );
		$unknown = 'unscanned' === $r['state'];
		$h      .= '<tr><th scope="row"><strong><a href="' . esc_url( wpa11y_detail_url( $r['id'] ) ) . '">' . esc_html( $r['title'] ) . '</a></strong></th>';
		$h      .= '<td>' . esc_html( $type ? $type->labels->singular_name : $r['type'] ) . '</td>';
		$h      .= '<td>' . ( $unknown ? $none : wpa11y_count_link( $r, 'errors' ) ) . '</td>';
		$h      .= '<td>' . ( $unknown ? $none : wpa11y_count_link( $r, 'warnings' ) ) . '</td>';
		$h      .= '<td>' . esc_html( wpa11y_scanned_label( $r ) ) . '</td></tr>';
	}
	return $h . '</tbody></table>';
}

function wpa11y_page_overview() {
	if ( ! current_user_can( WPA11Y_CAP ) ) { return; }
	// phpcs:disable WordPress.Security.NonceVerification -- read-only view parameters.
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
	if ( $post_id ) {
		wpa11y_page_detail( $post_id );
		return;
	}
	$filter  = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all';
	$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'errors';
	$order   = isset( $_GET['order'] ) && 'asc' === $_GET['order'] ? 'asc' : 'desc';
	// phpcs:enable
	echo '<div class="wrap wpa11y">' . wpa11y_render_overview( wpa11y_overview_items(), $filter, $orderby, $order, wpa11y_opt( 'wpa11y_last_full_scan' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

function wpa11y_column_html( $post_id ) {
	if ( is_wp_error( wpa11y_scannable_post( $post_id ) ) ) { return esc_html__( 'Not published', 'wpa11y' ); }
	$c = wpa11y_counts( wpa11y_get_result( $post_id ), wpa11y_active_rows( wpa11y_rows(), $post_id ) );
	return '<a href="' . esc_url( wpa11y_detail_url( $post_id ) ) . '">' . esc_html( wpa11y_counts_label( $c ) ) . '</a>';
}

// Runs on admin_init so custom post types registered on init are included.
add_action( 'admin_init', function () {
	if ( ! current_user_can( WPA11Y_CAP ) ) { return; }
	foreach ( wpa11y_post_types() as $type ) {
		add_filter( "manage_{$type}_posts_columns", function ( $cols ) {
			$cols['wpa11y'] = __( 'Accessibility', 'wpa11y' );
			return $cols;
		} );
		add_action( "manage_{$type}_posts_custom_column", function ( $col, $post_id ) {
			if ( 'wpa11y' === $col ) { echo wpa11y_column_html( $post_id ); } // phpcs:ignore WordPress.Security.EscapeOutput
		}, 10, 2 );
	}
} );

/* ------------------------------------------------------------------ *
 *  Dismissal log
 * ------------------------------------------------------------------ */

function wpa11y_log_filters() {
	return array(
		'all'    => __( 'All', 'wpa11y' ),
		'active' => __( 'Active', 'wpa11y' ),
		'gone'   => __( 'No longer present', 'wpa11y' ),
		'undone' => __( 'Undone', 'wpa11y' ),
	);
}

// "gone": still active, but the latest scan no longer has that warning (the element changed or the page is gone).
function wpa11y_log_rows( $rows, $filter ) {
	if ( ! isset( wpa11y_log_filters()[ $filter ] ) ) { $filter = 'all'; }
	$issues = array();
	$out    = array();
	foreach ( $rows as $r ) {
		$pid = (int) $r['post_id'];
		if ( ! isset( $issues[ $pid ] ) ) {
			$res            = wpa11y_get_result( $pid );
			$issues[ $pid ] = $res ? $res['issues'] : array();
		}
		if ( ! empty( $r['undone_at'] ) ) {
			$r['status'] = 'undone';
		} else {
			$i           = wpa11y_find_issue( $issues[ $pid ], wpa11y_issue_key( $r['code'], $r['selector'] ) );
			$r['status'] = $i && 'warning' === $i['type'] ? 'active' : 'gone';
		}
		if ( 'all' === $filter || $r['status'] === $filter ) { $out[] = $r; }
	}
	return $out;
}

function wpa11y_log_action( $do, $id, $user_id ) {
	if ( 'undo' === $do ) { return false !== wpa11y_undo_dismissal( $id, $user_id ); }
	if ( 'restore' === $do ) { return false !== wpa11y_restore_dismissal( $id, $user_id ); }
	return false;
}

function wpa11y_user_name( $user_id ) {
	$u = get_userdata( (int) $user_id );
	return $u ? $u->display_name : sprintf( __( 'user #%d', 'wpa11y' ), (int) $user_id );
}

function wpa11y_render_log( $rows, $filter, $msg ) {
	if ( ! isset( wpa11y_log_filters()[ $filter ] ) ) { $filter = 'all'; }
	$base     = admin_url( 'admin.php?page=wpa11y-log' );
	$messages = array(
		'log_undo'    => __( 'Dismissal undone. The warning is back under review.', 'wpa11y' ),
		'log_restore' => __( 'Dismissal restored.', 'wpa11y' ),
		'log_failed'  => __( 'That dismissal had already changed. Nothing was done.', 'wpa11y' ),
	);

	$h = '<h1>' . esc_html__( 'Dismissal log', 'wpa11y' ) . '</h1>';
	if ( isset( $messages[ $msg ] ) ) {
		$h .= '<div class="notice ' . ( 'log_failed' === $msg ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( $messages[ $msg ] ) . '</p></div>';
	}
	$h    .= '<p>' . esc_html__( 'Every warning anyone has dismissed, with who, when and why. Nothing here is ever deleted.', 'wpa11y' ) . '</p>';
	$links = array();
	foreach ( wpa11y_log_filters() as $key => $label ) {
		$links[] = '<li><a href="' . esc_url( add_query_arg( array( 'status' => $key ), $base ) ) . '"' . ( $key === $filter ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
	}
	$h .= '<ul class="subsubsub">' . implode( ' |</li>', $links ) . '</li></ul>';
	if ( ! $rows ) { return $h . '<p class="clear">' . esc_html__( 'No dismissals match.', 'wpa11y' ) . '</p>'; }

	$h .= '<table class="wp-list-table widefat striped wpa11y-log"><caption class="screen-reader-text">' . esc_html__( 'Dismissals, newest first', 'wpa11y' ) . '</caption><thead><tr>';
	foreach ( array( __( 'Dismissed', 'wpa11y' ), __( 'Page', 'wpa11y' ), __( 'Rule', 'wpa11y' ), __( 'Element', 'wpa11y' ), __( 'By', 'wpa11y' ), __( 'Note', 'wpa11y' ), __( 'Status', 'wpa11y' ), __( 'Action', 'wpa11y' ) ) as $label ) {
		$h .= '<th scope="col">' . esc_html( $label ) . '</th>';
	}
	$h    .= '</tr></thead><tbody>';
	$nonce = esc_attr( wp_create_nonce( 'wpa11y_log' ) );
	foreach ( $rows as $r ) {
		$post  = get_post( (int) $r['post_id'] );
		$title = $post ? wpa11y_title( $post ) : sprintf( __( '(deleted page #%d)', 'wpa11y' ), (int) $r['post_id'] );
		$page  = $post ? '<a href="' . esc_url( wpa11y_detail_url( (int) $r['post_id'] ) ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );
		if ( 'undone' === $r['status'] ) {
			$status = sprintf( __( 'Undone by %1$s on %2$s', 'wpa11y' ), wpa11y_user_name( $r['undone_by'] ), wpa11y_format_time( $r['undone_at'] ) );
			$do     = 'restore';
			$verb   = __( 'Restore', 'wpa11y' );
		} else {
			$status = wpa11y_log_filters()[ $r['status'] ];
			$do     = 'undo';
			$verb   = __( 'Undo', 'wpa11y' );
		}
		$form = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="wpa11y_log">'
			. '<input type="hidden" name="id" value="' . (int) $r['id'] . '">'
			. '<input type="hidden" name="status" value="' . esc_attr( $filter ) . '">'
			. '<input type="hidden" name="_wpnonce" value="' . $nonce . '">'
			. '<button type="submit" class="button button-small" name="do" value="' . $do . '">' . esc_html( $verb )
			. '<span class="screen-reader-text">' . esc_html( sprintf( __( ' dismissal of %1$s on %2$s', 'wpa11y' ), $r['code'], $title ) ) . '</span></button></form>';
		$h .= '<tr><td>' . esc_html( wpa11y_format_time( $r['created_at'] ) ) . '</td><td>' . $page . '</td><td><code>' . esc_html( $r['code'] ) . '</code></td>'
			. '<td><code>' . esc_html( $r['selector'] ) . '</code></td><td>' . esc_html( wpa11y_user_name( $r['user_id'] ) ) . '</td><td>' . esc_html( $r['note'] ) . '</td>'
			. '<td>' . esc_html( $status ) . '</td><td>' . $form . '</td></tr>';
	}
	return $h . '</tbody></table>';
}

function wpa11y_page_log() {
	if ( ! current_user_can( WPA11Y_CAP ) ) { return; }
	// phpcs:disable WordPress.Security.NonceVerification -- read-only view parameters.
	$filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
	$msg    = isset( $_GET['wpa11y_msg'] ) ? sanitize_key( wp_unslash( $_GET['wpa11y_msg'] ) ) : '';
	// phpcs:enable
	echo '<div class="wrap wpa11y">' . wpa11y_render_log( wpa11y_log_rows( wpa11y_rows(), $filter ), $filter, $msg ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

add_action( 'admin_post_wpa11y_log', function () {
	if ( ! current_user_can( WPA11Y_CAP ) ) { wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wpa11y' ), 403 ); }
	check_admin_referer( 'wpa11y_log' );
	$do     = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
	$ok     = wpa11y_log_action( $do, absint( $_POST['id'] ?? 0 ), get_current_user_id() );
	$status = sanitize_key( wp_unslash( $_POST['status'] ?? 'all' ) );
	wp_safe_redirect( add_query_arg( array( 'page' => 'wpa11y-log', 'status' => $status, 'wpa11y_msg' => $ok ? 'log_' . $do : 'log_failed' ), admin_url( 'admin.php' ) ) );
	exit;
} );
