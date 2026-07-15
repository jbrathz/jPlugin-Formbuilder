<?php

namespace JFB;

final class Renderer {
	public function __construct( private readonly Repository $repository ) {}

	public function render_shortcode( array|string $attributes ): string {
		$attributes = shortcode_atts( array( 'id' => '' ), (array) $attributes, 'jplugin_form' );
		return $this->render( sanitize_text_field( $attributes['id'] ) );
	}

	public function render_block( array $attributes ): string {
		return $this->render( sanitize_text_field( $attributes['formId'] ?? '' ) );
	}

	public function render( string $uuid ): string {
		$form = wp_is_uuid( $uuid ) ? $this->repository->get_form( $uuid, true ) : null;
		if ( ! $form ) {
			return current_user_can( 'jfb_manage_forms' ) ? '<div class="jfb-notice">' . esc_html__( 'Form not found or not published.', 'jplugin-formbuilder' ) . '</div>' : '';
		}

		wp_enqueue_style( 'jfb-public' );
		wp_enqueue_script( 'jfb-public' );
		$settings = Settings::get();
		if ( ! empty( $settings['turnstile_enabled'] ) && Settings::site_key() ) {
			wp_enqueue_script( 'jfb-turnstile' );
		}

		$palette = array_replace( Settings::default_palette(), $settings['palette'], $form['settings']['palette'] ?? array() );
		$style = '';
		foreach ( $palette as $key => $value ) {
			$style .= '--jfb-' . sanitize_key( $key ) . ':' . ( sanitize_hex_color( $value ) ?: Settings::default_palette()[ $key ] ) . ';';
		}

		ob_start();
		?>
		<section class="jfb-form-shell" style="<?php echo esc_attr( $style ); ?>" data-jfb-form>
			<header class="jfb-form-header"><span class="jfb-eyebrow"><?php esc_html_e( 'Secure form', 'jplugin-formbuilder' ); ?></span><h2><?php echo esc_html( $form['name'] ); ?></h2></header>
			<div class="jfb-form-feedback" role="status" aria-live="polite"><?php $this->render_redirect_message( $uuid ); ?></div>
			<form class="jfb-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-endpoint="<?php echo esc_url( rest_url( 'jplugin-formbuilder/v1/forms/' . $uuid . '/submissions' ) ); ?>">
				<input type="hidden" name="action" value="jfb_submit">
				<input type="hidden" name="jfb_form_uuid" value="<?php echo esc_attr( $uuid ); ?>">
				<input type="hidden" name="jfb_started_at" value="<?php echo esc_attr( time() ); ?>">
				<div class="jfb-honeypot" aria-hidden="true"><label>Website<input type="text" name="jfb_website" tabindex="-1" autocomplete="off"></label></div>
				<?php foreach ( $form['fields'] as $field ) : $this->render_field( $field ); endforeach; ?>
				<?php if ( ! empty( $settings['turnstile_enabled'] ) && Settings::site_key() ) : ?>
					<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( Settings::site_key() ); ?>"></div>
				<?php endif; ?>
				<button class="jfb-submit" type="submit"><span><?php esc_html_e( 'Send response', 'jplugin-formbuilder' ); ?></span><span aria-hidden="true">→</span></button>
			</form>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private function render_field( array $field ): void {
		$type = $field['type'];
		$key = $field['key'];
		$id = 'jfb-' . substr( str_replace( '-', '', $field['id'] ), 0, 12 );
		if ( 'heading' === $type ) { echo '<h3 class="jfb-section-title">' . esc_html( $field['label'] ) . '</h3>'; return; }
		if ( 'paragraph' === $type ) { echo '<p class="jfb-copy">' . esc_html( $field['label'] ) . '</p>'; return; }
		?>
		<div class="jfb-field jfb-field--<?php echo esc_attr( $type ); ?>">
			<?php if ( ! in_array( $type, array( 'radio', 'checkbox', 'consent' ), true ) ) : ?><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?><?php if ( $field['required'] ) : ?><span class="jfb-required" aria-hidden="true">*</span><?php endif; ?></label><?php endif; ?>
			<?php $this->render_control( $field, $id ); ?>
			<?php if ( $field['help'] ) : ?><small id="<?php echo esc_attr( $id . '-help' ); ?>"><?php echo esc_html( $field['help'] ); ?></small><?php endif; ?>
		</div>
		<?php
	}

	private function render_control( array $field, string $id ): void {
		$type = $field['type']; $key = $field['key']; $required = ! empty( $field['required'] ); $desc = $field['help'] ? $id . '-help' : '';
		if ( 'textarea' === $type ) { printf( '<textarea id="%s" name="%s" placeholder="%s" %s %s></textarea>', esc_attr( $id ), esc_attr( $key ), esc_attr( $field['placeholder'] ), $required ? 'required' : '', $desc ? 'aria-describedby="' . esc_attr( $desc ) . '"' : '' ); return; }
		if ( 'select' === $type ) { echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" ' . ( $required ? 'required' : '' ) . '><option value="">' . esc_html__( 'Choose an option', 'jplugin-formbuilder' ) . '</option>'; foreach ( $field['choices'] as $choice ) { echo '<option value="' . esc_attr( $choice ) . '">' . esc_html( $choice ) . '</option>'; } echo '</select>'; return; }
		if ( in_array( $type, array( 'radio', 'checkbox' ), true ) ) { echo '<fieldset><legend>' . esc_html( $field['label'] ) . ( $required ? '<span class="jfb-required" aria-hidden="true">*</span>' : '' ) . '</legend>'; foreach ( $field['choices'] as $i => $choice ) { $choice_id = $id . '-' . $i; printf( '<label class="jfb-choice" for="%1$s"><input id="%1$s" type="%2$s" name="%3$s" value="%4$s" %5$s><span>%6$s</span></label>', esc_attr( $choice_id ), esc_attr( $type ), esc_attr( 'checkbox' === $type ? $key . '[]' : $key ), esc_attr( $choice ), $required && 0 === $i ? 'required' : '', esc_html( $choice ) ); } echo '</fieldset>'; return; }
		if ( 'consent' === $type ) { printf( '<label class="jfb-choice" for="%1$s"><input id="%1$s" type="checkbox" name="%2$s" value="1" %3$s><span>%4$s</span></label>', esc_attr( $id ), esc_attr( $key ), $required ? 'required' : '', esc_html( $field['label'] ) ); return; }
		$input_type = in_array( $type, array( 'email', 'tel', 'number', 'url', 'date', 'file' ), true ) ? $type : 'text';
		$accept = 'file' === $type ? ' accept=".jpg,.jpeg,.png,.pdf,.docx,.xlsx"' : '';
		printf( '<input id="%s" type="%s" name="%s" placeholder="%s" %s%s>', esc_attr( $id ), esc_attr( $input_type ), esc_attr( $key ), esc_attr( $field['placeholder'] ), $required ? 'required' : '', $accept );
	}

	private function render_redirect_message( string $uuid ): void {
		$status = sanitize_key( wp_unslash( (string) ( $_GET['jfb_status'] ?? '' ) ) );
		$form = sanitize_text_field( wp_unslash( (string) ( $_GET['jfb_form'] ?? '' ) ) );
		if ( $form !== $uuid ) { return; }
		if ( 'success' === $status ) { echo '<p class="jfb-success">' . esc_html__( 'Thank you. Your response has been received.', 'jplugin-formbuilder' ) . '</p>'; }
		if ( 'error' === $status ) { echo '<p class="jfb-error">' . esc_html__( 'The response could not be submitted. Please check the form and try again.', 'jplugin-formbuilder' ) . '</p>'; }
	}
}
