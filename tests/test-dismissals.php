<?php
$w1 = wt_issue( 'warning', 'color-contrast', '#a' );
$w2 = wt_issue( 'warning', 'color-contrast', '#b' );
$e1 = wt_issue( 'error', 'image-alt', 'img' );

check( 'key: md5 of code and selector', wpa11y_issue_key( 'color-contrast', '#a' ), md5( "color-contrast\n#a" ) );
check( 'find: by key', wpa11y_find_issue( array( $e1, $w1 ), wpa11y_issue_key( 'color-contrast', '#a' ) ), $w1 );
check( 'find: missing', wpa11y_find_issue( array( $w1 ), 'nope' ), null );

wt_reset( array( 'posts' => array( array( 'ID' => 10 ), array( 'ID' => 11 ) ) ) );
check( 'add: returns id', wpa11y_add_dismissal( 10, 'color-contrast', '#a', 'Checked by hand', 5 ), 1 );
check( 'add: idempotent while active', wpa11y_add_dismissal( 10, 'color-contrast', '#a', 'again', 5 ), 1 );
check( 'add: one row stored', count( wpa11y_rows() ), 1 );
check( 'add: row shape', array_keys( wpa11y_rows()[0] ), array( 'id', 'undone_by', 'undone_at', 'post_id', 'code', 'selector', 'note', 'user_id', 'created_at' ) );
$active = wpa11y_active_rows( wpa11y_rows(), 10 );
check( 'active: one row for post 10', count( $active ), 1 );
check( 'active: none for post 11', wpa11y_active_rows( wpa11y_rows(), 11 ), array() );
check( 'active: all posts', count( wpa11y_active_rows( wpa11y_rows() ) ), 1 );

$split = wpa11y_split( array( $e1, $w1, $w2 ), $active );
check( 'split: errors', $split['errors'], array( $e1 ) );
check( 'split: warnings still to review', $split['warnings'], array( $w2 ) );
check( 'split: dismissed warning', $split['dismissed'][0]['selector'], '#a' );
check( 'split: dismissed carries row', $split['dismissed'][0]['dismissal']['note'], 'Checked by hand' );
$row_on_error = array( array( 'id' => '9', 'post_id' => '10', 'code' => 'image-alt', 'selector' => 'img', 'note' => '', 'user_id' => '5', 'created_at' => '2026-09-30 10:00:00', 'undone_by' => null, 'undone_at' => null ) );
check( 'split: an error is never hidden, even if a row matches', wpa11y_split( array( $e1 ), $row_on_error )['errors'], array( $e1 ) );
$moved = wpa11y_split( array( wt_issue( 'warning', 'color-contrast', '#a-new' ) ), $active );
check( 'split: changed selector reappears', array( count( $moved['warnings'] ), count( $moved['dismissed'] ) ), array( 1, 0 ) );

check( 'counts: unscanned', wpa11y_counts( null, array() ), array( 'errors' => 0, 'warnings' => 0, 'dismissed' => 0, 'state' => 'unscanned' ) );
check( 'counts: issues', wpa11y_counts( wt_result( array( $e1, $w1, $w2 ) ), $active ), array( 'errors' => 1, 'warnings' => 1, 'dismissed' => 1, 'state' => 'issues' ) );
check( 'counts: clean when only dismissed remain', wpa11y_counts( wt_result( array( $w1 ) ), $active ), array( 'errors' => 0, 'warnings' => 0, 'dismissed' => 1, 'state' => 'clean' ) );
$failed          = wt_result( array( $e1 ) );
$failed['error'] = 'Timeout';
check( 'counts: failed keeps stale counts', wpa11y_counts( $failed, array() ), array( 'errors' => 1, 'warnings' => 0, 'dismissed' => 0, 'state' => 'failed' ) );

check( 'undo: returns post id', wpa11y_undo_dismissal( 1, 7 ), 10 );
check( 'undo: second time refused', wpa11y_undo_dismissal( 1, 7 ), false );
check( 'undo: unknown id refused', wpa11y_undo_dismissal( 99, 7 ), false );
check( 'undo: no longer active', wpa11y_active_rows( wpa11y_rows(), 10 ), array() );
check( 'undo: row kept with who', array( count( wpa11y_rows() ), wpa11y_rows()[0]['undone_by'] ), array( 1, '7' ) );
check( 'restore: new row', wpa11y_restore_dismissal( 1, 5 ), 2 );
check( 'restore: keeps note', wpa11y_find_row( wpa11y_rows(), 2 )['note'], 'Checked by hand' );
check( 'restore: active row refused', wpa11y_restore_dismissal( 2, 5 ), false );
check( 'restore: a second restore of the old row refused while active', wpa11y_restore_dismissal( 1, 5 ), 2 );

check( 'group: by code, first-seen order', array_keys( wpa11y_group( array( $w1, $e1, $w2 ) ) ), array( 'color-contrast', 'image-alt' ) );
check( 'group: members', count( wpa11y_group( array( $w1, $e1, $w2 ) )['color-contrast'] ), 2 );

check( 'attempted: latest of scan and failure', wpa11y_attempted_at( array( 'scanned_at' => '2026-09-30T09:00:00Z', 'error_at' => '2026-09-30T10:00:00Z' ) ), '2026-09-30T10:00:00Z' );
check( 'attempted: none', wpa11y_attempted_at( null ), '' );
$now = strtotime( '2026-09-30T12:00:00Z' );
check( 'rescan: idle with no request', wpa11y_rescan_state( '', '2026-09-30T11:00:00Z', $now ), 'idle' );
check( 'rescan: pending', wpa11y_rescan_state( '2026-09-30T11:58:00Z', '2026-09-30T11:00:00Z', $now ), 'pending' );
check( 'rescan: pending with no scan ever', wpa11y_rescan_state( '2026-09-30T11:58:00Z', '', $now ), 'pending' );
check( 'rescan: done', wpa11y_rescan_state( '2026-09-30T11:58:00Z', '2026-09-30T11:59:00Z', $now ), 'idle' );
check( 'rescan: timed out after 10 minutes', wpa11y_rescan_state( '2026-09-30T11:50:00Z', '', $now ), 'timed_out' );
