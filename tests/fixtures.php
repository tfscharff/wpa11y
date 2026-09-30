<?php
// Shared test data.

function wt_issue( $type, $code, $selector, $extra = array() ) {
	return array_merge( array(
		'type'     => $type,
		'code'     => $code,
		'message'  => ucfirst( $code ) . ' message',
		'selector' => $selector,
		'context'  => '<p>' . $code . '</p>',
		'help_url' => 'https://dequeuniversity.com/rules/axe/4.11/' . $code,
	), $extra );
}

// Renders a published page that has never been scanned.
function wt_render_unscanned() {
	wt_add_post( array( 'ID' => 77 ) );
	return wpa11y_render_detail( 77 );
}

function wt_result( array $issues, $scanned_at = '2026-09-30T10:00:00Z' ) {
	return array( 'scanned_at' => $scanned_at, 'url' => WT_SITE . '/page-10/', 'issues' => $issues, 'error' => null, 'error_at' => null );
}
