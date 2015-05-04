<?php
/*
Plugin Name: tinyGRID
Plugin URI: https://arunas.co/tinygrid
Description: Check your visitors' IP adresses against Managed GRID API when they try to login, register, post a comment or view a page.
Author: Arūnas Liuiza
Version: 0.1.0
Author URI: http://arunas.co/
License: GPL2 or later
Text Domain: tinygrid
Domain Path: /languages
*/

// initialize plugin
add_action('plugins_loaded',array('tinyGRID','init'));

class tinyGRID {
  const API = 'https://api.managed.lt/grid/v1/';
  // plugin options
  public static $options = array(
    'check'   => array( 'login', 'register','comment'),                     // list of checks, activated by default
    // 'notify'  => 0,                                                         // notify admin if user is kicked out
  );

  // setup all filter/action hooks
  public static function init() {
    // init plugin options
    self::$options     = apply_filters( 'tinygrid_option_defaults', self::$options );
    $real_options      = get_option( 'tinygrid_options', self::$options );
    self::$options     = wp_parse_args( $real_options, self::$options );
    if ( is_admin() ) {
      add_action( 'admin_menu', array( 'tinyGRID', 'admin_init'  ) );
    }

    if ( in_array( 'pageview', self::$options['check'] ) ) {
      self::pageview();
    }

    // add_action( 'check_comment_flood',  array( 'tinyGRID', 'comment'    ), 10, 3 );
    if ( in_array( 'comment', self::$options['check'] ) ) {
      add_filter( 'pre_comment_approved', array( 'tinyGRID', 'spam'       ), 10, 2 );
    }
    if ( in_array( 'login', self::$options['check'] ) ) {
      add_filter( 'wp_authenticate_user', array( 'tinyGRID', 'login'      ), 10, 2 );
    }
    if ( in_array( 'register', self::$options['check'] ) ) {
      add_filter( 'registration_errors',  array( 'tinyGRID', 'register'   ), 10, 3 );
    }
    add_filter( 'shake_error_codes',    array( 'tinyGRID', 'shake'      ) );
    add_filter( 'wp_login_errors',      array( 'tinyGRID', 'error'      ) );

    load_plugin_textdomain( 'tinygrid', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
  }
  // init options page
  public static function admin_init() {
    require_once ( 'includes/options.php' );
    $fields =   array(
      "general" => array(
        'title' => '',
        'callback' => '',
        'options' => array(
          'check' => array(
            'title'=>__( 'Check the IP:', 'tinygrid' ),
            'callback' => 'checklist',
            'args' => array(
              'values' => array(
                'login'     => __( 'Visitor tries to login',          'tinygrid' ),
                'register'  => __( 'Visitor tries to register',       'tinygrid' ),
                'comment'   => __( 'Visitor tries to post a comment', 'tinygrid' ),
                'pageview'  => __( 'On every page view (could slow your site down)', 'tinygrid' ),
              ),
            )
          ),
          // 'notify' => array(
          //   'title'=> __( 'Notify admin via e-mail', 'tinygrid' ),
          //   'callback' => 'checkbox',
          // ),
        )
      )
    );
    $fields = apply_filters( 'tinygrid_option_fields', $fields );
    tinyGRID_options::init(
      'tinygrid',
      __( 'tinyGRID' , 'tinygrid' ),
      __( 'tinyGRID Settings' , 'tinygrid' ),
      $fields,
      __FILE__
    );
  }

  // check the ip on pageview, die()/wp_die() if positive
  public static function pageview ( ) {
    if ( !self::_check_ip() ) {
      $ip = self::_get_ip_address();
      do_action( 'tinygrid_kickout', false, $ip );
      if ( defined( 'DOING_AJAX' ) ) {
        die( sprintf( __( 'A banned IP address (%1$s) tried to access the page.', 'tinygrid' ), $ip ) );
      }
      wp_die( sprintf( __( 'A banned IP address (%1$s) tried to access the page.', 'tinygrid' ), $ip ), 409 );
    }
  }

  // check the ip on comment, die()/wp_die() if positive
  public static function comment ( $author_ip, $author_email, $date_gmt ) {
    if ( !self::_check_ip() ) {
      $ip = self::_get_ip_address();
      do_action( 'tinygrid_kickout', $author_email, $ip );
      if ( defined( 'DOING_AJAX' ) ) {
        die( sprintf( __( '%1$s tried to comment from a banned IP address (%2$s).', 'tinygrid' ),  $author_email, $ip ) );
      }
      wp_die( sprintf( __( '%1$s tried to comment from a banned IP address (%2$s).', 'tinygrid' ),  $author_email, $ip ), 409 );
    }
  }
  // check the ip on comment, mark as Spam if positive
  public static function spam( $approved, $commentdata) {
    if ( !self::_check_ip() ) {
      $ip = self::_get_ip_address();
      do_action( 'tinygrid_kickout', $commentdata['comment_author'], $ip );
      $approved = 'spam';
    }
    return $spam;
  }
  // check for IP on registration
  public static function register ( $errors, $sanitized_user_login, $user_email ) {
    if ( !self::_check_ip() ) {
      $ip = self::_get_ip_address();
      $errors->add( 'banned-ip',sprintf( __( 'The user %1$s tried to register from a banned IP address (%2$s).', 'tinygrid' ),  $sanitized_user_login, $ip ) );
      do_action( 'tinygrid_kickout', $sanitized_user_login, $ip );
    }
    return $errors;
  }
  // check the IP on login
  public static function login ( $user, $password ) {
    if ( !self::_check_ip() ) {
      $ip = self::_get_ip_address();
      $user = new WP_Error( 'banned-ip',sprintf( __( 'The user %1$s tried to login from a banned IP address (%2$s).', 'tinygrid' ),  $user->user_login, $ip ) );
      do_action( 'tinygrid_kickout', $user->user_login, $ip );
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
      $errors->add( 'banned-ip', sprintf( __( 'The user %1$s is already connected on another device.', 'tinygrid' ),  $_REQUEST['banned-ip'] ) );
    }
    return $errors;
  }
  // check for cached response in transiet, call API if not found.
  private static function _check_ip() {
    $result = true;
    $ip = self::_get_ip_address();
    $response = get_transient( 'tinygrid_'.$ip );
    if ( false === $response ) {
      $uri = self::API.$ip;
      $response = wp_remote_get( $uri );
      if ( 200 === $response['response']['code'] ) {
        $response = json_decode( $response['body'], true );
        set_transient( 'tinygrid_'.$ip, $response, 12 * HOUR_IN_SECONDS );
      } else {
        $response = array( 'blacklisted' => false );
      }
    }
    if ( $response['blacklisted'] ) {
      $result = false;
    }
    return $result;
  }
  // get ip address for current call
  private static function _get_ip_address() {
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
