<?php

namespace JFB;

final class Templates {
	public static function all(): array {
		return array(
			'contact' => array(
				'name' => __( 'Contact form', 'jplugin-formbuilder' ),
				'fields' => array(
					self::field( 'text', 'name', __( 'Name', 'jplugin-formbuilder' ), true ),
					self::field( 'email', 'email', __( 'Email', 'jplugin-formbuilder' ), true ),
					self::field( 'tel', 'phone', __( 'Telephone', 'jplugin-formbuilder' ) ),
					self::field( 'textarea', 'message', __( 'Message', 'jplugin-formbuilder' ), true ),
				),
			),
			'survey' => array(
				'name' => __( 'Opinion survey', 'jplugin-formbuilder' ),
				'fields' => array(
					self::choice( 'radio', 'experience', __( 'How was your experience?', 'jplugin-formbuilder' ), array( __( 'Excellent', 'jplugin-formbuilder' ), __( 'Good', 'jplugin-formbuilder' ), __( 'Needs improvement', 'jplugin-formbuilder' ) ), true ),
					self::field( 'textarea', 'suggestion', __( 'Suggestions', 'jplugin-formbuilder' ) ),
				),
			),
			'feedback' => array(
				'name' => __( 'Satisfaction feedback', 'jplugin-formbuilder' ),
				'fields' => array(
					self::choice( 'select', 'rating', __( 'Overall satisfaction', 'jplugin-formbuilder' ), array( '5', '4', '3', '2', '1' ), true ),
					self::choice( 'checkbox', 'strengths', __( 'What worked well?', 'jplugin-formbuilder' ), array( __( 'Service', 'jplugin-formbuilder' ), __( 'Speed', 'jplugin-formbuilder' ), __( 'Information', 'jplugin-formbuilder' ) ) ),
					self::field( 'textarea', 'comments', __( 'Additional comments', 'jplugin-formbuilder' ) ),
				),
			),
			'complaint' => array(
				'name' => __( 'Enquiry / complaint', 'jplugin-formbuilder' ),
				'fields' => array(
					self::field( 'heading', 'person_details_heading', __( 'Your details', 'jplugin-formbuilder' ), false, array( 'heading_style' => 'band' ) ),
					self::field( 'text', 'first_name', __( 'First name', 'jplugin-formbuilder' ), true, array( 'label_position' => 'left' ) ),
					self::field( 'text', 'last_name', __( 'Last name', 'jplugin-formbuilder' ), true, array( 'label_position' => 'left' ) ),
					self::field( 'email', 'email', __( 'Email', 'jplugin-formbuilder' ), true, array( 'label_position' => 'left' ) ),
					self::field( 'tel', 'phone', __( 'Telephone', 'jplugin-formbuilder' ), false, array( 'label_position' => 'left' ) ),
					self::field( 'heading', 'topic_heading', __( 'What would you like to contact us about?', 'jplugin-formbuilder' ), false, array( 'heading_style' => 'band' ) ),
					self::choice( 'radio', 'topic', __( 'Topic', 'jplugin-formbuilder' ), array( __( 'Report a website problem', 'jplugin-formbuilder' ), __( 'Ask for additional information', 'jplugin-formbuilder' ), __( 'Make a complaint', 'jplugin-formbuilder' ) ), true, array( 'label_position' => 'left', 'allow_other' => true ) ),
					self::field( 'heading', 'message_heading', __( 'Please tell us more', 'jplugin-formbuilder' ), false, array( 'heading_style' => 'band' ) ),
					self::field( 'textarea', 'message', __( 'Message', 'jplugin-formbuilder' ), true, array( 'label_position' => 'left' ) ),
				),
			),
			'registration' => array(
				'name' => __( 'Event registration', 'jplugin-formbuilder' ),
				'fields' => array(
					self::field( 'text', 'full_name', __( 'Full name', 'jplugin-formbuilder' ), true ),
					self::field( 'email', 'email', __( 'Email', 'jplugin-formbuilder' ), true ),
					self::field( 'tel', 'phone', __( 'Telephone', 'jplugin-formbuilder' ), true ),
					self::field( 'consent', 'consent', __( 'I agree to the stated data policy', 'jplugin-formbuilder' ), true ),
				),
			),
		);
	}

	public static function blank_field( string $type = 'text' ): array {
		return self::field( $type, 'field_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 8 ), __( 'Untitled field', 'jplugin-formbuilder' ) );
	}

	private static function field( string $type, string $key, string $label, bool $required = false, array $options = array() ): array {
		return array_merge( array( 'id' => wp_generate_uuid4(), 'type' => $type, 'key' => $key, 'label' => $label, 'help' => '', 'placeholder' => '', 'required' => $required, 'width' => 'full', 'label_position' => 'top' ), $options );
	}

	private static function choice( string $type, string $key, string $label, array $choices, bool $required = false, array $options = array() ): array {
		return self::field( $type, $key, $label, $required, $options ) + array( 'choices' => $choices );
	}
}
