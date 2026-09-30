<?php
wt_reset( array( 'posts' => array( array( 'ID' => 10 ) ) ) );
wpa11y_save_result( 10, wt_result( array( wt_issue( 'warning', 'color-contrast', '#a' ), wt_issue( 'error', 'image-alt', 'img' ) ) ) );
wpa11y_add_dismissal( 10, 'color-contrast', '#a', 'Checked by eye', 5 ); // 1: still present
wpa11y_add_dismissal( 10, 'color-contrast', '#gone', '', 5 );           // 2: element changed
wpa11y_add_dismissal( 10, 'link-name', 'a', '', 5 );                    // 3: will be undone
wpa11y_undo_dismissal( 3, 5 );
wpa11y_add_dismissal( 99, 'region', 'div', '', 5 );                     // 4: page deleted

$rows = wpa11y_log_rows( wpa11y_rows(), 'all' );
check( 'log: newest first', array_column( $rows, 'id' ), array( '4', '3', '2', '1' ) );
check( 'log: statuses', array_column( $rows, 'status' ), array( 'gone', 'undone', 'gone', 'active' ) );
check( 'log: filter active', array_column( wpa11y_log_rows( wpa11y_rows(), 'active' ), 'id' ), array( '1' ) );
check( 'log: filter gone', array_column( wpa11y_log_rows( wpa11y_rows(), 'gone' ), 'id' ), array( '4', '2' ) );
check( 'log: filter undone', array_column( wpa11y_log_rows( wpa11y_rows(), 'undone' ), 'id' ), array( '3' ) );
check( 'log: unknown filter shows all', count( wpa11y_log_rows( wpa11y_rows(), 'bogus' ) ), 4 );

$html = wpa11y_render_log( $rows, 'all', '' );
check( 'log: undo buttons for active and gone', substr_count( $html, 'name="do" value="undo"' ), 3 );
check( 'log: restore button for undone', substr_count( $html, 'name="do" value="restore"' ), 1 );
check( 'log: buttons name their row for screen readers', strpos( $html, 'Undo<span class="screen-reader-text"> dismissal of color-contrast on Page 10</span>' ) !== false, true );
check( 'log: note shown', strpos( $html, 'Checked by eye' ) !== false, true );
check( 'log: deleted page named', strpos( $html, '(deleted page #99)' ) !== false, true );
check( 'log: undone by who', strpos( $html, 'Undone by Pat Editor' ) !== false, true );
check( 'log: no duplicate nonce ids', strpos( $html, 'id="_wpnonce"' ), false );
check( 'log: message after undo', strpos( wpa11y_render_log( $rows, 'all', 'log_undo' ), 'Dismissal undone.' ) !== false, true );
check( 'log: empty state', strpos( wpa11y_render_log( array(), 'undone', '' ), 'No dismissals match.' ) !== false, true );

check( 'action: undo active', wpa11y_log_action( 'undo', 1, 5 ), true );
check( 'action: undo twice refused', wpa11y_log_action( 'undo', 1, 5 ), false );
check( 'action: restore undone', wpa11y_log_action( 'restore', 3, 5 ), true );
check( 'action: unknown action refused', wpa11y_log_action( 'delete', 2, 5 ), false );
check( 'action: rows never deleted', count( wpa11y_rows() ), 5 );
