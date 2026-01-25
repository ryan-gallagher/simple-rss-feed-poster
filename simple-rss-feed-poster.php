<?php
/*
Plugin Name: Simple RSS Feed Poster
Description: Fetches and posts new links from a single RSS feed, sorted alphabetically, with scheduled posting and preview.
Version: 2.3.3
Author: Ryan Gallagher
*/

/*
Changelog:
2.3.3 - Fixed: Timeout setting now correctly applies to SimplePie feed fetcher (was using wrong hook)

2.3.2 - Fixed: Duplicate success message display on manual post
        Added: Auto-append timestamp to feed URL to prevent caching issues
        Added: Sorting now ignores leading "The", "A", "An" articles
        Added: Increased feed fetch timeout from 10 to 30 seconds
        Added: Retry logic (up to 3 attempts with 5 second delay between)
        Added: Activity log showing last 15 events (replaces single status line)

2.3.1 - Fixed: Reduced RSS feed cache duration from 12 hours to 30 minutes so new items appear faster

2.3.0 - Added: Post days selection (choose specific days for weekly/custom schedules)
        Added: Minimum items threshold (skip posting if not enough new links)
        Added: Post header and footer text options

2.2.0 - Added: Full string replacements for complex site names with colons
        Added: Post status setting (publish or draft)
        Added: Link format setting (full link, bold prefix, or link only)
        Fixed: Scheduled time display now shows correct timezone

2.1.0 - Added: Title prefix replacement rules
        Added: Option to auto-strip suspicious prefixes
        Added: HTML entity decoding and whitespace normalization
        Added: Empty/malformed entries are now skipped

2.0.0 - Major revision:
        - Fixed: Cron now fires at configured time
        - Fixed: Duplicate tracking uses link-only approach
        - Fixed: Posted links array auto-prunes to prevent unbounded growth
        - Fixed: AJAX preview verifies nonce for security
        - Fixed: Feed errors log actual error message
        - Added: Category validation with fallback
        - Improved: Better error handling throughout
        
1.9.5 - Added: "Next Scheduled Post" preview in admin. Fixed function order to prevent syntax errors.
1.9.4 - Fixed: Timezone issues in scheduled post titles.
1.9.0 - 1.9.3 - Refined duplicate tracking, added history reset, and category selection.
*/

//------------------------------------------------------------------------------
// 1. Constants & Configuration
//------------------------------------------------------------------------------

define('SIMPLE_RSS_POSTER_MAX_TRACKED_LINKS', 500);
define('SIMPLE_RSS_POSTER_FEED_ITEM_LIMIT', 100);
define('SIMPLE_RSS_POSTER_CRON_HOOK', 'simple_rss_poster_scheduled_post');
define('SIMPLE_RSS_POSTER_DAYS', ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']);
define('SIMPLE_RSS_POSTER_FEED_CACHE_DURATION', 1800); // 30 minutes in seconds
define('SIMPLE_RSS_POSTER_FEED_TIMEOUT', 30); // 30 seconds timeout
define('SIMPLE_RSS_POSTER_RETRY_ATTEMPTS', 3);
define('SIMPLE_RSS_POSTER_RETRY_DELAY', 5); // 5 seconds between retries
define('SIMPLE_RSS_POSTER_MAX_LOG_ENTRIES', 15);

//------------------------------------------------------------------------------
// 1b. Feed Fetching Helper
//------------------------------------------------------------------------------

/**
 * Fetch an RSS feed with reduced cache duration, extended timeout, and retry logic.
 * 
 * @param string $feed_url The URL of the feed to fetch
 * @return SimplePie|WP_Error SimplePie object on success, WP_Error on failure
 */
function simple_rss_poster_fetch_feed($feed_url) {
    include_once(ABSPATH . WPINC . '/feed.php');
    
    // Append timestamp to prevent caching at the feed source level
    $cache_buster = strpos($feed_url, '?') !== false ? '&' : '?';
    $cache_buster .= '_nocache=' . time();
    $feed_url_with_timestamp = $feed_url . $cache_buster;
    
    // Temporarily reduce cache duration
    $cache_filter = function($lifetime) {
        return SIMPLE_RSS_POSTER_FEED_CACHE_DURATION;
    };
    
    // Set timeout on SimplePie object directly
    $timeout_filter = function($feed) {
        $feed->set_timeout(SIMPLE_RSS_POSTER_FEED_TIMEOUT);
    };
    
    add_filter('wp_feed_cache_transient_lifetime', $cache_filter);
    add_action('wp_feed_options', $timeout_filter);
    
    $last_error = null;
    
    // Retry logic
    for ($attempt = 1; $attempt <= SIMPLE_RSS_POSTER_RETRY_ATTEMPTS; $attempt++) {
        $rss = fetch_feed($feed_url_with_timestamp);
        
        if (!is_wp_error($rss)) {
            // Success - clean up filters and return
            remove_filter('wp_feed_cache_transient_lifetime', $cache_filter);
            remove_action('wp_feed_options', $timeout_filter);
            return $rss;
        }
        
        $last_error = $rss;
        
        // If this isn't the last attempt, wait before retrying
        if ($attempt < SIMPLE_RSS_POSTER_RETRY_ATTEMPTS) {
            sleep(SIMPLE_RSS_POSTER_RETRY_DELAY);
            
            // Clear the feed cache before retry to ensure fresh fetch
            $cache_key = 'feed_' . md5($feed_url_with_timestamp);
            delete_transient($cache_key);
        }
    }
    
    // All attempts failed - clean up and return the last error
    remove_filter('wp_feed_cache_transient_lifetime', $cache_filter);
    remove_action('wp_feed_options', $timeout_filter);
    
    return $last_error;
}

//------------------------------------------------------------------------------
// 1c. Activity Log Helper
//------------------------------------------------------------------------------

/**
 * Add an entry to the activity log.
 * 
 * @param string $message The log message
 * @param string $type Type of entry: 'success', 'error', 'warning', 'info'
 */
function simple_rss_poster_add_log_entry($message, $type = 'info') {
    $log = get_option('simple_rss_poster_activity_log', []);
    
    // Add new entry at the beginning
    array_unshift($log, [
        'timestamp' => current_time('mysql'),
        'message'   => $message,
        'type'      => $type
    ]);
    
    // Keep only the last N entries
    $log = array_slice($log, 0, SIMPLE_RSS_POSTER_MAX_LOG_ENTRIES);
    
    update_option('simple_rss_poster_activity_log', $log);
    
    // Also update the simple status for backward compatibility
    update_option('simple_rss_poster_scheduled_post_status', '[' . wp_date('Y-m-d H:i:s') . '] ' . $message);
}

/**
 * Get the activity log.
 * 
 * @return array Array of log entries
 */
function simple_rss_poster_get_activity_log() {
    return get_option('simple_rss_poster_activity_log', []);
}

/**
 * Clear the activity log.
 */
function simple_rss_poster_clear_activity_log() {
    update_option('simple_rss_poster_activity_log', []);
}

//------------------------------------------------------------------------------
// 2. Sanitization & Registration
//------------------------------------------------------------------------------

/**
 * Sanitizes the time input to ensure it's in HH:MM format.
 */
function simple_rss_poster_sanitize_post_time($input) {
    $input = sanitize_text_field($input);
    if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $input)) {
        add_settings_error(
            'simple_rss_poster_post_time',
            'invalid_post_time',
            __('Invalid post time. Please use HH:MM format (e.g., 14:30).', 'simple-rss-poster'),
            'error'
        );
        return get_option('simple_rss_poster_post_time', '00:00');
    }
    return $input;
}

/**
 * Sanitizes category selection, ensuring it exists.
 */
function simple_rss_poster_sanitize_category($input) {
    $cat_id = absint($input);
    if ($cat_id > 0 && !term_exists($cat_id, 'category')) {
        add_settings_error(
            'simple_rss_poster_post_category',
            'invalid_category',
            __('Selected category no longer exists. Please choose another.', 'simple-rss-poster'),
            'error'
        );
        return 0;
    }
    return $cat_id;
}

/**
 * Sanitizes textarea inputs (replacements, header, footer).
 */
function simple_rss_poster_sanitize_replacements($input) {
    $lines = explode("\n", $input);
    $clean_lines = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (!empty($line)) {
            $clean_lines[] = sanitize_text_field($line);
        }
    }
    
    return implode("\n", $clean_lines);
}

/**
 * Sanitizes post status selection.
 */
function simple_rss_poster_sanitize_post_status($input) {
    $allowed = ['publish', 'draft'];
    return in_array($input, $allowed, true) ? $input : 'publish';
}

/**
 * Sanitizes link format selection.
 */
function simple_rss_poster_sanitize_link_format($input) {
    $allowed = ['full_link', 'bold_prefix', 'link_only'];
    return in_array($input, $allowed, true) ? $input : 'full_link';
}

/**
 * Sanitizes post days array.
 */
function simple_rss_poster_sanitize_post_days($input) {
    if (!is_array($input)) {
        return array_fill_keys(SIMPLE_RSS_POSTER_DAYS, 1); // Default to all days
    }
    
    $clean = [];
    foreach (SIMPLE_RSS_POSTER_DAYS as $day) {
        $clean[$day] = isset($input[$day]) ? 1 : 0;
    }
    return $clean;
}

/**
 * Sanitizes minimum items (must be 0 or positive integer).
 */
function simple_rss_poster_sanitize_min_items($input) {
    $value = absint($input);
    return $value;
}

function simple_rss_poster_register_settings() {
    register_setting('simple_rss_poster_settings_group', 'simple_rss_poster_feed_url', [
        'sanitize_callback' => 'esc_url_raw'
    ]);
    register_setting('simple_rss_poster_settings_group', 'simple_rss_poster_post_title', [
        'sanitize_callback' => 'sanitize_text_field',
        'default' => 'Latest Links'
    ]);
    register_setting('simple_rss_poster_settings_group', 'simple_rss_poster_post_time', [
        'sanitize_callback' => 'simple_rss_poster_sanitize_post_time',
        'default' => '00:00'
    ]);
    register_setting('simple_rss_poster_settings_group', 'simple_rss_poster_post_days', [
        'sanitize_callback' => 'simple_rss_poster_sanitize_post_days',
        'default' => array_fill_keys(SIMPLE_RSS_POSTER_DAYS, 1)
    ]);
    register_setting('simple_rss_poster_settings_group', 'simple_rss_poster_post_category', [
        'sanitize_callback' => 'simple_rss_poster_sanitize_category',
        'default' => 0
    ]);
    register_setting('simple_rss_poster_settings_group', 'simple_rss_poster_post_status', [
        'sanitize_callback' => 'simple_rss_poster_sanitize_post_status',
        'default' => 'publish'
    ]);
    register_setting('simple_rss_poster_settings_group', 'simple_rss_poster_link_format', [
        'sanitize_callback' => 'simple_rss_poster_sanitize_link_format',
        'default' => 'full_link'
    ]);
    register_setting('simple_rss_poster_settings_group', 'simple_rss_poster_min_items', [
        'sanitize_callback' => 'simple_rss_poster_sanitize_min_items',
        'default' => 1
    ]);
    register_setting('simple_rss_poster_settings_group', 'simple_rss_poster_post_header', [
        'sanitize_callback' => 'wp_kses_post',
        'default' => ''
    ]);
    register_setting('simple_rss_poster_settings_group', 'simple_rss_poster_post_footer', [
        'sanitize_callback' => 'wp_kses_post',
        'default' => ''
    ]);
    register_setting('simple_rss_poster_settings_group', 'simple_rss_poster_full_replacements', [
        'sanitize_callback' => 'simple_rss_poster_sanitize_replacements',
        'default' => ''
    ]);
    register_setting('simple_rss_poster_settings_group', 'simple_rss_poster_title_replacements', [
        'sanitize_callback' => 'simple_rss_poster_sanitize_replacements',
        'default' => ''
    ]);
    register_setting('simple_rss_poster_settings_group', 'simple_rss_poster_auto_strip_suspicious', [
        'sanitize_callback' => 'rest_sanitize_boolean',
        'default' => false
    ]);
}
add_action('admin_init', 'simple_rss_poster_register_settings');

/**
 * Reschedule cron when post time or post days settings change.
 */
function simple_rss_poster_maybe_reschedule($old_value, $new_value) {
    if ($old_value !== $new_value) {
        simple_rss_poster_schedule_next_post();
    }
}
add_action('update_option_simple_rss_poster_post_time', 'simple_rss_poster_maybe_reschedule', 10, 2);
add_action('update_option_simple_rss_poster_post_days', 'simple_rss_poster_maybe_reschedule', 10, 2);

//------------------------------------------------------------------------------
// 3. Admin UI
//------------------------------------------------------------------------------

function simple_rss_poster_add_admin_menu() {
    add_options_page(
        'Simple RSS Poster Settings',
        'Simple RSS Poster',
        'manage_options',
        'simple-rss-poster-settings',
        'simple_rss_poster_settings_page'
    );
}
add_action('admin_menu', 'simple_rss_poster_add_admin_menu');

/**
 * Enqueue admin scripts only on our settings page.
 */
function simple_rss_poster_admin_scripts($hook) {
    if ($hook !== 'settings_page_simple-rss-poster-settings') {
        return;
    }
    wp_enqueue_script('jquery');
}
add_action('admin_enqueue_scripts', 'simple_rss_poster_admin_scripts');

function simple_rss_poster_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // Load current options
    $feed_url            = get_option('simple_rss_poster_feed_url', '');
    $post_title          = get_option('simple_rss_poster_post_title', 'Latest Links');
    $post_time           = get_option('simple_rss_poster_post_time', '00:00');
    $post_days           = get_option('simple_rss_poster_post_days', array_fill_keys(SIMPLE_RSS_POSTER_DAYS, 1));
    $post_category       = get_option('simple_rss_poster_post_category', 0);
    $post_status         = get_option('simple_rss_poster_post_status', 'publish');
    $link_format         = get_option('simple_rss_poster_link_format', 'full_link');
    $min_items           = get_option('simple_rss_poster_min_items', 1);
    $post_header         = get_option('simple_rss_poster_post_header', '');
    $post_footer         = get_option('simple_rss_poster_post_footer', '');
    $full_replacements   = get_option('simple_rss_poster_full_replacements', '');
    $title_replacements  = get_option('simple_rss_poster_title_replacements', '');
    $auto_strip          = get_option('simple_rss_poster_auto_strip_suspicious', false);
    $categories          = get_categories(['hide_empty' => false]);
    $tracked_count       = count(get_option('simple_rss_poster_posted_links', []));
    $activity_log        = simple_rss_poster_get_activity_log();

    // Get next scheduled run from WP-Cron (fixed timezone display)
    $next_scheduled = wp_next_scheduled(SIMPLE_RSS_POSTER_CRON_HOOK);
    $next_run = $next_scheduled 
        ? wp_date('l, F j, Y @ g:i a', $next_scheduled) 
        : 'Not scheduled - save settings to schedule';

    // Create nonce for AJAX
    $ajax_nonce = wp_create_nonce('simple_rss_poster_ajax_nonce');
    ?>

    <div class="wrap">
        <h1>Simple RSS Feed Poster</h1>
        
        <?php settings_errors('simple_rss_poster_messages'); ?>
        
        <form method="post" action="options.php">
            <?php settings_fields('simple_rss_poster_settings_group'); ?>
            
            <h2>Feed Settings</h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="simple_rss_poster_feed_url">RSS Feed URL</label></th>
                    <td>
                        <input type="url" 
                               id="simple_rss_poster_feed_url"
                               name="simple_rss_poster_feed_url" 
                               value="<?php echo esc_attr($feed_url); ?>" 
                               class="regular-text"
                               placeholder="https://example.com/feed/">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="simple_rss_poster_post_title">Base Post Title</label></th>
                    <td>
                        <input type="text" 
                               id="simple_rss_poster_post_title"
                               name="simple_rss_poster_post_title" 
                               value="<?php echo esc_attr($post_title); ?>" 
                               class="regular-text">
                        <p class="description">Date will be appended automatically (e.g., "Latest Links - January 17, 2026")</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="simple_rss_poster_post_category">Post Category</label></th>
                    <td>
                        <select id="simple_rss_poster_post_category" name="simple_rss_poster_post_category">
                            <option value="0" <?php selected($post_category, 0); ?>>— Uncategorized —</option>
                            <?php foreach ($categories as $cat) : ?>
                                <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected($post_category, $cat->term_id); ?>>
                                    <?php echo esc_html($cat->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="simple_rss_poster_post_status">Post Status</label></th>
                    <td>
                        <select id="simple_rss_poster_post_status" name="simple_rss_poster_post_status">
                            <option value="publish" <?php selected($post_status, 'publish'); ?>>Publish</option>
                            <option value="draft" <?php selected($post_status, 'draft'); ?>>Draft</option>
                        </select>
                        <p class="description">Draft lets you review posts before publishing.</p>
                    </td>
                </tr>
            </table>
            
            <h2>Schedule</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Days to Post</th>
                    <td>
                        <fieldset>
                            <?php foreach (SIMPLE_RSS_POSTER_DAYS as $day) : ?>
                                <label style="display: inline-block; margin-right: 15px;">
                                    <input type="checkbox" 
                                           name="simple_rss_poster_post_days[<?php echo $day; ?>]" 
                                           value="1"
                                           <?php checked(!empty($post_days[$day]), true); ?>>
                                    <?php echo $day; ?>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <p class="description">
                            Select which days to create digest posts. For a weekly digest, check only one day.<br>
                            <a href="#" id="select-all-days">Select All</a> | <a href="#" id="select-none-days">Select None</a> | <a href="#" id="select-weekdays">Weekdays Only</a>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="simple_rss_poster_post_time">Post Time</label></th>
                    <td>
                        <input type="time" 
                               id="simple_rss_poster_post_time"
                               name="simple_rss_poster_post_time" 
                               value="<?php echo esc_attr($post_time); ?>">
                        <p class="description">Time in your WordPress timezone (<?php echo esc_html(wp_timezone_string()); ?>)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="simple_rss_poster_min_items">Minimum Items</label></th>
                    <td>
                        <input type="number" 
                               id="simple_rss_poster_min_items"
                               name="simple_rss_poster_min_items" 
                               value="<?php echo esc_attr($min_items); ?>"
                               min="0"
                               max="50"
                               style="width: 70px;">
                        <p class="description">Skip posting if fewer than this many new items. Set to 0 to always post.</p>
                    </td>
                </tr>
            </table>
            
            <h2>Link Formatting</h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="simple_rss_poster_link_format">Link Format</label></th>
                    <td>
                        <select id="simple_rss_poster_link_format" name="simple_rss_poster_link_format">
                            <option value="full_link" <?php selected($link_format, 'full_link'); ?>>Full link (Site Name: Article Title all linked)</option>
                            <option value="bold_prefix" <?php selected($link_format, 'bold_prefix'); ?>>Bold prefix (Site Name bold, Article Title linked)</option>
                            <option value="link_only" <?php selected($link_format, 'link_only'); ?>>Link only (just Article Title linked)</option>
                        </select>
                        <p class="description">How each link item should be formatted in the post.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="simple_rss_poster_post_header">Post Header</label></th>
                    <td>
                        <input type="text" 
                               id="simple_rss_poster_post_header"
                               name="simple_rss_poster_post_header" 
                               value="<?php echo esc_attr($post_header); ?>" 
                               class="large-text">
                        <p class="description">Optional text to appear before the list of links. HTML allowed.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="simple_rss_poster_post_footer">Post Footer</label></th>
                    <td>
                        <input type="text" 
                               id="simple_rss_poster_post_footer"
                               name="simple_rss_poster_post_footer" 
                               value="<?php echo esc_attr($post_footer); ?>" 
                               class="large-text">
                        <p class="description">Optional text to appear after the list of links. HTML allowed.</p>
                    </td>
                </tr>
            </table>
            
            <h2>Title Cleanup</h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="simple_rss_poster_full_replacements">Full String Replacements</label></th>
                    <td>
                        <textarea id="simple_rss_poster_full_replacements"
                                  name="simple_rss_poster_full_replacements"
                                  rows="4"
                                  class="large-text code"><?php echo esc_textarea($full_replacements); ?></textarea>
                        <p class="description">
                            One rule per line. Format: <code>Full String to Find => Replacement</code><br>
                            Use this for site names that contain colons. Processed before prefix replacements.<br>
                            Example:<br>
                            <code>AFA: Animation For Adults : Animation News, Reviews, Articles, Podcasts and More => Animation for Adults</code>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="simple_rss_poster_title_replacements">Prefix Replacements</label></th>
                    <td>
                        <textarea id="simple_rss_poster_title_replacements"
                                  name="simple_rss_poster_title_replacements"
                                  rows="6"
                                  class="large-text code"><?php echo esc_textarea($title_replacements); ?></textarea>
                        <p class="description">
                            One rule per line. Format: <code>Old Prefix => New Prefix</code><br>
                            Matches the prefix (text before the first colon). Leave the right side empty to remove the prefix entirely.<br>
                            Examples:<br>
                            <code>rss.livelink.threads-in-node => Microsoft 365 Blog</code><br>
                            <code>(No title) =></code>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Auto-Strip Suspicious Prefixes</th>
                    <td>
                        <label for="simple_rss_poster_auto_strip_suspicious">
                            <input type="checkbox" 
                                   id="simple_rss_poster_auto_strip_suspicious"
                                   name="simple_rss_poster_auto_strip_suspicious"
                                   value="1"
                                   <?php checked($auto_strip, true); ?>>
                            Automatically remove prefixes that look like hostnames or URL fragments
                        </label>
                        <p class="description">
                            When enabled, prefixes containing dots but no spaces (like "rss.livelink.threads-in-node") 
                            will be stripped automatically, even without an explicit replacement rule.
                        </p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button('Save Settings'); ?>
        </form>

        <hr>

        <h2>Status</h2>
        <table class="widefat" style="max-width: 600px;">
            <tr>
                <th style="width: 200px;">Next Scheduled Run</th>
                <td><?php echo esc_html($next_run); ?></td>
            </tr>
            <tr>
                <th>Tracked Links</th>
                <td><?php echo esc_html($tracked_count); ?> / <?php echo SIMPLE_RSS_POSTER_MAX_TRACKED_LINKS; ?></td>
            </tr>
        </table>

        <h3>Activity Log</h3>
        <div style="max-width: 800px; max-height: 300px; overflow-y: auto; border: 1px solid #ccd0d4; background: #fff;">
            <?php if (empty($activity_log)) : ?>
                <p style="padding: 10px; margin: 0; color: #666;"><em>No activity logged yet.</em></p>
            <?php else : ?>
                <table class="widefat striped" style="border: none;">
                    <tbody>
                        <?php foreach ($activity_log as $entry) : 
                            $type_colors = [
                                'success' => '#00a32a',
                                'error'   => '#d63638',
                                'warning' => '#dba617',
                                'info'    => '#2271b1'
                            ];
                            $color = isset($type_colors[$entry['type']]) ? $type_colors[$entry['type']] : $type_colors['info'];
                        ?>
                            <tr>
                                <td style="width: 160px; white-space: nowrap; color: #666;">
                                    <?php echo esc_html($entry['timestamp']); ?>
                                </td>
                                <td style="border-left: 3px solid <?php echo esc_attr($color); ?>; padding-left: 10px;">
                                    <?php echo esc_html($entry['message']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <p>
            <form method="post" style="display: inline;">
                <?php wp_nonce_field('simple_rss_poster_clear_log', 'clear_log_nonce'); ?>
                <input type="submit" 
                       name="simple_rss_poster_clear_log" 
                       class="button button-link" 
                       value="Clear Log"
                       onclick="return confirm('Clear all activity log entries?');"
                       style="color: #b32d2e; text-decoration: none;">
            </form>
        </p>

        <hr>

        <h2>Post Preview</h2>
        <p class="description">Shows what the next post would contain based on current settings and unposted feed items.</p>
        <div id="rss-post-preview" style="border: 1px solid #ccd0d4; padding: 15px; background: #fff; max-width: 800px; margin-top: 10px;">
            <em>Loading preview...</em>
        </div>

        <hr>

        <h2>Manual Actions</h2>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <form method="post">
                <?php wp_nonce_field('simple_rss_poster_manual_post', 'manual_post_nonce'); ?>
                <input type="submit" 
                       name="simple_rss_poster_create_post" 
                       class="button button-primary" 
                       value="Create Post Now"
                       <?php disabled(empty($feed_url)); ?>>
            </form>

            <form method="post">
                <?php wp_nonce_field('simple_rss_poster_clear_cache', 'clear_cache_nonce'); ?>
                <input type="submit" 
                       name="simple_rss_poster_clear_cache" 
                       class="button" 
                       value="Clear Feed Cache"
                       <?php disabled(empty($feed_url)); ?>>
            </form>

            <form method="post">
                <?php wp_nonce_field('simple_rss_poster_reset_history', 'reset_history_nonce'); ?>
                <input type="submit" 
                       name="simple_rss_poster_reset" 
                       class="button" 
                       value="Reset Posting History" 
                       onclick="return confirm('Reset all tracking history? This may result in duplicate posts if items from the feed have already been posted.');">
            </form>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        var ajaxNonce = '<?php echo esc_js($ajax_nonce); ?>';
        var debounceTimer;
        
        // Day selection helpers
        $('#select-all-days').on('click', function(e) {
            e.preventDefault();
            $('input[name^="simple_rss_poster_post_days"]').prop('checked', true);
        });
        
        $('#select-none-days').on('click', function(e) {
            e.preventDefault();
            $('input[name^="simple_rss_poster_post_days"]').prop('checked', false);
        });
        
        $('#select-weekdays').on('click', function(e) {
            e.preventDefault();
            $('input[name^="simple_rss_poster_post_days"]').prop('checked', false);
            $('input[name="simple_rss_poster_post_days[Monday]"]').prop('checked', true);
            $('input[name="simple_rss_poster_post_days[Tuesday]"]').prop('checked', true);
            $('input[name="simple_rss_poster_post_days[Wednesday]"]').prop('checked', true);
            $('input[name="simple_rss_poster_post_days[Thursday]"]').prop('checked', true);
            $('input[name="simple_rss_poster_post_days[Friday]"]').prop('checked', true);
        });
        
        function getPreview() {
            var feedUrl = $('#simple_rss_poster_feed_url').val();
            var postTitle = $('#simple_rss_poster_post_title').val();
            var linkFormat = $('#simple_rss_poster_link_format').val();
            var postHeader = $('#simple_rss_poster_post_header').val();
            var postFooter = $('#simple_rss_poster_post_footer').val();
            var minItems = $('#simple_rss_poster_min_items').val();
            var fullReplacements = $('#simple_rss_poster_full_replacements').val();
            var titleReplacements = $('#simple_rss_poster_title_replacements').val();
            var autoStrip = $('#simple_rss_poster_auto_strip_suspicious').is(':checked') ? 1 : 0;
            
            if (!feedUrl) {
                $('#rss-post-preview').html('<em>Enter a feed URL to see preview.</em>');
                return;
            }
            
            $('#rss-post-preview').html('<em>Loading preview...</em>');
            
            $.post(ajaxurl, {
                action: 'simple_rss_poster_preview',
                nonce: ajaxNonce,
                feed_url: feedUrl,
                post_title: postTitle,
                link_format: linkFormat,
                post_header: postHeader,
                post_footer: postFooter,
                min_items: minItems,
                full_replacements: fullReplacements,
                title_replacements: titleReplacements,
                auto_strip: autoStrip
            }, function(response) {
                if (response.success) {
                    $('#rss-post-preview').html(response.data);
                } else {
                    $('#rss-post-preview').html('<span style="color: #d63638;">' + response.data + '</span>');
                }
            }).fail(function() {
                $('#rss-post-preview').html('<span style="color: #d63638;">Failed to load preview. Please try again.</span>');
            });
        }
        
        // Debounced preview on input change
        $('#simple_rss_poster_feed_url, #simple_rss_poster_post_title, #simple_rss_poster_post_header, #simple_rss_poster_post_footer, #simple_rss_poster_full_replacements, #simple_rss_poster_title_replacements, #simple_rss_poster_min_items').on('input change', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(getPreview, 500);
        });
        
        // Immediate preview on select/checkbox change
        $('#simple_rss_poster_link_format, #simple_rss_poster_auto_strip_suspicious').on('change', function() {
            getPreview();
        });
        
        // Initial preview load
        getPreview();
    });
    </script>
    <?php
}

//------------------------------------------------------------------------------
// 4. Title Processing
//------------------------------------------------------------------------------

/**
 * Parse replacement rules from textarea into an array.
 */
function simple_rss_poster_parse_replacements($replacements_text) {
    $rules = [];
    
    if (empty($replacements_text)) {
        return $rules;
    }
    
    $lines = explode("\n", $replacements_text);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) {
            continue;
        }
        
        $parts = explode('=>', $line, 2);
        if (count($parts) >= 1) {
            $old_value = trim($parts[0]);
            $new_value = isset($parts[1]) ? trim($parts[1]) : '';
            
            if (!empty($old_value)) {
                $rules[$old_value] = $new_value;
            }
        }
    }
    
    return $rules;
}

/**
 * Check if a prefix looks suspicious (like a hostname or URL fragment).
 */
function simple_rss_poster_is_suspicious_prefix($prefix) {
    if (strpos($prefix, '.') !== false && strpos($prefix, ' ') === false) {
        return true;
    }
    
    if (preg_match('/^[a-z0-9._-]+$/i', $prefix) && strpos($prefix, '.') !== false) {
        return true;
    }
    
    return false;
}

/**
 * Clean and process a single title.
 */
function simple_rss_poster_clean_title($raw_title, $full_replacements = [], $prefix_replacements = [], $auto_strip = false) {
    // Step 1: Decode HTML entities
    $title = html_entity_decode($raw_title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Step 2: Normalize whitespace
    $title = preg_replace('/[\s\x{00A0}]+/u', ' ', $title);
    $title = trim($title);
    
    if (empty($title)) {
        return null;
    }
    
    // Step 3: Apply full string replacements first
    foreach ($full_replacements as $find => $replace) {
        if (strpos($title, $find) !== false) {
            $title = str_replace($find, $replace, $title);
            $title = trim($title);
            $title = preg_replace('/^:\s*/', '', $title);
            $title = trim($title);
        }
    }
    
    if (empty($title)) {
        return null;
    }
    
    // Step 4: Split on first ": " to get prefix and article title
    $separator_pos = strpos($title, ': ');
    $prefix = '';
    $article_title = $title;
    
    if ($separator_pos !== false) {
        $prefix = substr($title, 0, $separator_pos);
        $article_title = substr($title, $separator_pos + 2);
        
        // Step 5: Check for prefix replacement rule
        if (isset($prefix_replacements[$prefix])) {
            $new_prefix = $prefix_replacements[$prefix];
            
            if (empty($new_prefix)) {
                $prefix = '';
            } else {
                $prefix = $new_prefix;
            }
        }
        // Step 6: Check for suspicious prefix
        elseif ($auto_strip && simple_rss_poster_is_suspicious_prefix($prefix)) {
            $prefix = '';
        }
    }
    
    $article_title = trim($article_title);
    $prefix = trim($prefix);
    
    if (empty($article_title)) {
        return null;
    }
    
    return [
        'prefix' => $prefix,
        'title'  => $article_title
    ];
}

/**
 * Format a link item based on the selected format.
 */
function simple_rss_poster_format_link($prefix, $title, $link, $format = 'full_link') {
    $escaped_title = esc_html($title);
    $escaped_prefix = esc_html($prefix);
    $escaped_link = esc_url($link);
    
    switch ($format) {
        case 'bold_prefix':
            if (!empty($prefix)) {
                return sprintf(
                    '<strong>%s</strong>: <a href="%s">%s</a>',
                    $escaped_prefix,
                    $escaped_link,
                    $escaped_title
                );
            } else {
                return sprintf(
                    '<a href="%s">%s</a>',
                    $escaped_link,
                    $escaped_title
                );
            }
            
        case 'link_only':
            return sprintf(
                '<a href="%s">%s</a>',
                $escaped_link,
                $escaped_title
            );
            
        case 'full_link':
        default:
            if (!empty($prefix)) {
                return sprintf(
                    '<a href="%s">%s: %s</a>',
                    $escaped_link,
                    $escaped_prefix,
                    $escaped_title
                );
            } else {
                return sprintf(
                    '<a href="%s">%s</a>',
                    $escaped_link,
                    $escaped_title
                );
            }
    }
}

/**
 * Strip leading articles (The, A, An) for sorting purposes.
 */
function simple_rss_poster_get_sort_key($prefix, $title) {
    $sort_key = !empty($prefix) ? $prefix . ': ' . $title : $title;
    // Remove leading "The ", "A ", "An " (case-insensitive)
    $sort_key = preg_replace('/^(The|A|An)\s+/i', '', $sort_key);
    return $sort_key;
}

//------------------------------------------------------------------------------
// 5. Feed Processing Logic
//------------------------------------------------------------------------------

/**
 * Process feed items and return new (unposted) items.
 */
function simple_rss_poster_process_feed_items($rss_items, $full_replacements = [], $prefix_replacements = [], $auto_strip = false) {
    $posted_links = get_option('simple_rss_poster_posted_links', []);
    
    $new_items = [];
    $new_links = [];

    foreach ($rss_items as $item) {
        $link = esc_url($item->get_permalink());
        
        if (in_array($link, $posted_links, true)) {
            continue;
        }

        $cleaned = simple_rss_poster_clean_title(
            $item->get_title(),
            $full_replacements,
            $prefix_replacements,
            $auto_strip
        );
        
        if ($cleaned === null) {
            continue;
        }

        $new_items[] = [
            'prefix' => $cleaned['prefix'],
            'title'  => $cleaned['title'],
            'link'   => $link
        ];
        $new_links[] = $link;
    }

    // Sort alphabetically, ignoring leading articles (The, A, An)
    usort($new_items, function($a, $b) {
        $a_sort = simple_rss_poster_get_sort_key($a['prefix'], $a['title']);
        $b_sort = simple_rss_poster_get_sort_key($b['prefix'], $b['title']);
        return strcasecmp($a_sort, $b_sort);
    });

    return [
        'items' => $new_items,
        'links' => $new_links
    ];
}

/**
 * Prune the posted links array to prevent unbounded growth.
 */
function simple_rss_poster_prune_links($links, $new_links) {
    $combined = array_merge($links, $new_links);
    $combined = array_unique($combined);
    
    if (count($combined) > SIMPLE_RSS_POSTER_MAX_TRACKED_LINKS) {
        $combined = array_slice($combined, -SIMPLE_RSS_POSTER_MAX_TRACKED_LINKS);
    }
    
    return array_values($combined);
}

//------------------------------------------------------------------------------
// 6. Handlers (Manual, Scheduled, AJAX)
//------------------------------------------------------------------------------

/**
 * AJAX Preview Handler
 */
function simple_rss_poster_ajax_preview() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'simple_rss_poster_ajax_nonce')) {
        wp_send_json_error('Security check failed. Please refresh the page and try again.');
        return;
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied.');
        return;
    }
    
    $feed_url = isset($_POST['feed_url']) ? esc_url_raw($_POST['feed_url']) : '';
    $post_title = isset($_POST['post_title']) ? sanitize_text_field($_POST['post_title']) : 'Latest Links';
    $link_format = isset($_POST['link_format']) ? sanitize_text_field($_POST['link_format']) : 'full_link';
    $post_header = isset($_POST['post_header']) ? wp_kses_post($_POST['post_header']) : '';
    $post_footer = isset($_POST['post_footer']) ? wp_kses_post($_POST['post_footer']) : '';
    $min_items = isset($_POST['min_items']) ? absint($_POST['min_items']) : 1;
    $full_replacements_raw = isset($_POST['full_replacements']) ? sanitize_textarea_field($_POST['full_replacements']) : '';
    $title_replacements_raw = isset($_POST['title_replacements']) ? sanitize_textarea_field($_POST['title_replacements']) : '';
    $auto_strip = isset($_POST['auto_strip']) && $_POST['auto_strip'] === '1';
    
    if (empty($feed_url)) {
        wp_send_json_error('Please enter a feed URL.');
        return;
    }

    $full_replacements = simple_rss_poster_parse_replacements($full_replacements_raw);
    $prefix_replacements = simple_rss_poster_parse_replacements($title_replacements_raw);

    $rss = simple_rss_poster_fetch_feed($feed_url);
    if (is_wp_error($rss)) {
        wp_send_json_error('Error fetching feed: ' . esc_html($rss->get_error_message()));
        return;
    }

    $data = simple_rss_poster_process_feed_items(
        $rss->get_items(0, SIMPLE_RSS_POSTER_FEED_ITEM_LIMIT),
        $full_replacements,
        $prefix_replacements,
        $auto_strip
    );
    
    $item_count = count($data['items']);
    
    if ($item_count === 0) {
        wp_send_json_success('<p><em>No new items to post. All feed items have already been posted.</em></p>');
        return;
    }
    
    // Check minimum items threshold
    if ($item_count < $min_items) {
        wp_send_json_success(sprintf(
            '<p><em>Only %d new item(s) found, but minimum is set to %d. Post would be skipped.</em></p>',
            $item_count,
            $min_items
        ));
        return;
    }
    
    $output = '<h3 style="margin-top: 0;">' . esc_html($post_title) . ' - ' . wp_date('F j, Y') . '</h3>';
    
    if (!empty($post_header)) {
        $output .= '<p>' . wp_kses_post($post_header) . '</p>';
    }
    
    $output .= '<p><strong>' . $item_count . ' new item(s) ready to post:</strong></p>';
    $output .= '<ul style="margin-left: 20px;">';
    
    foreach ($data['items'] as $item) {
        $output .= '<li>' . simple_rss_poster_format_link(
            $item['prefix'],
            $item['title'],
            $item['link'],
            $link_format
        ) . '</li>';
    }
    
    $output .= '</ul>';
    
    if (!empty($post_footer)) {
        $output .= '<p>' . wp_kses_post($post_footer) . '</p>';
    }
    
    wp_send_json_success($output);
}
add_action('wp_ajax_simple_rss_poster_preview', 'simple_rss_poster_ajax_preview');

/**
 * Manual Post Handler
 */
function simple_rss_poster_handle_manual_post() {
    if (!isset($_POST['simple_rss_poster_create_post'])) {
        return;
    }
    
    if (!isset($_POST['manual_post_nonce']) || !wp_verify_nonce($_POST['manual_post_nonce'], 'simple_rss_poster_manual_post')) {
        wp_die('Security check failed.');
    }
    
    if (!current_user_can('manage_options')) {
        wp_die('Permission denied.');
    }
    
    $result = simple_rss_poster_execute_post('manual');
    
    if (is_wp_error($result)) {
        add_settings_error(
            'simple_rss_poster_messages',
            'post_error',
            'Error creating post: ' . $result->get_error_message(),
            'error'
        );
    } elseif ($result === false) {
        add_settings_error(
            'simple_rss_poster_messages',
            'no_items',
            'No new items to post (or below minimum threshold).',
            'warning'
        );
    } else {
        $post_status = get_option('simple_rss_poster_post_status', 'publish');
        $status_label = ($post_status === 'draft') ? 'Draft created' : 'Post published';
        add_settings_error(
            'simple_rss_poster_messages',
            'post_success',
            $status_label . ' successfully!',
            'success'
        );
    }
    
    // Redirect without settings-updated=true to avoid duplicate messages from options.php
    wp_redirect(admin_url('options-general.php?page=simple-rss-poster-settings'));
    exit;
}
add_action('admin_init', 'simple_rss_poster_handle_manual_post');

/**
 * Reset History Handler
 */
function simple_rss_poster_handle_reset() {
    if (!isset($_POST['simple_rss_poster_reset'])) {
        return;
    }
    
    if (!isset($_POST['reset_history_nonce']) || !wp_verify_nonce($_POST['reset_history_nonce'], 'simple_rss_poster_reset_history')) {
        wp_die('Security check failed.');
    }
    
    if (!current_user_can('manage_options')) {
        wp_die('Permission denied.');
    }
    
    delete_option('simple_rss_poster_posted_links');
    simple_rss_poster_add_log_entry('Posting history reset by user', 'info');
    
    add_settings_error(
        'simple_rss_poster_messages',
        'reset_success',
        'Posting history has been reset.',
        'success'
    );
    
    wp_redirect(admin_url('options-general.php?page=simple-rss-poster-settings'));
    exit;
}
add_action('admin_init', 'simple_rss_poster_handle_reset');

/**
 * Clear Feed Cache Handler
 */
function simple_rss_poster_handle_clear_cache() {
    if (!isset($_POST['simple_rss_poster_clear_cache'])) {
        return;
    }
    
    if (!isset($_POST['clear_cache_nonce']) || !wp_verify_nonce($_POST['clear_cache_nonce'], 'simple_rss_poster_clear_cache')) {
        wp_die('Security check failed.');
    }
    
    if (!current_user_can('manage_options')) {
        wp_die('Permission denied.');
    }
    
    // Get the feed URL and clear its cache
    $feed_url = get_option('simple_rss_poster_feed_url', '');
    if (!empty($feed_url)) {
        // WordPress stores feed caches as transients with a hash of the URL
        $cache_key = 'feed_' . md5($feed_url);
        delete_transient($cache_key);
        
        // Also try the SimplePie cache key format
        $cache_key_sp = 'feed_mod_' . md5($feed_url);
        delete_transient($cache_key_sp);
        
        // Clear any other potential feed transients
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient%_feed_' . md5($feed_url) . '%'
            )
        );
    }
    
    add_settings_error(
        'simple_rss_poster_messages',
        'cache_cleared',
        'Feed cache has been cleared. The preview will now fetch fresh data.',
        'success'
    );
    
    wp_redirect(admin_url('options-general.php?page=simple-rss-poster-settings'));
    exit;
}
add_action('admin_init', 'simple_rss_poster_handle_clear_cache');

/**
 * Clear Activity Log Handler
 */
function simple_rss_poster_handle_clear_log() {
    if (!isset($_POST['simple_rss_poster_clear_log'])) {
        return;
    }
    
    if (!isset($_POST['clear_log_nonce']) || !wp_verify_nonce($_POST['clear_log_nonce'], 'simple_rss_poster_clear_log')) {
        wp_die('Security check failed.');
    }
    
    if (!current_user_can('manage_options')) {
        wp_die('Permission denied.');
    }
    
    simple_rss_poster_clear_activity_log();
    
    add_settings_error(
        'simple_rss_poster_messages',
        'log_cleared',
        'Activity log has been cleared.',
        'success'
    );
    
    wp_redirect(admin_url('options-general.php?page=simple-rss-poster-settings'));
    exit;
}
add_action('admin_init', 'simple_rss_poster_handle_clear_log');

/**
 * Core Posting Engine
 */
function simple_rss_poster_execute_post($trigger = 'scheduled') {
    $url = get_option('simple_rss_poster_feed_url');
    $title = get_option('simple_rss_poster_post_title', 'Latest Links');
    $category = get_option('simple_rss_poster_post_category', 0);
    $post_status = get_option('simple_rss_poster_post_status', 'publish');
    $link_format = get_option('simple_rss_poster_link_format', 'full_link');
    $min_items = get_option('simple_rss_poster_min_items', 1);
    $post_header = get_option('simple_rss_poster_post_header', '');
    $post_footer = get_option('simple_rss_poster_post_footer', '');
    $full_replacements_raw = get_option('simple_rss_poster_full_replacements', '');
    $title_replacements_raw = get_option('simple_rss_poster_title_replacements', '');
    $auto_strip = get_option('simple_rss_poster_auto_strip_suspicious', false);
    
    $trigger_label = ($trigger === 'manual') ? 'Manual' : 'Scheduled';
    
    if (empty($url)) {
        $error = new WP_Error('no_feed_url', 'No feed URL configured.');
        simple_rss_poster_add_log_entry($trigger_label . ' post failed: No feed URL configured', 'error');
        return $error;
    }
    
    $full_replacements = simple_rss_poster_parse_replacements($full_replacements_raw);
    $prefix_replacements = simple_rss_poster_parse_replacements($title_replacements_raw);
    
    $rss = simple_rss_poster_fetch_feed($url);
    if (is_wp_error($rss)) {
        simple_rss_poster_add_log_entry($trigger_label . ' post failed: ' . $rss->get_error_message(), 'error');
        return $rss;
    }

    $data = simple_rss_poster_process_feed_items(
        $rss->get_items(0, SIMPLE_RSS_POSTER_FEED_ITEM_LIMIT),
        $full_replacements,
        $prefix_replacements,
        $auto_strip
    );
    
    $item_count = count($data['items']);
    
    if ($item_count === 0) {
        simple_rss_poster_add_log_entry($trigger_label . ' run: No new items to post', 'info');
        return false;
    }
    
    // Check minimum items threshold
    if ($item_count < $min_items) {
        simple_rss_poster_add_log_entry(
            sprintf('%s run: Only %d item(s) found, minimum is %d. Skipped.', $trigger_label, $item_count, $min_items),
            'warning'
        );
        return false;
    }

    // Build post content
    $content = '';
    
    if (!empty($post_header)) {
        $content .= '<p>' . wp_kses_post($post_header) . '</p>' . "\n";
    }
    
    $content .= '<ul>';
    foreach ($data['items'] as $item) {
        $content .= '<li>' . simple_rss_poster_format_link(
            $item['prefix'],
            $item['title'],
            $item['link'],
            $link_format
        ) . '</li>';
    }
    $content .= '</ul>';
    
    if (!empty($post_footer)) {
        $content .= "\n" . '<p>' . wp_kses_post($post_footer) . '</p>';
    }

    // Validate category exists
    $post_category = [];
    if ($category > 0 && term_exists($category, 'category')) {
        $post_category = [$category];
    }

    // Create the post
    $post_id = wp_insert_post([
        'post_title'    => $title . ' - ' . wp_date('F j, Y'),
        'post_content'  => $content,
        'post_status'   => $post_status,
        'post_category' => $post_category
    ], true);

    if (is_wp_error($post_id)) {
        simple_rss_poster_add_log_entry($trigger_label . ' post failed: ' . $post_id->get_error_message(), 'error');
        return $post_id;
    }

    // Update tracking
    $current_links = get_option('simple_rss_poster_posted_links', []);
    $updated_links = simple_rss_poster_prune_links($current_links, $data['links']);
    update_option('simple_rss_poster_posted_links', $updated_links);
    
    $status_label = ($post_status === 'draft') ? 'draft' : 'post';
    simple_rss_poster_add_log_entry(
        sprintf('%s %s created with %d items (Post ID: %d)', $trigger_label, $status_label, $item_count, $post_id),
        'success'
    );
    
    return $post_id;
}

//------------------------------------------------------------------------------
// 7. WP-Cron Scheduling
//------------------------------------------------------------------------------

/**
 * Calculate the next scheduled post time based on settings.
 */
function simple_rss_poster_get_next_scheduled_time() {
    $post_time = get_option('simple_rss_poster_post_time', '00:00');
    $post_days = get_option('simple_rss_poster_post_days', array_fill_keys(SIMPLE_RSS_POSTER_DAYS, 1));
    
    $time_parts = explode(':', $post_time);
    $hour = intval($time_parts[0]);
    $minute = intval($time_parts[1]);
    
    // Check if any days are selected
    $any_day_selected = false;
    foreach ($post_days as $day => $selected) {
        if ($selected) {
            $any_day_selected = true;
            break;
        }
    }
    
    if (!$any_day_selected) {
        return false; // No days selected, don't schedule
    }
    
    $wp_timezone = wp_timezone();
    $now = new DateTime('now', $wp_timezone);
    
    // Start checking from today
    $target = new DateTime('now', $wp_timezone);
    $target->setTime($hour, $minute, 0);
    
    // If today's time has passed, start from tomorrow
    if ($target <= $now) {
        $target->modify('+1 day');
    }
    
    // Find the next selected day (up to 7 days out)
    for ($i = 0; $i < 7; $i++) {
        $day_name = $target->format('l'); // Full day name (e.g., "Monday")
        
        if (!empty($post_days[$day_name])) {
            return $target->getTimestamp();
        }
        
        $target->modify('+1 day');
    }
    
    return false; // No matching day found
}

/**
 * Schedule (or reschedule) the next post.
 */
function simple_rss_poster_schedule_next_post() {
    // Clear any existing scheduled events
    $timestamp = wp_next_scheduled(SIMPLE_RSS_POSTER_CRON_HOOK);
    if ($timestamp) {
        wp_unschedule_event($timestamp, SIMPLE_RSS_POSTER_CRON_HOOK);
    }
    
    // Schedule for the next occurrence of the configured time and day
    $next_time = simple_rss_poster_get_next_scheduled_time();
    if ($next_time) {
        wp_schedule_single_event($next_time, SIMPLE_RSS_POSTER_CRON_HOOK);
    }
}

/**
 * Ensure cron is scheduled on page load (if not already).
 */
function simple_rss_poster_maybe_schedule() {
    if (!wp_next_scheduled(SIMPLE_RSS_POSTER_CRON_HOOK)) {
        simple_rss_poster_schedule_next_post();
    }
}
add_action('wp', 'simple_rss_poster_maybe_schedule');
add_action('admin_init', 'simple_rss_poster_maybe_schedule');

/**
 * Cron callback - execute post and reschedule.
 */
function simple_rss_poster_cron_callback() {
    simple_rss_poster_execute_post('scheduled');
    
    // Reschedule for the next selected day
    simple_rss_poster_schedule_next_post();
}
add_action(SIMPLE_RSS_POSTER_CRON_HOOK, 'simple_rss_poster_cron_callback');

//------------------------------------------------------------------------------
// 8. Activation/Deactivation
//------------------------------------------------------------------------------

/**
 * Plugin activation
 */
function simple_rss_poster_activate() {
    add_option('simple_rss_poster_posted_links', []);
    add_option('simple_rss_poster_post_time', '00:00');
    add_option('simple_rss_poster_post_days', array_fill_keys(SIMPLE_RSS_POSTER_DAYS, 1));
    add_option('simple_rss_poster_post_title', 'Latest Links');
    add_option('simple_rss_poster_post_category', 0);
    add_option('simple_rss_poster_post_status', 'publish');
    add_option('simple_rss_poster_link_format', 'full_link');
    add_option('simple_rss_poster_min_items', 1);
    add_option('simple_rss_poster_post_header', '');
    add_option('simple_rss_poster_post_footer', '');
    add_option('simple_rss_poster_full_replacements', '');
    add_option('simple_rss_poster_title_replacements', '');
    add_option('simple_rss_poster_auto_strip_suspicious', false);
    add_option('simple_rss_poster_activity_log', []);
    
    simple_rss_poster_schedule_next_post();
}
register_activation_hook(__FILE__, 'simple_rss_poster_activate');

/**
 * Plugin deactivation
 */
function simple_rss_poster_deactivate() {
    $timestamp = wp_next_scheduled(SIMPLE_RSS_POSTER_CRON_HOOK);
    if ($timestamp) {
        wp_unschedule_event($timestamp, SIMPLE_RSS_POSTER_CRON_HOOK);
    }
}
register_deactivation_hook(__FILE__, 'simple_rss_poster_deactivate');
