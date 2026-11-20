<?php

namespace JFB;

final class Mailer {
	public static function notify( array $form, array $submission, array $payload ): void {
		$settings = $form['settings'];
		$recipients = $settings['notification_emails'] ?? ( empty( $settings['notification_email'] ) ? array() : array( $settings['notification_email'] ) );
		$url = add_query_arg( array( 'page' => 'jfb-inbox', 'submission' => $submission['uuid'] ), admin_url( 'admin.php' ) );
		$subject = sprintf( __( '[%s] New form response', 'jplugin-formbuilder' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$body = sprintf( __( "Form: %1\$s\nReceived: %2\$s UTC\nSubmission ID: %3\$s\nReview securely: %4\$s", 'jplugin-formbuilder' ), $form['name'], $submission['created_at'], $submission['uuid'], esc_url_raw( $url ) );
		$answers = array();
		foreach ( $form['fields'] as $field ) {
			$key = $field['key'] ?? '';
			$type = $field['type'] ?? 'text';
			if ( '' === $key || in_array( $type, array( 'heading', 'paragraph', 'file' ), true ) ) {
				continue;
			}
			$value = $payload[ $key ] ?? '';
			if ( '' === $value || array() === $value ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			} elseif ( 'consent' === $type ) {
				$value = __( 'Yes', 'jplugin-formbuilder' );
			}

			$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
			$value = str_replace( "\n", "\n  ", $value );
			$label = $field['label'] ?? '';
			$answers[] = sprintf( __( '%1$s: %2$s', 'jplugin-formbuilder' ), '' !== $label ? $label : $key, $value );
		}
		if ( $answers ) {
			$body .= "\n\n" . __( 'Response details', 'jplugin-formbuilder' ) . "\n\n" . implode( "\n\n", $answers );
		}

		foreach ( (array) $recipients as $recipient ) {
			if ( is_string( $recipient ) && is_email( $recipient ) ) {
				self::queue_and_send( $submission['id'], 'notification', $recipient, $subject, $body );
			}
		}
		if ( empty( $settings['confirmation_enabled'] ) ) { return; }
		$recipient = '';
		foreach ( $form['fields'] as $field ) {
			if ( 'email' === $field['type'] && ( $field['id'] ?? '' ) === ( $settings['confirmation_field'] ?? '' ) ) {
				$recipient = $payload[ $field['key'] ] ?? '';
				break;
			}
		}
		$replace = array( '{form_name}' => $form['name'], '{submission_id}' => $submission['uuid'] );
		$subject = strtr( $settings['confirmation_subject'] ?? '', $replace );
		$body = strtr( $settings['confirmation_body'] ?? '', $replace );
		self::queue_and_send( $submission['id'], 'confirmation', is_string( $recipient ) && is_email( $recipient ) ? $recipient : '', $subject, $body );
	}

	private static function queue_and_send( int $submission_id, string $kind, string $recipient, string $subject, string $body ): void {
		global $wpdb;
		$ok = $wpdb->insert( Database::table( 'mail_log' ), array(
			'submission_id' => $submission_id, 'kind' => $kind, 'recipient' => $recipient,
			'subject' => $subject, 'body' => $body, 'status' => $recipient ? 'pending' : 'skipped',
		) );
		if ( false !== $ok && $recipient ) { self::send( (int) $wpdb->insert_id, 'pending' ); }
		if ( false === $ok ) { error_log( 'jPlugin Formbuilder: could not record email status.' ); }
	}

	public static function send( int $id, string $expected = 'failed' ): bool {
		global $wpdb;
		$table = Database::table( 'mail_log' );
		// Claim the row before sending so simultaneous retry requests cannot duplicate it.
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE id = %d AND status = %s", 'sending', $id, $expected ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( 1 !== $claimed ) { return false; }
		$mail = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $mail ) { return false; }
		$error_code = '';
		$capture_error = static function ( $error ) use ( &$error_code ): void { $error_code = sanitize_key( $error->get_error_code() ); };
		add_action( 'wp_mail_failed', $capture_error );
		$headers = array();
		$plain_text = static fn(): string => 'text/plain';
		if ( 'notification' === $mail['kind'] ) {
			// Keep submitted text from being interpreted as HTML by site-wide mail settings.
			$headers[] = 'Content-Type: text/plain; charset=UTF-8';
			add_filter( 'wp_mail_content_type', $plain_text, PHP_INT_MAX );
		}
		try {
			$sent = wp_mail( $mail['recipient'], $mail['subject'], $mail['body'], $headers );
		} finally {
			if ( $headers ) {
				remove_filter( 'wp_mail_content_type', $plain_text, PHP_INT_MAX );
			}
			remove_action( 'wp_mail_failed', $capture_error );
		}
		$wpdb->update( $table, array( 'status' => $sent ? 'sent' : 'failed', 'error_code' => $sent ? '' : ( $error_code ?: 'mail_failed' ), 'attempted_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) );
		return $sent;
	}
}
