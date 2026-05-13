<?php
/**
 * Plugin Name: Mantia
 * Description: WhatsApp-first World Cup prediction game powered by openclaWP and Agents API.
 * Version: 0.1.0
 * Author: Automattic
 * Text Domain: mantia
 * Requires PHP: 8.1
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

define( 'MANTIA_VERSION', '0.1.0' );
define( 'MANTIA_PLUGIN_FILE', __FILE__ );
define( 'MANTIA_PATH', plugin_dir_path( __FILE__ ) );
define( 'MANTIA_URL', plugin_dir_url( __FILE__ ) );

require_once MANTIA_PATH . 'includes/autoload.php';

register_activation_hook( __FILE__, array( 'Mantia_Bootstrap', 'activate' ) );

add_action( 'plugins_loaded', array( 'Mantia_Bootstrap', 'init' ) );
