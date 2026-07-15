<?php
/**
 * Confirms that uninstall.php is non-destructive.
 */

require dirname( __DIR__, 4 ) . '/wp-load.php';

global $wpdb;
$before = array();
foreach ( array( 'forms', 'submissions', 'submission_files', 'rate_limits' ) as $name ) {
	$table = $wpdb->prefix . 'jfb_' . $name;
	$before[ $table ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'jPlugin-Formbuilder/jplugin-formbuilder.php' );
}
require dirname( __DIR__ ) . '/uninstall.php';

foreach ( $before as $table => $exists ) {
	$after = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists !== $after ) {
		fwrite( STDERR, "FAIL: uninstall changed {$table}\n" );
		exit( 1 );
	}
}

echo "PASS: uninstall retained all plugin tables\n";

