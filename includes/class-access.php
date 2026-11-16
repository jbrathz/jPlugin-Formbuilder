<?php

namespace JFB;

/** Per-form response access for users selected by a form administrator. */
final class Access {
	public const OPTION = 'jfb_form_viewers';

	public static function assignments(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	public static function form_ids( int $user_id ): array {
		$ids = array();
		foreach ( self::assignments() as $form_id => $users ) {
			if ( is_array( $users ) && in_array( $user_id, array_map( 'intval', $users ), true ) ) {
				$ids[] = absint( $form_id );
			}
		}
		return array_values( array_filter( $ids ) );
	}

	public static function grant_viewer_caps( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		if ( $user->ID && self::form_ids( (int) $user->ID ) ) {
			$allcaps['jfb_view_submissions'] = true;
			$allcaps['jfb_export_submissions'] = true;
		}
		return $allcaps;
	}

	public static function can_manage(): bool {
		return current_user_can( 'jfb_manage_forms' );
	}

	public static function can_view_form( int $form_id ): bool {
		return self::can_manage() || in_array( $form_id, self::form_ids( get_current_user_id() ), true );
	}

	public static function can_view_submission( ?array $item ): bool {
		return $item && self::can_view_form( (int) $item['form_id'] ) && ( self::can_manage() || 'trash' !== $item['status'] );
	}
}
