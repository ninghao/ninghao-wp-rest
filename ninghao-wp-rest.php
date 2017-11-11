<?php
/*
Plugin Name: Ninghao WP REST
Description: WP REST API alter
Author: wanghao
Version: 1.0.0
Author URI: https://ninghao.net
*/

function ninghao_wp_rest_field_alter( $data, $post, $context ) {
  $data->data['excerpt']['plaintext'] = wp_strip_all_tags($data->data['excerpt']['rendered']);
  return $data;
}

add_filter( 'rest_prepare_post', 'ninghao_wp_rest_field_alter', 10, 3 );
