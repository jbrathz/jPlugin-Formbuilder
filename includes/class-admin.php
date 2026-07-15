<?php

namespace JFB;

final class Admin {
	public function __construct( private readonly Repository $repository ) {}

	public function register_menu(): void {
		add_menu_page( __( 'jFormbuilder', 'jplugin-formbuilder' ), __( 'jFormbuilder', 'jplugin-formbuilder' ), 'jfb_manage_forms', 'jfb-forms', array( $this, 'forms_page' ), 'dashicons-feedback', 58 );
		add_submenu_page( 'jfb-forms', __( 'Forms', 'jplugin-formbuilder' ), __( 'Forms', 'jplugin-formbuilder' ), 'jfb_manage_forms', 'jfb-forms', array( $this, 'forms_page' ) );
		add_submenu_page( 'jfb-forms', __( 'Inbox', 'jplugin-formbuilder' ), __( 'Inbox', 'jplugin-formbuilder' ), 'jfb_view_submissions', 'jfb-inbox', array( $this, 'inbox_page' ) );
		add_submenu_page( 'jfb-forms', __( 'Settings', 'jplugin-formbuilder' ), __( 'Settings', 'jplugin-formbuilder' ), 'jfb_manage_settings', 'jfb-settings', array( $this, 'settings_page' ) );
	}

	public function register_settings(): void {
		register_setting( 'jfb_settings_group', Settings::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( Settings::class, 'sanitize' ), 'default' => Settings::defaults() ) );
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'jfb-' ) ) {
			return;
		}
		wp_enqueue_style( 'jfb-admin', JFB_URL . 'assets/css/admin.css', array(), JFB_VERSION );
		wp_enqueue_script( 'jfb-admin', JFB_URL . 'assets/js/admin.js', array(), JFB_VERSION, true );
	}

	public function forms_page(): void {
		$this->guard( 'jfb_manage_forms' );
		$uuid = sanitize_text_field( wp_unslash( (string) ( $_GET['form'] ?? '' ) ) );
		if ( $uuid && wp_is_uuid( $uuid ) ) {
			$this->builder_page( $uuid );
			return;
		}

		$forms = $this->repository->list_forms();
		?>
		<div class="wrap jfb-admin-wrap" data-jfb-admin data-rest-root="<?php echo esc_url( $this->rest_base_path() ); ?>" data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
			<?php $this->masthead( __( 'Forms', 'jplugin-formbuilder' ), __( 'Build clear, trustworthy forms without fighting your theme.', 'jplugin-formbuilder' ) ); ?>
			<section class="jfb-panel jfb-onboarding">
				<div><span class="jfb-kicker"><?php esc_html_e( 'Start with a proven structure', 'jplugin-formbuilder' ); ?></span><h2><?php esc_html_e( 'Choose a template', 'jplugin-formbuilder' ); ?></h2></div>
				<div class="jfb-template-grid">
					<?php foreach ( Templates::all() as $key => $template ) : ?>
						<button type="button" class="jfb-template-card" data-template="<?php echo esc_attr( $key ); ?>"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><strong><?php echo esc_html( $template['name'] ); ?></strong><small><?php echo esc_html( sprintf( _n( '%d field', '%d fields', count( $template['fields'] ), 'jplugin-formbuilder' ), count( $template['fields'] ) ) ); ?></small></button>
					<?php endforeach; ?>
				</div>
			</section>
			<section class="jfb-panel">
				<div class="jfb-panel-heading"><div><span class="jfb-kicker"><?php esc_html_e( 'Workspace', 'jplugin-formbuilder' ); ?></span><h2><?php esc_html_e( 'Your forms', 'jplugin-formbuilder' ); ?></h2></div><span class="jfb-count"><?php echo esc_html( count( $forms ) ); ?></span></div>
				<?php if ( ! $forms ) : ?><div class="jfb-empty"><span class="dashicons dashicons-feedback" aria-hidden="true"></span><h3><?php esc_html_e( 'No forms yet', 'jplugin-formbuilder' ); ?></h3><p><?php esc_html_e( 'Pick a template above. You can change every field before publishing.', 'jplugin-formbuilder' ); ?></p></div><?php else : ?>
				<div class="jfb-form-list"><?php foreach ( $forms as $form ) : ?>
					<a class="jfb-form-row" href="<?php echo esc_url( add_query_arg( array( 'page' => 'jfb-forms', 'form' => $form['uuid'] ), admin_url( 'admin.php' ) ) ); ?>"><span><strong><?php echo esc_html( $form['name'] ); ?></strong><small><?php echo esc_html( $form['uuid'] ); ?></small></span><span class="jfb-status jfb-status--<?php echo esc_attr( $form['status'] ); ?>"><?php echo esc_html( ucfirst( $form['status'] ) ); ?></span><time><?php echo esc_html( get_date_from_gmt( $form['updated_at'], 'd M Y H:i' ) ); ?></time><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
				<?php endforeach; ?></div><?php endif; ?>
			</section>
			<div class="jfb-toast" role="status" aria-live="polite"></div>
		</div>
		<?php
	}

	private function builder_page( string $uuid ): void {
		$form = $this->repository->get_form( $uuid );
		if ( ! $form ) { wp_die( esc_html__( 'Form not found.', 'jplugin-formbuilder' ) ); }
		$json = wp_json_encode( $form, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		$shortcode = sprintf( '[jplugin_form id="%s"]', $form['uuid'] );
		?>
		<div class="wrap jfb-admin-wrap" data-jfb-builder data-rest-root="<?php echo esc_url( $this->rest_base_path() ); ?>" data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
			<?php $this->masthead( __( 'Form builder', 'jplugin-formbuilder' ), __( 'Structure first. Style second. Validate everything.', 'jplugin-formbuilder' ) ); ?>
			<script type="application/json" id="jfb-form-data"><?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
			<div class="jfb-builder-toolbar"><a href="<?php echo esc_url( admin_url( 'admin.php?page=jfb-forms' ) ); ?>">← <?php esc_html_e( 'All forms', 'jplugin-formbuilder' ); ?></a><div class="jfb-embed-actions" aria-label="<?php esc_attr_e( 'Embed this form', 'jplugin-formbuilder' ); ?>"><button type="button" class="button button-small" data-copy-text="<?php echo esc_attr( $form['uuid'] ); ?>" data-copy-label="<?php esc_attr_e( 'UUID', 'jplugin-formbuilder' ); ?>"><?php esc_html_e( 'Copy UUID', 'jplugin-formbuilder' ); ?></button><button type="button" class="button button-small" data-copy-text="<?php echo esc_attr( $shortcode ); ?>" data-copy-label="<?php esc_attr_e( 'Shortcode', 'jplugin-formbuilder' ); ?>"><?php esc_html_e( 'Copy shortcode', 'jplugin-formbuilder' ); ?></button></div><button type="button" class="button button-link-delete" data-delete-form><?php esc_html_e( 'Delete form', 'jplugin-formbuilder' ); ?></button><span class="jfb-save-state" data-save-state><?php esc_html_e( 'Saved', 'jplugin-formbuilder' ); ?></span><button type="button" class="button button-primary" data-save-form><?php esc_html_e( 'Save form', 'jplugin-formbuilder' ); ?></button></div>
			<div class="jfb-builder-grid">
				<section class="jfb-panel jfb-builder-controls"><label><?php esc_html_e( 'Form name', 'jplugin-formbuilder' ); ?><input type="text" data-form-name></label><label><?php esc_html_e( 'Status', 'jplugin-formbuilder' ); ?><select data-form-status><option value="draft"><?php esc_html_e( 'Draft', 'jplugin-formbuilder' ); ?></option><option value="published"><?php esc_html_e( 'Published', 'jplugin-formbuilder' ); ?></option></select></label><label><?php esc_html_e( 'Success message', 'jplugin-formbuilder' ); ?><input type="text" data-success-message></label><label><?php esc_html_e( 'Submit button text', 'jplugin-formbuilder' ); ?><input type="text" data-submit-label placeholder="<?php esc_attr_e( 'Send response', 'jplugin-formbuilder' ); ?>"><small><?php esc_html_e( 'For example: Send enquiry or Submit registration.', 'jplugin-formbuilder' ); ?></small></label><label><?php esc_html_e( 'Notification email', 'jplugin-formbuilder' ); ?><input type="email" data-notification-email></label><label><?php esc_html_e( 'Retention days', 'jplugin-formbuilder' ); ?><input type="number" min="0" max="3650" data-retention-days><small><?php esc_html_e( '0 keeps submissions until an administrator removes them.', 'jplugin-formbuilder' ); ?></small></label><div class="jfb-builder-colors"><strong><?php esc_html_e( 'Form palette', 'jplugin-formbuilder' ); ?></strong><?php foreach ( Settings::default_palette() as $key => $color ) : ?><label><?php echo esc_html( ucfirst( $key ) ); ?><input type="color" data-palette-key="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $form['settings']['palette'][ $key ] ?? $color ); ?>"></label><?php endforeach; ?><small data-contrast-state></small></div><div class="jfb-field-tools"><label for="jfb-new-field"><?php esc_html_e( 'Add a field', 'jplugin-formbuilder' ); ?></label><select id="jfb-new-field" data-new-field><?php foreach ( Validator::FIELD_TYPES as $type ) : ?><option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( ucfirst( $type ) ); ?></option><?php endforeach; ?></select><button type="button" class="button" data-add-field><?php esc_html_e( 'Add field', 'jplugin-formbuilder' ); ?></button></div></section>
				<section class="jfb-panel"><div class="jfb-panel-heading"><div><span class="jfb-kicker"><?php esc_html_e( 'Canvas', 'jplugin-formbuilder' ); ?></span><h2><?php esc_html_e( 'Fields', 'jplugin-formbuilder' ); ?></h2></div></div><div class="jfb-field-list" data-field-list></div></section>
				<section class="jfb-panel jfb-preview-panel"><div class="jfb-panel-heading"><div><span class="jfb-kicker"><?php esc_html_e( 'Responsive preview', 'jplugin-formbuilder' ); ?></span><h2><?php esc_html_e( 'Form outline', 'jplugin-formbuilder' ); ?></h2></div></div><div data-builder-preview class="jfb-builder-preview"></div></section>
			</div><div class="jfb-toast" role="status" aria-live="polite"></div>
		</div>
		<?php
	}

	public function inbox_page(): void {
		$this->guard( 'jfb_view_submissions' );
		$uuid = sanitize_text_field( wp_unslash( (string) ( $_GET['submission'] ?? '' ) ) );
		$current_status = sanitize_key( wp_unslash( (string) ( $_GET['status'] ?? '' ) ) );
		$items = $this->repository->list_submissions( array( 'status' => $current_status ) );
		?>
		<div class="wrap jfb-admin-wrap"><?php $this->masthead( __( 'Submission inbox', 'jplugin-formbuilder' ), __( 'Review responses in WordPress. Sensitive values never travel in notification emails.', 'jplugin-formbuilder' ) ); ?>
			<nav class="nav-tab-wrapper"><a class="nav-tab <?php echo 'trash' !== $current_status ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=jfb-inbox' ) ); ?>"><?php esc_html_e( 'Inbox', 'jplugin-formbuilder' ); ?></a><a class="nav-tab <?php echo 'trash' === $current_status ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=jfb-inbox&status=trash' ) ); ?>"><?php esc_html_e( 'Trash', 'jplugin-formbuilder' ); ?></a></nav>
			<div class="jfb-inbox-grid"><section class="jfb-panel"><div class="jfb-panel-heading"><h2><?php esc_html_e( 'Responses', 'jplugin-formbuilder' ); ?></h2><?php if ( current_user_can( 'jfb_export_submissions' ) && 'trash' !== $current_status ) : ?><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=jfb_export' ), 'jfb_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'jplugin-formbuilder' ); ?></a><?php endif; ?></div>
			<?php if ( ! $items ) : ?><div class="jfb-empty"><h3><?php esc_html_e( 'Inbox zero', 'jplugin-formbuilder' ); ?></h3><p><?php esc_html_e( 'New responses will appear here.', 'jplugin-formbuilder' ); ?></p></div><?php else : ?><div class="jfb-submission-list"><?php foreach ( $items as $item ) : ?><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'jfb-inbox', 'submission' => $item['uuid'] ), admin_url( 'admin.php' ) ) ); ?>" class="jfb-submission-row <?php echo $uuid === $item['uuid'] ? 'is-active' : ''; ?>"><span><strong><?php echo esc_html( $item['form_name'] ?: __( 'Deleted form', 'jplugin-formbuilder' ) ); ?></strong><small><?php echo esc_html( $item['uuid'] ); ?></small></span><span class="jfb-status jfb-status--<?php echo esc_attr( $item['status'] ); ?>"><?php echo esc_html( ucfirst( $item['status'] ) ); ?></span><time><?php echo esc_html( get_date_from_gmt( $item['created_at'], 'd M Y H:i' ) ); ?></time></a><?php endforeach; ?></div><?php endif; ?></section>
			<aside class="jfb-panel jfb-submission-detail"><?php $this->submission_detail( $uuid ); ?></aside></div>
		</div>
		<?php
	}

	private function submission_detail( string $uuid ): void {
		if ( ! wp_is_uuid( $uuid ) ) { echo '<div class="jfb-empty"><h3>' . esc_html__( 'Select a response', 'jplugin-formbuilder' ) . '</h3></div>'; return; }
		$item = $this->repository->get_submission( $uuid );
		if ( ! $item ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'Submission not found.', 'jplugin-formbuilder' ) . '</p></div>'; return; }
		if ( 'new' === $item['status'] ) { $this->repository->update_submission_status( $uuid, 'read' ); }
		echo '<span class="jfb-kicker">' . esc_html( $item['form_name'] ) . '</span><h2>' . esc_html__( 'Response detail', 'jplugin-formbuilder' ) . '</h2><dl class="jfb-response-values">';
		foreach ( $item['fields'] as $field ) { $key = $field['key'] ?? ''; if ( ! array_key_exists( $key, $item['payload'] ) ) { continue; } $value = is_array( $item['payload'][ $key ] ) ? implode( ', ', $item['payload'][ $key ] ) : $item['payload'][ $key ]; echo '<div><dt>' . esc_html( $field['label'] ?: $key ) . '</dt><dd>' . nl2br( esc_html( $value ) ) . '</dd></div>'; }
		echo '</dl>';
		if ( $item['files'] ) { echo '<h3>' . esc_html__( 'Private files', 'jplugin-formbuilder' ) . '</h3><ul>'; foreach ( $item['files'] as $file ) { $url = wp_nonce_url( admin_url( 'admin-post.php?action=jfb_download&file=' . rawurlencode( $file['uuid'] ) ), 'jfb_download_' . $file['uuid'] ); echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $file['original_name'] ) . '</a> <small>(' . esc_html( size_format( $file['size_bytes'] ) ) . ')</small></li>'; } echo '</ul>'; }
		if ( 'trash' === $item['status'] ) {
			$restore = wp_nonce_url( admin_url( 'admin-post.php?action=jfb_submission_status&submission=' . rawurlencode( $uuid ) . '&status=read' ), 'jfb_submission_status_' . $uuid );
			$purge = wp_nonce_url( admin_url( 'admin-post.php?action=jfb_purge_submission&submission=' . rawurlencode( $uuid ) ), 'jfb_purge_submission_' . $uuid );
			echo '<p><a class="button" href="' . esc_url( $restore ) . '">' . esc_html__( 'Restore', 'jplugin-formbuilder' ) . '</a> <a class="button button-link-delete" href="' . esc_url( $purge ) . '">' . esc_html__( 'Delete permanently', 'jplugin-formbuilder' ) . '</a></p>';
		} else {
			$trash_url = wp_nonce_url( admin_url( 'admin-post.php?action=jfb_submission_status&submission=' . rawurlencode( $uuid ) . '&status=trash' ), 'jfb_submission_status_' . $uuid );
			echo '<p><a class="button button-link-delete" href="' . esc_url( $trash_url ) . '">' . esc_html__( 'Move to trash', 'jplugin-formbuilder' ) . '</a></p>';
		}
	}

	public function settings_page(): void {
		$this->guard( 'jfb_manage_settings' ); $settings = Settings::get();
		?>
		<div class="wrap jfb-admin-wrap"><?php $this->masthead( __( 'Security & appearance', 'jplugin-formbuilder' ), __( 'Keep the public surface small, predictable, and easy to audit.', 'jplugin-formbuilder' ) ); ?><?php $test_status = sanitize_key( wp_unslash( (string) ( $_GET['jfb_test'] ?? '' ) ) ); if ( $test_status ) : ?><div class="notice <?php echo 'ok' === $test_status ? 'notice-success' : 'notice-error'; ?>"><p><?php echo 'ok' === $test_status ? esc_html__( 'Cloudflare accepted the saved secret key.', 'jplugin-formbuilder' ) : esc_html__( 'Cloudflare connection test failed. Check the saved keys and outbound HTTPS.', 'jplugin-formbuilder' ); ?></p></div><?php endif; ?>
		<form method="post" action="options.php" class="jfb-settings-grid"><?php settings_fields( 'jfb_settings_group' ); ?>
			<section class="jfb-panel"><span class="jfb-kicker">Cloudflare</span><h2><?php esc_html_e( 'Turnstile & rate limiting', 'jplugin-formbuilder' ); ?></h2><label class="jfb-switch"><input type="checkbox" name="jfb_settings[turnstile_enabled]" value="1" <?php checked( $settings['turnstile_enabled'] ); ?>><span><?php esc_html_e( 'Require Turnstile on public forms', 'jplugin-formbuilder' ); ?></span></label><label><?php esc_html_e( 'Site key', 'jplugin-formbuilder' ); ?><input type="text" name="jfb_settings[turnstile_site_key]" value="<?php echo esc_attr( $settings['turnstile_site_key'] ); ?>" autocomplete="off"></label><label><?php esc_html_e( 'Secret key', 'jplugin-formbuilder' ); ?><input type="password" name="jfb_settings[turnstile_secret_key]" value="<?php echo esc_attr( $settings['turnstile_secret_key'] ? '••••••••' : '' ); ?>" autocomplete="new-password"></label><div class="jfb-inline-fields"><label><?php esc_html_e( 'Attempts / 10 min', 'jplugin-formbuilder' ); ?><input type="number" min="1" max="100" name="jfb_settings[rate_limit]" value="<?php echo esc_attr( $settings['rate_limit'] ); ?>"></label><label><?php esc_html_e( 'Minimum fill time', 'jplugin-formbuilder' ); ?><input type="number" min="1" max="30" name="jfb_settings[min_fill_seconds]" value="<?php echo esc_attr( $settings['min_fill_seconds'] ); ?>"></label></div><p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=jfb_turnstile_test' ), 'jfb_turnstile_test' ) ); ?>"><?php esc_html_e( 'Test saved Cloudflare keys', 'jplugin-formbuilder' ); ?></a></p><p class="description"><?php esc_html_e( 'Save changes before testing. Application limits complement Cloudflare WAF; they do not replace edge protection.', 'jplugin-formbuilder' ); ?></p></section>
			<section class="jfb-panel"><span class="jfb-kicker"><?php esc_html_e( 'Theme bridge', 'jplugin-formbuilder' ); ?></span><h2><?php esc_html_e( 'Global palette', 'jplugin-formbuilder' ); ?></h2><div class="jfb-color-grid"><?php foreach ( $settings['palette'] as $key => $color ) : ?><label><?php echo esc_html( ucfirst( $key ) ); ?><input type="color" name="jfb_settings[palette][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $color ); ?>"></label><?php endforeach; ?></div><p class="description"><?php esc_html_e( 'Forms inherit typography from the active theme. Colors are isolated through CSS variables.', 'jplugin-formbuilder' ); ?></p></section>
			<section class="jfb-panel jfb-data-panel"><span class="jfb-kicker"><?php esc_html_e( 'Data & Privacy', 'jplugin-formbuilder' ); ?></span><h2><?php esc_html_e( 'Uninstall keeps everything', 'jplugin-formbuilder' ); ?></h2><p><?php esc_html_e( 'Removing the plugin will not delete forms, submissions, settings, or files.', 'jplugin-formbuilder' ); ?></p><code><?php echo esc_html( implode( ', ', array( Database::table( 'forms' ), Database::table( 'submissions' ), Database::table( 'submission_files' ), Database::table( 'rate_limits' ) ) ) ); ?></code><code><?php echo esc_html( Settings::OPTION ); ?></code><code><?php echo esc_html( Storage::base_dir() ); ?></code><p><strong><?php echo Storage::is_protected() ? esc_html__( 'Private vault: writable and protected', 'jplugin-formbuilder' ) : esc_html__( 'Private vault needs attention before file forms can be published', 'jplugin-formbuilder' ); ?></strong></p></section>
			<p class="submit"><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save settings', 'jplugin-formbuilder' ); ?></button></p></form>
		</div>
		<?php
	}

	private function masthead( string $title, string $copy ): void { echo '<header class="jfb-admin-header"><div><span class="jfb-brand">J / FORM</span><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $copy ) . '</p></div><span class="jfb-version">v' . esc_html( JFB_VERSION ) . '</span></header>'; }
	private function rest_base_path(): string { return wp_make_link_relative( rest_url( 'jplugin-formbuilder/v1' ) ); }
	private function guard( string $cap ): void { if ( ! current_user_can( $cap ) ) { wp_die( esc_html__( 'You do not have permission to access this page.', 'jplugin-formbuilder' ), 403 ); } }
}
