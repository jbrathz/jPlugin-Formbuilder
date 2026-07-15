<?php
/**
 * Ephemeral fixture manager used by browser-smoke.py.
 */

require dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$action = $argv[1] ?? '';
$repo   = new JFB\Repository();

if ( 'setup' === $action ) {
	$suffix   = strtolower( wp_generate_password( 8, false, false ) );
	$username = 'jfb_test_' . $suffix;
	$password = wp_generate_password( 24, true, true );
	$user_id  = wp_create_user( $username, $password, $username . '@example.invalid' );
	if ( is_wp_error( $user_id ) ) {
		fwrite( STDERR, $user_id->get_error_message() );
		exit( 1 );
	}
	( new WP_User( $user_id ) )->set_role( 'administrator' );
	wp_set_current_user( $user_id );
	$expiration = time() + HOUR_IN_SECONDS;
	$token = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
	$form = $repo->save_form(
		array(
			'name' => 'Browser smoke form',
			'status' => 'published',
			'fields' => JFB\Templates::all()['contact']['fields'],
			'settings' => array( 'notification_email' => 'invalid', 'retention_days' => 1 ),
		)
	);
	if ( is_wp_error( $form ) ) {
		wp_delete_user( $user_id );
		fwrite( STDERR, $form->get_error_message() );
		exit( 1 );
	}
	$page_id = wp_insert_post(
		array(
			'post_title' => 'jPlugin Formbuilder Browser Smoke',
			'post_content' => '[jplugin_form id="' . $form['uuid'] . '"]',
			'post_status' => 'publish',
			'post_type' => 'page',
			'post_author' => $user_id,
		),
		true
	);
	if ( is_wp_error( $page_id ) ) {
		$repo->delete_form( $form['uuid'] );
		wp_delete_user( $user_id );
		fwrite( STDERR, $page_id->get_error_message() );
		exit( 1 );
	}
	echo wp_json_encode(
		array(
			'user_id' => $user_id,
			'form_uuid' => $form['uuid'],
			'page_id' => $page_id,
			'login_url' => wp_login_url(),
			'admin_url' => admin_url( 'admin.php?page=jfb-forms' ),
			'builder_url' => admin_url( 'admin.php?page=jfb-forms&form=' . $form['uuid'] ),
			'public_url' => get_permalink( $page_id ),
			'cookies' => array(
				array( 'name' => SECURE_AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $user_id, $expiration, 'secure_auth', $token ) ),
				array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token ) ),
			),
		)
	) . "\n";
	exit;
}

if ( 'cleanup' === $action ) {
	$user_id = absint( $argv[2] ?? 0 );
	$page_id = absint( $argv[3] ?? 0 );
	$uuid    = sanitize_text_field( $argv[4] ?? '' );
	$form    = wp_is_uuid( $uuid ) ? $repo->get_form( $uuid ) : null;
	if ( $form ) {
		foreach ( $repo->list_submissions( array( 'form_id' => $form['id'], 'limit' => 0 ) ) as $submission ) {
			$repo->purge_submission( $submission['id'] );
		}
		$repo->delete_form( $uuid );
	}
	if ( $page_id ) {
		wp_delete_post( $page_id, true );
	}
	if ( $user_id ) {
		wp_delete_user( $user_id );
	}
	echo "CLEAN\n";
	exit;
}

fwrite( STDERR, "Usage: browser-fixture.php setup|cleanup\n" );
exit( 2 );
