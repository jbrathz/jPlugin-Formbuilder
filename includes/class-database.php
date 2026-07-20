<?php

namespace JFB;

final class Database {
	public const SCHEMA_VERSION = '1.0.1';

	public static function table( string $name ): string {
		global $wpdb;
		$allowed = array( 'forms', 'submissions', 'submission_files', 'rate_limits' );
		if ( ! in_array( $name, $allowed, true ) ) {
			throw new \InvalidArgumentException( 'Unknown jPlugin Formbuilder table.' );
		}

		return $wpdb->prefix . 'jfb_' . $name;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$forms   = self::table( 'forms' );
		$subs    = self::table( 'submissions' );
		$files   = self::table( 'submission_files' );
		$limits  = self::table( 'rate_limits' );

		$sql = array(
			"CREATE TABLE {$forms} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				name varchar(190) NOT NULL,
				slug varchar(190) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'draft',
				schema_json longtext NOT NULL,
				settings_json longtext NOT NULL,
				version int(10) unsigned NOT NULL DEFAULT 1,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				KEY status (status),
				KEY slug (slug)
			) {$charset};",
			"CREATE TABLE {$subs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				form_id bigint(20) unsigned NOT NULL,
				form_version int(10) unsigned NOT NULL,
				schema_snapshot_json longtext NOT NULL,
				payload_json longtext NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'new',
				ip_hash char(64) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				expires_at datetime NULL,
				deleted_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				KEY form_status_date (form_id,status,created_at),
				KEY created_at (created_at),
				KEY expires_at (expires_at),
				KEY deleted_at (deleted_at)
			) {$charset};",
			"CREATE TABLE {$files} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				submission_id bigint(20) unsigned NOT NULL,
				field_key varchar(100) NOT NULL,
				storage_key varchar(255) NOT NULL,
				original_name varchar(255) NOT NULL,
				mime_type varchar(100) NOT NULL,
				size_bytes bigint(20) unsigned NOT NULL,
				sha256 char(64) NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				UNIQUE KEY storage_key (storage_key),
				KEY submission_id (submission_id)
			) {$charset};",
			"CREATE TABLE {$limits} (
				bucket_hash char(64) NOT NULL,
				window_start int(10) unsigned NOT NULL,
				hit_count int(10) unsigned NOT NULL DEFAULT 1,
				expires_at int(10) unsigned NOT NULL,
				PRIMARY KEY  (bucket_hash),
				KEY expires_at (expires_at)
			) {$charset};",
		);

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'jfb_schema_version', self::SCHEMA_VERSION, false );
	}
}
