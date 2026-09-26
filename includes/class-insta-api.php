<?php
/**
 * Instagram REST API Controller
 *
 * Registers REST API endpoints for profile lookup and safe image proxying.
 *
 * @package Insta_Profile_Lookup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Insta_Api {

	const API_NAMESPACE = 'insta-lookup/v1';

	/**
	 * Rate limit: max requests per window per IP.
	 */
	const RATE_LIMIT_MAX    = 10;
	const RATE_LIMIT_WINDOW = 60; // seconds

	/**
	 * Maximum image proxy response size in bytes (2 MB).
	 */
	const PROXY_MAX_SIZE = 2097152;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_image_proxy_response' ), 10, 4 );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_routes() {
		// Profile lookup endpoint (supports /profile?username=handle)
		register_rest_route(
			self::API_NAMESPACE,
			'/profile',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_profile_lookup' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'username' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'refresh'  => array(
							'required'          => false,
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
				array(
					'methods'             => 'OPTIONS',
					'callback'            => array( __CLASS__, 'handle_options_preflight' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		// Profile lookup endpoint (supports /profile/{username})
		register_rest_route(
			self::API_NAMESPACE,
			'/profile/(?P<username>[a-zA-Z0-9._]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_profile_lookup' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'username' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'refresh'  => array(
							'required'          => false,
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
				array(
					'methods'             => 'OPTIONS',
					'callback'            => array( __CLASS__, 'handle_options_preflight' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		// Safe image proxy endpoint
		register_rest_route(
			self::API_NAMESPACE,
			'/image-proxy',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_image_proxy' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'url' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
						),
					),
				),
				array(
					'methods'             => 'OPTIONS',
					'callback'            => array( __CLASS__, 'handle_options_preflight' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Preflight OPTIONS request handler for cross-origin callers.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_options_preflight() {
		$response = new WP_REST_Response( array( 'status' => 'ok' ), 200 );
		$response->header( 'Access-Control-Allow-Origin', '*' );
		$response->header( 'Access-Control-Allow-Methods', 'GET, OPTIONS' );
		$response->header( 'Access-Control-Allow-Headers', 'Content-Type, X-WP-Nonce, Authorization' );
		return $response;
	}

	/**
	 * Simple transient-based rate limiter keyed by client IP.
	 *
	 * @return true|WP_Error True if request is allowed, WP_Error if rate limited.
	 */
	private static function check_rate_limit() {
		$ip   = self::get_client_ip();
		$key  = 'insta_rl_' . md5( $ip );
		$hits = (int) get_transient( $key );

		// Logged-in users get a higher limit
		$max = is_user_logged_in() ? self::RATE_LIMIT_MAX * 3 : self::RATE_LIMIT_MAX;

		if ( $hits >= $max ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many requests. Please wait a moment and try again.', 'insta-profile-lookup' ),
				array(
					'status'  => 429,
					'headers' => array( 'Retry-After' => self::RATE_LIMIT_WINDOW ),
				)
			);
		}

		set_transient( $key, $hits + 1, self::RATE_LIMIT_WINDOW );
		return true;
	}

	/**
	 * Get the client's IP address with proxy-awareness and spoofing mitigation.
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		$remote_addr = ! empty( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		// If REMOTE_ADDR is a valid public IP, use it directly (cannot be spoofed by HTTP headers)
		if ( filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
			if ( ! self::is_private_ip( $remote_addr ) && '127.0.0.1' !== $remote_addr ) {
				return $remote_addr;
			}
		}

		// When behind a reverse proxy (e.g. Cloudflare, load balancer), evaluate X-Forwarded-For
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$raw_xff = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$parts   = explode( ',', $raw_xff );
			$client  = trim( $parts[0] );
			if ( filter_var( $client, FILTER_VALIDATE_IP ) ) {
				return $client;
			}
		}

		return filter_var( $remote_addr, FILTER_VALIDATE_IP ) ? $remote_addr : '0.0.0.0';
	}

	/**
	 * Handle profile lookup request.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_profile_lookup( WP_REST_Request $request ) {
		$raw_username = $request->get_param( 'username' );

		// Validate required username parameter
		if ( empty( $raw_username ) || ! is_string( $raw_username ) || '' === trim( $raw_username ) ) {
			return new WP_Error(
				'missing_username',
				__( 'Instagram username parameter is required.', 'insta-profile-lookup' ),
				array( 'status' => 400 )
			);
		}

		$rate_check = self::check_rate_limit();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$skip_cache = (bool) $request->get_param( 'refresh' );

		// Programmatic lookup via core API
		$profile = insta_lookup_profile( $raw_username, $skip_cache );

		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		// Provide convenient image proxy helper URLs for external frontends
		$profile_response = $profile;
		$proxy_endpoint   = rest_url( self::API_NAMESPACE . '/image-proxy' );

		if ( ! empty( $profile_response['profile_pic_url'] ) ) {
			$profile_response['profile_pic_proxy'] = add_query_arg(
				'url',
				$profile_response['profile_pic_url'],
				$proxy_endpoint
			);
		}

		if ( ! empty( $profile_response['media'] ) && is_array( $profile_response['media'] ) ) {
			foreach ( $profile_response['media'] as &$item ) {
				if ( ! empty( $item['thumbnail'] ) ) {
					$item['thumbnail_proxy'] = add_query_arg(
						'url',
						$item['thumbnail'],
						$proxy_endpoint
					);
				}
			}
			unset( $item );
		}

		$response = new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $profile_response,
			),
			200
		);

		$response->header( 'Access-Control-Allow-Origin', '*' );
		$response->header( 'Access-Control-Allow-Methods', 'GET, OPTIONS' );
		$response->header( 'Cache-Control', 'public, max-age=60' );

		return $response;
	}

	/**
	 * Handle image proxy request to bypass CDN referrer and CORS restrictions.
	 * Hardened against SSRF: enforces HTTPS, allowlisted CDN hosts only,
	 * validates resolved IP is not in a private/reserved range, enforces
	 * image content-type and max response size.
	 *
	 * @param WP_REST_Request $request
	 */
	public static function handle_image_proxy( WP_REST_Request $request ) {
		$rate_check = self::check_rate_limit();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$raw_url = $request->get_param( 'url' );

		// Validate URL format
		$url = filter_var( $raw_url, FILTER_VALIDATE_URL );
		if ( ! $url ) {
			return new WP_Error( 'invalid_url', 'Invalid image URL provided', array( 'status' => 400 ) );
		}

		// Enforce HTTPS only
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( 'https' !== strtolower( $scheme ) ) {
			return new WP_Error( 'invalid_scheme', 'Only HTTPS URLs are permitted', array( 'status' => 400 ) );
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return new WP_Error( 'invalid_host', 'Invalid image host', array( 'status' => 400 ) );
		}

		// Enforce standard HTTPS port 443
		$port = wp_parse_url( $url, PHP_URL_PORT );
		if ( ! empty( $port ) && 443 !== (int) $port ) {
			return new WP_Error( 'invalid_port', 'Only standard HTTPS port (443) is permitted', array( 'status' => 400 ) );
		}

		// Security: Only allow specific Instagram and Facebook CDN hosts
		$allowed_patterns = array(
			'/^[a-z0-9.-]+\.cdninstagram\.com$/i',
			'/^[a-z0-9.-]+\.fbcdn\.net$/i',
			'/^scontent[a-z0-9.-]*\.cdninstagram\.com$/i',
		);

		$is_allowed = false;
		foreach ( $allowed_patterns as $pattern ) {
			if ( preg_match( $pattern, $host ) ) {
				$is_allowed = true;
				break;
			}
		}

		if ( ! $is_allowed ) {
			return new WP_Error( 'disallowed_host', 'Image proxy host is not permitted', array( 'status' => 403 ) );
		}

		// SSRF protection: resolve DNS and block internal/private IPs
		$resolved_ip = gethostbyname( $host );
		if ( $resolved_ip === $host ) {
			return new WP_Error( 'dns_error', 'Could not resolve host', array( 'status' => 400 ) );
		}

		if ( self::is_private_ip( $resolved_ip ) ) {
			return new WP_Error( 'ssrf_blocked', 'Request to internal address blocked', array( 'status' => 403 ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'redirection'         => 0, // Prevent redirect-based SSRF bypass
				'sslverify'           => true,
				'headers'             => array(
					'User-Agent' => Insta_Scraper::BROWSER_USER_AGENT,
					'Accept'     => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
				),
				'limit_response_size' => self::PROXY_MAX_SIZE,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status       = wp_remote_retrieve_response_code( $response );
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		$image_body   = wp_remote_retrieve_body( $response );

		if ( 200 !== $status || empty( $image_body ) ) {
			return new WP_Error( 'fetch_failed', 'Could not stream image from upstream', array( 'status' => 502 ) );
		}

		// Strict content-type validation: must be an image
		if ( empty( $content_type ) || false === strpos( $content_type, 'image/' ) ) {
			return new WP_Error( 'invalid_content_type', 'Upstream response is not an image', array( 'status' => 502 ) );
		}

		// Enforce response size limit
		if ( strlen( $image_body ) > self::PROXY_MAX_SIZE ) {
			return new WP_Error( 'too_large', 'Image exceeds maximum allowed size', array( 'status' => 413 ) );
		}

		// Return WP_REST_Response without bypassing WordPress REST response layer
		$rest_response = new WP_REST_Response(
			array(
				'is_image_proxy' => true,
				'image_body'     => $image_body,
			),
			200
		);

		$rest_response->header( 'Content-Type', $content_type );
		$rest_response->header( 'Content-Length', strlen( $image_body ) );
		$rest_response->header( 'Cache-Control', 'public, max-age=86400' );
		$rest_response->header( 'X-Content-Type-Options', 'nosniff' );
		$rest_response->header( 'Content-Security-Policy', "default-src 'none'; img-src 'self'" );

		return $rest_response;
	}

	/**
	 * Stream image proxy response through WordPress REST pipeline without JSON encoding.
	 *
	 * @param bool             $served
	 * @param WP_HTTP_Response $result
	 * @param WP_REST_Request  $request
	 * @param WP_REST_Server   $server
	 * @return bool
	 */
	public static function serve_image_proxy_response( $served, $result, $request, $server ) {
		if ( $result instanceof WP_REST_Response ) {
			$data = $result->get_data();
			if ( is_array( $data ) && ! empty( $data['is_image_proxy'] ) && isset( $data['image_body'] ) ) {
				foreach ( $result->get_headers() as $header => $value ) {
					$server->send_header( $header, $value );
				}
				echo $data['image_body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				return true;
			}
		}
		return $served;
	}

	/**
	 * Check if an IP address is in a private or reserved range.
	 *
	 * @param string $ip
	 * @return bool True if private/reserved, false if publicly routable.
	 */
	private static function is_private_ip( $ip ) {
		return ! filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}
}
