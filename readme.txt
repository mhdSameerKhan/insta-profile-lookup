=== Instagram Public Profile Lookup V3 ===
Contributors: sameer
Donate link: https://github.com/sameer/insta-profile-lookup
Tags: instagram, profile, lookup, social media, scraper
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Look up public Instagram profile statistics, metadata, and recent posts without requiring user logins or full page reloads.

== Description ==

Instagram Public Profile Lookup allows WordPress site owners and visitors to look up public Instagram account statistics, profile details, and recent media items on-demand.

The plugin features a completely decoupled architecture:
1. **Core Data Engine**: High-performance transient caching, two-tier scraping pipeline (Instagram Web API with optional session ID, plus anonymous crawler fallback), and SSRF-hardened image proxying.
2. **Developer PHP API**: Programmatic functions (`insta_lookup_profile()`) that allow other plugins, themes, or custom frontends (e.g. React/Vue blocks) to consume the data without relying on the default shortcode.
3. **REST API**: Secure endpoints (`/wp-json/insta-lookup/v1/profile` and `/wp-json/insta-lookup/v1/image-proxy`) with transient-based IP rate limiting.
4. **Default Frontend Shortcode**: Interactive, accessible UI with skeleton loaders, instant error reporting, and responsive media grid.

== Installation ==

1. Upload the `insta-profile-lookup.zip` file via WordPress Admin (`Plugins > Add New > Upload Plugin`).
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. (Optional) Navigate to `Settings > Instagram Lookup` to configure cache duration or provide an optional Instagram session ID cookie.
4. Add the shortcode `[instagram_profile_lookup]` or `[insta_lookup]` to any page, post, or widget area.

== Shortcodes ==

* `[instagram_profile_lookup]` - Displays the profile search card and interactive results container.
* `[insta_lookup]` - Shorthand alias for the primary shortcode.

== REST API for Custom Frontends ==

The plugin provides a public, CORS-enabled REST API for external/custom frontends:

1. Look up profile by query param:
   `GET /wp-json/insta-lookup/v1/profile?username={handle}&refresh=1`

2. Look up profile by path param:
   `GET /wp-json/insta-lookup/v1/profile/{handle}`

3. Stream image via safe proxy:
   `GET /wp-json/insta-lookup/v1/image-proxy?url={encoded_image_url}`

Example JavaScript `fetch()`:
```javascript
async function getInstagramProfile(username) {
  const res = await fetch(`https://example.com/wp-json/insta-lookup/v1/profile/${encodeURIComponent(username)}`);
  const data = await res.json();
  if (!res.ok) {
    throw new Error(data.message || 'Lookup failed');
  }
  return data.data; // Structured profile object
}
```

== Developer Usage ==

To query Instagram profile data programmatically in your own theme or custom plugin:

`
$profile = insta_lookup_profile( 'natgeo' );

if ( ! is_wp_error( $profile ) ) {
    echo esc_html( $profile['full_name'] );
    echo esc_html( $profile['followers_count'] );
}
`

Available helper functions:
* `insta_lookup_profile( $username, $skip_cache = false )` - Retrieve profile data.
* `insta_lookup_is_cached( $username )` - Check if profile is cached in transients.
* `insta_lookup_clear_cache( $username = null )` - Purge a single handle or all profile cache.
* `insta_lookup_clean_username( $input )` - Sanitize username from handle or URL.

== Frequently Asked Questions ==

= Does this require an Instagram API token or Meta Developer App approval? =
No. The core engine queries publicly accessible endpoints and server-rendered metadata. An optional session ID can be supplied in Settings for higher volume stability.

= How does caching work? =
Profile lookups are cached as WordPress transients for a configurable duration (default: 1 hour). This prevents rate-limiting and ensures fast response times.

== Changelog ==

= 1.0.0 =
* Initial release with decoupled core scraper engine, REST API, developer functions, and frontend shortcode.
