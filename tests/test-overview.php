<?php
$items = array(
	array( 'id' => 1, 'title' => 'Beta', 'type' => 'page', 'errors' => 0, 'warnings' => 2, 'state' => 'issues', 'scanned_at' => '2026-09-30T10:00:00Z' ),
	array( 'id' => 2, 'title' => 'alpha', 'type' => 'page', 'errors' => 3, 'warnings' => 0, 'state' => 'issues', 'scanned_at' => '2026-09-30T10:01:00Z' ),
	array( 'id' => 3, 'title' => 'Gamma', 'type' => 'post', 'errors' => 0, 'warnings' => 0, 'state' => 'unscanned', 'scanned_at' => '' ),
	array( 'id' => 4, 'title' => 'Delta', 'type' => 'page', 'errors' => 1, 'warnings' => 0, 'state' => 'failed', 'scanned_at' => '2026-09-29T10:00:00Z' ),
);
$ids = function ( $rows ) { return array_column( $rows, 'id' ); };
check( 'overview: errors desc, ties by title', $ids( wpa11y_overview_rows( $items, 'all', 'errors', 'desc' ) ), array( 2, 4, 1, 3 ) );
check( 'overview: title asc, case-insensitive', $ids( wpa11y_overview_rows( $items, 'all', 'title', 'asc' ) ), array( 2, 1, 4, 3 ) );
check( 'overview: scanned desc', $ids( wpa11y_overview_rows( $items, 'all', 'scanned', 'desc' ) ), array( 2, 1, 4, 3 ) );
check( 'overview: filter errors', $ids( wpa11y_overview_rows( $items, 'errors', 'errors', 'desc' ) ), array( 2, 4 ) );
check( 'overview: filter warnings', $ids( wpa11y_overview_rows( $items, 'warnings', 'errors', 'desc' ) ), array( 1 ) );
check( 'overview: filter failed', $ids( wpa11y_overview_rows( $items, 'failed', 'errors', 'desc' ) ), array( 4 ) );
check( 'overview: filter unscanned', $ids( wpa11y_overview_rows( $items, 'unscanned', 'errors', 'desc' ) ), array( 3 ) );
check( 'overview: unknown filter and sort fall back', $ids( wpa11y_overview_rows( $items, 'bogus', 'bogus', 'desc' ) ), array( 2, 4, 1, 3 ) );
check( 'overview: filter counts', wpa11y_filter_counts( $items ), array( 'all' => 4, 'errors' => 2, 'warnings' => 1, 'failed' => 1, 'unscanned' => 1 ) );

$html = wpa11y_render_overview( $items, 'all', 'errors', 'desc', '2026-09-30T10:05:00Z' );
check( 'overview: summary', strpos( $html, '4 pages checked. 2 with errors. 2 warnings awaiting review.' ) !== false, true );
check( 'overview: last full scan', strpos( $html, 'Last full scan: Sep 30, 2026 10:05 am.' ) !== false, true );
check( 'overview: sorted column marked', substr_count( $html, 'aria-sort="descending"' ), 1 );
check( 'overview: current filter marked', substr_count( $html, 'aria-current="page"' ), 1 );
check( 'overview: row header links to detail', strpos( $html, '<th scope="row"><strong><a href="' . WT_SITE . '/wp-admin/admin.php?page=wpa11y&amp;post=2">alpha</a>' ) !== false, true );
check( 'overview: unscanned counts not shown as zero', strpos( $html, '<span class="screen-reader-text">Not scanned</span>' ) !== false, true );
check( 'overview: empty filter message', strpos( wpa11y_render_overview( array(), 'failed', 'errors', 'desc', '' ), 'No pages match this filter.' ) !== false, true );
check( 'overview: no full scan yet', strpos( wpa11y_render_overview( array(), 'all', 'errors', 'desc', '' ), 'No full scan has reported yet.' ) !== false, true );

wt_reset( array( 'posts' => array( array( 'ID' => 10 ), array( 'ID' => 12, 'post_type' => 'post', 'post_title' => 'News' ) ) ) );
wpa11y_save_result( 10, wt_result( array( wt_issue( 'error', 'image-alt', 'img' ), wt_issue( 'warning', 'color-contrast', '#a' ) ) ) );
wpa11y_add_dismissal( 10, 'color-contrast', '#a', '', 5 );
$built = wpa11y_overview_items();
check( 'items: counts exclude dismissed', $built[1], array( 'id' => 10, 'title' => 'Page 10', 'type' => 'page', 'errors' => 1, 'warnings' => 0, 'state' => 'issues', 'scanned_at' => '2026-09-30T10:00:00Z' ) );
check( 'items: unscanned', array( $built[0]['id'], $built[0]['state'] ), array( 12, 'unscanned' ) );

check( 'label: issues', wpa11y_counts_label( array( 'errors' => 1, 'warnings' => 2, 'dismissed' => 0, 'state' => 'issues' ) ), '1 error · 2 warnings' );
check( 'label: clean', wpa11y_counts_label( array( 'errors' => 0, 'warnings' => 0, 'dismissed' => 3, 'state' => 'clean' ) ), 'No issues' );
check( 'label: unscanned', wpa11y_counts_label( array( 'errors' => 0, 'warnings' => 0, 'dismissed' => 0, 'state' => 'unscanned' ) ), 'Not scanned' );
check( 'label: failed', wpa11y_counts_label( array( 'errors' => 1, 'warnings' => 0, 'dismissed' => 0, 'state' => 'failed' ) ), 'Scan failed' );
check( 'column: links to detail', wpa11y_column_html( 10 ), '<a href="' . WT_SITE . '/wp-admin/admin.php?page=wpa11y&amp;post=10">1 error · 0 warnings</a>' );
wt_add_post( array( 'ID' => 11, 'post_status' => 'draft' ) );
check( 'column: drafts are not scanned', wpa11y_column_html( 11 ), 'Not published' );
