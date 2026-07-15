<?php

namespace JFB;

final class Submission_Service {
	public function __construct( private readonly Repository $repository ) {}

	public function submit( string $form_uuid, array $values, array $files, array $meta ): array|\WP_Error {
		$form = $this->repository->get_form( $form_uuid, true );
		if ( ! $form ) {
			return new \WP_Error( 'jfb_form_unavailable', __( 'This form is not available.', 'jplugin-formbuilder' ), array( 'status' => 404 ) );
		}

		$ip = Security::client_ip();
		if ( ! Security::rate_limit( $form_uuid, $ip ) ) {
			return new \WP_Error( 'jfb_rate_limit', __( 'Too many attempts. Please wait and try again.', 'jplugin-formbuilder' ), array( 'status' => 429 ) );
		}
		if ( ! empty( $meta['website'] ) ) {
			return new \WP_Error( 'jfb_rejected', __( 'The response could not be accepted.', 'jplugin-formbuilder' ), array( 'status' => 400 ) );
		}
		$started = absint( $meta['started_at'] ?? 0 );
		if ( ! $started || time() - $started < (int) Settings::get()['min_fill_seconds'] || time() - $started > DAY_IN_SECONDS ) {
			return new \WP_Error( 'jfb_timing', __( 'Please reload the form and try again.', 'jplugin-formbuilder' ), array( 'status' => 400 ) );
		}

		$turnstile = Security::verify_turnstile( sanitize_text_field( (string) ( $meta['turnstile'] ?? '' ) ), $ip );
		if ( is_wp_error( $turnstile ) ) {
			return $turnstile;
		}

		$payload = Validator::validate_submission( $form['fields'], $values );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		foreach ( $form['fields'] as $field ) {
			if ( 'file' === $field['type'] && ! empty( $field['required'] ) && empty( $files[ $field['key'] ]['name'] ) ) {
				return new \WP_Error( 'jfb_required', sprintf( __( '%s is required.', 'jplugin-formbuilder' ), $field['label'] ), array( 'status' => 400, 'field' => $field['key'] ) );
			}
		}

		$submission = $this->repository->insert_submission( $form, $payload, Security::ip_hash( $ip ) );
		if ( is_wp_error( $submission ) ) {
			return $submission;
		}

		foreach ( $form['fields'] as $field ) {
			$key = $field['key'];
			if ( 'file' !== $field['type'] || empty( $files[ $key ]['name'] ) ) {
				continue;
			}
			$stored = Storage::store( $files[ $key ], $field, $submission['id'] );
			if ( is_wp_error( $stored ) ) {
				$this->repository->update_submission_status( $submission['uuid'], 'trash' );
				return $stored;
			}
			if ( ! $this->repository->insert_file( $submission['id'], $key, $stored ) ) {
				Storage::delete( $stored['storage_key'] );
				$this->repository->update_submission_status( $submission['uuid'], 'trash' );
				return new \WP_Error( 'jfb_file_db', __( 'The upload metadata could not be saved.', 'jplugin-formbuilder' ), array( 'status' => 500 ) );
			}
		}

		$this->send_notification( $form, $submission );
		return array( 'uuid' => $submission['uuid'], 'message' => $form['settings']['success_message'] ?? __( 'Thank you. Your response has been received.', 'jplugin-formbuilder' ) );
	}

	private function send_notification( array $form, array $submission ): void {
		$to = sanitize_email( $form['settings']['notification_email'] ?? '' );
		if ( ! is_email( $to ) ) {
			return;
		}
		$url = add_query_arg( array( 'page' => 'jfb-inbox', 'submission' => $submission['uuid'] ), admin_url( 'admin.php' ) );
		$subject = sprintf( __( '[%s] New form response', 'jplugin-formbuilder' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$body = sprintf( __( "Form: %1\$s\nReceived: %2\$s UTC\nSubmission ID: %3\$s\nReview securely: %4\$s", 'jplugin-formbuilder' ), $form['name'], $submission['created_at'], $submission['uuid'], esc_url_raw( $url ) );
		wp_mail( $to, $subject, $body );
	}
}

