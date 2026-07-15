<?php

namespace JFB;

final class Rest_Controller extends \WP_REST_Controller {
	protected $namespace = 'jplugin-formbuilder/v1';

	public function __construct( private readonly Repository $repository, private readonly Submission_Service $submissions ) {}

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/forms', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'list_forms' ), 'permission_callback' => array( $this, 'can_manage_forms' ) ),
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'create_form' ), 'permission_callback' => array( $this, 'can_manage_forms' ) ),
		) );
		register_rest_route( $this->namespace, '/forms/(?P<uuid>[a-f0-9-]{36})', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'get_form' ), 'permission_callback' => array( $this, 'can_manage_forms' ) ),
			array( 'methods' => \WP_REST_Server::EDITABLE, 'callback' => array( $this, 'update_form' ), 'permission_callback' => array( $this, 'can_manage_forms' ) ),
			array( 'methods' => \WP_REST_Server::DELETABLE, 'callback' => array( $this, 'delete_form' ), 'permission_callback' => array( $this, 'can_manage_forms' ) ),
		) );
		register_rest_route( $this->namespace, '/forms/(?P<uuid>[a-f0-9-]{36})/submissions', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'submit_form' ),
			'permission_callback' => '__return_true',
			'args' => array(
				'jfb_started_at' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'jfb_website' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'cf-turnstile-response' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
		register_rest_route( $this->namespace, '/submissions', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( $this, 'list_submissions' ),
			'permission_callback' => array( $this, 'can_view_submissions' ),
		) );
		register_rest_route( $this->namespace, '/submissions/(?P<uuid>[a-f0-9-]{36})', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'get_submission' ), 'permission_callback' => array( $this, 'can_view_submissions' ) ),
			array( 'methods' => \WP_REST_Server::EDITABLE, 'callback' => array( $this, 'update_submission' ), 'permission_callback' => array( $this, 'can_view_submissions' ) ),
		) );
		register_rest_route( $this->namespace, '/templates', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => static fn() => rest_ensure_response( Templates::all() ),
			'permission_callback' => array( $this, 'can_manage_forms' ),
		) );
	}

	public function list_forms(): \WP_REST_Response { return rest_ensure_response( $this->repository->list_forms() ); }

	public function get_form( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$form = $this->repository->get_form( $request['uuid'] );
		return $form ? rest_ensure_response( $form ) : new \WP_Error( 'jfb_not_found', __( 'Form not found.', 'jplugin-formbuilder' ), array( 'status' => 404 ) );
	}

	public function create_form( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->repository->save_form( $request->get_json_params() ?: $request->get_body_params() );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result, 201 );
	}

	public function update_form( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->repository->save_form( $request->get_json_params() ?: $request->get_body_params(), $request['uuid'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function delete_form( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( array( 'deleted' => $this->repository->delete_form( $request['uuid'] ) ) );
	}

	public function submit_form( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params = $request->get_params();
		unset( $params['uuid'], $params['jfb_started_at'], $params['jfb_website'], $params['cf-turnstile-response'], $params['_wpnonce'] );
		$result = $this->submissions->submit(
			$request['uuid'],
			$params,
			$request->get_file_params(),
			array(
				'started_at' => $request->get_param( 'jfb_started_at' ),
				'website' => $request->get_param( 'jfb_website' ),
				'turnstile' => $request->get_param( 'cf-turnstile-response' ),
			)
		);
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result, 201 );
	}

	public function list_submissions( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( $this->repository->list_submissions( array( 'form_id' => $request->get_param( 'form_id' ), 'status' => $request->get_param( 'status' ) ) ) );
	}

	public function get_submission( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$item = $this->repository->get_submission( $request['uuid'] );
		if ( ! $item ) {
			return new \WP_Error( 'jfb_not_found', __( 'Submission not found.', 'jplugin-formbuilder' ), array( 'status' => 404 ) );
		}
		if ( 'new' === $item['status'] ) {
			$this->repository->update_submission_status( $request['uuid'], 'read' );
			$item['status'] = 'read';
		}
		return rest_ensure_response( $item );
	}

	public function update_submission( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( ! in_array( $status, array( 'new', 'read', 'trash' ), true ) ) {
			return new \WP_Error( 'jfb_invalid_status', __( 'Invalid submission status.', 'jplugin-formbuilder' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'updated' => $this->repository->update_submission_status( $request['uuid'], $status ) ) );
	}

	public function can_manage_forms(): bool { return current_user_can( 'jfb_manage_forms' ); }
	public function can_view_submissions(): bool { return current_user_can( 'jfb_view_submissions' ); }
}

