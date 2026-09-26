<?php
/**
 * Plugin Name:       Instagram Public Profile Lookup V2
 * Plugin URI:        https://github.com/mhdSameerKhan/insta-profile-lookup
 * Description:       Look up public Instagram profile stats, metadata, and recent posts without requiring authentication or full page reloads.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Sameer
 * Author URI:        https://github.com/mhdSameerKhan
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       insta-profile-lookup
 * Domain Path:       /languages
 *
 * @package           Insta_Profile_Lookup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin Constants
define( 'INSTA_LOOKUP_VERSION', '1.0.0' );
define( 'INSTA_LOOKUP_FILE', __FILE__ );
define( 'INSTA_LOOKUP_PATH', plugin_dir_path( __FILE__ ) );
define( 'INSTA_LOOKUP_URL', plugin_dir_url( __FILE__ ) );
define( 'INSTA_LOOKUP_BASENAME', plugin_basename( __FILE__ ) );

// Core Data Engine
require_once INSTA_LOOKUP_PATH . 'includes/class-insta-cache.php';
require_once INSTA_LOOKUP_PATH . 'includes/class-insta-scraper.php';
require_once INSTA_LOOKUP_PATH . 'includes/insta-functions.php';

// API & Admin Interface
require_once INSTA_LOOKUP_PATH . 'includes/class-insta-api.php';
require_once INSTA_LOOKUP_PATH . 'includes/class-insta-admin.php';

// Frontend Integration (Shortcode & Assets)
require_once INSTA_LOOKUP_PATH . 'includes/class-insta-shortcode.php';

/**
 * Plugin Activation Callback
 */
function insta_lookup_activate() {
	// Verify environment compatibility
	if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
		if ( function_exists( 'deactivate_plugins' ) ) {
			deactivate_plugins( INSTA_LOOKUP_BASENAME );
		}
		wp_die(
			esc_html__( 'Instagram Public Profile Lookup requires PHP 7.4 or higher.', 'insta-profile-lookup' ),
			'Plugin Activation Error',
			array( 'back_link' => true )
		);
	}

	// Initialize default settings if not already present
	if ( false === get_option( 'insta_lookup_cache_ttl' ) ) {
		add_option( 'insta_lookup_cache_ttl', 3600 );
	}
	if ( false === get_option( 'insta_lookup_cache_version' ) ) {
		add_option( 'insta_lookup_cache_version', 1 );
	}
}
register_activation_hook( __FILE__, 'insta_lookup_activate' );

/**
 * Plugin Deactivation Callback
 */
function insta_lookup_deactivate() {
	// Clean up transients when plugin is deactivated
	Insta_Cache::clear_all();
}
register_deactivation_hook( __FILE__, 'insta_lookup_deactivate' );

/**
 * Add settings shortcut link to the Plugins list table.
 *
 * @param array $links Array of plugin action links.
 * @return array Modified array of plugin action links.
 */
function insta_lookup_action_links( $links ) {
	$settings_url  = admin_url( 'options-general.php?page=insta-lookup-settings' );
	$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'insta-profile-lookup' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . INSTA_LOOKUP_BASENAME, 'insta_lookup_action_links' );

/**
 * Load plugin textdomain for internationalization.
 */
function insta_lookup_load_textdomain() {
	load_plugin_textdomain(
		'insta-profile-lookup',
		false,
		dirname( INSTA_LOOKUP_BASENAME ) . '/languages'
	);
}
add_action( 'init', 'insta_lookup_load_textdomain' );

/**
 * Initialize Plugin Components
 */
function insta_lookup_init() {
	Insta_Api::init();
	Insta_Shortcode::init();
	Insta_Admin::init();
}
add_action( 'plugins_loaded', 'insta_lookup_init' );
