<?php

class Ninghao_WP_REST_Users_Controller extends WP_REST_Users_Controller {
  protected $meta;

  public function __construct() {
    $this->namespace = 'users/v1';
    $this->rest_base = 'register';
    $this->meta = new WP_REST_User_Meta_Fields();
  }

  public function register_routes() {
    register_rest_route( $this->namespace, '/' . $this->rest_base, array(
      array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => array( $this, 'create_item' ),
        'permission_callback' => array( $this, 'create_item_permissions_check' ),
        'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
      ),
      'schema' => array( $this, 'get_public_item_schema' ),
    ) );
  }

  public function create_item_permissions_check( $request ) {
    if ( ! empty( $request['roles'] ) ) {
      return new WP_Error( 'rest_cannot_create_user', __( 'Sorry, you are not allowed to create new users.' ), array( 'status' => rest_authorization_required_code() ) );
    }

    return true;
  }

  public function create_item( $request ) {
		if ( ! empty( $request['id'] ) ) {
			return new WP_Error( 'rest_user_exists', __( 'Cannot create existing user.' ), array( 'status' => 400 ) );
		}

		$schema = $this->get_item_schema();

		if ( ! empty( $request['roles'] ) && ! empty( $schema['properties']['roles'] ) ) {
			$check_permission = $this->check_role_update( $request['id'], $request['roles'] );

			if ( is_wp_error( $check_permission ) ) {
				return $check_permission;
			}
		}

		$user = $this->prepare_item_for_database( $request );

		if ( is_multisite() ) {
			$ret = wpmu_validate_user_signup( $user->user_login, $user->user_email );

			if ( is_wp_error( $ret['errors'] ) && ! empty( $ret['errors']->errors ) ) {
				$error = new WP_Error( 'rest_invalid_param', __( 'Invalid user parameter(s).' ), array( 'status' => 400 ) );
				foreach ( $ret['errors']->errors as $code => $messages ) {
					foreach ( $messages as $message ) {
						$error->add( $code, $message );
					}
					if ( $error_data = $error->get_error_data( $code ) ) {
						$error->add_data( $error_data, $code );
					}
				}
				return $error;
			}
		}

		if ( is_multisite() ) {
			$user_id = wpmu_create_user( $user->user_login, $user->user_pass, $user->user_email );

			if ( ! $user_id ) {
				return new WP_Error( 'rest_user_create', __( 'Error creating new user.' ), array( 'status' => 500 ) );
			}

			$user->ID = $user_id;
			$user_id  = wp_update_user( wp_slash( (array) $user ) );

			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}

			$result= add_user_to_blog( get_site()->id, $user_id, '' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} else {
			$user_id = wp_insert_user( wp_slash( (array) $user ) );

			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
		}

		$user = get_user_by( 'id', $user_id );

		/**
		 * Fires immediately after a user is created or updated via the REST API.
		 *
		 * @since 4.7.0
		 *
		 * @param WP_User         $user     Inserted or updated user object.
		 * @param WP_REST_Request $request  Request object.
		 * @param bool            $creating True when creating a user, false when updating.
		 */
		do_action( 'rest_insert_user', $user, $request, true );

		if ( ! empty( $request['roles'] ) && ! empty( $schema['properties']['roles'] ) ) {
			array_map( array( $user, 'add_role' ), $request['roles'] );
		}

		if ( ! empty( $schema['properties']['meta'] ) && isset( $request['meta'] ) ) {
			$meta_update = $this->meta->update_value( $request['meta'], $user_id );

			if ( is_wp_error( $meta_update ) ) {
				return $meta_update;
			}
		}

		$user = get_user_by( 'id', $user_id );
		$fields_update = $this->update_additional_fields_for_object( $user, $request );

		if ( is_wp_error( $fields_update ) ) {
			return $fields_update;
		}

		$request->set_param( 'context', 'edit' );

		$response = $this->prepare_item_for_response( $user, $request );
		$response = rest_ensure_response( $response );

		$response->set_status( 201 );
		$response->header( 'Location', rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $user_id ) ) );

		return $response;
	}
}
