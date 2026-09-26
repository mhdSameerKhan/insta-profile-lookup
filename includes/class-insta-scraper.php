<?php
/**
 * Instagram Public Profile Scraper & Parser
 *
 * Isolated service for retrieving, parsing, and normalizing Instagram public profile data.
 *
 * @package Insta_Profile_Lookup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Insta_Scraper {

	/**
	 * Primary User-Agent for server-rendered metadata retrieval.
	 */
	const BOT_USER_AGENT = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';

	/**
	 * Secondary fallback User-Agent.
	 */
	const BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

	/**
	 * Instagram Web App ID defaults.
	 */
	const DEFAULT_IG_APP_ID = '936619743392459';
	const IG_APP_ID         = '936619743392459'; // For backward compatibility

	/**
	 * Get the active Instagram App ID. Allows override via constant or filter.
	 *
	 * @return string
	 */
	public static function get_app_id() {
		if ( defined( 'INSTA_APP_ID' ) && ! empty( INSTA_APP_ID ) ) {
			return (string) INSTA_APP_ID;
		}
		return (string) apply_filters( 'insta_lookup_app_id', self::DEFAULT_IG_APP_ID );
	}

	/**
	 * Sanitize and extract username from input string.
	 * Supports: "username", "@username", "https://instagram.com/username", "username?hl=en".
	 *
	 * @param string $input
	 * @return string|false Sanitized username or false if invalid.
	 */
	public static function clean_username( $input ) {
		if ( ! is_string( $input ) ) {
			return false;
		}

		$input = trim( $input );

		// Remove leading/trailing slashes
		$input = trim( $input, '/' );

		// If input is a URL, parse path (including stories/ links)
		if ( preg_match( '#instagram\.com/(?:stories/)?([a-zA-Z0-9._]+)#i', $input, $url_matches ) ) {
			$input = $url_matches[1];
		}

		// Strip query parameters
		if ( false !== strpos( $input, '?' ) ) {
			$parts = explode( '?', $input );
			$input = $parts[0];
		}

		// Remove leading @
		$input = ltrim( $input, '@' );

		// Trim whitespace again
		$input = trim( $input );

		// Reserved paths on instagram.com that are not usernames
		$reserved = array( 'p', 'reel', 'reels', 'explore', 'stories', 'accounts', 'developer', 'about', 'legal', 'terms', 'privacy', 'directory' );
		if ( in_array( strtolower( $input ), $reserved, true ) ) {
			return false;
		}

		// Validate against Instagram username rules: 1-30 chars, alphanumeric, dots, underscores
		if ( preg_match( '/^[a-zA-Z0-9._]{1,30}$/', $input ) ) {
			return strtolower( $input );
		}

		return false;
	}

	/**
	 * Fetch and parse Instagram profile data.
	 *
	 * @param string $raw_username
	 * @param bool   $skip_cache
	 * @return array|WP_Error
	 */
	public static function get_profile( $raw_username, $skip_cache = false ) {
		$username = self::clean_username( $raw_username );
		if ( ! $username ) {
			return new WP_Error(
				'invalid_username',
				__( 'Invalid Instagram username. Usernames may only contain letters, numbers, periods, and underscores (max 30 characters).', 'insta-profile-lookup' ),
				array( 'status' => 400 )
			);
		}

		// 1. Check Cache
		if ( ! $skip_cache ) {
			$cached = Insta_Cache::get( $username );
			if ( $cached ) {
				return $cached;
			}
		}

		// 2. Attempt Strategy A: Session Cookie if provided
		$session_id = Insta_Admin::get_session_id();
		$api_error  = null;

		if ( ! empty( $session_id ) ) {
			// Check for literal placeholder string
			if ( 'paste_your_copied_session_id_here' === trim( $session_id ) ) {
				return new WP_Error(
					'placeholder_session_id',
					__( 'Placeholder session ID detected in wp-config.php. Please replace "paste_your_copied_session_id_here" with your actual Instagram session cookie value.', 'insta-profile-lookup' ),
					array( 'status' => 400 )
				);
			}

			$result = self::fetch_via_web_api( $username, $session_id );
			if ( ! is_wp_error( $result ) && ! empty( $result ) ) {
				Insta_Cache::set( $username, $result );
				return $result;
			}
			// If web API definitively confirmed user does not exist, return immediately
			if ( is_wp_error( $result ) && 'user_not_found' === $result->get_error_code() ) {
				return $result;
			}
			$api_error = $result;
		}

		// 3. Attempt Strategy B: Server-rendered HTML scraper (passes session cookie if available)
		$result = self::fetch_via_html_scraper( $username, $session_id );
		if ( ! is_wp_error( $result ) && ! empty( $result ) ) {
			Insta_Cache::set( $username, $result );
			return $result;
		}

		// If HTML scraper returned a specific error (e.g., 404 user not found), return it
		if ( is_wp_error( $result ) ) {
			// If a session ID was provided but both strategies failed, provide informative diagnostic message
			if ( ! empty( $session_id ) && 'upstream_rate_limited' === $result->get_error_code() ) {
				$detail = is_wp_error( $api_error ) ? ' (' . $api_error->get_error_message() . ')' : '';
				return new WP_Error(
					'session_rejected_or_expired',
					sprintf(
						__( 'Instagram rejected the session%s. Your Instagram session ID may be expired, invalid, or requires a fresh login. Please check or regenerate your session ID.', 'insta-profile-lookup' ),
						$detail
					),
					array( 'status' => 401 )
				);
			}
			return $result;
		}

		return new WP_Error(
			'lookup_failed',
			__( 'Unable to retrieve Instagram profile data at this time. Please try again shortly.', 'insta-profile-lookup' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Fetch profile via Instagram web_profile_info endpoint (authenticated with session).
	 *
	 * @param string $username
	 * @param string $session_id
	 * @return array|WP_Error
	 */
	private static function fetch_via_web_api( $username, $session_id ) {
		$url = "https://www.instagram.com/api/v1/users/web_profile_info/?username={$username}";

		// Clean up session ID format (strip quotes, spaces, or leading 'sessionid=')
		$clean_sid = trim( $session_id, "\"' \t\n\r\0\x0B;" );
		if ( 0 === stripos( $clean_sid, 'sessionid=' ) ) {
			$clean_sid = substr( $clean_sid, 10 );
		}

		$cookie_header = "sessionid={$clean_sid};";

		// Extract ds_user_id from session ID (format: {user_id}%3A...)
		if ( preg_match( '/^(\d+)[:%]/', $clean_sid, $uid_m ) ) {
			$cookie_header .= " ds_user_id={$uid_m[1]};";
		}

		$headers = array(
			'User-Agent'         => self::BROWSER_USER_AGENT,
			'x-ig-app-id'        => self::get_app_id(),
			'x-asbd-id'          => '129477',
			'x-ig-www-claim'     => '0',
			'x-requested-with'   => 'XMLHttpRequest',
			'Accept'             => '*/*',
			'Accept-Language'    => 'en-US,en;q=0.9',
			'Referer'            => "https://www.instagram.com/{$username}/",
			'Cookie'             => $cookie_header,
		);

		$args = apply_filters(
			'insta_lookup_web_api_args',
			array(
				'headers'   => $headers,
				'timeout'   => 15,
				'sslverify' => true,
			),
			$username
		);

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$is_timeout    = false !== stripos( $error_message, 'timed out' ) || false !== stripos( $error_message, 'timeout' );
			return new WP_Error(
				$is_timeout ? 'upstream_timeout' : 'upstream_connection_failed',
				$is_timeout
					? __( 'Connection to Instagram timed out. Please try again.', 'insta-profile-lookup' )
					: sprintf( __( 'Could not connect to Instagram: %s', 'insta-profile-lookup' ), $error_message ),
				array( 'status' => $is_timeout ? 504 : 502 )
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 404 === $status ) {
			return new WP_Error(
				'user_not_found',
				sprintf( __( 'Instagram user "@%s" could not be found.', 'insta-profile-lookup' ), $username ),
				array( 'status' => 404 )
			);
		}

		if ( 200 !== $status ) {
			return new WP_Error( 'api_error', "Instagram Web API returned HTTP {$status}", array( 'status' => $status ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		if ( ! isset( $json['data']['user'] ) ) {
			return new WP_Error( 'parse_error', 'Invalid API response structure', array( 'status' => 500 ) );
		}

		return self::normalize_user_object( $json['data']['user'] );
	}

	/**
	 * Fetch profile via public server-rendered HTML.
	 *
	 * @param string $username
	 * @return array|WP_Error
	 */
	private static function fetch_via_html_scraper( $username, $session_id = '' ) {
		$url = "https://www.instagram.com/{$username}/";

		$headers = array(
			'User-Agent'      => self::BOT_USER_AGENT,
			'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
			'Accept-Language' => 'en-US,en;q=0.9',
		);

		// If a session ID is available, pass it to the HTML scraper and switch to browser user agent
		if ( ! empty( $session_id ) && 'paste_your_copied_session_id_here' !== trim( $session_id ) ) {
			$clean_sid = trim( $session_id, "\"' \t\n\r\0\x0B;" );
			if ( 0 === stripos( $clean_sid, 'sessionid=' ) ) {
				$clean_sid = substr( $clean_sid, 10 );
			}
			$headers['Cookie']     = "sessionid={$clean_sid};";
			$headers['User-Agent'] = self::BROWSER_USER_AGENT;
		}

		$args = apply_filters(
			'insta_lookup_html_scraper_args',
			array(
				'headers'   => $headers,
				'timeout'   => 15,
				'sslverify' => true,
			),
			$username
		);

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$is_timeout    = false !== stripos( $error_message, 'timed out' ) || false !== stripos( $error_message, 'timeout' );
			return new WP_Error(
				$is_timeout ? 'upstream_timeout' : 'upstream_connection_failed',
				$is_timeout
					? __( 'Connection to Instagram timed out. Please try again.', 'insta-profile-lookup' )
					: sprintf( __( 'Could not connect to Instagram: %s', 'insta-profile-lookup' ), $error_message ),
				array( 'status' => $is_timeout ? 504 : 502 )
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 404 === $status ) {
			return new WP_Error(
				'user_not_found',
				sprintf( __( 'Instagram user "@%s" could not be found.', 'insta-profile-lookup' ), $username ),
				array( 'status' => 404 )
			);
		}

		if ( 429 === $status ) {
			return new WP_Error(
				'upstream_rate_limited',
				__( 'Instagram rate limit reached. Please wait a few moments or configure a session ID in settings.', 'insta-profile-lookup' ),
				array( 'status' => 429 )
			);
		}

		if ( $status >= 500 ) {
			return new WP_Error(
				'upstream_server_error',
				__( 'Instagram service is temporarily unavailable. Please try again shortly.', 'insta-profile-lookup' ),
				array( 'status' => 502 )
			);
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return new WP_Error( 'empty_response', 'Empty response from Instagram', array( 'status' => 500 ) );
		}

		// Check for profile not found indicators
		if ( false !== stripos( $html, "Sorry, this page isn't available" ) ||
			 false !== stripos( $html, 'The link you followed may be broken' ) ) {
			return new WP_Error(
				'user_not_found',
				sprintf( __( 'Instagram user "@%s" could not be found.', 'insta-profile-lookup' ), $username ),
				array( 'status' => 404 )
			);
		}

		// Extract OpenGraph and Meta tags (resilient to attribute ordering, quote styles, and entities)
		$og_desc = self::extract_meta_tag( $html, 'og:description' );
		if ( empty( $og_desc ) ) {
			$og_desc = self::extract_meta_tag( $html, 'description' );
		}

		// If no description and no handle mention, profile does not exist
		if ( empty( $og_desc ) && ! preg_match( "/@{$username}/i", $html ) ) {
			return new WP_Error(
				'user_not_found',
				sprintf( __( 'Instagram user "@%s" could not be found.', 'insta-profile-lookup' ), $username ),
				array( 'status' => 404 )
			);
		}

		$og_title = self::extract_meta_tag( $html, 'og:title' );
		if ( empty( $og_title ) ) {
			$og_title = self::extract_meta_tag( $html, 'title' );
		}

		$og_image = self::extract_meta_tag( $html, 'og:image' );

		// Parse Followers, Following, and Posts from description
		// Format: "269M Followers, 195 Following, 32K Posts - See Instagram photos and videos from National Geographic (@natgeo)"
		// Handles plural & singular ("1 Follower", "0 Following", "1 Post")
		$followers_count = null;
		$following_count = null;
		$posts_count     = null;

		if ( preg_match( '/([0-9.,]+[KMB]?)\s*Followers?,\s*([0-9.,]+[KMB]?)\s*Following,\s*([0-9.,]+[KMB]?)\s*Posts?/i', $og_desc, $stats_m ) ) {
			$followers_count = self::parse_abbreviated_number( $stats_m[1] );
			$following_count = self::parse_abbreviated_number( $stats_m[2] );
			$posts_count     = self::parse_abbreviated_number( $stats_m[3] );
		}

		// Parse Display Name from og:title (e.g., "National Geographic (@natgeo) • Instagram photos and videos")
		$full_name = '';
		if ( ! empty( $og_title ) ) {
			if ( preg_match( '/^(.*?)\s*\(@/i', $og_title, $nm ) ) {
				$full_name = trim( $nm[1] );
			}
		}

		// Try to extract rich JSON embedded in script tags
		$biography    = '';
		$is_verified  = false;
		$is_private   = false;
		$external_url = '';

		// Search for JSON script containing user entity
		if ( false !== strpos( $html, '"biography":' ) ) {
			$pos          = strpos( $html, '"biography":' );
			$script_start = strrpos( substr( $html, 0, $pos ), '<script' );
			$script_end   = strpos( $html, '</script>', $pos );

			if ( false !== $script_start && false !== $script_end ) {
				$script_content = substr( $html, $script_start, ( $script_end + 9 ) - $script_start );
				$inner          = preg_replace( '/^<script[^>]*>|<\/script>$/is', '', $script_content );
				$json           = json_decode( $inner, true );

				if ( $json && is_array( $json ) ) {
					// Try to find the specific user node matching our target username
					// to avoid extracting data from unrelated entities (comments, suggestions)
					$source = self::find_user_node( $json, $username );
					if ( ! $source ) {
						// Fallback: blind key extraction from entire JSON tree
						$source = array();
						self::extract_nested_keys(
							$json,
							array( 'full_name', 'biography', 'is_verified', 'is_private',
								'profile_pic_url', 'profile_pic_url_hd', 'external_url',
								'follower_count', 'following_count' ),
							$source
						);
					}

					if ( ! empty( $source['full_name'] ) ) {
						$full_name = $source['full_name'];
					}
					if ( isset( $source['biography'] ) ) {
						$biography = $source['biography'];
					}
					if ( isset( $source['is_verified'] ) ) {
						$is_verified = (bool) $source['is_verified'];
					}
					if ( isset( $source['is_private'] ) ) {
						$is_private = (bool) $source['is_private'];
					}
					if ( ! empty( $source['profile_pic_url_hd'] ) ) {
						$og_image = $source['profile_pic_url_hd'];
					} elseif ( ! empty( $source['profile_pic_url'] ) && empty( $og_image ) ) {
						$og_image = $source['profile_pic_url'];
					}
					if ( ! empty( $source['external_url'] ) ) {
						$external_url = $source['external_url'];
					}
					if ( null === $followers_count ) {
						if ( isset( $source['edge_followed_by']['count'] ) ) {
							$followers_count = (int) $source['edge_followed_by']['count'];
						} elseif ( isset( $source['follower_count'] ) ) {
							$followers_count = (int) $source['follower_count'];
						}
					}
					if ( null === $following_count ) {
						if ( isset( $source['edge_follow']['count'] ) ) {
							$following_count = (int) $source['edge_follow']['count'];
						} elseif ( isset( $source['following_count'] ) ) {
							$following_count = (int) $source['following_count'];
						}
					}
					if ( null === $posts_count ) {
						if ( isset( $source['edge_owner_to_timeline_media']['count'] ) ) {
							$posts_count = (int) $source['edge_owner_to_timeline_media']['count'];
						} elseif ( isset( $source['media_count'] ) ) {
							$posts_count = (int) $source['media_count'];
						}
					}
				}
			}
		}

		// Fallback for bio from meta description if bio wasn't in JSON
		if ( empty( $biography ) && ! empty( $og_desc ) ) {
			if ( preg_match( '/on Instagram:\s*"(.*?)"/is', $og_desc, $bio_m ) ) {
				$biography = trim( $bio_m[1] );
			}
		}

		// Data quality validation: if all key fields are empty/zero, parsing likely failed
		if ( empty( $full_name ) && null === $followers_count && empty( $biography ) && empty( $og_image ) ) {
			return new WP_Error(
				'parse_failed',
				__( 'Could not extract meaningful profile data. Instagram may have changed its page structure.', 'insta-profile-lookup' ),
				array( 'status' => 502 )
			);
		}

		// Extract Media Items if public profile
		$media_items = array();
		if ( ! $is_private ) {
			$media_items = self::extract_media_items( $html, $username );
		}

		$profile_data = array(
			'username'        => $username,
			'full_name'       => ! empty( $full_name ) ? $full_name : $username,
			'biography'       => $biography,
			'external_url'    => $external_url,
			'profile_pic_url' => $og_image,
			'followers_count' => null !== $followers_count ? $followers_count : 0,
			'following_count' => null !== $following_count ? $following_count : 0,
			'posts_count'     => null !== $posts_count ? $posts_count : 0,
			'is_verified'     => $is_verified,
			'is_private'      => $is_private,
			'media'           => $media_items,
			'retrieved_at'    => current_time( 'mysql' ),
			'cached'          => false,
		);

		return apply_filters( 'insta_lookup_scraped_profile', $profile_data, $username );
	}

	/**
	 * Extract recent media posts from HTML and Polaris JSON.
	 *
	 * @param string $html
	 * @param string $username
	 * @return array
	 */
	private static function extract_media_items( $html, $username ) {
		$items = array();

		// Strategy 1: Search for POLARIS_ media objects in JSON
		if ( preg_match_all( '/\{[^{}]*"id":"POLARIS_(\d+)"[^{}]*\}/i', $html, $m_nodes ) ) {
			foreach ( $m_nodes[0] as $node_str ) {
				$m_data = json_decode( $node_str, true );
				if ( ! empty( $m_data['id'] ) ) {
					$raw_id = str_replace( 'POLARIS_', '', $m_data['id'] );
					$code   = self::media_id_to_shortcode( $raw_id );
					if ( ! empty( $code ) ) {
						$items[ $raw_id ] = array(
							'id'        => $raw_id,
							'shortcode' => $code,
							'url'       => "https://www.instagram.com/p/{$code}/",
							'thumbnail' => '',
							'caption'   => '',
							'is_video'  => isset( $m_data['media_type'] ) && 2 === (int) $m_data['media_type'],
						);
					}
				}
			}
		}

		// Strategy 2: Extract from <img> tags in the HTML
		preg_match_all( '/<img\s+[^>]*src="([^"]+)"[^>]*>/is', $html, $img_tags );
		if ( ! empty( $img_tags[0] ) ) {
			foreach ( $img_tags[0] as $tag ) {
				// Skip profile picture
				if ( false !== stripos( $tag, 'profile picture' ) ) {
					continue;
				}

				// Extract src
				if ( ! preg_match( '/src="([^"]+)"/i', $tag, $src_m ) ) {
					continue;
				}
				$src = html_entity_decode( $src_m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );

				// Skip tracking pixels, static sprites or icon images
				if ( false === strpos( $src, 'cdninstagram.com' ) && false === strpos( $src, 'fbcdn.net' ) ) {
					continue;
				}

				// Extract caption from alt
				$caption = '';
				if ( preg_match( '/alt="([^"]*)"/is', $tag, $alt_m ) ) {
					$caption = html_entity_decode( $alt_m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}

				// Check if there's a cache key or media ID
				$item_id   = '';
				$shortcode = '';
				if ( preg_match( '/ig_cache_key=([a-zA-Z0-9%_-]+)/', $src, $key_m ) ) {
					$b64 = urldecode( $key_m[1] );
					if ( preg_match( '/^([a-zA-Z0-9+\/=]+)/', $b64, $pure_b64 ) ) {
						$decoded = base64_decode( $pure_b64[1] );
						if ( is_numeric( $decoded ) ) {
							$item_id   = $decoded;
							$shortcode = self::media_id_to_shortcode( $decoded );
						}
					}
				}

				if ( empty( $item_id ) ) {
					$item_id = 'media_' . count( $items );
				}

				if ( isset( $items[ $item_id ] ) ) {
					$items[ $item_id ]['thumbnail'] = $src;
					$items[ $item_id ]['caption']   = $caption;
				} else {
					$items[ $item_id ] = array(
						'id'        => $item_id,
						'shortcode' => $shortcode,
						'url'       => ! empty( $shortcode ) ? "https://www.instagram.com/p/{$shortcode}/" : "https://www.instagram.com/{$username}/",
						'thumbnail' => $src,
						'caption'   => $caption,
						'is_video'  => false,
					);
				}

				// Limit to latest 12 posts
				if ( count( $items ) >= 12 ) {
					break;
				}
			}
		}

		// Filter items with valid thumbnails and reset array keys
		$result = array();
		foreach ( $items as $it ) {
			if ( ! empty( $it['thumbnail'] ) ) {
				$result[] = $it;
			}
		}

		return array_slice( $result, 0, 12 );
	}

	/**
	 * Normalize a raw user object from Instagram Web API.
	 *
	 * @param array $u
	 * @return array
	 */
	private static function normalize_user_object( $u ) {
		$media = array();
		if ( isset( $u['edge_owner_to_timeline_media']['edges'] ) && is_array( $u['edge_owner_to_timeline_media']['edges'] ) ) {
			foreach ( $u['edge_owner_to_timeline_media']['edges'] as $edge ) {
				$node      = $edge['node'] ?? array();
				$shortcode = $node['shortcode'] ?? '';
				$caption   = '';
				if ( ! empty( $node['edge_media_to_caption']['edges'][0]['node']['text'] ) ) {
					$caption = $node['edge_media_to_caption']['edges'][0]['node']['text'];
				}
				$media[] = array(
					'id'        => $node['id'] ?? '',
					'shortcode' => $shortcode,
					'url'       => ! empty( $shortcode ) ? "https://www.instagram.com/p/{$shortcode}/" : '',
					'thumbnail' => $node['display_url'] ?? '',
					'caption'   => $caption,
					'is_video'  => ! empty( $node['is_video'] ),
				);
			}
		}

		return array(
			'username'        => $u['username'] ?? '',
			'full_name'       => $u['full_name'] ?? $u['username'] ?? '',
			'biography'       => $u['biography'] ?? '',
			'external_url'    => $u['external_url'] ?? '',
			'profile_pic_url' => $u['profile_pic_url_hd'] ?? $u['profile_pic_url'] ?? '',
			'followers_count' => (int) ( $u['edge_followed_by']['count'] ?? 0 ),
			'following_count' => (int) ( $u['edge_follow']['count'] ?? 0 ),
			'posts_count'     => (int) ( $u['edge_owner_to_timeline_media']['count'] ?? 0 ),
			'is_verified'     => (bool) ( $u['is_verified'] ?? false ),
			'is_private'      => (bool) ( $u['is_private'] ?? false ),
			'media'           => $media,
			'retrieved_at'    => current_time( 'mysql' ),
			'cached'          => false,
		);
	}

	/**
	 * Convert Instagram numeric media ID to alphanumeric shortcode.
	 *
	 * @param string $media_id
	 * @return string
	 */
	public static function media_id_to_shortcode( $media_id ) {
		if ( ! is_numeric( $media_id ) ) {
			return '';
		}

		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
		$code     = '';

		if ( function_exists( 'bcmod' ) && function_exists( 'bcdiv' ) ) {
			while ( bccomp( $media_id, '0' ) > 0 ) {
				$rem      = (int) bcmod( $media_id, '64' );
				$code     = $alphabet[ $rem ] . $code;
				$media_id = bcdiv( $media_id, '64', 0 );
			}
			return $code;
		}

		if ( function_exists( 'gmp_init' ) ) {
			$id = gmp_init( $media_id );
			while ( gmp_cmp( $id, 0 ) > 0 ) {
				$rem      = gmp_intval( gmp_mod( $id, 64 ) );
				$code     = $alphabet[ $rem ] . $code;
				$id       = gmp_div_q( $id, 64 );
			}
			return $code;
		}

		// Native 64-bit PHP arithmetic fallback when extensions are absent
		if ( PHP_INT_SIZE >= 8 ) {
			$id = (int) $media_id;
			if ( $id > 0 && (string) $id === (string) $media_id ) {
				while ( $id > 0 ) {
					$rem  = $id % 64;
					$code = $alphabet[ $rem ] . $code;
					$id   = intdiv( $id, 64 );
				}
				return $code;
			}
		}

		return '';
	}

	/**
	 * Parse string representations like "269M", "1.5K", "3,200" into integers.
	 *
	 * @param string $str
	 * @return int
	 */
	private static function parse_abbreviated_number( $str ) {
		$str = trim( str_replace( ',', '', $str ) );
		$multiplier = 1;

		if ( preg_match( '/([0-9.]+)\s*([KMB])/i', $str, $m ) ) {
			$val  = (float) $m[1];
			$unit = strtoupper( $m[2] );
			if ( 'K' === $unit ) {
				$multiplier = 1000;
			} elseif ( 'M' === $unit ) {
				$multiplier = 1000000;
			} elseif ( 'B' === $unit ) {
				$multiplier = 1000000000;
			}
			return (int) round( $val * $multiplier );
		}

		return (int) $str;
	}

	/**
	 * Find a user node in nested JSON data that matches the target username.
	 * This is more reliable than blind key extraction, as it ensures data
	 * belongs to the correct user and not to other entities (comments, suggestions).
	 *
	 * @param array  $arr      The JSON data to search.
	 * @param string $username The target username to match.
	 * @return array|null The matching user node, or null if not found.
	 */
	private static function find_user_node( $arr, $username ) {
		if ( ! is_array( $arr ) ) {
			return null;
		}
		// Check if this node looks like a user object for our target
		if ( isset( $arr['username'] ) &&
			 strtolower( $arr['username'] ) === strtolower( $username ) &&
			 ( isset( $arr['biography'] ) || isset( $arr['full_name'] ) || isset( $arr['follower_count'] ) || isset( $arr['edge_followed_by'] ) ) ) {
			return $arr;
		}
		// Recurse into child arrays
		foreach ( $arr as $v ) {
			if ( is_array( $v ) ) {
				$found = self::find_user_node( $v, $username );
				if ( $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Helper to recursively search an array for target keys.
	 *
	 * @param array $arr
	 * @param array $keys
	 * @param array &$results
	 */
	private static function extract_nested_keys( $arr, $keys, &$results ) {
		if ( ! is_array( $arr ) ) {
			return;
		}
		foreach ( $arr as $k => $v ) {
			if ( in_array( $k, $keys, true ) && ! isset( $results[ $k ] ) ) {
				$results[ $k ] = $v;
			}
			if ( is_array( $v ) ) {
				self::extract_nested_keys( $v, $keys, $results );
			}
		}
	}

	/**
	 * Resilient meta tag content extractor.
	 * Handles any attribute ordering, both property and name attributes,
	 * single and double quote varieties, and decodes HTML entities.
	 *
	 * @param string $html
	 * @param string $name_or_property
	 * @return string
	 */
	public static function extract_meta_tag( $html, $name_or_property ) {
		$esc = preg_quote( $name_or_property, '/' );

		// Order 1: property|name before content
		if ( preg_match( '/<meta\s+[^>]*?(?:property|name)=["\']' . $esc . '["\'][^>]*?content=(["\'])(.*?)\1/is', $html, $m ) ) {
			return html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		// Order 2: content before property|name
		if ( preg_match( '/<meta\s+[^>]*?content=(["\'])(.*?)\1[^>]*?(?:property|name)=["\']' . $esc . '["\']/is', $html, $m ) ) {
			return html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		return '';
	}
}
