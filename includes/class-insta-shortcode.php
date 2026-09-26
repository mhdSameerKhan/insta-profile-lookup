<?php
/**
 * Instagram Shortcode Handler
 *
 * Registers the shortcode [instagram_profile_lookup] and enqueues relevant assets.
 *
 * @package Insta_Profile_Lookup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Insta_Shortcode {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_shortcode( 'instagram_profile_lookup', array( __CLASS__, 'render_shortcode' ) );
		add_shortcode( 'insta_lookup', array( __CLASS__, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Register scripts and styles.
	 */
	public static function register_assets() {
		wp_register_style(
			'insta-lookup-css',
			INSTA_LOOKUP_URL . 'public/css/insta-lookup.css',
			array(),
			INSTA_LOOKUP_VERSION
		);

		wp_register_script(
			'insta-lookup-js',
			INSTA_LOOKUP_URL . 'public/js/insta-lookup.js',
			array(),
			INSTA_LOOKUP_VERSION,
			true
		);

		wp_localize_script(
			'insta-lookup-js',
			'instaLookupSettings',
			array(
				'apiUrl'        => esc_url_raw( rest_url( 'insta-lookup/v1/profile' ) ),
				'proxyUrl'      => esc_url_raw( rest_url( 'insta-lookup/v1/image-proxy' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'strings'       => array(
					'search'        => __( 'Search', 'insta-profile-lookup' ),
					'searching'     => __( 'Searching...', 'insta-profile-lookup' ),
					'followers'     => __( 'Followers', 'insta-profile-lookup' ),
					'following'     => __( 'Following', 'insta-profile-lookup' ),
					'posts'         => __( 'Posts', 'insta-profile-lookup' ),
					'verified'      => __( 'Verified', 'insta-profile-lookup' ),
					'private'       => __( 'Private Account', 'insta-profile-lookup' ),
					'public'        => __( 'Public Account', 'insta-profile-lookup' ),
					'privateNotice' => __( 'This account is private. Recent media and stories are hidden by the user.', 'insta-profile-lookup' ),
					'noMedia'       => __( 'No public photos or videos found.', 'insta-profile-lookup' ),
					'viewOnInsta'   => __( 'View on Instagram', 'insta-profile-lookup' ),
					'cached'        => __( 'Cached Result', 'insta-profile-lookup' ),
					'live'          => __( 'Live Result', 'insta-profile-lookup' ),
					'refresh'       => __( 'Refresh Data', 'insta-profile-lookup' ),
					'enterUsername' => __( 'Please enter a valid Instagram username.', 'insta-profile-lookup' ),
				),
			)
		);
	}

	/**
	 * Render shortcode output.
	 *
	 * @param array $atts
	 * @return string
	 */
	public static function render_shortcode( $atts ) {
		// Ensure assets are loaded when shortcode is rendered
		wp_enqueue_style( 'insta-lookup-css' );
		wp_enqueue_script( 'insta-lookup-js' );

		ob_start();
		include INSTA_LOOKUP_PATH . 'templates/lookup-form.php';
		return ob_get_clean();
	}
}
