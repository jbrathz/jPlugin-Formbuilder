<?php

namespace JFB;

final class Storage {
	private const DEFAULT_MIMES = array(
		'image/jpeg' => array( 'jpg', 'jpeg' ),
		'image/png'  => array( 'png' ),
		'application/pdf' => array( 'pdf' ),
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => array( 'docx' ),
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => array( 'xlsx' ),
	);

	public static function base_dir(): string {
		if ( defined( 'JFB_PRIVATE_UPLOAD_DIR' ) && is_string( JFB_PRIVATE_UPLOAD_DIR ) && JFB_PRIVATE_UPLOAD_DIR !== '' ) {
			return untrailingslashit( JFB_PRIVATE_UPLOAD_DIR );
		}

		return WP_CONTENT_DIR . '/jfb-private';
	}

	public static function ensure_vault(): bool {
		$base = self::base_dir();
		if ( ! wp_mkdir_p( $base ) ) {
			return false;
		}

		$guards = array(
			'.htaccess'  => "Require all denied\nDeny from all\nOptions -Indexes\n<FilesMatch \".*\">\nRequire all denied\n</FilesMatch>\n",
			'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization><directoryBrowse enabled="false" /></system.webServer></configuration>',
			'index.php'  => "<?php\nhttp_response_code( 404 );\nexit;\n",
		);

		foreach ( $guards as $name => $content ) {
			$path = $base . '/' . $name;
			if ( ! file_exists( $path ) && false === file_put_contents( $path, $content, LOCK_EX ) ) {
				return false;
			}
		}

		return is_writable( $base );
	}

	public static function is_protected(): bool {
		$base = self::base_dir();
		if ( ! is_dir( $base ) || ! is_writable( $base ) ) {
			return false;
		}

		if ( ! self::is_within( $base, ABSPATH ) ) {
			return true;
		}

		if ( ! is_readable( $base . '/.htaccess' ) && ! is_readable( $base . '/web.config' ) ) {
			return false;
		}

		return self::probe_direct_access();
	}

	public static function probe_direct_access( bool $force = false ): bool {
		if ( ! self::is_within( self::base_dir(), ABSPATH ) ) {
			return true;
		}
		if ( ! $force ) {
			$cached = get_transient( 'jfb_vault_probe' );
			if ( 'protected' === $cached || 'exposed' === $cached ) {
				return 'protected' === $cached;
			}
		}

		$relative = ltrim( str_replace( wp_normalize_path( WP_CONTENT_DIR ), '', wp_normalize_path( self::base_dir() ) ), '/' );
		$url = content_url( '/' . $relative . '/index.php' );
		$response = wp_safe_remote_get( $url, array( 'timeout' => 6, 'redirection' => 2, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ) ) );
		$protected = ! is_wp_error( $response ) && in_array( wp_remote_retrieve_response_code( $response ), array( 401, 403, 404 ), true );
		set_transient( 'jfb_vault_probe', $protected ? 'protected' : 'exposed', HOUR_IN_SECONDS );
		return $protected;
	}

	public static function store( array $file, array $field, int $submission_id ): array|\WP_Error {
		if ( ! self::ensure_vault() || ! self::is_protected() ) {
			return new \WP_Error( 'jfb_storage_unavailable', __( 'Private file storage is not available.', 'jplugin-formbuilder' ), array( 'status' => 503 ) );
		}

		if ( ! isset( $file['tmp_name'], $file['name'], $file['size'], $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new \WP_Error( 'jfb_upload_error', __( 'The upload could not be completed.', 'jplugin-formbuilder' ), array( 'status' => 400 ) );
		}

		$max_mb = min( 20, max( 1, (int) ( $field['max_mb'] ?? 5 ) ) );
		if ( (int) $file['size'] < 1 || (int) $file['size'] > $max_mb * MB_IN_BYTES ) {
			return new \WP_Error( 'jfb_upload_size', __( 'The uploaded file has an invalid size.', 'jplugin-formbuilder' ), array( 'status' => 400 ) );
		}

		$original = sanitize_file_name( wp_basename( (string) $file['name'] ) );
		if ( $original === '' || preg_match( '/\.(?:php\d*|phtml|phar|cgi|pl|py|sh|js|html?|svg|xml|zip|rar|7z)(?:\.|$)/i', $original ) ) {
			return new \WP_Error( 'jfb_upload_type', __( 'This file type is not allowed.', 'jplugin-formbuilder' ), array( 'status' => 400 ) );
		}

		$ext   = strtolower( pathinfo( $original, PATHINFO_EXTENSION ) );
		$finfo = new \finfo( FILEINFO_MIME_TYPE );
		$mime  = (string) $finfo->file( $file['tmp_name'] );
		$mime  = self::office_mime( $file['tmp_name'], $ext, $mime );
		$map   = self::allowed_mimes( $field );
		if ( ! isset( $map[ $mime ] ) || ! in_array( $ext, $map[ $mime ], true ) ) {
			return new \WP_Error( 'jfb_upload_mismatch', __( 'The file contents do not match the extension.', 'jplugin-formbuilder' ), array( 'status' => 400 ) );
		}

		$folder = gmdate( 'Y/m' ) . '/' . $submission_id;
		$dir    = self::base_dir() . '/' . $folder;
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'jfb_upload_write', __( 'The file could not be stored.', 'jplugin-formbuilder' ), array( 'status' => 500 ) );
		}

		$key  = $folder . '/' . wp_generate_uuid4() . '.' . $ext;
		$path = self::base_dir() . '/' . $key;
		if ( ! self::is_within( $path, self::base_dir() ) || ! move_uploaded_file( $file['tmp_name'], $path ) ) {
			return new \WP_Error( 'jfb_upload_write', __( 'The file could not be stored.', 'jplugin-formbuilder' ), array( 'status' => 500 ) );
		}
		chmod( $path, 0640 );

		return array(
			'uuid'          => wp_generate_uuid4(),
			'storage_key'   => $key,
			'original_name' => $original,
			'mime_type'     => $mime,
			'size_bytes'    => (int) $file['size'],
			'sha256'        => hash_file( 'sha256', $path ),
		);
	}

	public static function resolve( string $storage_key ): string|false {
		$storage_key = ltrim( str_replace( '\\', '/', $storage_key ), '/' );
		if ( str_contains( $storage_key, '..' ) ) {
			return false;
		}
		$path = self::base_dir() . '/' . $storage_key;
		return self::is_within( $path, self::base_dir() ) && is_file( $path ) ? $path : false;
	}

	public static function delete( string $storage_key ): void {
		$path = self::resolve( $storage_key );
		if ( $path ) {
			wp_delete_file( $path );
		}
	}

	private static function allowed_mimes( array $field ): array {
		$requested = array_filter( array_map( 'sanitize_mime_type', (array) ( $field['allowed_mimes'] ?? array() ) ) );
		if ( ! $requested ) {
			return self::DEFAULT_MIMES;
		}

		return array_intersect_key( self::DEFAULT_MIMES, array_flip( $requested ) );
	}

	private static function office_mime( string $path, string $ext, string $detected ): string {
		if ( 'application/zip' !== $detected || ! in_array( $ext, array( 'docx', 'xlsx' ), true ) || ! class_exists( '\\ZipArchive' ) ) {
			return $detected;
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::RDONLY ) ) {
			return $detected;
		}
		$valid = false !== $zip->locateName( '[Content_Types].xml' ) && false !== $zip->locateName( 'docx' === $ext ? 'word/document.xml' : 'xl/workbook.xml' );
		$zip->close();
		if ( ! $valid ) {
			return $detected;
		}
		return 'docx' === $ext ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
	}

	private static function is_within( string $path, string $base ): bool {
		$path = wp_normalize_path( $path );
		$base = trailingslashit( wp_normalize_path( $base ) );
		return str_starts_with( trailingslashit( $path ), $base );
	}
}
