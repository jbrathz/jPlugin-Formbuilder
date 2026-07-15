<?php

namespace JFB;

final class Security {
	private const CF_IPV4_URL = 'https://www.cloudflare.com/ips-v4';
	private const CF_IPV6_URL = 'https://www.cloudflare.com/ips-v6';

	public static function client_ip(): string {
		$remote = filter_var( $_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP ) ?: '';
		$cf_ip  = filter_var( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '', FILTER_VALIDATE_IP ) ?: '';
		if ( $remote && $cf_ip && self::is_cloudflare_ip( $remote ) ) {
			return $cf_ip;
		}
		return $remote;
	}

	public static function ip_hash( string $ip ): string {
		return hash_hmac( 'sha256', $ip ?: 'unknown', wp_salt( 'nonce' ) );
	}

	public static function rate_limit( string $form_uuid, string $ip ): bool {
		global $wpdb;
		$settings = Settings::get();
		$limit    = (int) $settings['rate_limit'];
		$window   = 10 * MINUTE_IN_SECONDS;
		$start    = (int) floor( time() / $window ) * $window;
		$bucket   = hash_hmac( 'sha256', $form_uuid . '|' . $ip . '|' . $start, wp_salt( 'auth' ) );
		$table    = Database::table( 'rate_limits' );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (bucket_hash,window_start,hit_count,expires_at) VALUES (%s,%d,1,%d)
				 ON DUPLICATE KEY UPDATE hit_count=hit_count+1",
				$bucket,
				$start,
				$start + $window * 2
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT hit_count FROM {$table} WHERE bucket_hash=%s", $bucket ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $count <= $limit;
	}

	public static function verify_turnstile( string $token, string $ip ): true|\WP_Error {
		$settings = Settings::get();
		if ( empty( $settings['turnstile_enabled'] ) ) {
			return true;
		}
		$secret = Settings::secret_key();
		if ( $token === '' || $secret === '' ) {
			return new \WP_Error( 'jfb_turnstile_required', __( 'Security verification is required.', 'jplugin-formbuilder' ), array( 'status' => 400 ) );
		}
		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 8,
				'body' => array( 'secret' => $secret, 'response' => $token, 'remoteip' => $ip ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'jfb_turnstile_unavailable', __( 'Security verification is temporarily unavailable.', 'jplugin-formbuilder' ), array( 'status' => 503 ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return ! empty( $data['success'] ) ? true : new \WP_Error( 'jfb_turnstile_failed', __( 'Security verification failed.', 'jplugin-formbuilder' ), array( 'status' => 400 ) );
	}

	public static function refresh_cloudflare_ranges(): bool {
		$ranges = array();
		foreach ( array( self::CF_IPV4_URL, self::CF_IPV6_URL ) as $url ) {
			$response = wp_safe_remote_get( $url, array( 'timeout' => 8 ) );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return false;
			}
			$ranges = array_merge( $ranges, preg_split( '/\s+/', trim( wp_remote_retrieve_body( $response ) ) ) );
		}
		$ranges = array_values( array_filter( $ranges, static fn( $cidr ) => str_contains( $cidr, '/' ) ) );
		if ( ! $ranges ) {
			return false;
		}
		set_transient( 'jfb_cf_ranges', $ranges, 7 * DAY_IN_SECONDS );
		return true;
	}

	public static function test_turnstile_configuration(): true|\WP_Error {
		$secret = Settings::secret_key();
		if ( '' === Settings::site_key() || '' === $secret ) {
			return new \WP_Error( 'jfb_turnstile_keys', __( 'Save both Turnstile keys before testing.', 'jplugin-formbuilder' ) );
		}
		$response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', array( 'timeout' => 8, 'body' => array( 'secret' => $secret, 'response' => 'configuration-test' ) ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$errors = (array) ( $data['error-codes'] ?? array() );
		if ( in_array( 'invalid-input-secret', $errors, true ) || in_array( 'missing-input-secret', $errors, true ) ) {
			return new \WP_Error( 'jfb_turnstile_secret', __( 'Cloudflare rejected the secret key.', 'jplugin-formbuilder' ) );
		}
		return true;
	}

	private static function is_cloudflare_ip( string $ip ): bool {
		$ranges = get_transient( 'jfb_cf_ranges' );
		if ( ! is_array( $ranges ) ) {
			self::refresh_cloudflare_ranges();
			$ranges = get_transient( 'jfb_cf_ranges' );
		}
		foreach ( (array) $ranges as $cidr ) {
			if ( self::ip_in_cidr( $ip, $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	private static function ip_in_cidr( string $ip, string $cidr ): bool {
		[ $subnet, $bits ] = array_pad( explode( '/', $cidr, 2 ), 2, null );
		$ip_bin = inet_pton( $ip );
		$sub_bin = inet_pton( $subnet );
		if ( false === $ip_bin || false === $sub_bin || strlen( $ip_bin ) !== strlen( $sub_bin ) ) {
			return false;
		}
		$bits = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		$remainder = $bits % 8;
		if ( substr( $ip_bin, 0, $bytes ) !== substr( $sub_bin, 0, $bytes ) ) {
			return false;
		}
		return 0 === $remainder || ( ord( $ip_bin[ $bytes ] ) & ( 0xff << ( 8 - $remainder ) ) ) === ( ord( $sub_bin[ $bytes ] ) & ( 0xff << ( 8 - $remainder ) ) );
	}
}
