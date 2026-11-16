<?php
// Access-policy checks without a WordPress database.
class WP_User { public function __construct( public int $ID ) {} }
$GLOBALS['assignments'] = array( 10 => array( 2, 3 ), 20 => array( 3 ) );
$GLOBALS['current_id'] = 2;
$GLOBALS['manager'] = false;
function get_option( $name, $default = false ) { return $GLOBALS['assignments']; }
function get_current_user_id() { return $GLOBALS['current_id']; }
function current_user_can( $cap ) { return 'jfb_manage_forms' === $cap && $GLOBALS['manager']; }
function absint( $value ) { return abs( (int) $value ); }
require __DIR__ . '/../includes/class-access.php';
require __DIR__ . '/../includes/class-repository.php';
function check_access( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); } echo "PASS: $message\n"; }
check_access( JFB\Access::form_ids( 2 ) === array( 10 ), 'assignments are per user' );
$caps = JFB\Access::grant_viewer_caps( array(), array(), array(), new WP_User( 2 ) );
check_access( ! empty( $caps['jfb_view_submissions'] ) && ! empty( $caps['jfb_export_submissions'] ) && empty( $caps['jfb_manage_forms'] ), 'assigned user receives only view and export capabilities' );
check_access( JFB\Access::can_view_submission( array( 'form_id' => 10, 'status' => 'new' ) ), 'assigned response accessible' );
check_access( ! JFB\Access::can_view_submission( array( 'form_id' => 20, 'status' => 'new' ) ), 'other form response denied' );
check_access( ! JFB\Access::can_view_submission( array( 'form_id' => 10, 'status' => 'trash' ) ), 'trashed response denied' );
$where = new ReflectionMethod( JFB\Repository::class, 'submission_where' );
[ $sql, $args ] = $where->invoke( new JFB\Repository(), array( 'form_id' => 20, 'status' => 'trash' ) );
check_access( in_array( 's.form_id IN (%d)', $sql, true ) && $args === array( 10, 20 ) && in_array( "s.status <> 'trash'", $sql, true ), 'list, count and export filters enforce assignment and exclude trash' );
$GLOBALS['assignments'] = array();
check_access( JFB\Access::grant_viewer_caps( array(), array(), array(), new WP_User( 2 ) ) === array(), 'revocation removes granted capabilities' );
[ $sql ] = $where->invoke( new JFB\Repository(), array() );
check_access( in_array( '1=0', $sql, true ), 'unassigned user query returns no responses' );
$GLOBALS['manager'] = true;
check_access( JFB\Access::can_view_submission( array( 'form_id' => 20, 'status' => 'trash' ) ), 'form manager retains full access' );
