<?php

namespace JFB;

final class Repository {
	public function list_forms(): array {
		global $wpdb;
		$table = Database::table( 'forms' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY updated_at DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( $this, 'hydrate_form' ), $rows ?: array() );
	}

	public function get_form( string $uuid, bool $published_only = false ): ?array {
		global $wpdb;
		$table = Database::table( 'forms' );
		$sql   = "SELECT * FROM {$table} WHERE uuid = %s";
		$args  = array( $uuid );
		if ( $published_only ) {
			$sql .= ' AND status = %s';
			$args[] = 'published';
		}
		$row = $wpdb->get_row( $wpdb->prepare( $sql, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ? $this->hydrate_form( $row ) : null;
	}

	public function save_form( array $data, ?string $uuid = null ): array|\WP_Error {
		global $wpdb;
		$table = Database::table( 'forms' );
		$now   = current_time( 'mysql', true );
		$user  = get_current_user_id();
		$clean = Validator::sanitize_form( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$record = array(
			'name'          => $clean['name'],
			'slug'          => $clean['slug'],
			'status'        => $clean['status'],
			'schema_json'   => wp_json_encode( array( 'fields' => $clean['fields'] ) ),
			'settings_json' => wp_json_encode( $clean['settings'] ),
			'updated_by'    => $user,
			'updated_at'    => $now,
		);

		if ( $uuid ) {
			$current = $this->get_form( $uuid );
			if ( ! $current ) {
				return new \WP_Error( 'jfb_not_found', __( 'Form not found.', 'jplugin-formbuilder' ), array( 'status' => 404 ) );
			}
			$record['version'] = (int) $current['version'] + 1;
			$ok = $wpdb->update( $table, $record, array( 'uuid' => $uuid ) );
		} else {
			$uuid = wp_generate_uuid4();
			$record += array( 'uuid' => $uuid, 'version' => 1, 'created_by' => $user, 'created_at' => $now );
			$ok = $wpdb->insert( $table, $record );
		}

		return false === $ok ? new \WP_Error( 'jfb_db_error', __( 'The form could not be saved.', 'jplugin-formbuilder' ), array( 'status' => 500 ) ) : $this->get_form( $uuid );
	}

	public function delete_form( string $uuid ): bool {
		global $wpdb;
		return false !== $wpdb->delete( Database::table( 'forms' ), array( 'uuid' => $uuid ) );
	}

	public function insert_submission( array $form, array $payload, string $ip_hash ): array|\WP_Error {
		global $wpdb;
		$table = Database::table( 'submissions' );
		$uuid  = wp_generate_uuid4();
		$days  = absint( $form['settings']['retention_days'] ?? 0 );
		$now   = current_time( 'mysql', true );
		$ok    = $wpdb->insert(
			$table,
			array(
				'uuid' => $uuid,
				'form_id' => $form['id'],
				'form_version' => $form['version'],
				'schema_snapshot_json' => wp_json_encode( array( 'fields' => $form['fields'] ) ),
				'payload_json' => wp_json_encode( $payload ),
				'status' => 'new',
				'ip_hash' => $ip_hash,
				'created_at' => $now,
				'expires_at' => $days ? gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS * $days ) : null,
			)
		);

		return false === $ok ? new \WP_Error( 'jfb_db_error', __( 'The response could not be saved.', 'jplugin-formbuilder' ), array( 'status' => 500 ) ) : array( 'id' => (int) $wpdb->insert_id, 'uuid' => $uuid, 'created_at' => $now );
	}

	public function insert_file( int $submission_id, string $field_key, array $stored ): bool {
		global $wpdb;
		return false !== $wpdb->insert( Database::table( 'submission_files' ), $stored + array( 'submission_id' => $submission_id, 'field_key' => $field_key, 'created_at' => current_time( 'mysql', true ) ) );
	}

	public function list_submissions( array $filters = array() ): array {
		global $wpdb;
		$subs  = Database::table( 'submissions' );
		$forms = Database::table( 'forms' );
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['form_id'] ) ) {
			$where[] = 's.form_id = %d';
			$args[]  = absint( $filters['form_id'] );
		}
		if ( ! empty( $filters['status'] ) && in_array( $filters['status'], array( 'new', 'read', 'trash' ), true ) ) {
			$where[] = 's.status = %s';
			$args[]  = $filters['status'];
		} else {
			$where[] = "s.status <> 'trash'";
		}
		$limit = array_key_exists( 'limit', $filters ) ? absint( $filters['limit'] ) : 200;
		$sql = "SELECT s.*, f.name AS form_name FROM {$subs} s LEFT JOIN {$forms} f ON f.id=s.form_id WHERE " . implode( ' AND ', $where ) . ' ORDER BY s.created_at DESC';
		if ( $limit ) {
			$sql .= ' LIMIT ' . $limit;
		}
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( $this, 'hydrate_submission' ), $rows ?: array() );
	}

	public function get_submission( string $uuid ): ?array {
		global $wpdb;
		$subs  = Database::table( 'submissions' );
		$forms = Database::table( 'forms' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT s.*, f.name AS form_name FROM {$subs} s LEFT JOIN {$forms} f ON f.id=s.form_id WHERE s.uuid=%s", $uuid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row ) {
			return null;
		}
		$item = $this->hydrate_submission( $row );
		$item['files'] = $wpdb->get_results( $wpdb->prepare( 'SELECT uuid,field_key,original_name,mime_type,size_bytes FROM ' . Database::table( 'submission_files' ) . ' WHERE submission_id=%d', $row['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $item;
	}

	public function update_submission_status( string $uuid, string $status ): bool {
		global $wpdb;
		if ( ! in_array( $status, array( 'new', 'read', 'trash' ), true ) ) {
			return false;
		}
		$data = array( 'status' => $status, 'deleted_at' => 'trash' === $status ? current_time( 'mysql', true ) : null );
		return false !== $wpdb->update( Database::table( 'submissions' ), $data, array( 'uuid' => $uuid ) );
	}

	public function get_file( string $uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Database::table( 'submission_files' ) . ' WHERE uuid=%s', $uuid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ?: null;
	}

	public function purge_submission( int $submission_id ): void {
		global $wpdb;
		$files = $wpdb->get_results( $wpdb->prepare( 'SELECT storage_key FROM ' . Database::table( 'submission_files' ) . ' WHERE submission_id=%d', $submission_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $files ?: array() as $file ) {
			Storage::delete( $file['storage_key'] );
		}
		$wpdb->delete( Database::table( 'submission_files' ), array( 'submission_id' => $submission_id ) );
		$wpdb->delete( Database::table( 'submissions' ), array( 'id' => $submission_id ) );
	}

	public function maintenance(): void {
		global $wpdb;
		$subs = Database::table( 'submissions' );
		$now = current_time( 'mysql', true );
		$grace = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "UPDATE {$subs} SET status='trash',deleted_at=%s WHERE status<>'trash' AND expires_at IS NOT NULL AND expires_at<=%s", $now, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$subs} WHERE status='trash' AND deleted_at IS NOT NULL AND deleted_at<=%s ORDER BY id ASC LIMIT 100", $grace ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $ids as $id ) {
			$this->purge_submission( (int) $id );
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Database::table( 'rate_limits' ) . ' WHERE expires_at < %d', time() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function hydrate_form( array $row ): array {
		$schema = json_decode( $row['schema_json'], true );
		$row['id'] = (int) $row['id'];
		$row['version'] = (int) $row['version'];
		$row['fields'] = is_array( $schema['fields'] ?? null ) ? $schema['fields'] : array();
		$row['settings'] = json_decode( $row['settings_json'], true ) ?: array();
		unset( $row['schema_json'], $row['settings_json'] );
		return $row;
	}

	private function hydrate_submission( array $row ): array {
		$row['id'] = (int) $row['id'];
		$row['form_id'] = (int) $row['form_id'];
		$row['payload'] = json_decode( $row['payload_json'], true ) ?: array();
		$schema = json_decode( $row['schema_snapshot_json'], true );
		$row['fields'] = $schema['fields'] ?? array();
		unset( $row['payload_json'], $row['schema_snapshot_json'], $row['ip_hash'] );
		return $row;
	}
}
