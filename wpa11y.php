<?php
/**
 * Plugin Name:       wpa11y
 * Description:       Site accessibility dashboard for editors. A daily axe scan on GitHub Actions reports every published page; editors drill into issues, review and dismiss warnings, and rescan a page.
 * Version:           0.1.0
 * Author:            Madeleine Clark Wallace Library
 * License:           GPL-2.0+
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'WPA11Y_VERSION', '0.1.0' );
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
