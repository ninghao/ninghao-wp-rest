<?php
/*
Plugin Name: Ninghao WP REST
Description: WP REST API alter
Author: wanghao
Version: 1.0.0
Author URI: https://ninghao.net
*/

require plugin_dir_path(__FILE__) . 'includes/endpoints/class-ninghao-wp-rest-users-controller.php';
require plugin_dir_path(__FILE__) . 'includes/endpoints/class-ninghao-wp-rest-weixin-controller.php';

function ninghao_wp_rest_field_alter( $data, $post, $context ) {
  $data->data['excerpt']['plaintext'] = wp_strip_all_tags($data->data['excerpt']['rendered']);
  return $data;
}

add_filter( 'rest_prepare_post', 'ninghao_wp_rest_field_alter', 10, 3 );

function ninghao_wp_rest_jwt_alter( $data, $user ) {
  $avatar = [
    'lg' => get_avatar_url( $user->ID, ['size' => '192']),
    'md' => get_avatar_url( $user->ID, ['size' => '96']),
    'sm' => get_avatar_url( $user->ID, ['size' => '48'])
  ];

  $data['user_avatar'] = $avatar;

  $wx_avatar = get_user_meta( $user->ID, 'wx_avatar_url', true );

  if ( $wx_avatar ) {
    $wx_avatar = substr( $wx_avatar, 0, -1 );
    $wx_avatar = [
      'lg' => $wx_avatar . '132',
      'md' => $wx_avatar . '96',
      'sm' => $wx_avatar . '46'
    ];
    $data['user_avatar'] = $wx_avatar;
  }

  $data['user_caps'] = $user->caps;
  $data['user_id'] = $user->ID;

  return $data;
}

add_filter( 'jwt_auth_token_before_dispatch', 'ninghao_wp_rest_jwt_alter', 10, 2 );
add_action( 'rest_api_init', function () {
  $users = new Ninghao_WP_REST_Users_Controller();
  $users->register_routes();
} );
add_action( 'rest_api_init', function () {
  $weixin = new Ninghao_WP_REST_Weixin_Controller();
  $weixin->register_routes();
} );
