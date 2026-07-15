<?php
/**
 * Run inside the WordPress container:
 * php wp-content/plugins/jPlugin-Formbuilder/tests/runtime-smoke.php
 */

require dirname( __DIR__, 4 ) . '/wp-load.php';

$fail = static function ( string $message ): void {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
};

echo 'SITE=' . site_url() . "\n";
echo 'VAULT=' . JFB\Storage::base_dir() . "\n";
echo 'PROTECTED=' . ( JFB\Storage::is_protected() ? 'yes' : 'no' ) . "\n";

if ( get_option( 'jfb_schema_version' ) !== JFB\Database::SCHEMA_VERSION ) {
	$fail( 'Schema version is missing.' );
}

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	$fail( 'No administrator exists for the smoke test.' );
}
wp_set_current_user( $admin[0]->ID );

$repo    = new JFB\Repository();
$created = $repo->save_form(
	array(
		'name' => 'Runtime smoke form',
		'status' => 'draft',
		'fields' => JFB\Templates::all()['contact']['fields'],
		'settings' => array(),
	)
);
if ( is_wp_error( $created ) ) {
	$fail( $created->get_error_message() );
}
echo 'FORM=' . $created['uuid'] . ' VERSION=' . $created['version'] . "\n";

$created['name'] = 'Runtime smoke updated';
$updated = $repo->save_form( $created, $created['uuid'] );
if ( is_wp_error( $updated ) || 2 !== $updated['version'] ) {
	$fail( 'Form update/version check failed.' );
}

$_GET['form'] = $created['uuid'];
ob_start();
( new JFB\Admin( $repo ) )->forms_page();
$builder_html = ob_get_clean();
unset( $_GET['form'] );
if ( ! str_contains( $builder_html, 'data-jfb-builder' ) ) {
	$fail( 'Admin builder rendering failed.' );
}

do_action( 'rest_api_init' );
$routes = rest_get_server()->get_routes();
foreach ( array( '/jplugin-formbuilder/v1/forms', '/jplugin-formbuilder/v1/submissions' ) as $route ) {
	if ( ! isset( $routes[ $route ] ) ) {
		$fail( "Missing REST route {$route}." );
	}
}

$published = $repo->save_form(
	array(
		'name' => 'Runtime public form',
		'status' => 'published',
		'fields' => JFB\Templates::all()['contact']['fields'],
		'settings' => array( 'notification_email' => 'invalid', 'retention_days' => 1 ),
	)
);
if ( is_wp_error( $published ) ) {
	$fail( $published->get_error_message() );
}
$renderer = new JFB\Renderer( $repo );
if ( ! str_contains( $renderer->render( $published['uuid'] ), 'data-jfb-form' ) ) {
	$fail( 'Published form rendering failed.' );
}
$service = new JFB\Submission_Service( $repo );
$submitted = $service->submit(
	$published['uuid'],
	array( 'name' => 'Smoke Tester', 'email' => 'smoke@example.com', 'phone' => '', 'message' => 'Runtime test' ),
	array(),
	array( 'started_at' => time() - 5, 'website' => '', 'turnstile' => '' )
);
if ( is_wp_error( $submitted ) ) {
	$fail( $submitted->get_error_message() );
}
$saved_submission = $repo->get_submission( $submitted['uuid'] );
if ( ! $saved_submission || 'Smoke Tester' !== $saved_submission['payload']['name'] ) {
	$fail( 'Submission persistence failed.' );
}
$repo->purge_submission( $saved_submission['id'] );
$repo->delete_form( $published['uuid'] );

if ( ! $repo->delete_form( $created['uuid'] ) ) {
	$fail( 'Smoke-test form cleanup failed.' );
}

echo "PASS\n";
