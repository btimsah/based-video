<?php
/**
 * Plugin Name: Based Video Paywall
 * Plugin URI:  https://crypto-plugins.com
 * Description: Viewers watch a video preview, then pay USDC on Base to unlock the full stream and optional download.
 * Version:     2.5.1
 * Author:      Robert Timsah
 */

if (!defined('WPINC')) {
    die;
}

// =============================================================================
// 1. DATABASE SETUP
// =============================================================================

function base_pv_activate() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'base_pv_orders';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        email varchar(255) NOT NULL,
        post_id bigint(20) UNSIGNED NOT NULL,
        transaction_id varchar(255) DEFAULT NULL,
        usdc_amount decimal(20,6) NOT NULL, /* USDC has 6 decimals */
        usd_amount decimal(10,2) NOT NULL,
        order_status varchar(20) NOT NULL, -- pending, success, failed
        created_at datetime NOT NULL,
        user_ip varchar(45) DEFAULT '',
        PRIMARY KEY  (id),
        UNIQUE KEY transaction_id (transaction_id),
        KEY email (email),
        KEY post_id (post_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'base_pv_activate');

// =============================================================================
// 2. ADMIN MENU & SETTINGS
// =============================================================================

function base_pv_admin_menu() {
    add_menu_page('Based Video', 'Based Video', 'manage_options', 'base-pv-main', 'base_pv_settings_page', 'dashicons-database', 20);
    add_submenu_page('base-pv-main', 'Settings', 'Settings', 'manage_options', 'base-pv-main', 'base_pv_settings_page');
    add_submenu_page('base-pv-main', 'Orders', 'Orders', 'manage_options', 'base-pv-orders', 'base_pv_orders_page_html');
}
add_action('admin_menu', 'base_pv_admin_menu');

function base_pv_register_settings() {
    register_setting('base_pv_group', 'base_pv_apikey'); // Etherscan/BaseScan API Key
    register_setting('base_pv_group', 'base_pv_wallet');
    register_setting('base_pv_group', 'base_pv_usdc_contract');
    register_setting('base_pv_group', 'base_pv_admin_email');
    register_setting('base_pv_group', 'base_pv_disable_admin_emails');
    register_setting('base_pv_group', 'base_pv_slack_percentage');
    register_setting('base_pv_group', 'base_pv_support_url'); 
}
add_action('admin_init', 'base_pv_register_settings');

// --- EMAIL HELPER FUNCTION (COINBASE BLUE AESTHETIC) ---
function base_pv_send_styled_email($to, $subject, $heading, $lines, $cta = null) {
    $headers = array('Content-Type: text/html; charset=UTF-8');
    
    // Base/Coinbase Theme Colors
    $bg = "#F4F6F8";      // Soft Gray-Blue Background
    $panel = "#ffffff";   // White Panel
    $brand_blue = "#0052FF"; // Coinbase Blue
    $text = "#050F19";    // Dark Navy/Black
    $gray = "#5B616E";    // Muted Text
    $border = "#E2E8F0";  // Light Border

    $message_content = "";
    foreach($lines as $line) {
        $message_content .= "<p style='margin: 0 0 15px 0; color: {$gray}; font-size: 16px; line-height: 1.6;'>{$line}</p>";
    }

    $button_html = "";
    if ($cta) {
        $button_html = "
        <div style='margin-top: 30px; text-align: center;'>
            <a href='{$cta['url']}' style='background-color: {$brand_blue}; color: #ffffff; text-decoration: none; padding: 15px 30px; border-radius: 50px; font-weight: bold; text-transform: uppercase; font-size: 14px; display: inline-block; box-shadow: 0 4px 15px rgba(0, 82, 255, 0.3);'>
                {$cta['text']}
            </a>
        </div>";
    }

    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif; background-color: {$bg}; }
        </style>
    </head>
    <body style='background-color: {$bg}; margin: 0; padding: 40px 0;'>
        <div style='max-width: 600px; margin: 0 auto; background-color: {$panel}; border-radius: 24px; overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,0.05);'>
            <!-- Header -->
            <div style='background: #fff; padding: 30px; text-align: center; border-bottom: 1px solid {$border};'>
                <h2 style='margin: 0; color: {$brand_blue}; font-size: 24px; font-weight: 800; letter-spacing: -0.5px;'>{$heading}</h2>
            </div>
            
            <!-- Content -->
            <div style='padding: 40px 40px 50px;'>
                {$message_content}
                {$button_html}
            </div>
            
            <!-- Footer -->
            <div style='padding: 20px; text-align: center; font-size: 12px; color: #999; background-color: #fafafa; border-top: 1px solid #eee;'>
                <p style='margin: 0;'>Securely Delivered by Based Video Paywall</p>
            </div>
        </div>
    </body>
    </html>
    ";

    wp_mail($to, $subject, $body, $headers);
}

function base_pv_process_order_actions() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'base-pv-orders') return;
    if (!isset($_GET['base_action']) || !isset($_GET['order_id'])) return;

    $action = $_GET['base_action'];
    $oid = intval($_GET['order_id']);

    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'base_pv_action_' . $oid)) wp_die('Security check failed');

    global $wpdb;
    $table_name = $wpdb->prefix . 'base_pv_orders';

    // Fetch order details first
    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $oid));

    if ($action === 'delete') {
        $wpdb->delete($table_name, ['id' => $oid]);
        $msg = 'Order deleted successfully.';
    } elseif ($action === 'mark_success') {
        $wpdb->update($table_name, ['order_status' => 'success'], ['id' => $oid]);
        $msg = 'Order manually marked as success. Email sent.';

        // --- SEND USER EMAIL ON MANUAL SUCCESS ---
        if ($order) {
            $post_title = get_the_title($order->post_id);
            $post_url = get_permalink($order->post_id);
            
            base_pv_send_styled_email(
                $order->email,
                "Access Granted: $post_title",
                "Purchase Confirmed",
                [
                    "You have been manually granted access to <strong>$post_title</strong>.",
                    "To watch your video, simply visit the page below and enter your email address (<strong>{$order->email}</strong>) into the login box inside the player.",
                    "Thank you for your support!"
                ],
                ['text' => 'Watch Now', 'url' => $post_url]
            );
        }
    } else return;

    wp_redirect(add_query_arg(['page' => 'base-pv-orders', 'base_msg' => urlencode($msg)], admin_url('admin.php')));
    exit;
}
add_action('admin_init', 'base_pv_process_order_actions');

function base_pv_settings_page() {
    ?>
    <div class="wrap">
        <h1>Based Video Paywall Settings</h1>
        
        <div style="background: #fff; border-left: 4px solid #0052FF; padding: 15px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
            <h3 style="margin-top:0;">Base Network (USDC) Setup</h3>
            <p>This plugin accepts <strong>USDC</strong> on the <strong>Base</strong> network. Funds are sent directly to your wallet.</p>
            <p>You will need a free <a href="https://etherscan.io/apis" target="_blank" style="text-decoration:none; color:#0052FF; font-weight:bold;">Etherscan V2 API Key</a> (or BaseScan API Key) to verify transactions.</p>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields('base_pv_group'); do_settings_sections('base_pv_group'); ?>
            <table class="form-table">
                <tr><th scope="row">Etherscan/BaseScan API Key</th><td><input type="text" name="base_pv_apikey" value="<?php echo esc_attr(get_option('base_pv_apikey')); ?>" class="regular-text" /></td></tr>
                <tr><th scope="row">Receiving Wallet (EVM)</th><td><input type="text" name="base_pv_wallet" value="<?php echo esc_attr(get_option('base_pv_wallet')); ?>" class="regular-text" /></td></tr>
                <tr>
                    <th scope="row">USDC Contract Address</th>
                    <td>
                        <input type="text" name="base_pv_usdc_contract" value="<?php echo esc_attr(get_option('base_pv_usdc_contract', '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913')); ?>" class="regular-text" />
                        <p class="description">Default is Base Mainnet USDC: <code>0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Admin Email</th>
                    <td>
                        <input type="email" name="base_pv_admin_email" value="<?php echo esc_attr(get_option('base_pv_admin_email')); ?>" class="regular-text" />
                        <p class="description">Receives checkout notifications.</p>
                        <br>
                        <label>
                            <input type="checkbox" name="base_pv_disable_admin_emails" value="1" <?php checked(get_option('base_pv_disable_admin_emails'), '1'); ?> />
                            Disable admin email notifications
                        </label>
                    </td>
                </tr>
                <tr><th scope="row">Support URL</th><td><input type="url" name="base_pv_support_url" value="<?php echo esc_attr(get_option('base_pv_support_url')); ?>" class="regular-text" /><p class="description">Shown on the Order Lookup page.</p></td></tr>
                <tr><th scope="row">Price Slack (%)</th><td><input type="number" step="0.1" name="base_pv_slack_percentage" value="<?php echo esc_attr(get_option('base_pv_slack_percentage', 2.0)); ?>" class="small-text" /> %</td></tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

function base_pv_orders_page_html() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'base_pv_orders';
    $orders = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
    if (isset($_GET['base_msg'])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(urldecode($_GET['base_msg'])) . '</p></div>';
    ?>
    <div class="wrap">
        <h1>Checkout Orders (Base)</h1>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th>Date</th><th>Status</th><th>Email</th><th>Video</th><th>USD</th><th>USDC</th><th>Actions</th></tr></thead>
            <tbody>
                <?php if (empty($orders)) : ?><tr><td colspan="7">No orders found.</td></tr><?php else : foreach ($orders as $order) : 
                    $col = $order->order_status === 'success' ? '#0052FF' : ($order->order_status === 'pending' ? '#f59e0b' : '#ef4444'); ?>
                    <tr>
                        <td><?php echo esc_html($order->created_at); ?></td>
                        <td><strong style="color:<?php echo $col; ?>"><?php echo esc_html(ucfirst($order->order_status)); ?></strong></td>
                        <td><?php echo esc_html($order->email); ?></td>
                        <td><?php echo get_the_title($order->post_id) ?: 'Deleted'; ?></td>
                        <td>$<?php echo esc_html($order->usd_amount); ?></td>
                        <td><?php echo esc_html($order->usdc_amount); ?></td>
                        <td>
                            <?php $url = admin_url('admin.php?page=base-pv-orders&order_id=' . $order->id . '&_wpnonce=' . wp_create_nonce('base_pv_action_' . $order->id)); ?>
                            <?php if ($order->order_status !== 'success') : ?><a href="<?php echo esc_url($url . '&base_action=mark_success'); ?>" class="button button-small button-primary">Mark Paid</a><?php endif; ?>
                            <a href="<?php echo esc_url($url . '&base_action=delete'); ?>" class="button button-small" style="color:red;">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// =============================================================================
// 3. META BOX & ADMIN ASSETS
// =============================================================================

function base_pv_add_meta_box() { add_meta_box('base_pv_meta', 'Based Video Paywall', 'base_pv_meta_html', 'post', 'side', 'high'); }
add_action('add_meta_boxes', 'base_pv_add_meta_box');

// Enqueue media library scripts for admin
function base_pv_admin_scripts() {
    wp_enqueue_media();
}
add_action('admin_enqueue_scripts', 'base_pv_admin_scripts');

function base_pv_meta_html($post) {
    wp_nonce_field('base_pv_save', 'base_pv_nonce');
    $enabled = get_post_meta($post->ID, '_base_pv_enabled', true);
    $price = get_post_meta($post->ID, '_base_pv_price', true);
    $video_url = get_post_meta($post->ID, '_base_pv_video_url', true);
    $video_poster = get_post_meta($post->ID, '_base_pv_video_poster', true);
    $limit = get_post_meta($post->ID, '_base_pv_limit_min', true);
    $disable_dl = get_post_meta($post->ID, '_base_pv_disable_download', true);
    $is_free = get_post_meta($post->ID, '_base_pv_is_free_test', true);
    
    // NEW: Max Width Setting
    $max_width = get_post_meta($post->ID, '_base_pv_max_width', true);
    if(empty($max_width)) $max_width = '800'; // Default
    ?>
    
    <div style="background:#eef2ff; border:1px solid #0052FF; padding:10px; border-radius:5px; margin-bottom:15px; text-align:center;">
        <strong>Shortcode:</strong><br>
        <code style="display:block; margin-top:5px; user-select:all;">[base_video_paywall id="<?php echo $post->ID; ?>"]</code>
    </div>

    <p><label><input type="checkbox" name="base_pv_enabled" value="1" <?php checked($enabled, '1'); ?> /> <strong>Enable Premium Video</strong></label></p>
    <p><label><input type="checkbox" name="base_pv_is_free_test" value="1" <?php checked($is_free, '1'); ?> /> <strong>Enable Free Test Mode</strong> <span style="color:#888; font-size:11px;">(No payment required)</span></label></p>
    <p><label><input type="checkbox" name="base_pv_disable_download" value="1" <?php checked($disable_dl, '1'); ?> /> <strong>Disable Download Button</strong></label></p>
    
    <!-- Video URL Upload -->
    <p>
        <label><strong>Video URL:</strong></label><br>
        <input type="text" name="base_pv_video_url" id="base_pv_video_url" value="<?php echo esc_attr($video_url); ?>" style="width:100%; margin-bottom:5px;" />
        <button type="button" class="button base-upload-btn" data-target="#base_pv_video_url">Browse/Upload Video</button>
    </p>
    <p style="font-size: 11px; color: #666; margin-top: -8px;">Need hosting? We recommend <a href="https://storj.io/signup" target="_blank" style="text-decoration:none; color:#0052FF; font-weight:bold;">Storj</a>.</p>
    
    <!-- Poster Image Upload -->
    <p>
        <label><strong>Poster Image (Optional):</strong></label><br>
        <input type="text" name="base_pv_video_poster" id="base_pv_video_poster" value="<?php echo esc_attr($video_poster); ?>" style="width:100%; margin-bottom:5px;" />
        <button type="button" class="button base-upload-btn" data-target="#base_pv_video_poster">Browse/Upload Image</button>
    </p>
    <p style="font-size: 11px; color: #666; margin-top: -8px;">Overrides Featured Image if set.</p>

    <!-- NEW: Player Width -->
    <p>
        <label><strong>Player Max Width (px):</strong></label><br>
        <input type="number" name="base_pv_max_width" value="<?php echo esc_attr($max_width); ?>" style="width:100px;" /> px
        <span style="font-size:11px; color:#666; display:block;">Sets the maximum width. Player is always centered.</span>
    </p>

    <p><label>Price (USD/USDC):</label><br><input type="number" step="0.01" name="base_pv_price" value="<?php echo esc_attr($price); ?>" style="width:100%;" /></p>
    <p><label>Preview (Minutes):</label><br><input type="number" step="0.1" name="base_pv_limit_min" value="<?php echo esc_attr($limit); ?>" style="width:100%;" /></p>
    <?php
}

// Add JS for Media Uploader in Footer
function base_pv_admin_footer_script() {
    ?>
    <script>
    jQuery(document).ready(function($){
        $('.base-upload-btn').click(function(e) {
            e.preventDefault();
            var targetInput = $($(this).data('target'));
            var custom_uploader = wp.media({
                title: 'Select File',
                button: { text: 'Use this file' },
                multiple: false
            }).on('select', function() {
                var attachment = custom_uploader.state().get('selection').first().toJSON();
                targetInput.val(attachment.url);
            }).open();
        });
    });
    </script>
    <?php
}
add_action('admin_footer', 'base_pv_admin_footer_script');

function base_pv_save_meta($post_id) {
    if (!isset($_POST['base_pv_nonce']) || !wp_verify_nonce($_POST['base_pv_nonce'], 'base_pv_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
    
    update_post_meta($post_id, '_base_pv_enabled', isset($_POST['base_pv_enabled']) ? '1' : '');
    update_post_meta($post_id, '_base_pv_disable_download', isset($_POST['base_pv_disable_download']) ? '1' : '');
    update_post_meta($post_id, '_base_pv_is_free_test', isset($_POST['base_pv_is_free_test']) ? '1' : '');
    
    if (isset($_POST['base_pv_price'])) update_post_meta($post_id, '_base_pv_price', sanitize_text_field($_POST['base_pv_price']));
    if (isset($_POST['base_pv_video_url'])) update_post_meta($post_id, '_base_pv_video_url', esc_url_raw($_POST['base_pv_video_url']));
    if (isset($_POST['base_pv_video_poster'])) update_post_meta($post_id, '_base_pv_video_poster', esc_url_raw($_POST['base_pv_video_poster']));
    if (isset($_POST['base_pv_max_width'])) update_post_meta($post_id, '_base_pv_max_width', intval($_POST['base_pv_max_width']));
    
    if (isset($_POST['base_pv_limit_min'])) {
        $val = floatval($_POST['base_pv_limit_min']);
        update_post_meta($post_id, '_base_pv_limit_min', $val >= 0.1 ? $val : 0.1);
    }
}
add_action('save_post', 'base_pv_save_meta');

// =============================================================================
// 4. FRONTEND
// =============================================================================

function base_pv_enqueue_assets() { 
    global $post;
    if ((is_singular() && has_shortcode($post->post_content, 'base_video_paywall')) || has_shortcode($post->post_content, 'base_pv_order_lookup')) {
        wp_enqueue_style('base-pv-styles', plugin_dir_url(__FILE__) . 'style.css', [], '2.5.1'); 
        wp_enqueue_style( 'base-pv-fonts', 'https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap', array(), null );
    }
}
add_action('wp_enqueue_scripts', 'base_pv_enqueue_assets');

// --- SHORTCODE: ORDER LOOKUP ---
add_shortcode('base_pv_order_lookup', 'base_pv_order_lookup_shortcode');
function base_pv_order_lookup_shortcode() {
    ob_start();
    ?>
    <div class="base-lookup-wrapper">
        <div class="base-lookup-controls">
            <input type="email" id="base-lookup-email" placeholder="Enter your email address..." class="base-lookup-input">
            <button onclick="baseLookupOrders()" class="base-lookup-btn">Find Orders</button>
        </div>
        <div id="base-lookup-results" class="base-results-grid"></div>
        <div id="base-lookup-loading" style="display:none; text-align:center; padding:40px;">
            <div class="base-spinner"></div>
            <p style="color:#999; font-size:14px; margin-top:10px;">Searching database...</p>
        </div>
    </div>
    <script>
    async function baseLookupOrders() {
        const email = document.getElementById('base-lookup-email').value;
        const resDiv = document.getElementById('base-lookup-results');
        const loadDiv = document.getElementById('base-lookup-loading');
        if(!email.includes('@')) return alert('Please enter a valid email.');
        resDiv.innerHTML = '';
        loadDiv.style.display = 'block';
        try {
            const fd = new FormData();
            fd.append('action', 'base_pv_lookup_orders');
            fd.append('email', email);
            const req = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {method: 'POST', body: fd});
            const res = await req.json();
            loadDiv.style.display = 'none';
            if(res.success && res.data.length > 0) {
                let html = '';
                res.data.forEach(item => {
                    html += `
                    <a href="${item.url}" class="base-lookup-card group">
                        <div class="base-card-thumb">
                            <img src="${item.image}" class="base-card-img" onerror="this.src='https://via.placeholder.com/300x169/f0f0f0/cccccc?text=No+Image'">
                             <div class="base-card-badge">Unlocked</div>
                        </div>
                        <h2 class="base-card-title">${item.title}</h2>
                        <div class="base-card-footer">
                            <span class="base-card-date">Ready to Watch</span>
                            <span class="base-card-action">Play Video &rarr;</span>
                        </div>
                    </a>`;
                });
                resDiv.innerHTML = html;
            } else {
                resDiv.innerHTML = '<div style="grid-column:1/-1; text-align:center; color:#999; padding:60px; font-size:16px;">No purchases found for this email.</div>';
            }
        } catch(e) {
            loadDiv.style.display = 'none';
            alert('Error connecting to server.');
        }
    }
    </script>
    <?php
    return ob_get_clean();
}

// --- MAIN VIDEO SHORTCODE ---
add_shortcode('base_video_paywall', 'base_pv_render_shortcode');

function base_pv_render_shortcode($atts) {
    // Default to current post ID if not provided
    $a = shortcode_atts(array(
        'id' => get_the_ID(),
    ), $atts);
    
    $post_id = intval($a['id']);
    if (!$post_id) return '';

    // Check Enabled
    $enabled = get_post_meta($post_id, '_base_pv_enabled', true);
    if (!$enabled) return '';

    // Data Gathering
    $video_url = get_post_meta($post_id, '_base_pv_video_url', true);
    if (empty($video_url)) return '';

    // Poster Logic: Custom -> Featured -> Gradient Fallback (Handled in HTML)
    $poster_custom = get_post_meta($post_id, '_base_pv_video_poster', true);
    $poster_feat = get_the_post_thumbnail_url($post_id, 'full');
    $poster_url = $poster_custom ? $poster_custom : $poster_feat;

    $price = get_post_meta($post_id, '_base_pv_price', true);
    $limit_min = get_post_meta($post_id, '_base_pv_limit_min', true) ?: 2;
    $limit_sec = $limit_min * 60;
    $disable_dl = get_post_meta($post_id, '_base_pv_disable_download', true);
    $is_free = get_post_meta($post_id, '_base_pv_is_free_test', true);
    
    // NEW: Max Width (Default 800px)
    // Applied strictly with !important inline to override block themes like 2023
    $max_width = get_post_meta($post_id, '_base_pv_max_width', true) ?: '800';
    $container_style = 'max-width:' . esc_attr($max_width) . 'px !important; margin-left:auto !important; margin-right:auto !important; width: 100% !important; display: block;';

    // UI Strings
    $display_price = $is_free ? 'FREE' : '$' . esc_html($price);
    $button_text = $is_free ? 'Unlock' : 'Pay'; 
    $site_name = get_bloginfo('name');
    $site_icon = get_site_icon_url(32) ?: 'https://via.placeholder.com/20?text=Icon';

    // Unique Gradient ID
    $grad_id = 'base-grad-' . $post_id;

    ob_start();
    ?>
    <div class="base-theater-backdrop" id="base-backdrop-<?php echo $post_id; ?>"></div>

    <!-- WRAPPER: Now centered and constrained by user setting with strict styling -->
    <div class="base-player-container payment-active" id="base-wrapper-<?php echo $post_id; ?>" style="<?php echo $container_style; ?>">
        
        <!-- INJECT SVG GRADIENT DEFINITION (BLUE THEME) -->
        <svg style="width:0;height:0;position:absolute;" aria-hidden="true" focusable="false">
          <defs>
            <linearGradient id="<?php echo $grad_id; ?>" x1="0%" y1="0%" x2="100%" y2="100%">
              <stop offset="0%" style="stop-color:#0052FF;stop-opacity:1" />
              <stop offset="100%" style="stop-color:#0039b3;stop-opacity:1" />
            </linearGradient>
          </defs>
        </svg>

        <!-- 1. TOP IDENTITY BAR -->
        <div class="base-top-bar">
            <div class="base-id-left">
                <?php if (has_site_icon()) : ?>
                    <img src="<?php echo esc_url(get_site_icon_url(100)); ?>" class="base-site-icon" alt="Favicon">
                <?php else: ?>
                    <div style="width:32px;height:32px;background:#0052FF;border-radius:8px;"></div>
                <?php endif; ?>
                <span class="base-site-name"><?php echo esc_html($site_name); ?></span>
            </div>
            
            <!-- CONTROLS MOVED TO TOP RIGHT (Login) -->
            <div class="base-id-right">
                
                <!-- LOGIN -->
                <div style="position:relative;">
                    <button class="base-icon-btn" title="Login / Restore" onclick="document.getElementById('base-login-<?php echo $post_id; ?>').classList.toggle('active')">
                        <!-- User Icon -->
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="url(#<?php echo $grad_id; ?>)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </button>
                    <!-- Login Drawer (Top Positioned) -->
                    <div class="base-login-drawer" id="base-login-<?php echo $post_id; ?>">
                        <input type="email" id="base-res-email-<?php echo $post_id; ?>" class="base-login-input" placeholder="Purchased Email...">
                        <button class="base-login-go" onclick="baseRestore(<?php echo $post_id; ?>)">GO</button>
                    </div>
                </div>

            </div>
        </div>

        <!-- 2. VIDEO AREA -->
        <div class="base-video-area" id="base-area-<?php echo $post_id; ?>">
            <?php
                $bg_style = $poster_url 
                    ? 'background: url(' . esc_url($poster_url) . ') no-repeat center center / cover !important;' 
                    : 'background: linear-gradient(135deg, #F0F4FF 0%, #ffffff 100%) !important;';
            ?>

            <!-- Poster / Start Overlay -->
            <div class="base-poster-fallback" id="base-start-<?php echo $post_id; ?>" onclick="baseInitVideo(<?php echo $post_id; ?>)"
                 style="<?php echo $bg_style; ?>">
                <?php if (!$poster_url): ?>
                <div class="base-poster-brand">
                    <img src="<?php echo esc_url($site_icon); ?>" style="width: 60px; height: 60px; border-radius: 12px; margin-bottom: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);">
                    <div style="font-size: 24px; font-weight: 800; color: #111;"><?php echo esc_html($site_name); ?></div>
                </div>
                <?php endif; ?>
                <div class="base-poster-play-btn">
                    <svg viewBox="0 0 24 24" style="width: 100%; height: 100%; fill: #fff;"><path d="M8 5v14l11-7z"/></svg>
                </div>
            </div>

            <!-- Lock Screen -->
            <div class="base-lock-screen" id="base-lock-<?php echo $post_id; ?>">
                <div class="base-lock-icon">&#128274;</div>
                <div class="base-lock-msg">Make small USDC payment to continue watching.</div>
            </div>

            <video id="base-video-<?php echo $post_id; ?>" class="base-pv-video" 
                   data-src="<?php echo esc_url($video_url); ?>" 
                   preload="none"
                   controls controlsList="nodownload" oncontextmenu="return false;" playsinline x-webkit-airplay="allow"></video>
        </div>

        <!-- 3. ACCESS BAR (Bottom) - NOW ONLY FOR DOWNLOAD -->
        <?php if (!$disable_dl): ?>
            <div class="base-access-bar base-hidden" id="base-dl-bar-<?php echo $post_id; ?>" style="display:none !important;">
                <a href="<?php echo esc_url($video_url); ?>" download class="base-text-btn highlight" id="base-dl-btn-<?php echo $post_id; ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                    Download Video
                </a>
            </div>
        <?php endif; ?>

        <!-- 4. SECONDARY VIDEO FOOTER (The Minimalist Checkout) -->
        <div class="base-checkout-footer" id="base-checkout-footer-<?php echo $post_id; ?>">
            
            <div class="base-ft-wrapper">
                <!-- STEP 1: PROMPT (Minimalist Bar) -->
                <div id="base-ft-step-1-<?php echo $post_id; ?>" class="base-ft-step">
                    <div class="base-ft-bar">
                        <div class="base-ft-info">
                            <span class="base-ft-title">Unlock full video for <?php echo $display_price; ?></span>
                            <span class="base-ft-sub">One-time payment for lifetime access.</span>
                        </div>
                        <div class="base-ft-action">
                            <input type="email" id="base-email-<?php echo $post_id; ?>" class="base-ft-input" placeholder="Enter your email...">
                            <button class="base-ft-btn" onclick="baseInitPay(<?php echo $post_id; ?>)">
                                <?php echo $button_text; ?>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- STEP 2: PAYMENT (Compact Grid) -->
                <div id="base-ft-step-2-<?php echo $post_id; ?>" class="base-ft-step" style="display:none;">
                    <div class="base-ft-checkout">
                        
                        <!-- Row 1: Address (Full Width) -->
                        <div class="base-ft-address-row">
                            <div class="base-ft-row">
                                <span class="base-ft-label">To Address (Base/EVM)</span>
                                <span class="base-ft-val" id="base-wallet-<?php echo $post_id; ?>">...</span>
                                <span class="base-ft-copy" onclick="baseCopy('base-wallet-<?php echo $post_id; ?>')">COPY</span>
                            </div>
                        </div>

                        <!-- Row 2: QR & Amount (Split) -->
                        <div class="base-ft-split">
                            <!-- QR Code (Hidden on Mobile) -->
                            <div class="base-ft-qr" id="base-qr-<?php echo $post_id; ?>"></div>
                            
                            <!-- Details -->
                            <div class="base-ft-details">
                                <div class="base-ft-row">
                                    <span class="base-ft-label">Amount (USDC)</span>
                                    <span class="base-ft-val" id="base-amt-<?php echo $post_id; ?>">...</span>
                                    <span class="base-ft-copy" onclick="baseCopy('base-amt-<?php echo $post_id; ?>')">COPY</span>
                                </div>
                                <div style="font-size:11px; color:#666; padding:5px; background:#fafafa; border-radius:5px;">
                                    ⚠️ Please ensure you are sending <strong>USDC</strong> on the <strong>Base</strong> network.
                                </div>
                                <!-- Action Row -->
                                <div style="display:flex; gap:10px; margin-top:5px;">
                                    <button class="base-ft-btn" style="flex:1; justify-content:center;" onclick="baseVerify(<?php echo $post_id; ?>)">I Sent It</button>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- STEP 3: VERIFYING -->
                <div id="base-ft-step-3-<?php echo $post_id; ?>" class="base-ft-step base-ft-status" style="display:none;">
                    <div class="base-spinner"></div>
                    <div class="base-ft-title">Verifying Payment...</div>
                    <div class="base-ft-sub">Checking BaseScan. This takes a few seconds.</div>
                </div>

                <!-- STEP 4: SUCCESS -->
                <div id="base-ft-step-4-<?php echo $post_id; ?>" class="base-ft-step base-ft-status" style="display:none;">
                     <div style="font-size:30px; margin-bottom:10px;">&#10004;</div>
                     <div class="base-ft-title" style="color:var(--base-blue)!important;">Access Granted!</div>
                     <button class="base-ft-btn" style="margin-top:15px; margin-left:auto; margin-right:auto;" onclick="basePlayFull(<?php echo $post_id; ?>)">Play Video</button>
                </div>
            </div>
            
            <!-- Bottom Gradient Sliver -->
            <a href="https://www.base.org/" target="_blank" class="base-setup-bar">
                Powered by Base
            </a>

        </div>
    </div>

    <script>
    (function(){
        const pid = <?php echo $post_id; ?>;
        const v = document.getElementById('base-video-'+pid);
        const area = document.getElementById('base-area-'+pid);
        const wrapper = document.getElementById('base-wrapper-'+pid);
        const footer = document.getElementById('base-checkout-footer-'+pid);
        const lockScreen = document.getElementById('base-lock-'+pid);
        const limitSec = <?php echo floatval($limit_sec); ?>;
        let paid = false;
        let sessionId = '';
        let videoRatio = 16/9;
        
        v.addEventListener('loadedmetadata', function() {
            if (this.videoWidth && this.videoHeight) {
                videoRatio = this.videoWidth / this.videoHeight;
                area.style.aspectRatio = videoRatio;
            }
        });
        
        // -- VIDEO LOGIC --
        window.baseInitVideo = function(id) {
            const vid = document.getElementById('base-video-'+id);
            const overlay = document.getElementById('base-start-'+id);
            if(vid.getAttribute('data-src')) {
                vid.src = vid.getAttribute('data-src');
                vid.removeAttribute('data-src');
                vid.load();
            }
            overlay.style.setProperty('display', 'none', 'important');
            vid.play().catch(e => console.log('Autoplay prevented', e));
        };

        v.addEventListener('timeupdate', () => {
            // PAYWALL LOGIC
            if(!paid && v.currentTime > limitSec) {
                v.pause(); v.currentTime = limitSec;
                
                lockScreen.classList.add('active'); 
                footer.classList.add('active');
                wrapper.classList.add('payment-active');
                
                if (document.fullscreenElement) document.exitFullscreen();
            }
        });

        window.baseCopy = function(id) { 
            navigator.clipboard.writeText(document.getElementById(id).innerText); 
            alert('Copied!'); 
        };
        
        window.basePlayFull = function(id) { 
            paid = true; 
            lockScreen.classList.remove('active');
            footer.classList.remove('active'); 
            wrapper.classList.remove('payment-active');
            v.play(); 
        };

        function unlockUI() {
            paid = true;
            lockScreen.classList.remove('active');
            footer.classList.remove('active');
            wrapper.classList.remove('payment-active');
            
            const dlBar = document.getElementById('base-dl-bar-'+pid);
            if(dlBar) { 
                dlBar.classList.remove('base-hidden'); 
                dlBar.style.display = 'flex'; 
            }
            
            v.play();
        }

        // -- PAY LOGIC --
        window.baseInitPay = async function(id) {
            const email = document.getElementById('base-email-'+id).value;
            if(!email.includes('@')) return alert('Valid email required');
            try {
                const fd = new FormData(); fd.append('action', 'base_pv_init'); fd.append('post_id', id); fd.append('email', email);
                const req = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {method:'POST', body:fd});
                const res = await req.json();
                
                if(res.success) {
                    if (res.data.access_granted) { unlockUI(); return; }

                    if (res.data.is_free) {
                        paid = true;
                        document.getElementById('base-ft-step-1-'+id).style.display = 'none';
                        document.getElementById('base-ft-step-4-'+id).style.display = 'block';
                        
                        const dlBar = document.getElementById('base-dl-bar-'+id);
                        if(dlBar) { dlBar.classList.remove('base-hidden'); dlBar.style.display = 'flex'; }
                        return;
                    }

                    sessionId = res.data.session_id;
                    document.getElementById('base-amt-'+id).innerText = res.data.usdc_amount;
                    document.getElementById('base-wallet-'+id).innerText = res.data.wallet;
                    document.getElementById('base-qr-'+id).innerHTML = `<img src="${res.data.qr}" class="base-qr-img">`;
                    
                    document.getElementById('base-ft-step-1-'+id).style.display = 'none';
                    document.getElementById('base-ft-step-2-'+id).style.display = 'block';
                } else alert(res.data.message);
            } catch(e) { alert('Network error'); }
        };

        window.baseVerify = async function(id) {
            document.getElementById('base-ft-step-2-'+id).style.display = 'none';
            document.getElementById('base-ft-step-3-'+id).style.display = 'block';
            let attempts = 0;
            const interval = setInterval(async () => {
                attempts++;
                try {
                    const fd = new FormData(); fd.append('action', 'base_pv_verify'); fd.append('session_id', sessionId);
                    const req = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {method:'POST', body:fd});
                    const res = await req.json();
                    if(res.success && res.data.status === 'success') {
                        clearInterval(interval);
                        document.getElementById('base-ft-step-3-'+id).style.display = 'none';
                        document.getElementById('base-ft-step-4-'+id).style.display = 'block';
                        
                        const dlBar = document.getElementById('base-dl-bar-'+id);
                        if(dlBar) { dlBar.classList.remove('base-hidden'); dlBar.style.display = 'flex'; }
                        
                        paid = true;
                    } else if (attempts > 8) {
                        clearInterval(interval);
                        alert("Not detected yet. Try again.");
                        document.getElementById('base-ft-step-3-'+id).style.display = 'none';
                        document.getElementById('base-ft-step-2-'+id).style.display = 'block';
                    }
                } catch(e) { clearInterval(interval); }
            }, 5000);
        };

        window.baseRestore = async function(id) {
            const email = document.getElementById('base-res-email-'+id).value;
            try {
                const fd = new FormData(); fd.append('action', 'base_pv_restore'); fd.append('email', email); fd.append('post_id', id);
                const req = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {method:'POST', body:fd});
                const res = await req.json();
                if(res.success) {
                    unlockUI();
                    document.getElementById('base-login-'+id).classList.remove('active');
                    alert('Access Restored!');
                } else alert('No purchase found.');
            } catch(e) {}
        };
    })();
    </script>
    <?php
    return ob_get_clean();
}


// =============================================================================
// 5. AJAX LOGIC (BASE + USDC)
// =============================================================================

add_action('wp_ajax_base_pv_init', 'base_pv_init_ajax');
add_action('wp_ajax_nopriv_base_pv_init', 'base_pv_init_ajax');
function base_pv_init_ajax() {
    global $wpdb;
    $post_id = intval($_POST['post_id']);
    $email = sanitize_email($_POST['email']);
    $is_free = get_post_meta($post_id, '_base_pv_is_free_test', true);

    $existing_order = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}base_pv_orders WHERE email = %s AND post_id = %d AND order_status = 'success'", 
        $email, $post_id
    ));

    if ($existing_order) {
        wp_send_json_success(['access_granted' => true]);
        return;
    }

    if ($is_free) {
        $wpdb->insert($wpdb->prefix.'base_pv_orders', [
            'email' => $email, 
            'post_id' => $post_id, 
            'usdc_amount' => 0, 
            'usd_amount' => 0, 
            'order_status' => 'success', 
            'transaction_id' => 'free_test_' . time() . '_' . rand(100,999),
            'created_at' => current_time('mysql'), 
            'user_ip' => $_SERVER['REMOTE_ADDR']
        ]);
        
        $post_title = get_the_title($post_id);
        $post_url = get_permalink($post_id);
        base_pv_send_styled_email(
            $email,
            "Access Granted: $post_title",
            "Test Mode Access",
            [
                "You have been granted access to <strong>$post_title</strong> via Free Test Mode.",
                "To watch your video, simply visit the page below and enter your email address (<strong>{$email}</strong>) into the login box inside the player.",
                "Enjoy!"
            ],
            ['text' => 'Watch Now', 'url' => $post_url]
        );

        wp_send_json_success(['is_free' => true]);
        return;
    }

    $price_usd = get_post_meta($post_id, '_base_pv_price', true);
    $wallet = get_option('base_pv_wallet');
    if (!$wallet || !$price_usd) wp_send_json_error(['message' => 'Config Error']);
    
    // USDC is pegged 1:1 to USD
    $usdc_amount = floatval($price_usd);
    
    $session_id = wp_create_nonce('base_' . $post_id . '_' . time());
    $wpdb->insert($wpdb->prefix.'base_pv_orders', [
        'email' => $email, 
        'post_id' => $post_id, 
        'usdc_amount' => $usdc_amount, 
        'usd_amount' => $price_usd, 
        'order_status' => 'pending', 
        'created_at' => current_time('mysql'), 
        'user_ip' => $_SERVER['REMOTE_ADDR']
    ]);
    $order_id = $wpdb->insert_id;
    set_transient('base_sess_'.$session_id, ['order_id' => $order_id, 'post_id' => $post_id, 'usdc_amount' => $usdc_amount, 'email' => $email, 'wallet' => $wallet], HOUR_IN_SECONDS);
    
    $adm = get_option('base_pv_admin_email');
    $disable_adm_notify = get_option('base_pv_disable_admin_emails');
    if($adm && !$disable_adm_notify) {
        $post_title = get_the_title($post_id);
        base_pv_send_styled_email(
            $adm,
            "Checkout Attempt: $post_title",
            "Checkout Started",
            [
                "User <strong>$email</strong> has initiated checkout for:",
                "Video: <strong>$post_title</strong>",
                "Amount: <strong>$usdc_amount USDC</strong>",
                "Order ID: #$order_id"
            ]
        );
    }

    // Standard QR code (no specific Base URI scheme widely adopted, plain address is safest for EVM)
    $qr_code_url = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode($wallet);

    wp_send_json_success(['session_id' => $session_id, 'usdc_amount' => $usdc_amount, 'wallet' => $wallet, 'qr' => $qr_code_url]);
}

add_action('wp_ajax_base_pv_verify', 'base_pv_verify_ajax');
add_action('wp_ajax_nopriv_base_pv_verify', 'base_pv_verify_ajax');
function base_pv_verify_ajax() {
    global $wpdb;
    $sid = sanitize_text_field($_POST['session_id']);
    $data = get_transient('base_sess_'.$sid);
    if (!$data) wp_send_json_error(['status' => 'expired']);
    
    $wallet = $data['wallet'];
    $target_amount = floatval($data['usdc_amount']);
    
    // Slack calculation
    $slack = floatval(get_option('base_pv_slack_percentage', 2.0));
    $min_req = $target_amount * ((100 - $slack) / 100);

    // API Setup (BaseScan/Etherscan)
    $api_key = get_option('base_pv_apikey');
    $usdc_contract = get_option('base_pv_usdc_contract', '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913'); // Official Base USDC
    $chain_id = 8453; // Base Mainnet

    // Using the structure from crypto-shop.php
    $url = "https://api.etherscan.io/v2/api?chainid=$chain_id&module=account&action=tokentx&contractaddress=$usdc_contract&address=$wallet&page=1&offset=20&sort=desc&apikey=$api_key";
    
    $verified = false;
    $tx_hash = '';

    $res = wp_remote_get($url);
    if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) == 200) {
        $body = json_decode(wp_remote_retrieve_body($res), true);
        $txs = $body['result'] ?? [];
        
        if (is_array($txs)) {
            foreach ($txs as $tx) {
                // Check timestamp (within last 2 hours)
                if (time() - $tx['timeStamp'] > 7200) continue;
                
                // Check recipient
                if (strcasecmp($tx['to'], $wallet) !== 0) continue;
                
                // Check amount (USDC has 6 decimals, so divide by 1,000,000)
                $val = floatval($tx['value']) / 1000000;
                if ($val < $min_req) continue;
                
                // Check duplication
                if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}base_pv_orders WHERE transaction_id=%s AND order_status='success'", $tx['hash']))) continue;
                
                $verified = true;
                $tx_hash = $tx['hash'];
                break;
            }
        }
    }

    if ($verified) {
        $wpdb->update($wpdb->prefix.'base_pv_orders', ['order_status'=>'success', 'transaction_id'=>$tx_hash], ['id' => $data['order_id']]);
        
        $adm = get_option('base_pv_admin_email');
        $disable_adm_notify = get_option('base_pv_disable_admin_emails');
        if($adm && !$disable_adm_notify) { 
            $post_title = get_the_title($data['post_id']);
            base_pv_send_styled_email(
                $adm,
                "💰 Paid: $post_title",
                "Payment Confirmed",
                [
                    "A payment has been successfully confirmed on the Base blockchain!",
                    "User: <strong>{$data['email']}</strong>",
                    "Video: <strong>$post_title</strong>",
                    "Amount: <strong>{$target_amount} USDC</strong>",
                    "Transaction: <a href='https://basescan.org/tx/{$tx_hash}' style='color:#0052FF;'>View on BaseScan</a>"
                ]
            );
        }
        wp_send_json_success(['status'=>'success']);
    }

    wp_send_json_success(['status' => 'pending']);
}

add_action('wp_ajax_base_pv_restore', 'base_pv_restore_ajax');
add_action('wp_ajax_nopriv_base_pv_restore', 'base_pv_restore_ajax');
function base_pv_restore_ajax() {
    global $wpdb;
    $email = sanitize_email($_POST['email']);
    $post_id = intval($_POST['post_id']);
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}base_pv_orders WHERE email = %s AND post_id = %d AND order_status = 'success'", $email, $post_id));
    if ($exists) wp_send_json_success();
    else wp_send_json_error();
}

// --- ORDER LOOKUP AJAX ---
add_action('wp_ajax_base_pv_lookup_orders', 'base_pv_lookup_orders_ajax');
add_action('wp_ajax_nopriv_base_pv_lookup_orders', 'base_pv_lookup_orders_ajax');
function base_pv_lookup_orders_ajax() {
    global $wpdb;
    $email = sanitize_email($_POST['email']);
    if (!is_email($email)) wp_send_json_error();

    $post_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->prefix}base_pv_orders WHERE email = %s AND order_status = 'success' ORDER BY created_at DESC", 
        $email
    ));

    $results = [];
    if (!empty($post_ids)) {
        foreach ($post_ids as $pid) {
            $post = get_post($pid);
            if ($post && $post->post_status === 'publish') {
                $img = get_the_post_thumbnail_url($pid, 'medium_large');
                if (!$img) $img = 'https://via.placeholder.com/300x169/f0f0f0/cccccc?text=No+Image';
                
                $results[] = [
                    'id' => $pid,
                    'title' => $post->post_title,
                    'url' => get_permalink($pid),
                    'image' => $img
                ];
            }
        }
    }
    
    wp_send_json_success($results);
}
?>