<?php
// Standalone behavior checks; no real email or database is used.
const ARRAY_A = 'ARRAY_A';
const MINUTE_IN_SECONDS = 60;
const DAY_IN_SECONDS = 86400;
class WP_Error { public function __construct( public $code, public $message = '', public $data = array() ) {} public function get_error_code() { return $this->code; } }
function __( $s, $domain = '' ) { return $s; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function is_email( $v ) { return filter_var( $v, FILTER_VALIDATE_EMAIL ); }
function sanitize_email( $v ) { return filter_var( $v, FILTER_SANITIZE_EMAIL ); }
function sanitize_text_field( $v ) { return trim( strip_tags( $v ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( $v ) ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $v ) ); }
function sanitize_hex_color( $v ) { return $v; }
function get_option( $v, $default = false ) { return 'admin_email' === $v ? 'admin@example.com' : $default; }
function get_current_user_id() { return 1; }
function wp_is_uuid( $v ) { return preg_match( '/^[a-f0-9-]{36}$/D', $v ) === 1; }
function wp_generate_uuid4() { static $id = 0; return sprintf( '00000000-0000-4000-8000-%012d', ++$id ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_salt( $scheme ) { return 'test-salt-' . $scheme; }
function absint( $v ) { return abs( (int) $v ); }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function admin_url( $v ) { return 'https://example.test/wp-admin/' . $v; }
function wp_specialchars_decode( $v, $flags ) { return html_entity_decode( $v, $flags ); }
function get_bloginfo( $v ) { return 'Example'; }
function esc_url_raw( $v ) { return $v; }
function current_time( $v, $gmt ) { return '2026-11-16 12:00:00'; }
$GLOBALS['mail_results'] = array();
$GLOBALS['actions'] = array();
$GLOBALS['filters'] = array();
function add_action( $name, $callback ) { $GLOBALS['actions'][$name][] = $callback; }
function remove_action( $name, $callback ) { $GLOBALS['actions'][$name] = array_filter( $GLOBALS['actions'][$name], fn( $item ) => $item !== $callback ); }
function add_filter( $name, $callback, $priority = 10 ) { $GLOBALS['filters'][$name][$priority][] = $callback; }
function remove_filter( $name, $callback, $priority = 10 ) { $GLOBALS['filters'][$name][$priority] = array_filter( $GLOBALS['filters'][$name][$priority], fn( $item ) => $item !== $callback ); }
function wp_mail( $recipient, $subject, $body, $headers, $attachments = array() ) {
	$content_type = 'text/html'; // Simulate a site-wide HTML mail setting.
	$filters = $GLOBALS['filters']['wp_mail_content_type'] ?? array();
	ksort( $filters );
	foreach ( $filters as $callbacks ) {
		foreach ( $callbacks as $callback ) { $content_type = $callback( $content_type ); }
	}
	$GLOBALS['calls'][] = array( $recipient, $subject, $body, $headers, $attachments, $content_type );
	return $GLOBALS['mail_results'][$recipient] ?? true;
}
class FakeDB {
 public $prefix = 'wp_', $insert_id = 0, $rows = array(), $forms = array();
 function insert( $table, $data ) {
	 $this->insert_id++;
	 if ( 'wp_jfb_forms' === $table ) {
		 $this->forms[$data['uuid']] = $data + array( 'id' => $this->insert_id );
	 } elseif ( 'wp_jfb_mail_log' === $table ) {
		 $this->rows[$this->insert_id] = $data + array( 'id' => $this->insert_id, 'error_code' => '', 'attempted_at' => null );
	 }
	 return 1;
 }
 function prepare( $sql, ...$args ) { return array( $sql, $args ); }
 function query( $query ) { [ $sql, $args ] = $query; if ( str_contains( $sql, 'wp_jfb_rate_limits' ) ) return 1; [ $new, $id, $expected ] = $args; if ( ! isset( $this->rows[$id] ) || $this->rows[$id]['status'] !== $expected ) return 0; $this->rows[$id]['status'] = $new; return 1; }
 function get_var( $query ) { return 1; }
 function get_row( $query, $mode ) {
	 return str_contains( $query[0], 'wp_jfb_forms' ) ? ( $this->forms[$query[1][0]] ?? null ) : ( $this->rows[$query[1][0]] ?? null );
 }
 function update( $table, $data, $where ) { $this->rows[$where['id']] = array_replace( $this->rows[$where['id']], $data ); return 1; }
}
$GLOBALS['wpdb'] = new FakeDB();
require __DIR__ . '/../includes/class-settings.php';
require __DIR__ . '/../includes/class-validator.php';
require __DIR__ . '/../includes/class-database.php';
require __DIR__ . '/../includes/class-repository.php';
require __DIR__ . '/../includes/class-mailer.php';
require __DIR__ . '/../includes/class-security.php';
require __DIR__ . '/../includes/class-submission-service.php';
function check( $condition, $label ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); } echo "PASS: $label\n"; }
$validate = new ReflectionMethod( JFB\Validator::class, 'sanitize_form_settings' );
$fields = array(
	array( 'key' => 'heading', 'type' => 'heading', 'label' => 'About you' ),
	array( 'id' => 'field-id', 'key' => 'email', 'type' => 'email', 'label' => 'Email address' ),
	array( 'key' => 'message', 'type' => 'textarea', 'label' => 'ความคิดเห็น' ),
	array( 'key' => 'topics', 'type' => 'checkbox', 'label' => 'Topics', 'choices' => array( 'Forms', 'Email' ) ),
	array( 'key' => 'score', 'type' => 'number', 'label' => '' ),
	array( 'key' => 'allow_contact', 'type' => 'consent', 'label' => 'May we contact you?' ),
	array( 'key' => 'phone', 'type' => 'tel', 'label' => 'Phone' ),
	array( 'key' => 'updates', 'type' => 'checkbox', 'label' => 'Updates', 'choices' => array( 'Newsletter' ) ),
	array( 'key' => 'upload', 'type' => 'file', 'label' => 'Private upload' ),
);
$clean = $validate->invoke( null, array( 'notification_email' => 'old@example.com' ), $fields );
check( $clean['notification_emails'] === array( 'old@example.com' ) && ! $clean['confirmation_enabled'], 'legacy setting retained, confirmation off' );
$clean = $validate->invoke( null, array( 'notification_email' => '' ), $fields );
check( $clean['notification_emails'] === array(), 'legacy blank remains disabled' );
$clean = $validate->invoke( null, array( 'notification_emails' => array( 'a@example.com', 'A@example.com', 'b@example.com' ), 'confirmation_enabled' => true, 'confirmation_field' => 'field-id', 'confirmation_subject' => 'We received your response', 'confirmation_body' => 'Thank you for {form_name}. Reference: {submission_id}' ), $fields );
check( count( $clean['notification_emails'] ) === 2, 'deduplicate recipients' );
check( is_wp_error( $validate->invoke( null, array( 'notification_emails' => array( 'bad' ) ), $fields ) ), 'invalid recipient rejected' );
check( is_wp_error( $validate->invoke( null, array( 'confirmation_enabled' => true, 'confirmation_field' => 'deleted' ), $fields ) ), 'deleted confirmation field rejected' );
$form_row = array( 'id' => '1', 'version' => '1', 'name' => 'Feedback', 'schema_json' => json_encode( array( 'fields' => $fields ) ) );
$GLOBALS['wpdb']->forms['new-form'] = $form_row + array( 'settings_json' => json_encode( $clean ) );
$repository = new JFB\Repository();
$form = $repository->get_form( 'new-form', true );
$submission = array( 'id' => 1, 'uuid' => 'abc', 'created_at' => '2026-11-16 12:00:00' );
$GLOBALS['mail_results']['b@example.com'] = false;
$payload = JFB\Validator::validate_submission( $form['fields'], array(
	'message'       => "<b>สนใจครับ</b>\r\nขอรายละเอียดเพิ่มเติม",
	'email'         => 'user@example.com',
	'topics'        => array( 'Forms', 'Email' ),
	'score'         => '0',
	'allow_contact' => '1',
	'upload'        => 'private.pdf',
	'notification_emails' => array( 'other@example.com' ),
) );
JFB\Mailer::notify( $form, $submission, $payload );
$rows = $GLOBALS['wpdb']->rows;
check( count( $rows ) === 3 && count( $GLOBALS['calls'] ) === 3, 'send separate notifications and confirmation' );
check( $rows[2]['status'] === 'failed' && $rows[3]['status'] === 'sent', 'record per-recipient result independently' );
$notification = $GLOBALS['calls'][0][2];
$expected_answers = "Response details\n\nEmail address: user@example.com\n\nความคิดเห็น: สนใจครับ\n  ขอรายละเอียดเพิ่มเติม\n\nTopics: Forms, Email\n\nscore: 0\n\nMay we contact you?: Yes";
check( str_ends_with( $notification, $expected_answers ), 'admin email includes answered fields in form order with multiline text, choices, zero, and consent' );
check( str_contains( $notification, 'https://example.test/wp-admin/admin.php?page=jfb-inbox&submission=abc' ), 'admin email links to the response inbox' );
check( $GLOBALS['calls'][1][2] === $notification, 'all admin recipients receive response details' );
check( $GLOBALS['calls'][0][3] === array( 'Content-Type: text/plain; charset=UTF-8' ) && $GLOBALS['calls'][0][4] === array() && $GLOBALS['calls'][0][5] === 'text/plain', 'admin mail is plain text with no attachments even on an HTML mail site' );
check( $GLOBALS['calls'][2][2] === 'Thank you for Feedback. Reference: abc', 'respondent confirmation contains only its configured message' );
check( $GLOBALS['calls'][2][3] === array() && $GLOBALS['calls'][2][5] === 'text/html', 'admin mail content type does not affect confirmation' );
$GLOBALS['mail_results']['b@example.com'] = true;
check( JFB\Mailer::send( 2 ) && ! JFB\Mailer::send( 2 ), 'retry failed message only once' );
check( $GLOBALS['calls'][3][2] === $notification, 'retry sends the original admin email with response details' );
check( $GLOBALS['calls'][3][5] === 'text/plain', 'admin retry is plain text' );
JFB\Mailer::notify( $form, $submission, array( 'email' => '' ) );
check( end( $GLOBALS['wpdb']->rows )['status'] === 'skipped', 'missing optional email skipped' );
check( ! str_contains( $GLOBALS['calls'][4][2], 'Response details' ), 'empty answers omit the response details section' );

$GLOBALS['wpdb']->forms['legacy-form'] = $form_row + array( 'settings_json' => '{"notification_email":"old@example.com"}' );
$legacy_form = $repository->get_form( 'legacy-form', true );
JFB\Mailer::notify( $legacy_form, $submission, $payload );
$legacy_mail = end( $GLOBALS['calls'] );
check( $legacy_mail[0] === 'old@example.com' && $legacy_mail[2] === $notification, 'legacy form receives the same answers without resaving its settings' );

$mail_count = count( $GLOBALS['calls'] );
foreach ( array( '{"notification_email":""}', '{"notification_email":"old@example.com","notification_emails":[]}', '{"notification_email":"invalid address"}' ) as $settings_json ) {
	$GLOBALS['wpdb']->forms['legacy-form']['settings_json'] = $settings_json;
	JFB\Mailer::notify( $repository->get_form( 'legacy-form', true ), $submission, $payload );
}
check( count( $GLOBALS['calls'] ) === $mail_count, 'blank, explicitly disabled, and invalid recipients receive no response details' );

$custom_form = $repository->save_form( array(
	'name' => 'Workshop registration',
	'status' => 'published',
	'fields' => array(
		array( 'type' => 'select', 'key' => 'session_slot', 'label' => 'รอบที่สะดวก', 'choices' => array( 'วันเสาร์', 'วันอาทิตย์' ), 'required' => true ),
		array( 'type' => 'text', 'key' => 'organization', 'label' => 'หน่วยงาน' ),
	),
	'settings' => array( 'notification_emails' => array( 'workshop@example.com' ) ),
) );
check( is_array( $custom_form ), 'a custom form can be saved through the form repository' );
$service = new JFB\Submission_Service( $repository );
$result = $service->submit( $custom_form['uuid'], array( 'session_slot' => 'วันอาทิตย์', 'organization' => 'ทีมวิชาการ', 'notification_email' => 'other@example.com' ), array(), array( 'started_at' => time() - 10 ) );
$custom_mail = end( $GLOBALS['calls'] );
check( is_array( $result ) && count( $GLOBALS['calls'] ) === $mail_count + 1 && $custom_mail[0] === 'workshop@example.com', 'custom submission sends only to the configured recipient' );
check( str_ends_with( $custom_mail[2], "Response details\n\nรอบที่สะดวก: วันอาทิตย์\n\nหน่วยงาน: ทีมวิชาการ" ), 'a custom form email uses its saved field labels and submitted answers' );
