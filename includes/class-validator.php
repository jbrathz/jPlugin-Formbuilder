<?php

namespace JFB;

final class Validator {
	public const FIELD_TYPES = array( 'text', 'textarea', 'email', 'tel', 'number', 'url', 'date', 'select', 'radio', 'checkbox', 'consent', 'file', 'heading', 'paragraph' );

	public static function sanitize_form( array $input ): array|\WP_Error {
		$name   = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		$status = in_array( $input['status'] ?? '', array( 'draft', 'published' ), true ) ? $input['status'] : 'draft';
		if ( $name === '' ) {
			return new \WP_Error( 'jfb_form_name', __( 'A form name is required.', 'jplugin-formbuilder' ), array( 'status' => 400 ) );
		}

		$raw_fields = is_array( $input['fields'] ?? null ) ? $input['fields'] : array();
		if ( count( $raw_fields ) > 100 ) {
			return new \WP_Error( 'jfb_field_limit', __( 'A form can contain at most 100 fields.', 'jplugin-formbuilder' ), array( 'status' => 400 ) );
		}

		$fields = array();
		$keys   = array();
		foreach ( $raw_fields as $position => $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$type = in_array( $raw['type'] ?? '', self::FIELD_TYPES, true ) ? $raw['type'] : 'text';
			$key  = sanitize_key( (string) ( $raw['key'] ?? '' ) );
			if ( $key === '' ) {
				$key = 'field_' . ( $position + 1 );
			}
			if ( isset( $keys[ $key ] ) ) {
				return new \WP_Error( 'jfb_duplicate_key', __( 'Every field must have a unique key.', 'jplugin-formbuilder' ), array( 'status' => 400 ) );
			}
			$keys[ $key ] = true;

			$field = array(
				'id'          => wp_is_uuid( (string) ( $raw['id'] ?? '' ) ) ? $raw['id'] : wp_generate_uuid4(),
				'type'        => $type,
				'key'         => $key,
				'label'       => sanitize_text_field( (string) ( $raw['label'] ?? '' ) ),
				'help'        => sanitize_text_field( (string) ( $raw['help'] ?? '' ) ),
				'placeholder' => sanitize_text_field( (string) ( $raw['placeholder'] ?? '' ) ),
				'required'    => ! empty( $raw['required'] ),
			);

			if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ) {
				$choices = array_slice( array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $raw['choices'] ?? array() ) ) ) ), 0, 50 );
				if ( ! $choices ) {
					return new \WP_Error( 'jfb_choices_required', __( 'Choice fields need at least one option.', 'jplugin-formbuilder' ), array( 'status' => 400 ) );
				}
				$field['choices'] = $choices;
			}

			if ( 'file' === $type ) {
				$field['max_mb'] = min( 20, max( 1, absint( $raw['max_mb'] ?? 5 ) ) );
				$field['allowed_mimes'] = array_values( array_filter( array_map( 'sanitize_mime_type', (array) ( $raw['allowed_mimes'] ?? array() ) ) ) );
			}
			$fields[] = $field;
		}

		if ( 'published' === $status && self::has_file_field( $fields ) && ! Storage::is_protected() ) {
			return new \WP_Error( 'jfb_vault_unprotected', __( 'This form cannot be published until private upload storage is protected.', 'jplugin-formbuilder' ), array( 'status' => 409 ) );
		}

		$settings = self::sanitize_form_settings( is_array( $input['settings'] ?? null ) ? $input['settings'] : array() );

		return array(
			'name'     => $name,
			'slug'     => sanitize_title( (string) ( $input['slug'] ?? $name ) ),
			'status'   => $status,
			'fields'   => $fields,
			'settings' => $settings,
		);
	}

	public static function validate_submission( array $fields, array $values ): array|\WP_Error {
		if ( count( $values ) > 120 ) {
			return new \WP_Error( 'jfb_payload_limit', __( 'The submission contains too many values.', 'jplugin-formbuilder' ), array( 'status' => 400 ) );
		}

		$clean = array();
		foreach ( $fields as $field ) {
			$type = $field['type'] ?? 'text';
			$key  = $field['key'] ?? '';
			if ( $key === '' || in_array( $type, array( 'heading', 'paragraph', 'file' ), true ) ) {
				continue;
			}

			$value = $values[ $key ] ?? '';
			if ( 'checkbox' === $type ) {
				$value = is_array( $value ) ? $value : ( $value === '' ? array() : array( $value ) );
				$value = array_values( array_intersect( array_map( 'sanitize_text_field', $value ), $field['choices'] ?? array() ) );
				if ( ! empty( $field['required'] ) && ! $value ) {
					return self::required_error( $field );
				}
				$clean[ $key ] = $value;
				continue;
			}

			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( ! empty( $field['required'] ) && $value === '' ) {
				return self::required_error( $field );
			}
			if ( $value === '' ) {
				$clean[ $key ] = '';
				continue;
			}

			switch ( $type ) {
				case 'email':
					$value = sanitize_email( $value );
					if ( ! is_email( $value ) ) {
						return self::invalid_error( $field );
					}
					break;
				case 'url':
					$value = esc_url_raw( $value, array( 'http', 'https' ) );
					if ( $value === '' ) {
						return self::invalid_error( $field );
					}
					break;
				case 'number':
					if ( ! is_numeric( $value ) ) {
						return self::invalid_error( $field );
					}
					$value = (string) (float) $value;
					break;
				case 'date':
					$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
					if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
						return self::invalid_error( $field );
					}
					break;
				case 'select':
				case 'radio':
					$value = sanitize_text_field( $value );
					if ( ! in_array( $value, $field['choices'] ?? array(), true ) ) {
						return self::invalid_error( $field );
					}
					break;
				case 'consent':
					$value = in_array( strtolower( $value ), array( '1', 'yes', 'true', 'on' ), true ) ? '1' : '';
					if ( ! empty( $field['required'] ) && $value !== '1' ) {
						return self::required_error( $field );
					}
					break;
				case 'textarea':
					$value = mb_substr( sanitize_textarea_field( $value ), 0, 10000 );
					break;
				default:
					$value = mb_substr( sanitize_text_field( $value ), 0, 1000 );
			}
			$clean[ $key ] = $value;
		}

		return $clean;
	}

	private static function sanitize_form_settings( array $input ): array {
		$colors = Settings::default_palette();
		foreach ( array_keys( $colors ) as $key ) {
			if ( isset( $input['palette'][ $key ] ) ) {
				$colors[ $key ] = sanitize_hex_color( $input['palette'][ $key ] ) ?: $colors[ $key ];
			}
		}

		return array(
			'success_message' => sanitize_text_field( (string) ( $input['success_message'] ?? __( 'Thank you. Your response has been received.', 'jplugin-formbuilder' ) ) ),
			'notification_email' => sanitize_email( (string) ( $input['notification_email'] ?? get_option( 'admin_email' ) ) ),
			'retention_days' => min( 3650, max( 0, absint( $input['retention_days'] ?? 0 ) ) ),
			'palette' => $colors,
		);
	}

	private static function has_file_field( array $fields ): bool {
		foreach ( $fields as $field ) {
			if ( 'file' === ( $field['type'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function required_error( array $field ): \WP_Error {
		return new \WP_Error( 'jfb_required', sprintf( __( '%s is required.', 'jplugin-formbuilder' ), $field['label'] ?: $field['key'] ), array( 'status' => 400, 'field' => $field['key'] ) );
	}

	private static function invalid_error( array $field ): \WP_Error {
		return new \WP_Error( 'jfb_invalid', sprintf( __( '%s is invalid.', 'jplugin-formbuilder' ), $field['label'] ?: $field['key'] ), array( 'status' => 400, 'field' => $field['key'] ) );
	}
}

