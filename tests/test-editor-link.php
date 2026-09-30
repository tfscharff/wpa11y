<?php
wt_reset( array( 'posts' => array( array( 'ID' => 10 ) ) ) );
$GLOBALS['wt_no_edit'] = false;
wpa11y_save_result( 10, wt_result( array(
	wt_issue( 'error', 'list', 'ul:nth-child(3)', array( 'context' => '<ul><br><li>Physical condition</li></ul>' ) ),
	wt_issue( 'warning', 'color-contrast', '#a' ),
) ) );
$key  = wpa11y_issue_key( 'list', 'ul:nth-child(3)' );
$html = wpa11y_render_detail( 10 );
check( 'editor link: one per error and warning', substr_count( $html, 'class="button wpa11y-show-in-editor"' ), 2 );
check( 'editor link: opens the edit screen with the issue key', strpos( $html, 'href="' . WT_SITE . '/wp-admin/post.php?post=10&amp;action=edit&amp;wpa11y_find=' . $key . '"' ) !== false, true );
check( 'editor link: names its issue for screen readers', strpos( $html, 'Show in editor<span class="screen-reader-text">: list, ul:nth-child(3)</span>' ) !== false, true );

wpa11y_add_dismissal( 10, 'color-contrast', '#a', '', 5 );
check( 'editor link: none on dismissed items', substr_count( wpa11y_render_detail( 10 ), 'wpa11y-show-in-editor' ), 1 );

$GLOBALS['wt_no_edit'] = true;
$html = wpa11y_render_detail( 10 );
check( 'editor link: hidden when the user cannot edit the page', strpos( $html, 'wpa11y-show-in-editor' ), false );
check( 'edit page link: hidden when the user cannot edit the page', strpos( $html, 'Edit page' ), false );
$GLOBALS['wt_no_edit'] = false;

check( 'find payload: issue for a current key', wpa11y_find_payload( 10, $key ), array( 'found' => true, 'code' => 'list', 'message' => 'List message', 'selector' => 'ul:nth-child(3)', 'context' => '<ul><br><li>Physical condition</li></ul>', 'text' => '' ) );
check( 'find payload: stale key', wpa11y_find_payload( 10, 'stale' ), array( 'found' => false ) );
check( 'find payload: unscanned page', wpa11y_find_payload( 99, $key ), array( 'found' => false ) );

$cfg = wpa11y_editor_config( 10, $key );
check( 'editor config: carries the issue', array( $cfg['issue']['found'], $cfg['issue']['code'] ), array( true, 'list' ) );
check( 'editor config: messages take the issue text', array( strpos( $cfg['strings']['found'], '%s' ) !== false, strpos( $cfg['strings']['notFound'], '%s' ) !== false ), array( true, true ) );
check( 'editor config: key is cleaned before lookup', wpa11y_editor_config( 10, strtoupper( $key ) . '<x>' )['issue']['found'], false );
