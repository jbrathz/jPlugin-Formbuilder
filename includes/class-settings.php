<?php

namespace JFB;

final class Settings {
	public const OPTION = 'jfb_settings';

	public static function defaults(): array {
		return array(
			'turnstile_enabled' => false,
			'turnstile_site_key' => '',
			'turnstile_secret_key' => '',
			'rate_limit' => 10,
			'min_fill_seconds' => 2,
			'palette' => self::default_palette(),
		);
	}

	public static function get(): array {
		return array_replace_recursive( self::defaults(), (array) get_option( self::OPTION, array() ) );
	}

	public static function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : array();
		$current = self::get();
		$secret = trim( (string) ( $input['turnstile_secret_key'] ?? '' ) );
		if ( '' === $secret || '••••••••' === $secret ) {
			$secret = $current['turnstile_secret_key'];
		}

		$palette = self::default_palette();
		foreach ( array_keys( $palette ) as $key ) {
			$palette[ $key ] = sanitize_hex_color( $input['palette'][ $key ] ?? '' ) ?: $palette[ $key ];
		}

		return array(
			'turnstile_enabled' => ! empty( $input['turnstile_enabled'] ),
			'turnstile_site_key' => sanitize_text_field( (string) ( $input['turnstile_site_key'] ?? '' ) ),
			'turnstile_secret_key' => sanitize_text_field( $secret ),
			'rate_limit' => min( 100, max( 1, absint( $input['rate_limit'] ?? 10 ) ) ),
			'min_fill_seconds' => min( 30, max( 1, absint( $input['min_fill_seconds'] ?? 2 ) ) ),
			'palette' => $palette,
		);
	}

	public static function default_palette(): array {
		return array(
			'primary' => '#176b5b',
			'accent' => '#d97706',
			'background' => '#ffffff',
			'text' => '#18332e',
			'border' => '#c9d8d3',
			'error' => '#b42318',
		);
	}

	public static function site_key(): string {
		return defined( 'JFB_TURNSTILE_SITE_KEY' ) ? (string) JFB_TURNSTILE_SITE_KEY : (string) self::get()['turnstile_site_key'];
	}

	public static function secret_key(): string {
		return defined( 'JFB_TURNSTILE_SECRET_KEY' ) ? (string) JFB_TURNSTILE_SECRET_KEY : (string) self::get()['turnstile_secret_key'];
	}
}

