<?php
/*
Plugin Name: Simple RSS Feed Poster
Description: Fetches and posts new links from a single RSS feed, sorted alphabetically, with scheduled posting and preview.
Version: 2.2.0
Author: Ryan Gallagher
*/

/*
Changelog:
2.2.0 - Added: Full string replacements (for complex site names with colons)
        Added: Post status setting (publish or draft)
        Added: Link format setting (full link, bold prefix, or link only)
        Fixed: Scheduled time display now shows correct timezone

2.1.0 - Added: Title prefix replacement rules (define custom mappings for bad feed names)
        Added: Option to auto-strip suspicious prefixes (hostnames, URL fragments)
        Added: HTML entity decoding and whitespace normalization for cleaner titles
        Added: Empty/malformed entries are now skipped

2.0.0 - Major revision:
        - Fixed: Cron now actually fires at configured time (was ignoring post_time setting)
        - Fixed: Duplicate tracking now uses link-only approach (removed timestamp comparison that could miss late-added items)
        - Fixed: Posted links array now auto-prunes to last 500 entries to prevent unbounded growth
        - Fixed: AJAX preview now verifies nonce for security
        - Fixed: Feed errors now log the actual error message for troubleshooting
        - Fixed: Preview and post now use same item limit (100) for consistency
        - Added: Fallback to uncategorized with admin notice if selected category is deleted
        - Removed: Unused auto_post_threshold option
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
 * Sanitizes textarea inputs (replacements).
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
 * Reschedule cron when post time setting changes.
 */
function simple_rss_poster_maybe_reschedule($old_value, $new_value) {
    if ($old_value !== $new_value) {
        simple_rss_poster_schedule_next_post();
    }
}
add_action('update_option_simple_rss_poster_post_time', 'simple_rss_poster_maybe_reschedule', 10, 2);

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
    $post_category       = get_option('simple_rss_poster_post_category', 0);
    $post_status         = get_option('simple_rss_poster_post_status', 'publish');
    $link_format         = get_option('simple_rss_poster_link_format', 'full_link');
    $full_replacements   = get_option('simple_rss_poster_full_replacements', '');
    $title_replacements  = get_option('simple_rss_poster_title_replacements', '');
    $auto_strip          = get_option('simple_rss_poster_auto_strip_suspicious', false);
    $status              = get_option('simple_rss_poster_scheduled_post_status', '');
    $categories          = get_categories(['hide_empty' => false]);
    $tracked_count       = count(get_option('simple_rss_poster_posted_links', []));

    // Get next scheduled run from WP-Cron (fixed timezone display)
    $next_scheduled = wp_next_scheduled(SIMPLE_RSS_POSTER_CRON_HOOK);
    $next_run = $next_scheduled 
        ? wp_date('F j, Y @ g:i a', $next_scheduled) 
        : 'Not scheduled - save settings to schedule';

    // Create nonce for AJAX
    $ajax_nonce = wp_create_nonce('simple_rss_poster_ajax_nonce');
    ?>

    <div class="wrap">
        <h1>Simple RSS Feed Poster</h1>
        
        <?php settings_errors(); ?>
        
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
                    <th scope="row"><label for="simple_rss_poster_post_time">Daily Post Time</label></th>
                    <td>
                        <input type="time" 
                               id="simple_rss_poster_post_time"
                               name="simple_rss_poster_post_time" 
                               value="<?php echo esc_attr($post_time); ?>">
                        <p class="description">Time in your WordPress timezone (<?php echo esc_html(wp_timezone_string()); ?>)</p>
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
                <th>Last Activity</th>
                <td><?php echo esc_html($status ?: 'No activity logged yet.'); ?></td>
            </tr>
            <tr>
                <th>Tracked Links</th>
                <td><?php echo esc_html($tracked_count); ?> / <?php echo SIMPLE_RSS_POSTER_MAX_TRACKED_LINKS; ?></td>
            </tr>
        </table>

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
        
        function getPreview() {
            var feedUrl = $('#simple_rss_poster_feed_url').val();
            var postTitle = $('#simple_rss_poster_post_title').val();
            var linkFormat = $('#simple_rss_poster_link_format').val();
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
        $('#simple_rss_poster_feed_url, #simple_rss_poster_post_title, #simple_rss_poster_full_replacements, #simple_rss_poster_title_replacements').on('input change', function() {
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
 * 
 * @param string $replacements_text Raw text from settings
 * @return array Associative array of find => replace
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
        
        // Split on => separator
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
 * 
 * @param string $prefix The prefix to check
 * @return bool True if the prefix looks suspicious
 */
function simple_rss_poster_is_suspicious_prefix($prefix) {
    // Contains dots but no spaces - likely a hostname
    if (strpos($prefix, '.') !== false && strpos($prefix, ' ') === false) {
        return true;
    }
    
    // Looks like a URL path or technical identifier
    if (preg_match('/^[a-z0-9._-]+$/i', $prefix) && strpos($prefix, '.') !== false) {
        return true;
    }
    
    return false;
}

/**
 * Clean and process a single title.
 * 
 * @param string $raw_title The raw title from the feed
 * @param array $full_replacements Full string replacement rules
 * @param array $prefix_replacements Prefix replacement rules
 * @param bool $auto_strip Whether to auto-strip suspicious prefixes
 * @return array|null Array with 'prefix' and 'title' keys, or null if title should be skipped
 */
function simple_rss_poster_clean_title($raw_title, $full_replacements = [], $prefix_replacements = [], $auto_strip = false) {
    // Step 1: Decode HTML entities
    $title = html_entity_decode($raw_title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Step 2: Normalize whitespace (including non-breaking spaces)
    $title = preg_replace('/[\s\x{00A0}]+/u', ' ', $title);
    $title = trim($title);
    
    // Skip if empty after cleaning
    if (empty($title)) {
        return null;
    }
    
    // Step 3: Apply full string replacements first
    foreach ($full_replacements as $find => $replace) {
        if (strpos($title, $find) !== false) {
            $title = str_replace($find, $replace, $title);
            $title = trim($title);
            // Clean up any leftover ": " at the start
            $title = preg_replace('/^:\s*/', '', $title);
            $title = trim($title);
        }
    }
    
    // Skip if empty after full replacements
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
                // Empty replacement = strip prefix entirely
                $prefix = '';
            } else {
                // Replace with new prefix
                $prefix = $new_prefix;
            }
        }
        // Step 6: Check for suspicious prefix (if auto-strip enabled)
        elseif ($auto_strip && simple_rss_poster_is_suspicious_prefix($prefix)) {
            $prefix = '';
        }
    }
    
    // Final trim and empty check
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
 * 
 * @param string $prefix The site name prefix
 * @param string $title The article title
 * @param string $link The URL
 * @param string $format The format style (full_link, bold_prefix, link_only)
 * @return string Formatted HTML
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

//------------------------------------------------------------------------------
// 5. Feed Processing Logic
//------------------------------------------------------------------------------

/**
 * Process feed items and return new (unposted) items.
 * 
 * @param array $rss_items SimplePie items from the feed
 * @param array $full_replacements Full string replacement rules
 * @param array $prefix_replacements Prefix replacement rules
 * @param bool $auto_strip Whether to auto-strip suspicious prefixes
 * @return array Array with 'items' (formatted) and 'links' (for tracking)
 */
function simple_rss_poster_process_feed_items($rss_items, $full_replacements = [], $prefix_replacements = [], $auto_strip = false) {
    $posted_links = get_option('simple_rss_poster_posted_links', []);
    
    $new_items = [];
    $new_links = [];

    foreach ($rss_items as $item) {
        $link = esc_url($item->get_permalink());
        
        // Skip if we've already posted this link
        if (in_array($link, $posted_links, true)) {
            continue;
        }

        // Clean the title
        $cleaned = simple_rss_poster_clean_title(
            $item->get_title(),
            $full_replacements,
            $prefix_replacements,
            $auto_strip
        );
        
        // Skip if title is empty/invalid after cleaning
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

    // Sort alphabetically by title (case-insensitive)
    usort($new_items, function($a, $b) {
        // Sort by prefix first if both have one, otherwise by title
        $a_sort = !empty($a['prefix']) ? $a['prefix'] . ': ' . $a['title'] : $a['title'];
        $b_sort = !empty($b['prefix']) ? $b['prefix'] . ': ' . $b['title'] : $b['title'];
        return strcasecmp($a_sort, $b_sort);
    });

    return [
        'items' => $new_items,
        'links' => $new_links
    ];
}

/**
 * Prune the posted links array to prevent unbounded growth.
 * 
 * @param array $links Current array of posted links
 * @param array $new_links New links to add
 * @return array Pruned array of links
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
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'simple_rss_poster_ajax_nonce')) {
        wp_send_json_error('Security check failed. Please refresh the page and try again.');
        return;
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied.');
        return;
    }
    
    $feed_url = isset($_POST['feed_url']) ? esc_url_raw($_POST['feed_url']) : '';
    $post_title = isset($_POST['post_title']) ? sanitize_text_field($_POST['post_title']) : 'Latest Links';
    $link_format = isset($_POST['link_format']) ? sanitize_text_field($_POST['link_format']) : 'full_link';
    $full_replacements_raw = isset($_POST['full_replacements']) ? sanitize_textarea_field($_POST['full_replacements']) : '';
    $title_replacements_raw = isset($_POST['title_replacements']) ? sanitize_textarea_field($_POST['title_replacements']) : '';
    $auto_strip = isset($_POST['auto_strip']) && $_POST['auto_strip'] === '1';
    
    if (empty($feed_url)) {
        wp_send_json_error('Please enter a feed URL.');
        return;
    }

    // Parse replacement rules
    $full_replacements = simple_rss_poster_parse_replacements($full_replacements_raw);
    $prefix_replacements = simple_rss_poster_parse_replacements($title_replacements_raw);

    include_once(ABSPATH . WPINC . '/feed.php');
    
    $rss = fetch_feed($feed_url);
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
    
    if (empty($data['items'])) {
        wp_send_json_success('<p><em>No new items to post. All feed items have already been posted.</em></p>');
        return;
    }
    
    $output = '<h3 style="margin-top: 0;">' . esc_html($post_title) . ' - ' . date_i18n('F j, Y') . '</h3>';
    $output .= '<p><strong>' . count($data['items']) . ' new item(s) ready to post:</strong></p>';
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
            'No new items to post.',
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
    
    set_transient('settings_errors', get_settings_errors(), 30);
    
    wp_redirect(admin_url('options-general.php?page=simple-rss-poster-settings&settings-updated=true'));
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
    update_option('simple_rss_poster_scheduled_post_status', 'History reset at ' . wp_date('Y-m-d H:i:s'));
    
    add_settings_error(
        'simple_rss_poster_messages',
        'reset_success',
        'Posting history has been reset.',
        'success'
    );
    
    set_transient('settings_errors', get_settings_errors(), 30);
    
    wp_redirect(admin_url('options-general.php?page=simple-rss-poster-settings&settings-updated=true'));
    exit;
}
add_action('admin_init', 'simple_rss_poster_handle_reset');

/**
 * Core Posting Engine
 * 
 * @param string $trigger Source of the post request ('manual', 'scheduled')
 * @return int|false|WP_Error Post ID on success, false if no items, WP_Error on failure
 */
function simple_rss_poster_execute_post($trigger = 'scheduled') {
    $url = get_option('simple_rss_poster_feed_url');
    $title = get_option('simple_rss_poster_post_title', 'Latest Links');
    $category = get_option('simple_rss_poster_post_category', 0);
    $post_status = get_option('simple_rss_poster_post_status', 'publish');
    $link_format = get_option('simple_rss_poster_link_format', 'full_link');
    $full_replacements_raw = get_option('simple_rss_poster_full_replacements', '');
    $title_replacements_raw = get_option('simple_rss_poster_title_replacements', '');
    $auto_strip = get_option('simple_rss_poster_auto_strip_suspicious', false);
    
    if (empty($url)) {
        $error = new WP_Error('no_feed_url', 'No feed URL configured.');
        simple_rss_poster_log_status($trigger, $error);
        return $error;
    }
    
    // Parse replacement rules
    $full_replacements = simple_rss_poster_parse_replacements($full_replacements_raw);
    $prefix_replacements = simple_rss_poster_parse_replacements($title_replacements_raw);
    
    include_once(ABSPATH . WPINC . '/feed.php');
    
    $rss = fetch_feed($url);
    if (is_wp_error($rss)) {
        simple_rss_poster_log_status($trigger, $rss);
        return $rss;
    }

    $data = simple_rss_poster_process_feed_items(
        $rss->get_items(0, SIMPLE_RSS_POSTER_FEED_ITEM_LIMIT),
        $full_replacements,
        $prefix_replacements,
        $auto_strip
    );
    
    if (empty($data['items'])) {
        simple_rss_poster_log_status($trigger, null, 0);
        return false;
    }

    // Build post content
    $content = '<ul>';
    foreach ($data['items'] as $item) {
        $content .= '<li>' . simple_rss_poster_format_link(
            $item['prefix'],
            $item['title'],
            $item['link'],
            $link_format
        ) . '</li>';
    }
    $content .= '</ul>';

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
        simple_rss_poster_log_status($trigger, $post_id);
        return $post_id;
    }

    // Update tracking
    $current_links = get_option('simple_rss_poster_posted_links', []);
    $updated_links = simple_rss_poster_prune_links($current_links, $data['links']);
    update_option('simple_rss_poster_posted_links', $updated_links);
    
    simple_rss_poster_log_status($trigger, null, count($data['items']), $post_id, $post_status);
    
    return $post_id;
}

/**
 * Log status message for admin display
 */
function simple_rss_poster_log_status($trigger, $error = null, $item_count = 0, $post_id = null, $post_status = 'publish') {
    $timestamp = wp_date('Y-m-d H:i:s');
    $trigger_label = ($trigger === 'manual') ? 'Manual' : 'Scheduled';
    $status_label = ($post_status === 'draft') ? 'draft' : 'post';
    
    if (is_wp_error($error)) {
        $message = sprintf('[%s] %s post failed: %s', $timestamp, $trigger_label, $error->get_error_message());
    } elseif ($item_count === 0) {
        $message = sprintf('[%s] %s run: No new items to post.', $timestamp, $trigger_label);
    } else {
        $message = sprintf('[%s] %s %s created with %d items (Post ID: %d)', $timestamp, $trigger_label, $status_label, $item_count, $post_id);
    }
    
    update_option('simple_rss_poster_scheduled_post_status', $message);
}

//------------------------------------------------------------------------------
// 7. WP-Cron Scheduling
//------------------------------------------------------------------------------

/**
 * Calculate the next scheduled post time based on settings.
 * 
 * @return int Unix timestamp for next scheduled run
 */
function simple_rss_poster_get_next_scheduled_time() {
    $post_time = get_option('simple_rss_poster_post_time', '00:00');
    $time_parts = explode(':', $post_time);
    $hour = intval($time_parts[0]);
    $minute = intval($time_parts[1]);
    
    // Get current time in WP timezone
    $wp_timezone = wp_timezone();
    $now = new DateTime('now', $wp_timezone);
    
    // Create target time for today in WP timezone
    $target = new DateTime('now', $wp_timezone);
    $target->setTime($hour, $minute, 0);
    
    // If target time has passed today, schedule for tomorrow
    if ($target <= $now) {
        $target->modify('+1 day');
    }
    
    return $target->getTimestamp();
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
    
    // Schedule for the next occurrence of the configured time
    $next_time = simple_rss_poster_get_next_scheduled_time();
    wp_schedule_single_event($next_time, SIMPLE_RSS_POSTER_CRON_HOOK);
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
 * Cron callback - execute post and reschedule for tomorrow.
 */
function simple_rss_poster_cron_callback() {
    simple_rss_poster_execute_post('scheduled');
    
    // Reschedule for tomorrow at the same time
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
    // Initialize options if they don't exist
    add_option('simple_rss_poster_posted_links', []);
    add_option('simple_rss_poster_post_time', '00:00');
    add_option('simple_rss_poster_post_title', 'Latest Links');
    add_option('simple_rss_poster_post_category', 0);
    add_option('simple_rss_poster_post_status', 'publish');
    add_option('simple_rss_poster_link_format', 'full_link');
    add_option('simple_rss_poster_full_replacements', '');
    add_option('simple_rss_poster_title_replacements', '');
    add_option('simple_rss_poster_auto_strip_suspicious', false);
    
    // Schedule the first cron run
    simple_rss_poster_schedule_next_post();
}
register_activation_hook(__FILE__, 'simple_rss_poster_activate');

/**
 * Plugin deactivation
 */
function simple_rss_poster_deactivate() {
    // Clear scheduled events
    $timestamp = wp_next_scheduled(SIMPLE_RSS_POSTER_CRON_HOOK);
    if ($timestamp) {
        wp_unschedule_event($timestamp, SIMPLE_RSS_POSTER_CRON_HOOK);
    }
}
register_deactivation_hook(__FILE__, 'simple_rss_poster_deactivate');
