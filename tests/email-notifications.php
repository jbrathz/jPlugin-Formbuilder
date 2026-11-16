<?php
// Standalone behavior checks; no real email or database is used.
const ARRAY_A = 'ARRAY_A';
class WP_Error { public function __construct( public $code, public $message = '', public $data = array() ) {} public function get_error_code() { return $this->code; } }
function __( $s, $domain = '' ) { return $s; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function is_email( $v ) { return filter_var( $v, FILTER_VALIDATE_EMAIL ); }
function sanitize_email( $v ) { return filter_var( $v, FILTER_SANITIZE_EMAIL ); }
function sanitize_text_field( $v ) { return trim( strip_tags( $v ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( $v ) ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $v ) ); }
function sanitize_hex_color( $v ) { return $v; }
function get_option( $v ) { return 'admin@example.com'; }
function absint( $v ) { return abs( (int) $v ); }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function admin_url( $v ) { return 'https://example.test/wp-admin/' . $v; }
function wp_specialchars_decode( $v, $flags ) { return html_entity_decode( $v, $flags ); }
function get_bloginfo( $v ) { return 'Example'; }
function esc_url_raw( $v ) { return $v; }
function current_time( $v, $gmt ) { return '2026-11-16 12:00:00'; }
$GLOBALS['mail_results'] = array();
$GLOBALS['actions'] = array();
function add_action( $name, $callback ) { $GLOBALS['actions'][$name][] = $callback; }
function remove_action( $name, $callback ) { $GLOBALS['actions'][$name] = array_filter( $GLOBALS['actions'][$name], fn( $item ) => $item !== $callback ); }
function wp_mail( $recipient, $subject, $body ) { $GLOBALS['calls'][] = array( $recipient, $subject, $body ); return $GLOBALS['mail_results'][$recipient] ?? true; }
class FakeDB {
 public $prefix = 'wp_', $insert_id = 0, $rows = array();
 function insert( $table, $data ) { $this->insert_id++; $this->rows[$this->insert_id] = $data + array( 'id' => $this->insert_id, 'error_code' => '', 'attempted_at' => null ); return 1; }
 function prepare( $sql, ...$args ) { return array( $sql, $args ); }
 function query( $query ) { [ $sql, $args ] = $query; [ $new, $id, $expected ] = $args; if ( ! isset( $this->rows[$id] ) || $this->rows[$id]['status'] !== $expected ) return 0; $this->rows[$id]['status'] = $new; return 1; }
 function get_row( $query, $mode ) { return $this->rows[$query[1][0]] ?? null; }
 function update( $table, $data, $where ) { $this->rows[$where['id']] = array_replace( $this->rows[$where['id']], $data ); return 1; }
}
$GLOBALS['wpdb'] = new FakeDB();
require __DIR__ . '/../includes/class-settings.php';
require __DIR__ . '/../includes/class-validator.php';
require __DIR__ . '/../includes/class-database.php';
require __DIR__ . '/../includes/class-mailer.php';
function check( $condition, $label ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); } echo "PASS: $label\n"; }
$validate = new ReflectionMethod( JFB\Validator::class, 'sanitize_form_settings' );
$fields = array( array( 'id' => 'field-id', 'key' => 'email', 'type' => 'email' ) );
$clean = $validate->invoke( null, array( 'notification_email' => 'old@example.com' ), $fields );
check( $clean['notification_emails'] === array( 'old@example.com' ) && ! $clean['confirmation_enabled'], 'legacy setting retained, confirmation off' );
$clean = $validate->invoke( null, array( 'notification_email' => '' ), $fields );
check( $clean['notification_emails'] === array(), 'legacy blank remains disabled' );
$clean = $validate->invoke( null, array( 'notification_emails' => array( 'a@example.com', 'A@example.com', 'b@example.com' ), 'confirmation_enabled' => true, 'confirmation_field' => 'field-id', 'confirmation_subject' => 'We received your response', 'confirmation_body' => 'Thank you for {form_name}. Reference: {submission_id}' ), $fields );
check( count( $clean['notification_emails'] ) === 2, 'deduplicate recipients' );
check( is_wp_error( $validate->invoke( null, array( 'notification_emails' => array( 'bad' ) ), $fields ) ), 'invalid recipient rejected' );
check( is_wp_error( $validate->invoke( null, array( 'confirmation_enabled' => true, 'confirmation_field' => 'deleted' ), $fields ) ), 'deleted confirmation field rejected' );
$form = array( 'name' => 'Feedback', 'fields' => $fields, 'settings' => $clean );
$submission = array( 'id' => 1, 'uuid' => 'abc', 'created_at' => '2026-11-16 12:00:00' );
$GLOBALS['mail_results']['b@example.com'] = false;
JFB\Mailer::notify( $form, $submission, array( 'email' => 'user@example.com' ) );
$rows = $GLOBALS['wpdb']->rows;
check( count( $rows ) === 3 && count( $GLOBALS['calls'] ) === 3, 'send separate notifications and confirmation' );
check( $rows[2]['status'] === 'failed' && $rows[3]['status'] === 'sent', 'record per-recipient result independently' );
check( str_contains( $GLOBALS['calls'][2][2], 'Feedback' ) && str_contains( $GLOBALS['calls'][2][2], 'abc' ), 'confirmation placeholders expanded' );
$GLOBALS['mail_results']['b@example.com'] = true;
check( JFB\Mailer::send( 2 ) && ! JFB\Mailer::send( 2 ), 'retry failed message only once' );
JFB\Mailer::notify( $form, $submission, array( 'email' => '' ) );
check( end( $GLOBALS['wpdb']->rows )['status'] === 'skipped', 'missing optional email skipped' );
