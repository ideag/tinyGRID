<?php
/*
Plugin Name: tinyIPblock
Plugin URI: https://arunas.co/tinyipblock
Description: Block known bad IP addresses from commenting, logging in or viewing your site.
Author: Arūnas Liuiza
Version: 0.1.0
Author URI: http://arunas.co/
License: GPL2 or later
Text Domain: tinyip
Domain Path: /languages
*/

// initialize plugin
add_action('plugins_loaded',array('tinyIPblock','init'));

class tinyIPblock {
  const API = 'https://api.managed.lt/grid/v1/';
  public static function init() {
//    add_action( 'check_comment_flood',  array( 'tinyIPblock', 'comment'    ), 10, 3 );
    add_filter( 'pre_comment_approved', array( 'tinyIPblock', 'spam'       ), 10, 2 );
    add_filter( 'wp_authenticate_user', array( 'tinyIPblock', 'login'      ), 10, 2 );
    add_filter( 'registration_errors',  array( 'tinyIPblock', 'register'   ), 10, 3 );
    add_filter( 'shake_error_codes',    array( 'tinyIPblock', 'shake'      ) );
    add_filter( 'wp_login_errors',      array( 'tinyIPblock', 'error'      ) );
  }
  public static function comment ( $author_ip, $author_email, $date_gmt ) {
    if ( !self::_check_ip() ) {
      $ip = self::_get_ip_address();
      do_action( 'tinyipblock_kickout', $author_email, $ip );
      if ( defined( 'DOING_AJAX' ) ) {
        die( sprintf( __( '%1$s tried to comment from a banned IP address (%2$s).', 'tinyipblock' ),  $author_email, $ip ) );
      }
      wp_die( sprintf( __( '%1$s tried to comment from a banned IP address (%2$s).', 'tinyipblock' ),  $author_email, $ip ), 409 );
    }
  }
  public static function spam( $approved, $commentdata) {
    if ( !self::_check_ip() ) {
      $ip = self::_get_ip_address();
      do_action( 'tinyipblock_kickout', $commentdata['comment_author'], $ip );
      $approved = 'spam';
    }
    return $spam;
  }
  public static function register ( $errors, $sanitized_user_login, $user_email ) {
    if ( !self::_check_ip() ) {
      $ip = self::_get_ip_address();
      $errors->add( 'banned-ip',sprintf( __( 'The user %1$s tried to register from a banned IP address (%2$s).', 'tinyipblock' ),  $sanitized_user_login, $ip ) );
      do_action( 'tinyipblock_kickout', $sanitized_user_login, $ip );
    }
    return $errors;
  }
  public static function login ( $user, $password ) {
    if ( !self::_check_ip() ) {
      $ip = self::_get_ip_address();
      $user = new WP_Error( 'banned-ip',sprintf( __( 'The user %1$s tried to login from a banned IP address (%2$s).', 'tinyipblock' ),  $user->user_login, $ip ) );
      do_action( 'tinyipblock_kickout', $user->user_login, $ip );
    }
    return $user;
  }

  // === Misc.
  // make WordPress 'shake' the error message
  public static function shake ( $codes = array() ) {
    $codes[] = 'banned-ip';
    return $codes;
  }
  // show correct error when user is kicked out of the system
  public static function error ($errors) {
    if (isset($_REQUEST['banned-ip'])) {
      $errors->add( 'banned-ip', sprintf( __( 'The user %1$s is already connected on another device.', 'tinyipblock' ),  $_REQUEST['banned-ip'] ) );
    }
    return $errors;
  }
  private static function _check_ip() {
    $result = true;
    $ip = self::_get_ip_address();
    $response = get_transient( 'tinyipblock_'.$ip );
    if ( !$response ) {
      $uri = self::API.$ip;
      $response = wp_remote_get( $uri );
      if ( 200 === $response['response']['code'] ) {
        $response = json_decode( $response['body'], true );
        set_transient( 'tinyipblock_'.$ip, $response, 12 * HOUR_IN_SECONDS );
      } else {
        $response = array( 'blacklisted' => false );
      }
    }
    if ( $response->blacklisted ) {
      $result = false;
    }
    return $result;
  }
  private static function _get_ip_address() {
    return '43.255.190.170';
    $server_ip_keys = array(
      'HTTP_CLIENT_IP',
      'HTTP_X_FORWARDED_FOR',
      'HTTP_X_FORWARDED',
      'HTTP_X_CLUSTER_CLIENT_IP',
      'HTTP_FORWARDED_FOR',
      'HTTP_FORWARDED',
      'REMOTE_ADDR',
    );
    foreach ( $server_ip_keys as $key ) {
      if ( isset( $_SERVER[ $key ] ) ) {
        return $_SERVER[ $key ];
      }
    }
    // Fallback local ip.
    return '127.0.0.1';
  }

}
?>
