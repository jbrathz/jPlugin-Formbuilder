<?php

namespace JFB;

final class Activator {
	private const CAPS = array(
		'jfb_manage_forms',
		'jfb_view_submissions',
		'jfb_export_submissions',
		'jfb_manage_settings',
	);

	public static function activate(): void {
		Database::install();
		Storage::ensure_vault();

		$role = get_role( 'administrator' );
		if ( $role ) {
			foreach ( self::CAPS as $cap ) {
				$role->add_cap( $cap );
			}
		}

		if ( ! wp_next_scheduled( 'jfb_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'jfb_daily_maintenance' );
		}
	}

	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( 'jfb_daily_maintenance' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'jfb_daily_maintenance' );
		}
	}
}

