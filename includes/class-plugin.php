<?php

namespace JFB;

final class Plugin {
	private Repository $repository;
	private Submission_Service $submissions;

	public function run(): void {
		$this->repository  = new Repository();
		$this->submissions = new Submission_Service( $this->repository );
		$renderer          = new Renderer( $this->repository );

		add_action( 'init', array( $this, 'register_assets_and_block' ) );
		add_shortcode( 'jplugin_form', array( $renderer, 'render_shortcode' ) );
		add_action( 'rest_api_init', array( new Rest_Controller( $this->repository, $this->submissions ), 'register_routes' ) );
		add_action( 'admin_post_nopriv_jfb_submit', array( $this, 'handle_fallback_submission' ) );
		add_action( 'admin_post_jfb_submit', array( $this, 'handle_fallback_submission' ) );
		add_action( 'admin_post_jfb_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_jfb_download', array( $this, 'handle_download' ) );
		add_action( 'admin_post_jfb_submission_status', array( $this, 'handle_submission_status' ) );
		add_action( 'admin_post_jfb_purge_submission', array( $this, 'handle_purge_submission' ) );
		add_action( 'admin_post_jfb_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_jfb_turnstile_test', array( $this, 'handle_turnstile_test' ) );
		add_action( 'jfb_daily_maintenance', array( $this->repository, 'maintenance' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( JFB_FILE ), array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
		add_filter( 'site_status_tests', array( $this, 'site_health_tests' ) );

		if ( is_admin() ) {
			$admin = new Admin( $this->repository );
			add_action( 'admin_menu', array( $admin, 'register_menu' ) );
			add_action( 'admin_init', array( $admin, 'register_settings' ) );
			add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_assets' ) );
		}

		if ( get_option( 'jfb_schema_version' ) !== Database::SCHEMA_VERSION ) {
			Database::install();
		}
	}

	public function register_assets_and_block(): void {
		wp_register_style( 'jfb-public', JFB_URL . 'assets/css/public.css', array(), JFB_VERSION );
		wp_register_script( 'jfb-public', JFB_URL . 'assets/js/public.js', array(), JFB_VERSION, true );
		wp_register_script( 'jfb-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true );
		register_block_type(
			JFB_PATH . 'blocks/form',
			array(
				'render_callback' => array( new Renderer( $this->repository ), 'render_block' ),
			)
		);
	}

	public function handle_fallback_submission(): void {
		$uuid = sanitize_text_field( wp_unslash( (string) ( $_POST['jfb_form_uuid'] ?? '' ) ) );
		$form = wp_is_uuid( $uuid ) ? $this->repository->get_form( $uuid, true ) : null;
		$redirect = wp_get_referer() ?: home_url( '/' );
		$anchor = sanitize_html_class( wp_unslash( (string) ( $_POST['jfb_feedback_anchor'] ?? '' ) ) );
		if ( ! $form ) {
			wp_safe_redirect( $this->redirect_with_fragment( add_query_arg( array( 'jfb_status' => 'error' ), $redirect ), $anchor ) ); exit;
		}
		$values = array();
		$files  = array();
		foreach ( $form['fields'] as $field ) {
			$key = $field['key'];
			if ( isset( $_POST[ $key ] ) ) {
				$values[ $key ] = is_array( $_POST[ $key ] ) ? array_map( 'wp_unslash', $_POST[ $key ] ) : wp_unslash( $_POST[ $key ] );
			}
			if ( 'file' === $field['type'] && isset( $_FILES[ $key ] ) && is_array( $_FILES[ $key ] ) ) {
				$files[ $key ] = $_FILES[ $key ];
			}
		}
		$result = $this->submissions->submit(
			$uuid,
			$values,
			$files,
			array(
				'started_at' => absint( $_POST['jfb_started_at'] ?? 0 ),
				'website' => sanitize_text_field( wp_unslash( $_POST['jfb_website'] ?? '' ) ),
				'turnstile' => sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ?? '' ) ),
			)
		);
		wp_safe_redirect( $this->redirect_with_fragment( add_query_arg( array( 'jfb_status' => is_wp_error( $result ) ? 'error' : 'success', 'jfb_form' => $uuid ), $redirect ), $anchor ) );
		exit;
	}

	private function redirect_with_fragment( string $url, string $anchor ): string {
		if ( '' === $anchor ) {
			return $url;
		}

		return $url . '#' . rawurlencode( $anchor );
	}

	public function handle_export(): void {
		if ( ! current_user_can( 'jfb_export_submissions' ) || ! check_admin_referer( 'jfb_export' ) ) {
			wp_die( esc_html__( 'You do not have permission to export submissions.', 'jplugin-formbuilder' ), 403 );
		}
		$items = $this->repository->list_submissions( array( 'limit' => 0 ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="jfb-submissions-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'wb' );
		fwrite( $out, "\xEF\xBB\xBF" );

		$field_columns = $this->export_field_columns( $items );
		$header = array_merge( array( 'submission_uuid', 'form', 'status', 'created_at' ), array_values( $field_columns ) );
		fputcsv( $out, $header );

		foreach ( $items as $item ) {
			$row = array(
				$this->export_cell( $item['uuid'] ),
				$this->export_cell( $item['form_name'] ?: __( 'Deleted form', 'jplugin-formbuilder' ) ),
				$this->export_cell( $item['status'] ),
				$this->export_cell( $item['created_at'] ),
			);

			$values = array();
			foreach ( $item['payload'] as $key => $value ) {
				$values[ $key ] = $this->export_cell( $value );
			}

			$files = $this->repository->get_submission( $item['uuid'] );
			foreach ( $files['files'] ?? array() as $file ) {
				$current = $values[ $file['field_key'] ] ?? '';
				$name = $this->export_cell( $file['original_name'] );
				$values[ $file['field_key'] ] = '' === $current ? $name : $current . ' | ' . $name;
			}

			foreach ( array_keys( $field_columns ) as $field_key ) {
				$row[] = $values[ $field_key ] ?? '';
			}

			fputcsv( $out, $row );
		}
		fclose( $out ); exit;
	}

	private function export_field_columns( array $items ): array {
		$columns = array();
		$used_labels = array();

		foreach ( $items as $item ) {
			foreach ( $item['fields'] as $field ) {
				$type = $field['type'] ?? '';
				$key = $field['key'] ?? '';
				if ( '' === $key || in_array( $type, array( 'heading', 'paragraph' ), true ) || isset( $columns[ $key ] ) ) {
					continue;
				}

				$label = trim( (string) ( $field['label'] ?? '' ) );
				if ( '' === $label ) {
					$label = $key;
				}

				if ( isset( $used_labels[ $label ] ) ) {
					$label .= ' (' . $key . ')';
				}

				$used_labels[ $label ] = true;
				$columns[ $key ] = $label;
			}
		}

		foreach ( $items as $item ) {
			foreach ( $item['payload'] as $key => $value ) {
				if ( isset( $columns[ $key ] ) ) {
					continue;
				}

				$columns[ $key ] = $key;
			}
		}

		return $columns;
	}

	private function export_cell( mixed $value ): string {
		$value = is_array( $value ) ? implode( ' | ', array_map( array( $this, 'export_cell' ), $value ) ) : (string) $value;
		return preg_match( '/^[=+\-@]/', $value ) ? "'" . $value : $value;
	}

	public function handle_download(): void {
		$uuid = sanitize_text_field( wp_unslash( (string) ( $_GET['file'] ?? '' ) ) );
		if ( ! current_user_can( 'jfb_view_submissions' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'jfb_download_' . $uuid ) ) {
			wp_die( esc_html__( 'You do not have permission to download this file.', 'jplugin-formbuilder' ), 403 );
		}
		$file = $this->repository->get_file( $uuid );
		$path = $file ? Storage::resolve( $file['storage_key'] ) : false;
		if ( ! $file || ! $path ) { wp_die( esc_html__( 'File not found.', 'jplugin-formbuilder' ), 404 ); }
		nocache_headers(); header( 'Content-Type: application/octet-stream' ); header( 'Content-Disposition: attachment; filename="' . rawurlencode( $file['original_name'] ) . '"' ); header( 'X-Content-Type-Options: nosniff' ); header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); exit; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	}

	public function handle_submission_status(): void {
		$uuid = sanitize_text_field( wp_unslash( (string) ( $_GET['submission'] ?? '' ) ) );
		$status = sanitize_key( wp_unslash( (string) ( $_GET['status'] ?? '' ) ) );
		if ( ! current_user_can( 'jfb_view_submissions' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'jfb_submission_status_' . $uuid ) ) { wp_die( esc_html__( 'Permission denied.', 'jplugin-formbuilder' ), 403 ); }
		$this->repository->update_submission_status( $uuid, $status );
		wp_safe_redirect( admin_url( 'admin.php?page=jfb-inbox' ) ); exit;
	}

	public function handle_purge_submission(): void {
		$uuid = sanitize_text_field( wp_unslash( (string) ( $_GET['submission'] ?? '' ) ) );
		if ( ! current_user_can( 'jfb_view_submissions' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'jfb_purge_submission_' . $uuid ) ) {
			wp_die( esc_html__( 'Permission denied.', 'jplugin-formbuilder' ), 403 );
		}
		$item = $this->repository->get_submission( $uuid );
		if ( $item && 'trash' === $item['status'] ) {
			$this->repository->purge_submission( $item['id'] );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=jfb-inbox&status=trash' ) ); exit;
	}

	public function handle_turnstile_test(): void {
		if ( ! current_user_can( 'jfb_manage_settings' ) || ! check_admin_referer( 'jfb_turnstile_test' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'jplugin-formbuilder' ), 403 );
		}
		$result = Security::test_turnstile_configuration();
		$status = is_wp_error( $result ) ? 'error' : 'ok';
		$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Cloudflare accepted the saved secret key.', 'jplugin-formbuilder' );
		set_transient(
			Security::turnstile_test_transient_key( get_current_user_id() ),
			array( 'status' => $status, 'message' => $message ),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( wp_make_link_relative( add_query_arg( array( 'page' => 'jfb-settings', 'jfb_test' => $status ), admin_url( 'admin.php' ) ) ) ); exit;
	}

	public function handle_save_settings(): void {
		if ( ! current_user_can( 'jfb_manage_settings' ) || ! check_admin_referer( 'jfb_save_settings' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'jplugin-formbuilder' ), 403 );
		}

		$input = isset( $_POST[ Settings::OPTION ] ) && is_array( $_POST[ Settings::OPTION ] )
			? wp_unslash( $_POST[ Settings::OPTION ] )
			: array();
		update_option( Settings::OPTION, Settings::sanitize( $input ) );

		$query = array( 'page' => 'jfb-settings', 'jfb_saved' => '1' );
		if ( 'save_test' === sanitize_key( wp_unslash( (string) ( $_POST['jfb_intent'] ?? '' ) ) ) ) {
			$result  = Security::test_turnstile_configuration();
			$status  = is_wp_error( $result ) ? 'error' : 'ok';
			$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Cloudflare accepted the saved secret key.', 'jplugin-formbuilder' );
			set_transient(
				Security::turnstile_test_transient_key( get_current_user_id() ),
				array( 'status' => $status, 'message' => $message ),
				MINUTE_IN_SECONDS
			);
			$query['jfb_test'] = $status;
		}

		wp_safe_redirect( wp_make_link_relative( add_query_arg( $query, admin_url( 'admin.php' ) ) ) );
		exit;
	}

	public function action_links( array $links ): array { array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=jfb-forms' ) ) . '">' . esc_html__( 'Forms', 'jplugin-formbuilder' ) . '</a>' ); return $links; }
	public function row_meta( array $links, string $file ): array { if ( plugin_basename( JFB_FILE ) === $file ) { $links[] = '<strong>' . esc_html__( 'Uninstall retains all forms, submissions, settings, and private files.', 'jplugin-formbuilder' ) . '</strong>'; } return $links; }
	public function site_health_tests( array $tests ): array {
		$tests['direct']['jfb_private_vault'] = array( 'label' => __( 'jPlugin Formbuilder private vault', 'jplugin-formbuilder' ), 'test' => array( $this, 'site_health_vault' ) );
		return $tests;
	}
	public function site_health_vault(): array {
		$protected = Storage::probe_direct_access( true );
		return array(
			'label' => $protected ? __( 'Formbuilder private uploads are blocked from direct web access', 'jplugin-formbuilder' ) : __( 'Formbuilder could not prove that private uploads are protected', 'jplugin-formbuilder' ),
			'status' => $protected ? 'good' : 'critical',
			'badge' => array( 'label' => __( 'Security', 'jplugin-formbuilder' ), 'color' => 'blue' ),
			'description' => '<p>' . ( $protected ? esc_html__( 'Uploaded files can only be retrieved through the authenticated download endpoint.', 'jplugin-formbuilder' ) : esc_html__( 'Move JFB_PRIVATE_UPLOAD_DIR outside the document root or add a server deny rule. Forms containing file fields cannot be published while this check fails.', 'jplugin-formbuilder' ) ) . '</p>',
			'actions' => '',
			'test' => 'jfb_private_vault',
		);
	}
}
