<?php
// Minimal WordPress stand-ins so wpa11y.php can be unit-tested with the PHP CLI.
// Pattern from wheatonlibrary-specs/tests. Only what the plugin calls is stubbed.

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'WT_SITE', 'https://library.wheatoncollege.edu' );

$GLOBALS['wt_results'] = array( 'pass' => 0, 'fail' => 0 );
$GLOBALS['wt_hooks']   = array();

function check( $name, $actual, $expected ) {
	if ( $actual === $expected ) {
		$GLOBALS['wt_results']['pass']++;
		return;
	}
	$GLOBALS['wt_results']['fail']++;
	echo "FAIL: $name\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }

class WP_REST_Request {
	private $params;
	private $headers;
	private $json;
	public function __construct( $params = array(), $headers = array(), $json = null ) {
		$this->params  = $params;
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
		$this->json    = $json;
	}
	public function get_param( $key ) { return $this->params[ $key ] ?? null; }
	public function get_header( $key ) { return $this->headers[ strtolower( $key ) ] ?? null; }
	public function get_json_params() { return $this->json; }
}

// In-memory stand-in for the dismissals table. The plugin only inserts,
// updates by id, and reads every row newest first.
class Wt_Wpdb {
	public $prefix    = 'wp_';
	public $rows      = array();
	public $insert_id = 0;
	public function get_charset_collate() { return ''; }
	public function insert( $table, $data, $format = null ) {
		$this->insert_id++;
		$row = array( 'id' => (string) $this->insert_id, 'undone_by' => null, 'undone_at' => null );
		foreach ( $data as $k => $v ) { $row[ $k ] = null === $v ? null : (string) $v; }
		$this->rows[] = $row;
		return 1;
	}
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$n = 0;
		foreach ( $this->rows as &$row ) {
			if ( (int) $row['id'] === (int) $where['id'] ) {
				foreach ( $data as $k => $v ) { $row[ $k ] = (string) $v; }
				$n++;
			}
		}
		unset( $row );
		return $n;
	}
	public function get_results( $sql, $output = null ) { return array_reverse( $this->rows ); }
}

function wt_reset( array $state = array() ) {
	$GLOBALS['wt_posts']   = array();
	$GLOBALS['wt_meta']    = array();
	$GLOBALS['wt_options'] = array();
	$GLOBALS['wt_user']    = 5;
	$GLOBALS['wt_caps']    = array( 'edit_pages' => true, 'manage_options' => true );
	$GLOBALS['wt_http']    = array( 'calls' => array(), 'response' => array( 'response' => array( 'code' => 204 ), 'body' => '' ) );
	$GLOBALS['wpdb']       = new Wt_Wpdb();
	foreach ( $state['posts'] ?? array() as $p ) { wt_add_post( $p ); }
	if ( function_exists( 'wpa11y_rows' ) ) { wpa11y_rows( true ); }
}
function wt_add_post( array $p ) {
	$p = array_merge( array(
		'post_type'     => 'page',
		'post_status'   => 'publish',
		'post_password' => '',
		'post_title'    => 'Page ' . $p['ID'],
		'post_name'     => 'page-' . $p['ID'],
	), $p );
	$GLOBALS['wt_posts'][ $p['ID'] ] = (object) $p;
}

// Hooks and registration: recorded, never run.
function add_action( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['wt_hooks'][] = array( 'action', $hook, $cb ); }
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['wt_hooks'][] = array( 'filter', $hook, $cb ); }
function register_activation_hook( $file, $cb ) { $GLOBALS['wt_hooks'][] = array( 'activation', $file, $cb ); }
function register_rest_route( $ns, $route, $args ) { $GLOBALS['wt_routes'][ $ns . $route ] = $args; }

// Escaping, i18n, sanitizing — same no-double-encode behaviour as core.
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false ); }
function esc_url_raw( $s ) { return (string) $s; }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function __( $s, $domain = 'default' ) { return $s; }
function esc_html__( $s, $domain = 'default' ) { return esc_html( $s ); }
function esc_attr__( $s, $domain = 'default' ) { return esc_attr( $s ); }
function _n( $single, $plural, $n, $domain = 'default' ) { return 1 === (int) $n ? $single : $plural; }
function sanitize_html_class( $c ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $c ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
// Core's update_post_meta() unslashes its value; these make the stubs do the same.
function wp_slash( $v ) { return is_string( $v ) ? addslashes( $v ) : $v; }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function wp_create_nonce( $action ) { return 'nonce-' . $action; }
function wp_nonce_field( $action, $name = '_wpnonce', $referer = true, $echo = true ) {
	$h = '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '">';
	if ( $echo ) { echo $h; }
	return $h;
}

// Options and transients.
function get_option( $k, $default = false ) { return $GLOBALS['wt_options'][ $k ] ?? $default; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['wt_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['wt_options'][ $k ] ); return true; }
function set_transient( $k, $v, $ttl = 0 ) { return update_option( '_transient_' . $k, $v ); }
function get_transient( $k ) { return get_option( '_transient_' . $k ); }
function delete_transient( $k ) { return delete_option( '_transient_' . $k ); }

// Posts and meta.
function get_post( $post ) {
	if ( is_object( $post ) ) { return $post; }
	return $GLOBALS['wt_posts'][ (int) $post ] ?? null;
}
function get_permalink( $post ) { $p = get_post( $post ); return $p ? WT_SITE . '/' . $p->post_name . '/' : false; }
function get_the_title( $post ) { $p = get_post( $post ); return $p ? $p->post_title : ''; }
function get_edit_post_link( $id, $context = 'display' ) { if ( ! empty( $GLOBALS['wt_no_edit'] ) ) { return null; } return WT_SITE . '/wp-admin/post.php?post=' . (int) $id . '&action=edit'; }
function get_post_types( $args = array(), $output = 'names' ) { return array( 'post' => 'post', 'page' => 'page', 'attachment' => 'attachment' ); }
function is_post_type_viewable( $t ) { return in_array( $t, array( 'post', 'page', 'attachment' ), true ); }
function get_post_type_object( $t ) { return (object) array( 'labels' => (object) array( 'singular_name' => ucfirst( $t ) ) ); }
function get_posts( $args ) {
	$types = (array) $args['post_type'];
	$out   = array();
	foreach ( $GLOBALS['wt_posts'] as $p ) {
		if ( in_array( $p->post_type, $types, true ) && 'publish' === $p->post_status && '' === $p->post_password ) {
			$out[] = $p;
		}
	}
	usort( $out, function ( $a, $b ) { return strcasecmp( $a->post_title, $b->post_title ); } );
	return $out;
}
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['wt_meta'][ (int) $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['wt_meta'][ (int) $id ][ $k ] = wp_unslash( $v ); return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['wt_meta'][ (int) $id ][ $k ] ); return true; }

// Users and time.
function get_current_user_id() { return $GLOBALS['wt_user']; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['wt_caps'][ $cap ] ); }
function get_userdata( $id ) { return 5 === (int) $id ? (object) array( 'display_name' => 'Pat Editor' ) : false; }
function wp_date( $format, $ts ) { return gmdate( $format, $ts ); }

// URLs.
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function home_url( $path = '' ) { return WT_SITE . $path; }
function admin_url( $path = '' ) { return WT_SITE . '/wp-admin/' . $path; }
function rest_url( $path = '' ) { return WT_SITE . '/wp-json/' . $path; }
function plugins_url( $path, $file ) { return WT_SITE . '/wp-content/plugins/wpa11y/' . $path; }
function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }

// HTTP.
function wp_remote_post( $url, $args ) {
	$GLOBALS['wt_http']['calls'][] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['wt_http']['response'];
}
function wp_remote_retrieve_response_code( $r ) { return is_wp_error( $r ) ? '' : $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return is_wp_error( $r ) ? '' : $r['body']; }

wt_reset();
function wp_check_invalid_utf8( $s ) { return 1 === preg_match( '//u', (string) $s ) ? (string) $s : ''; }
