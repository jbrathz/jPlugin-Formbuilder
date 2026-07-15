<?php
/**
 * jPlugin Formbuilder intentionally retains all data on uninstall.
 * See readme.txt and Formbuilder > Settings > Data & Privacy for manual cleanup.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Intentional no-op: forms, submissions, settings, and private files are retained.

