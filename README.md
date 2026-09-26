# Instagram Public Profile Lookup (WordPress Plugin)

[![WordPress 5.8+](https://img.shields.io/badge/WordPress-5.8%2B-blue.svg)](https://wordpress.org)
[![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

A lightweight, performant WordPress plugin to search and display public Instagram profile statistics, metadata, and recent posts without requiring user logins or full page reloads.

Built with a **decoupled architecture**: the core scraper, caching layer, and REST API are completely independent of the frontend, allowing custom themes, Gutenberg blocks, or external web apps to consume the data easily.

---

## Features

- **No Instagram API / Meta App Approval Required**: Queries publicly accessible metadata and web endpoints.
- **Two-Tier Scraping Pipeline**:
  - *Strategy A (Authenticated Web API)*: Uses an optional session ID cookie for high-volume environments.
  - *Strategy B (Public HTML Scraper)*: Intelligent crawler fallback using OpenGraph and embedded Polaris JSON.
- **Fast Transient Caching**: Prevents Instagram rate-limiting with configurable TTL (default 1 hour) and versioned cache keys.
- **Decoupled REST API**:
  - `GET /wp-json/insta-lookup/v1/profile/{username}`
  - `GET /wp-json/insta-lookup/v1/profile?username={username}`
  - `GET /wp-json/insta-lookup/v1/image-proxy?url={cdn_url}`
- **SSRF-Hardened Image Proxy**: Bypasses Instagram CDN CORS/referrer blocks securely (HTTPS-only, host allowlist, DNS resolution checks against internal IP ranges).
- **Default Frontend Shortcode**: Responsive UI with skeleton shimmer loaders, error handling, and media grid.
- **Developer PHP API**: Helper functions (`insta_lookup_profile()`, `insta_lookup_clear_cache()`, etc.) and extensible WordPress filters/actions.

---

## Installation

1. Download [`insta-profile-lookup.zip`](https://github.com/mhdSameerKhan/insta-profile-lookup/raw/main/insta-profile-lookup.zip).
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**.
3. Choose the ZIP file and click **Install Now**.
4. Click **Activate Plugin**.

---

## How to Use

### 1. Shortcode (Default Frontend)
Add the shortcode to any WordPress page, post, or widget:
```text
[instagram_profile_lookup]
```
*(Or shorthand: `[insta_lookup]`)*

### 2. Admin Settings
Navigate to **Settings → Instagram Lookup** in WordPress Admin:
- **Cache Duration**: Select 15 minutes, 1 hour (recommended), 6 hours, or 24 hours.
- **Instagram Session ID (Optional)**: Enter an active session cookie to bypass public scraping limits. Stored values are encrypted at rest with AES-256. Alternatively, define `INSTA_SESSION_ID` in `wp-config.php`.
- **Clear Cache**: Flush all cached profile transients instantly.

---

## REST API Documentation

### Profile Lookup Endpoint
```http
GET /wp-json/insta-lookup/v1/profile/{username}
```
**Optional Query Parameters:**
- `refresh=1`: Bypass transient cache and force a fresh lookup.

### Example Response (`200 OK`)
```json
{
  "success": true,
  "data": {
    "username": "natgeo",
    "full_name": "National Geographic",
    "biography": "Inspiring people to care about the planet since 1888.",
    "external_url": "https://www.nationalgeographic.com",
    "profile_pic_url": "https://scontent...cdninstagram.com/...",
    "profile_pic_proxy": "https://example.com/wp-json/insta-lookup/v1/image-proxy?url=https%3A%2F%2Fscontent...",
    "followers_count": 283500000,
    "following_count": 142,
    "posts_count": 29810,
    "is_verified": true,
    "is_private": false,
    "media": [
      {
        "id": "3214567890123456789",
        "shortcode": "C_8xK1AbCde",
        "url": "https://www.instagram.com/p/C_8xK1AbCde/",
        "thumbnail": "https://scontent...cdninstagram.com/...",
        "thumbnail_proxy": "https://example.com/wp-json/insta-lookup/v1/image-proxy?url=https%3A%2F%2Fscontent...",
        "caption": "Photo by ...",
        "is_video": false
      }
    ],
    "retrieved_at": "2026-09-26 15:25:00",
    "cached": true
  }
}
```

### JavaScript `fetch()` Example for Custom Frontends
```javascript
async function getInstagramProfile(username) {
  const res = await fetch(`https://example.com/wp-json/insta-lookup/v1/profile/${encodeURIComponent(username)}`);
  const data = await res.json();
  if (!res.ok) {
    throw new Error(data.message || 'Lookup failed');
  }
  return data.data;
}
```

---

## PHP Developer API

Use core functions directly in theme templates or custom plugins:

```php
$profile = insta_lookup_profile( 'natgeo' );

if ( ! is_wp_error( $profile ) ) {
    echo esc_html( $profile['full_name'] );
    echo esc_html( $profile['followers_count'] );
}
```

Available functions:
- `insta_lookup_profile( $username, $skip_cache = false )`
- `insta_lookup_is_cached( $username )`
- `insta_lookup_clear_cache( $username = null )`
- `insta_lookup_clean_username( $input )`
- `insta_lookup_get_cache_ttl()`
- `insta_lookup_get_session_id()`

---

## License

GPL-2.0-or-later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html) for details.
