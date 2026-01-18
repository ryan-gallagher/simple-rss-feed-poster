# Simple RSS Feed Poster

A WordPress plugin that automatically creates daily or weekly digest posts from an RSS feed. Perfect for sharing bookmarked links, curated content, or any RSS-based link collection.

## Features

- **Flexible scheduling**: Choose specific days of the week and time to post (daily, weekdays only, weekly, etc.)
- **Alphabetical sorting** of links for easy scanning
- **Duplicate detection** to prevent reposting (tracks last 500 links)
- **Minimum items threshold**: Skip posting if not enough new links
- **Post header and footer**: Add custom intro/outro text to each digest
- **Flexible link formatting**:
  - Full link (Site Name: Article Title all linked)
  - Bold prefix (Site Name bold, Article Title linked)
  - Link only (just Article Title, no prefix)
- **Title cleanup tools**:
  - Full string replacements for complex site names with colons
  - Prefix replacements for simple site name swaps
  - Auto-strip suspicious prefixes (hostnames, URL fragments)
  - HTML entity decoding and whitespace normalization
- **Post as draft or publish** immediately
- **Live preview** in the admin panel
- **Manual posting** button for on-demand digest creation

## Installation

1. Download `simple-rss-feed-poster.php`
2. Create a folder called `simple-rss-feed-poster` in your `/wp-content/plugins/` directory
3. Upload the PHP file into that folder
4. Activate through the WordPress Plugins menu
5. Configure under **Settings → Simple RSS Poster**

Or zip the folder and upload via **Plugins → Add New → Upload Plugin**.

## Configuration

### Feed Settings

- **RSS Feed URL**: The feed to pull links from
- **Base Post Title**: Title prefix for posts (date is appended automatically)
- **Post Category**: Which category to assign posts to
- **Post Status**: Publish immediately or save as draft for review

### Schedule

- **Days to Post**: Select which days of the week to create digests. For a weekly digest, check only one day (e.g., Friday). Quick links for "Select All", "Select None", and "Weekdays Only".
- **Post Time**: What time to create the post (uses your WordPress timezone)
- **Minimum Items**: Skip posting if fewer than this many new items. Set to 0 to always post.

### Link Formatting

Choose how each link appears in your digest:

| Format | Output |
|--------|--------|
| Full link | [Site Name: Article Title](url) |
| Bold prefix | **Site Name**: [Article Title](url) |
| Link only | [Article Title](url) |

**Post Header**: Optional text that appears before the list of links (HTML allowed). Example: "Here are some interesting links I found today:"

**Post Footer**: Optional text that appears after the list of links (HTML allowed). Example: "Found something interesting? [Send it my way](mailto:you@example.com)!"

### Title Cleanup

#### Full String Replacements

For site names that contain colons, use full string matching. The plugin will find and replace the exact string anywhere in the title.

```
AFA: Animation For Adults : Animation News, Reviews, Articles, Podcasts and More => Animation for Adults
The Digital Bits - Bill Hunt's My Two Cents => The Digital Bits
```

#### Prefix Replacements

For simpler cases, replace just the prefix (text before the first `: `). Leave the right side empty to remove the prefix entirely.

```
rss.livelink.threads-in-node => Microsoft 365 Blog
Blog on 1Password Blog => 1Password
(No title) =>
kottke.org => Jason Kottke
```

#### Auto-Strip Suspicious Prefixes

Enable this option to automatically remove prefixes that look like hostnames or URL fragments (contain dots but no spaces), even without an explicit rule.

## Use Case: Feedbin → Pinboard → WordPress

This plugin was designed for a workflow where:

1. You star articles in [Feedbin](https://feedbin.com/)
2. An automation (IFTTT/Zapier) saves starred items to [Pinboard](https://pinboard.in/) with the format `Site Name: Article Title`
3. This plugin pulls from your Pinboard RSS feed and creates a daily/weekly digest post

The title cleanup features help normalize inconsistent feed names from various sources.

## WP-Cron Note

WordPress cron is "pseudo-cron" - it only runs when someone visits your site. For reliable scheduling on low-traffic sites, consider setting up a real system cron job:

```bash
*/15 * * * * curl -s https://yoursite.com/wp-cron.php?doing_wp_cron > /dev/null 2>&1
```

And disable WordPress's built-in pseudo-cron by adding to `wp-config.php`:

```php
define('DISABLE_WP_CRON', true);
```

## Changelog

### 2.3.0
- Added: Post days selection (choose specific days for weekly/custom schedules)
- Added: Minimum items threshold (skip posting if not enough new links)
- Added: Post header and footer text options

### 2.2.0
- Added: Full string replacements for complex site names with colons
- Added: Post status setting (publish or draft)
- Added: Link format setting (full link, bold prefix, or link only)
- Fixed: Scheduled time display now shows correct timezone

### 2.1.0
- Added: Title prefix replacement rules
- Added: Option to auto-strip suspicious prefixes
- Added: HTML entity decoding and whitespace normalization
- Added: Empty/malformed entries are now skipped

### 2.0.0
- Fixed: Cron now fires at configured time
- Fixed: Duplicate tracking uses link-only approach
- Fixed: Posted links array auto-prunes to prevent unbounded growth
- Fixed: AJAX preview verifies nonce for security
- Fixed: Feed errors log actual error message
- Added: Category validation with fallback
- Improved: Better error handling throughout

### 1.9.x
- Initial public features: scheduled posting, preview, category selection

## License

GPL v2 or later

## Author

Your Name
