<?php
/**
 * Plugin Name:       wpa11y
 * Description:       Site accessibility dashboard for editors. A daily axe scan on GitHub Actions reports every published page; editors drill into issues, review and dismiss warnings, and rescan a page.
 * Version:           0.4.0
 * Author:            Madeleine Clark Wallace Library
 * License:           GPL-2.0+
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'WPA11Y_VERSION', '0.4.0' );
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
		. ( $has_hash ? ' <span class="description">' . esc_html__( 'The daily scan fails until GitHub has the new secret.', 'wpa11y' ) . '</span>' : '' ) . '</form>';

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
		. '<input type="password" class="regular-text" id="wpa11y-token" name="token" autocomplete="off" aria-describedby="wpa11y-token-help">'
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
