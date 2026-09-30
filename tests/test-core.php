<?php
// URL normalizing.
check( 'normalize: trailing slash, lowercase host', wpa11y_normalize_url( 'https://Library.WheatonCollege.edu/about' ), WT_SITE . '/about/' );
check( 'normalize: keeps query', wpa11y_normalize_url( WT_SITE . '/?page_id=5' ), WT_SITE . '/?page_id=5' );
check( 'normalize: rejects relative', wpa11y_normalize_url( '/about/' ), '' );

// Scanner secret.
$hash = hash( 'sha256', 'right-secret' );
check( 'bearer: accepts right secret', wpa11y_bearer_ok( 'Bearer right-secret', $hash ), true );
check( 'bearer: case-insensitive scheme', wpa11y_bearer_ok( 'bearer right-secret', $hash ), true );
check( 'bearer: rejects wrong secret', wpa11y_bearer_ok( 'Bearer wrong', $hash ), false );
check( 'bearer: rejects missing header', wpa11y_bearer_ok( null, $hash ), false );
check( 'bearer: rejects empty token', wpa11y_bearer_ok( 'Bearer ', $hash ), false );
check( 'bearer: rejects when no secret is set', wpa11y_bearer_ok( 'Bearer ', '' ), false );
check( 'bearer: rejects any token when no secret is set', wpa11y_bearer_ok( 'Bearer x', '' ), false );

// Which posts are scanned.
check( 'post types: public viewable minus attachments', wpa11y_post_types(), array( 'post', 'page' ) );
wt_reset( array( 'posts' => array(
	array( 'ID' => 10 ),
	array( 'ID' => 11, 'post_status' => 'draft' ),
	array( 'ID' => 12, 'post_password' => 'x' ),
	array( 'ID' => 13, 'post_type' => 'attachment' ),
	array( 'ID' => 14, 'post_title' => '' ),
) ) );
check( 'scannable: published page', wpa11y_scannable_post( 10 )->ID, 10 );
check( 'scannable: draft refused', wpa11y_scannable_post( 11 )->get_error_code(), 'wpa11y_bad_post' );
check( 'scannable: password refused', is_wp_error( wpa11y_scannable_post( 12 ) ), true );
check( 'scannable: attachment refused', is_wp_error( wpa11y_scannable_post( 13 ) ), true );
check( 'scannable: missing refused', wpa11y_scannable_post( 99 )->get_error_data(), array( 'status' => 404 ) );
check( 'scannable: list', array_map( function ( $p ) { return $p->ID; }, wpa11y_scannable_posts() ), array( 14, 10 ) );
check( 'title: untitled fallback', wpa11y_title( get_post( 14 ) ), '(no title)' );

// Cleaning a posted result.
$permalink = WT_SITE . '/page-10/';
$good      = array(
	'url'        => WT_SITE . '/page-10',
	'scanned_at' => '2026-09-30T10:04:11.123Z',
	'issues'     => array( wt_issue( 'warning', 'color-contrast', 'p' ) ),
);
$ok = wpa11y_clean_result( $good, $permalink );
check( 'clean: normalizes time', $ok['scanned_at'], '2026-09-30T10:04:11Z' );
check( 'clean: keeps issue', $ok['issues'][0], wt_issue( 'warning', 'color-contrast', 'p' ) );
check( 'clean: wrong url', wpa11y_clean_result( array_merge( $good, array( 'url' => WT_SITE . '/other/' ) ), $permalink )->get_error_code(), 'wpa11y_url_mismatch' );
check( 'clean: bad time', wpa11y_clean_result( array_merge( $good, array( 'scanned_at' => 'soon' ) ), $permalink )->get_error_code(), 'wpa11y_bad_time' );
check( 'clean: notice type refused', wpa11y_clean_result( array_merge( $good, array( 'issues' => array( wt_issue( 'notice', 'x', 'p' ) ) ) ), $permalink )->get_error_code(), 'wpa11y_bad_issue' );
check( 'clean: missing issues refused', wpa11y_clean_result( array( 'url' => $permalink, 'scanned_at' => '2026-09-30T10:00:00Z' ), $permalink )->get_error_code(), 'wpa11y_bad_issues' );
check( 'clean: too many issues refused', wpa11y_clean_result( array_merge( $good, array( 'issues' => array_fill( 0, 1001, wt_issue( 'error', 'x', 'p' ) ) ) ), $permalink )->get_error_code(), 'wpa11y_bad_issues' );
check( 'clean: not an object', wpa11y_clean_result( null, $permalink )->get_error_code(), 'wpa11y_bad_body' );
$long = wpa11y_clean_result( array_merge( $good, array( 'issues' => array( wt_issue( 'error', 'x', 'p', array( 'context' => str_repeat( 'é', 3000 ) ) ) ) ) ), $permalink );
check( 'clean: clips context by character', $long['issues'][0]['context'], str_repeat( 'é', 2000 ) );
$js = wpa11y_clean_result( array_merge( $good, array( 'issues' => array( wt_issue( 'error', 'x', 'p', array( 'help_url' => 'javascript:alert(1)' ) ) ) ) ), $permalink );
check( 'clean: non-https help url dropped', $js['issues'][0]['help_url'], '' );
check( 'clean: failure', wpa11y_clean_result( array( 'url' => $permalink, 'scanned_at' => '2026-09-30T10:00:00Z', 'error' => 'Timeout' ), $permalink ), array( 'scanned_at' => '2026-09-30T10:00:00Z', 'url' => $permalink, 'error' => 'Timeout' ) );

// Merging with what was stored before.
$prev = array( 'scanned_at' => 'A', 'url' => 'u', 'issues' => array( 'x' ), 'error' => null, 'error_at' => null );
$m    = wpa11y_merge_result( $prev, array( 'scanned_at' => 'B', 'url' => 'u', 'error' => 'Timeout' ) );
check( 'merge: failure keeps previous issues', $m['issues'], array( 'x' ) );
check( 'merge: failure keeps last good time', $m['scanned_at'], 'A' );
check( 'merge: failure recorded', array( $m['error'], $m['error_at'] ), array( 'Timeout', 'B' ) );
check( 'merge: failure with nothing before', wpa11y_merge_result( null, array( 'scanned_at' => 'B', 'url' => 'u', 'error' => 'Timeout' ) ), array( 'scanned_at' => null, 'url' => 'u', 'issues' => array(), 'error' => 'Timeout', 'error_at' => 'B' ) );
check( 'merge: success clears failure', wpa11y_merge_result( $m, array( 'scanned_at' => 'C', 'url' => 'u', 'issues' => array() ) ), array( 'scanned_at' => 'C', 'url' => 'u', 'issues' => array(), 'error' => null, 'error_at' => null ) );

// Storage round trip.
wt_reset( array( 'posts' => array( array( 'ID' => 10 ) ) ) );
$r = wt_result( array( wt_issue( 'error', 'link-name', 'a[href="C:\\docs\\a.pdf"]', array( 'context' => '<a href="C:\\docs\\a.pdf">Say "hi"</a>' ) ) ) );
wpa11y_save_result( 10, $r );
check( 'result: survives meta slashing', wpa11y_get_result( 10 ), $r );
check( 'result: missing is null', wpa11y_get_result( 99 ), null );

// Time formatting.
check( 'time: ISO', wpa11y_format_time( '2026-09-30T10:04:00Z' ), 'Sep 30, 2026 10:04 am' );
check( 'time: MySQL UTC', wpa11y_format_time( '2026-09-30 10:04:00' ), 'Sep 30, 2026 10:04 am' );
check( 'time: empty', wpa11y_format_time( '' ), '' );
$t = wpa11y_clean_result( array_merge( $good, array( 'issues' => array( wt_issue( 'error', 'list', 'ul', array( 'text' => str_repeat( 'é', 400 ) ) ) ) ) ), $permalink );
check( 'clean: element text kept, clipped to 300', $t['issues'][0]['text'], str_repeat( 'é', 300 ) );
$old = wt_issue( 'error', 'list', 'ul' );
unset( $old['text'] );
check( 'clean: results without text (older scanner) get empty text', wpa11y_clean_result( array_merge( $good, array( 'issues' => array( $old ) ) ), $permalink )['issues'][0]['text'], '' );
