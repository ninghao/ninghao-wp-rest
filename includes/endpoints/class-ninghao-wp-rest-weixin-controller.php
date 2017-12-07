<?php

use \Firebase\JWT\JWT;

class Ninghao_WP_REST_Weixin_Controller extends WP_REST_Controller {
  public function __construct() {
    $this->namespace = 'weixin/v1';
  }

  public function register_routes() {
    register_rest_route( $this->namespace, '/bind', [
      'methods' => WP_REST_Server::EDITABLE,
      'callback' => [ $this, 'bind' ],
      'permission_callback' => [ $this, 'bind_permissions_check' ]
    ] );
    register_rest_route( $this->namespace, '/login', [
      'methods' => WP_REST_Server::EDITABLE,
      'callback' => [ $this, 'login' ],
      'permission_callback' => [ $this, 'login_permissions_check' ]
    ] );
  }

  public function login_permissions_check( $request ) {
    return true;
  }

  public function login( $request ) {
    $js_code = $request['code'];
    $session = $this->get_weixin_session( $js_code );
    if ( is_wp_error( $session ) ) {
      return $session;
    }

    $user = $this->get_user_by_openid( $session['openid'] );

    if ( !$user ) {
      return new WP_Error(
        'weixin_rest_not_bind',
        __( '您的微信帐号还没有跟网站用户绑定' ),
        [
          'status' => 404
        ]
      );
    }

    $token = $this->generate_token( $user );

    if ( is_wp_error( $token ) ) {
      return $token;
    }

    $response = rest_ensure_response( $token );
    $response->set_status( 201 );

    return $response;
  }

  public function generate_token( $user )
  {
      $secret_key = defined('JWT_AUTH_SECRET_KEY') ? JWT_AUTH_SECRET_KEY : false;

      /** First thing, check the secret key if not exist return a error*/
      if (!$secret_key) {
          return new WP_Error(
              'jwt_auth_bad_config',
              __('JWT is not configurated properly, please contact the admin', 'wp-api-jwt-auth'),
              array(
                  'status' => 403,
              )
          );
      }

      /** Valid credentials, the user exists create the according Token */
      $issuedAt = time();
      $notBefore = apply_filters('jwt_auth_not_before', $issuedAt, $issuedAt);
      $expire = apply_filters('jwt_auth_expire', $issuedAt + (DAY_IN_SECONDS * 7), $issuedAt);

      $token = array(
          'iss' => get_bloginfo('url'),
          'iat' => $issuedAt,
          'nbf' => $notBefore,
          'exp' => $expire,
          'data' => array(
              'user' => array(
                  'id' => $user->data->ID,
              ),
          ),
      );

      /** Let the user modify the token data before the sign. */
      $token = JWT::encode(apply_filters('jwt_auth_token_before_sign', $token, $user), $secret_key);

      /** The token is signed, now create the object with no sensible user data to the client*/
      $data = array(
          'token' => $token,
          'user_email' => $user->data->user_email,
          'user_nicename' => $user->data->user_nicename,
          'user_display_name' => $user->data->display_name,
      );

      /** Let the user modify the data before send it back */
      return apply_filters('jwt_auth_token_before_dispatch', $data, $user);
  }

  public function bind_permissions_check( $request ) {
		$user = $this->get_user( $request['userId'] );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( ! empty( $request['roles'] ) ) {
			if ( ! current_user_can( 'promote_user', $user->ID ) ) {
				return new WP_Error( 'rest_cannot_edit_roles', __( 'Sorry, you are not allowed to edit roles of this user.' ), array( 'status' => rest_authorization_required_code() ) );
			}

			$request_params = array_keys( $request->get_params() );
			sort( $request_params );
			// If only 'id' and 'roles' are specified (we are only trying to
			// edit roles), then only the 'promote_user' cap is required.
			if ( $request_params === array( 'id', 'roles' ) ) {
				return true;
			}
		}

		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return new WP_Error( 'rest_cannot_edit', __( 'Sorry, you are not allowed to edit this user.' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

  protected function get_user( $id ) {
		$error = new WP_Error( 'rest_user_invalid_id', __( 'Invalid user ID.' ), array( 'status' => 404 ) );
		if ( (int) $id <= 0 ) {
			return $error;
		}

		$user = get_userdata( (int) $id );
		if ( empty( $user ) || ! $user->exists() ) {
			return $error;
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user->ID ) ) {
			return $error;
		}

		return $user;
	}

  public function bind( $request ) {
    $js_code = $request['code'];
    $user_id = $request['userId'];
    $user_info = $request['userInfo']['userInfo'];

    $session = $this->get_weixin_session( $js_code );
    if ( is_wp_error($session) ) {
      return $session;
    }

    $user = $this->get_user_by_openid( $session['openid'] );

    if ( $user ) {
      return new WP_Error(
        'weixin_rest_already_bind',
        __( '您的微信帐号与某个用户已经绑定在一起了。' ),
        [
          'status' => 400
        ]
      );
    }

    $this->update_user_weixin_session( $user_id, $session );
    $this->update_user_weixin_user_info( $user_id, $user_info );
    return 'ok';
  }

  public function update_user_weixin_user_info( $user_id, $user_info ) {
    update_user_meta( $user_id, 'wx_avatar_url', $user_info['avatarUrl'] );
    update_user_meta( $user_id, 'wx_city', $user_info['city'] );
    update_user_meta( $user_id, 'wx_country', $user_info['country'] );
    update_user_meta( $user_id, 'wx_gender', $user_info['gender'] );
    update_user_meta( $user_id, 'wx_language', $user_info['language'] );
    update_user_meta( $user_id, 'wx_nickname', $user_info['nickName'] );
    update_user_meta( $user_id, 'wx_province', $user_info['province'] );
  }

  public function update_user_weixin_session( $user_id, $session ) {
    update_user_meta( $user_id, 'wx_openid', $session['openid'] );
    update_user_meta( $user_id, 'wx_session_key', $session['session_key'] );
  }

  public function get_user_by_openid( $openid ) {
    $users = get_users([
      'meta_key' => 'wx_openid',
      'meta_value' => $openid,
      'meta_compare' => '='
    ]);

    if ( empty( $users ) ) {
      return NULL;
    }

    return $users[0];
  }

  public function get_weixin_session( $js_code ) {
    $API_BASE = 'https://api.weixin.qq.com/sns/jscode2session';
    $APP_ID = env('WX_APP_ID');
    $SECRET = env('WX_APP_SECRET');
    $url = "$API_BASE?appid=$APP_ID&secret=$SECRET&js_code=$js_code&grant_type=authorization_code";

    $response = wp_remote_get( $url );
    $session = json_decode( $response['body'], true );

    if ( isset( $session['errcode'] ) ) {
      return new WP_Error(
        $session['errcode'],
        $session['errmsg'],
        [
          'status' => 400
        ]
      );
    }

    return $session;
  }
}
