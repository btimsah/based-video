<?php
/**
 * Plugin Name: Based Video Paywall
 * Plugin URI:  https://crypto-plugins.com
 * Description: Viewers watch a video preview, then pay USDC on Base to unlock the full stream. Powered by Moralis & BaseCore Logic.
 * Version:     3.1.3
 * Author:      Robert Timsah
 */

if (!defined('WPINC')) {
    die;
}

// =============================================================================
// 1. CORE CONSTANTS & ACTIVATION
// =============================================================================

define('BASE_PV_VERSION', '3.1.3');
define('BASE_PV_USDC_DEFAULT', '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913'); // Official Base USDC

function base_pv_activate() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'base_pv_orders';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        email varchar(255) NOT NULL,
        post_id bigint(20) UNSIGNED NOT NULL,
        addon_source varchar(50) DEFAULT 'video',
        transaction_id varchar(255) DEFAULT NULL,
        usdc_amount decimal(20,6) NOT NULL, /* 6 decimals for Unique Penny Logic */
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
// 2. INFRASTRUCTURE HELPERS (MORALIS & RESERVATION)
// =============================================================================

/**
 * Helper: Smart API Fetcher (Moralis)
 */
function base_pv_fetch_moralis($endpoint, $params = []) {
    $api_key = get_option('base_pv_moralis_key');
    if(!$api_key) return new WP_Error('no_key', 'API Key Missing');

    $url = "https://deep-index.moralis.io/api/v2.2/" . ltrim($endpoint, '/');
    if(!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $args = [
        'headers' => [
            'accept' => 'application/json',
            'X-API-Key' => $api_key
        ],
        'timeout' => 20
    ];

    $res = wp_remote_get($url, $args);
    
    if(is_wp_error($res)) return $res;
    
    $code = wp_remote_retrieve_response_code($res);
    $body = json_decode(wp_remote_retrieve_body($res), true);
    
    if($code !== 200) {
        return new WP_Error('moralis_error', $body['message'] ?? 'Unknown Moralis Error');
    }
    
    return $body;
}

// Helper: Check Connection
function base_pv_check_moralis() {
    // Ping Moralis for Base Block Date (Lightweight check)
    $res = base_pv_fetch_moralis('dateToBlock', [
        'chain' => 'base',
        'date' => date('Y-m-d')
    ]);
    
    return !is_wp_error($res) && isset($res['block']);
}

/**
 * Helper: Reserve Unique Amount (The "Library Checkout" System)
 * Generates a unique 6-decimal amount and immediately RESERVES it in DB.
 * Also performs garbage collection on old pending orders.
 */
function base_pv_reserve_unique_amount($base_price_usd, $source = 'video', $email = '', $post_id = 0) {
    global $wpdb;
    
    // 1. Garbage Collection: Release amounts held by pending orders older than 1 hour
    $wpdb->query("DELETE FROM {$wpdb->prefix}base_pv_orders WHERE order_status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    
    $max_attempts = 50;
    
    for ($i = 0; $i < $max_attempts; $i++) {
        // Generate random 6-decimal offset (0.000001 to 0.009999)
        $micro_cents = mt_rand(1, 9999);
        $candidate_amount = floatval($base_price_usd) + ($micro_cents / 1000000);
        $formatted_amount = number_format($candidate_amount, 6, '.', '');
        
        // Check if this specific amount is currently "Checked Out" (Pending)
        $collision = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}base_pv_orders WHERE usdc_amount = %s AND order_status = 'pending'",
            $formatted_amount
        ));
        
        if (!$collision) {
            // Available! "Check it out" immediately.
            $inserted = $wpdb->insert($wpdb->prefix.'base_pv_orders', [
                'email' => $email,
                'post_id' => $post_id,
                'addon_source' => $source,
                'usdc_amount' => $formatted_amount,
                'usd_amount' => $base_price_usd,
                'order_status' => 'pending',
                'created_at' => current_time('mysql'),
                'user_ip' => $_SERVER['REMOTE_ADDR']
            ]);
            
            if ($inserted) {
                return [
                    'order_id' => $wpdb->insert_id,
                    'amount' => $formatted_amount
                ];
            }
        }
    }
    
    return false;
}

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

// =============================================================================
// 3. ADMIN MENU & SETTINGS
// =============================================================================

function base_pv_admin_menu() {
    add_menu_page('Based Video', 'Based Video', 'manage_options', 'base-pv-main', 'base_pv_settings_page', 'dashicons-video-alt3', 20);
    add_submenu_page('base-pv-main', 'Settings', 'Settings', 'manage_options', 'base-pv-main', 'base_pv_settings_page');
    add_submenu_page('base-pv-main', 'Orders', 'Orders', 'manage_options', 'base-pv-orders', 'base_pv_orders_page_html');
}
add_action('admin_menu', 'base_pv_admin_menu');

function base_pv_register_settings() {
    register_setting('base_pv_group', 'base_pv_moralis_key');
    register_setting('base_pv_group', 'base_pv_wallet');
    register_setting('base_pv_group', 'base_pv_usdc_contract');
    register_setting('base_pv_group', 'base_pv_admin_email');
    register_setting('base_pv_group', 'base_pv_disable_admin_emails');
    register_setting('base_pv_group', 'base_pv_support_url'); 
}
add_action('admin_init', 'base_pv_register_settings');

// --- ADMIN ACTION HANDLER (DELETE/MARK PAID) ---
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


// Add Admin CSS for Dashboard
function base_pv_admin_head() {
    $screen = get_current_screen();
    if (strpos($screen->id, 'base-pv') !== false) {
        ?>
        <style>
            /* BaseCore / Coinbase Blue Theme */
            @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
            .base-core-wrap { font-family: 'Inter', sans-serif; color: #0A0B0D; max-width: 1200px; margin: 20px 20px 0 0; }
            .base-header { background: #fff; padding: 32px; border-radius: 12px; border: 1px solid #E2E8F0; border-top: 4px solid #0052FF; box-shadow: 0 2px 4px rgba(0,0,0,0.02); margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; }
            .base-header h1 { margin: 0 0 8px 0; color: #0A0B0D; font-weight: 700; font-size: 24px; }
            .base-version { font-size: 11px; font-weight: 600; background: #EEF2FF; color: #0052FF; padding: 4px 8px; border-radius: 100px; margin-left: 8px; }
            .base-grid { display: flex; gap: 24px; flex-wrap: wrap; }
            .base-col-main { flex: 2; min-width: 450px; }
            .base-col-side { flex: 1; min-width: 280px; }
            .base-card { background: #fff; border: 1px solid #E2E8F0; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
            .base-card-header { display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #E2E8F0; padding-bottom: 16px; margin-bottom: 20px; }
            .base-card-header h3 { margin: 0; font-size: 16px; font-weight: 600; color: #0A0B0D; }
            .base-card-header .dashicons { font-size: 20px; color: #0052FF; }
            .base-status-row { display: flex; gap: 24px; }
            .base-status-item { flex: 1; background: #F4F6F8; padding: 20px; border-radius: 8px; text-align: center; }
            .base-label { display: block; font-size: 11px; text-transform: uppercase; font-weight: 700; color: #5B616E; margin-bottom: 8px; }
            .base-indicator { font-weight: 600; font-size: 14px; margin-bottom: 16px; display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 100px; background: #E5E7EB; color: #374151; }
            .base-indicator.success { background: #D1FAE5; color: #065F46; }
            .base-indicator.error { background: #FEE2E2; color: #991B1B; }
            .base-indicator.loading { background: #DBEAFE; color: #1E40AF; }
            button.button-small { background: #fff!important; border: 1px solid #D1D5DB!important; color: #374151!important; border-radius: 6px!important; font-weight: 500!important; padding: 4px 12px!important; }
            button.button-small:hover { border-color: #0052FF!important; color: #0052FF!important; background: #F5F8FF!important; }
            .base-form-row { margin-bottom: 20px; }
            .base-form-row label { display: block; font-weight: 500; font-size: 13px; margin-bottom: 8px; color: #0A0B0D; }
            .base-form-row input[type="text"], .base-form-row input[type="password"], .base-form-row input[type="email"] { width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid #E2E8F0; }
            .base-form-row input:focus { border-color: #0052FF; box-shadow: 0 0 0 1px #0052FF; outline: none; }
            .wp-core-ui .button-primary { background: #0052FF!important; border-color: #0052FF!important; border-radius: 8px!important; padding: 6px 20px!important; font-weight: 600!important; }
            .wp-core-ui .button-primary:hover { background: #0040CC!important; }
            .dashicons.spin { animation: dashicons-spin 1s infinite; }
            @keyframes dashicons-spin { 0% { transform: translateY(-50%) rotate(0deg); } 100% { transform: translateY(-50%) rotate(360deg); } }
        </style>
        <?php
    }
}
add_action('admin_head', 'base_pv_admin_head');

function base_pv_settings_page() {
    $wallet = get_option('base_pv_wallet');
    $configured = !empty($wallet) && !empty(get_option('base_pv_moralis_key'));
    ?>
    <div class="wrap base-core-wrap">
        <div class="base-header">
            <h1>Based Video Paywall <span class="base-version">v<?php echo BASE_PV_VERSION; ?></span></h1>
            <p>Monetize video content with USDC on Base.</p>
        </div>

        <div class="base-grid">
            <!-- LEFT COLUMN -->
            <div class="base-col-main">
                
                <!-- STATUS CARD -->
                <div class="base-card base-status-card">
                    <h2>System Status</h2>
                    <div class="base-status-row">
                        <div class="base-status-item">
                            <span class="base-label">Moralis API (Base)</span>
                            <div class="base-indicator" id="base-status-moralis"><span class="dashicons dashicons-minus"></span> Idle</div>
                            <button type="button" class="button button-small" id="btn-test-moralis">Test Connection</button>
                        </div>
                    </div>
                </div>

                <!-- DIAGNOSTIC CARD -->
                <div class="base-card">
                    <div class="base-card-header">
                        <span class="dashicons dashicons-hammer"></span>
                        <h3>Payment System Diagnostic</h3>
                    </div>
                    <?php if($configured): ?>
                        <div style="background: #F9FAFB; padding: 25px; border-radius: 8px; border: 1px solid #E5E7EB; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 20px;">
                            <div style="flex: 1;">
                                <p style="margin: 0 0 15px; font-size: 13px; line-height: 1.5; color: #374151;">
                                    <strong>Test Flow:</strong> Send the <strong>EXACT</strong> amount below (USDC on Base).
                                    <br>This amount is uniquely reserved in the database to verify the loop.
                                </p>
                                <div style="display: flex; gap: 10px; align-items: flex-end; margin-bottom: 15px;">
                                    <div style="flex: 1;">
                                        <label style="display: block; font-weight: 600; margin-bottom: 5px; font-size: 12px; color: #4B5563;">Send Exact (USDC)</label>
                                        <div style="position: relative;">
                                            <input type="text" id="test-amount-display" readonly style="width: 100%; background: #fff; font-family: 'Monaco', monospace; color: #0052FF; font-weight: bold; font-size: 16px; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px;">
                                            <button type="button" id="btn-refresh-amount" style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); border: none; background: transparent; cursor: pointer; color: #6B7280;" title="New Amount">
                                                <span class="dashicons dashicons-update"></span>
                                            </button>
                                        </div>
                                    </div>
                                    <div style="flex: 2;">
                                        <label style="display: block; font-weight: 600; margin-bottom: 5px; font-size: 12px; color: #4B5563;">To Wallet</label>
                                        <input type="text" value="<?php echo esc_attr($wallet); ?>" readonly style="width: 100%; background: #F3F4F6; font-family: 'Monaco', monospace; color: #666; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 13px;" onclick="this.select();">
                                    </div>
                                </div>
                                <button type="button" class="button button-primary" id="btn-verify-test" style="width: 100%; margin-bottom: 10px;">I've Sent Payment</button>
                                <div id="test-result" style="font-weight: 600; font-size: 13px; text-align: center; min-height: 20px;"></div>
                            </div>
                            <div style="flex: 0 0 auto; text-align: center;">
                                <div style="background: #fff; padding: 10px; border: 1px solid #E5E7EB; border-radius: 8px;">
                                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=<?php echo esc_attr($wallet); ?>" style="display: block; width: 140px; height: 140px; border-radius: 4px;">
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="description" style="color: #d63638;">Configure Moralis API Key and Wallet below to enable testing.</p>
                    <?php endif; ?>
                </div>

                <form method="post" action="options.php">
                    <?php settings_fields('base_pv_group'); do_settings_sections('base_pv_group'); ?>
                    
                    <div class="base-card">
                        <div class="base-card-header">
                            <span class="dashicons dashicons-money"></span>
                            <h3>Base (USDC) Configuration</h3>
                        </div>
                        <div class="base-form-row">
                            <label>Moralis API Key</label>
                            <input type="password" name="base_pv_moralis_key" value="<?php echo esc_attr(get_option('base_pv_moralis_key')); ?>" />
                            <p class="description">Get a free key at <a href="https://admin.moralis.io/" target="_blank">admin.moralis.io</a>.</p>
                        </div>
                        <div class="base-form-row">
                            <label>Receiving Wallet (EVM)</label>
                            <input type="text" name="base_pv_wallet" value="<?php echo esc_attr(get_option('base_pv_wallet')); ?>" placeholder="0x..." />
                        </div>
                        <div class="base-form-row">
                            <label>USDC Contract Address</label>
                            <input type="text" name="base_pv_usdc_contract" value="<?php echo esc_attr(get_option('base_pv_usdc_contract', BASE_PV_USDC_DEFAULT)); ?>" />
                            <p class="description">Default: <code><?php echo BASE_PV_USDC_DEFAULT; ?></code></p>
                        </div>
                        <div class="base-form-row">
                            <label>Admin Email</label>
                            <input type="email" name="base_pv_admin_email" value="<?php echo esc_attr(get_option('base_pv_admin_email')); ?>" />
                            <label style="margin-top:10px; font-weight:normal;">
                                <input type="checkbox" name="base_pv_disable_admin_emails" value="1" <?php checked(get_option('base_pv_disable_admin_emails'), '1'); ?> /> Disable admin notifications
                            </label>
                        </div>
                        <div class="base-form-row">
                            <label>Support URL</label>
                            <input type="url" name="base_pv_support_url" value="<?php echo esc_attr(get_option('base_pv_support_url')); ?>" />
                        </div>
                    </div>
                    <?php submit_button('Save Settings'); ?>
                </form>
            </div>

            <!-- RIGHT COLUMN -->
            <div class="base-col-side">
                <div class="base-card">
                    <h3>Guide</h3>
                    <p>1. <strong>Upload</strong> video via the Post Meta box.</p>
                    <p>2. <strong>Set Price</strong> in USD.</p>
                    <p>3. <strong>Embed</strong> using <code>[base_video_paywall]</code>.</p>
                    <p style="margin-top:15px; font-size:12px; color:#666;">Users pay a unique fractional USDC amount. Verification is automatic via Moralis.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- ADMIN JS -->
    <script>
    jQuery(document).ready(function($) {
        // Test Connection
        $('#btn-test-moralis').on('click', function(e) {
            e.preventDefault();
            var $el = $('#base-status-moralis');
            $el.attr('class', 'base-indicator loading').html('<span class="dashicons dashicons-update"></span> Testing...');
            $.post(ajaxurl, { action: 'base_pv_test_moralis' }, function(res) {
                if(res.success) $el.attr('class', 'base-indicator success').html('<span class="dashicons dashicons-yes"></span> Connected');
                else $el.attr('class', 'base-indicator error').html('Failed');
            });
        });

        // Test Transaction Logic
        function generateTestAmount() {
            $.post(ajaxurl, { action: 'base_pv_get_test_amount' }, function(res) {
                if(res.success) $('#test-amount-display').val(res.data.amount);
            });
        }
        if($('#test-amount-display').length) generateTestAmount();
        
        $('#btn-refresh-amount').on('click', function(e) {
            e.preventDefault();
            $(this).find('.dashicons').addClass('spin');
            generateTestAmount();
            setTimeout(() => $(this).find('.dashicons').removeClass('spin'), 500);
        });

        var testAttempts = 0;
        $('#btn-verify-test').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $res = $('#test-result');
            var targetAmount = $('#test-amount-display').val();
            
            if(testAttempts >= 5) { $btn.prop('disabled', true).text('Limit Reached'); return; }
            testAttempts++;
            $btn.prop('disabled', true);
            
            var timeLeft = 20;
            var timer = setInterval(function() {
                $btn.text('Checking in ' + timeLeft + 's...');
                timeLeft--;
                if(timeLeft < 0) {
                    clearInterval(timer);
                    $btn.text("Checking Blockchain...");
                    $.post(ajaxurl, { action: 'base_pv_test_transaction', amount: targetAmount }, function(res) {
                        $btn.prop('disabled', false).text("I've Sent Payment");
                        if(res.success) {
                            $res.css('color', '#00a32a').html(res.data.msg);
                            if(!res.data.already_verified) setTimeout(generateTestAmount, 3000);
                        } else {
                            $res.css('color', '#d63638').html(res.data);
                        }
                    });
                }
            }, 1000);
        });
    });
    </script>
    <?php
}

function base_pv_orders_page_html() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'base_pv_orders';
    $orders = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
    if (isset($_GET['base_msg'])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(urldecode($_GET['base_msg'])) . '</p></div>';
    ?>
    <div class="wrap">
        <h1>Orders</h1>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th>Date</th><th>Status</th><th>Email</th><th>USD</th><th>USDC</th><th>TX ID</th><th>Actions</th></tr></thead>
            <tbody>
                <?php if (!$orders): ?><tr><td colspan="7">No orders.</td></tr><?php endif; ?>
                <?php foreach($orders as $o): ?>
                <tr>
                    <td><?php echo $o->created_at; ?></td>
                    <td style="color:<?php echo $o->order_status=='success'?'#00a32a':'orange';?>"><strong><?php echo strtoupper($o->order_status); ?></strong></td>
                    <td><?php echo esc_html($o->email); ?></td>
                    <td>$<?php echo $o->usd_amount; ?></td>
                    <td><?php echo $o->usdc_amount; ?></td>
                    <td><small><?php echo substr($o->transaction_id,0,8).'...'; ?></small></td>
                    <td>
                        <?php $url = admin_url('admin.php?page=base-pv-orders&order_id=' . $o->id . '&_wpnonce=' . wp_create_nonce('base_pv_action_' . $o->id)); ?>
                        <?php if ($o->order_status !== 'success') : ?><a href="<?php echo esc_url($url . '&base_action=mark_success'); ?>" class="button button-small button-primary">Mark Paid</a><?php endif; ?>
                        <a href="<?php echo esc_url($url . '&base_action=delete'); ?>" class="button button-small" style="color:red;border-color:#ef4444;">Delete</a>
                    </td>
                </tr>
                <?php endforeach; ?>
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
    $max_width = get_post_meta($post->ID, '_base_pv_max_width', true) ?: '800';
    ?>
    
    <div style="background:#eef2ff; border:1px solid #0052FF; padding:10px; border-radius:5px; margin-bottom:15px; text-align:center;">
        <strong>Shortcode:</strong><br>
        <code style="display:block; margin-top:5px; user-select:all;">[base_video_paywall id="<?php echo $post->ID; ?>"]</code>
    </div>

    <p><label><input type="checkbox" name="base_pv_enabled" value="1" <?php checked($enabled, '1'); ?> /> Enable Premium Video</label></p>
    <p><label><input type="checkbox" name="base_pv_is_free_test" value="1" <?php checked($is_free, '1'); ?> /> Free Test Mode</label></p>
    <p><label><input type="checkbox" name="base_pv_disable_download" value="1" <?php checked($disable_dl, '1'); ?> /> Disable Download</label></p>
    
    <p>
        <label><strong>Video URL:</strong></label><br>
        <input type="text" name="base_pv_video_url" id="base_pv_video_url" value="<?php echo esc_attr($video_url); ?>" style="width:100%; margin-bottom:5px;" />
        <button type="button" class="button base-upload-btn" data-target="#base_pv_video_url">Browse/Upload</button>
    </p>
    
    <p>
        <label><strong>Poster Image:</strong></label><br>
        <input type="text" name="base_pv_video_poster" id="base_pv_video_poster" value="<?php echo esc_attr($video_poster); ?>" style="width:100%; margin-bottom:5px;" />
        <button type="button" class="button base-upload-btn" data-target="#base_pv_video_poster">Browse/Upload</button>
    </p>

    <p><label>Max Width (px):</label><br><input type="number" name="base_pv_max_width" value="<?php echo esc_attr($max_width); ?>" style="width:100px;" /></p>
    <p><label>Price (USD):</label><br><input type="number" step="0.01" name="base_pv_price" value="<?php echo esc_attr($price); ?>" style="width:100%;" /></p>
    <p><label>Preview (Min):</label><br><input type="number" step="0.1" name="base_pv_limit_min" value="<?php echo esc_attr($limit); ?>" style="width:100%;" /></p>
    
    <script>
    jQuery(document).ready(function($){
        $('.base-upload-btn').click(function(e) {
            e.preventDefault();
            var t = $($(this).data('target'));
            var u = wp.media({title:'Select File',button:{text:'Use'},multiple:false}).on('select', function() {
                t.val(u.state().get('selection').first().toJSON().url);
            }).open();
        });
    });
    </script>
    <?php
}

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
    if (isset($_POST['base_pv_limit_min'])) update_post_meta($post_id, '_base_pv_limit_min', floatval($_POST['base_pv_limit_min']));
}
add_action('save_post', 'base_pv_save_meta');

// =============================================================================
// 4. FRONTEND
// =============================================================================

function base_pv_enqueue_assets() { 
    global $post;
    if ((is_singular() && has_shortcode($post->post_content, 'base_video_paywall')) || has_shortcode($post->post_content, 'base_pv_order_lookup')) {
        wp_enqueue_style('base-pv-styles', plugin_dir_url(__FILE__) . 'style.css', [], '3.0.0'); 
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
    </div>
    <script>
    async function baseLookupOrders() {
        const email = document.getElementById('base-lookup-email').value;
        const resDiv = document.getElementById('base-lookup-results');
        if(!email.includes('@')) return alert('Please enter a valid email.');
        resDiv.innerHTML = '<p style="text-align:center;">Searching...</p>';
        try {
            const fd = new FormData(); fd.append('action', 'base_pv_lookup_orders'); fd.append('email', email);
            const req = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {method: 'POST', body: fd});
            const res = await req.json();
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
                        <div class="base-card-footer"><span class="base-card-action">Play Video &rarr;</span></div>
                    </a>`;
                });
                resDiv.innerHTML = html;
            } else resDiv.innerHTML = '<p style="text-align:center;">No purchases found.</p>';
        } catch(e) { alert('Error connecting.'); }
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
            
            <div class="base-ft-wrapper" id="base-ft-main-<?php echo $post_id; ?>">
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
                    <div class="base-ft-sub">Checking Blockchain... This takes a few seconds.</div>
                </div>

                <!-- STEP 4: SUCCESS -->
                <div id="base-ft-step-4-<?php echo $post_id; ?>" class="base-ft-step base-ft-status" style="display:none;">
                     <div style="font-size:30px; margin-bottom:10px;">&#10004;</div>
                     <div class="base-ft-title" style="color:var(--base-blue)!important;">Access Granted!</div>
                     <button class="base-ft-btn" style="margin-top:15px; margin-left:auto; margin-right:auto;" onclick="basePlayFull(<?php echo $post_id; ?>)">Play Video</button>
                </div>
            </div>
            
            <!-- Bottom Gradient Sliver -->
            <a href="https://www.coinbase.com/" target="_blank" class="base-setup-bar">
                Buy USDC On Coinbase
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
            
            // HIDE THE CHECKOUT WRAPPER TO CLOSE THE GAP
            const ftMain = document.getElementById('base-ft-main-'+pid);
            if(ftMain) ftMain.style.display = 'none';
        };

        function unlockUI() {
            paid = true;
            lockScreen.classList.remove('active');
            footer.classList.remove('active');
            wrapper.classList.remove('payment-active');
            
            // HIDE THE CHECKOUT WRAPPER TO CLOSE THE GAP
            const ftMain = document.getElementById('base-ft-main-'+pid);
            if(ftMain) ftMain.style.display = 'none';
            
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
            
            // Visual feedback
            const btn = document.querySelector('#base-ft-step-1-'+id+' .base-ft-btn');
            const originalText = btn.innerHTML;
            btn.innerHTML = '...';
            
            try {
                const fd = new FormData(); fd.append('action', 'base_pv_init'); fd.append('post_id', id); fd.append('email', email);
                const req = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {method:'POST', body:fd});
                const res = await req.json();
                
                btn.innerHTML = originalText;

                if(res.success) {
                    // IMMEDIATE ACCESS LOGIC
                    if (res.data.is_free || res.data.access_granted) {
                        alert('Welcome back! Access Restored.');
                        unlockUI(); // Use unlockUI to ensure gap is closed
                        return;
                    }

                    sessionId = res.data.session_id;
                    document.getElementById('base-amt-'+id).innerText = res.data.usdc_amount;
                    document.getElementById('base-wallet-'+id).innerText = res.data.wallet;
                    document.getElementById('base-qr-'+id).innerHTML = `<img src="${res.data.qr}" class="base-qr-img">`;
                    
                    document.getElementById('base-ft-step-1-'+id).style.display = 'none';
                    document.getElementById('base-ft-step-2-'+id).style.display = 'block';
                } else alert(res.data.message);
            } catch(e) { 
                btn.innerHTML = originalText;
                alert('Network error'); 
            }
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
                        
                        // Immediately unlock and play instead of waiting for user click
                        // BUT user might want to see success message briefly?
                        // User asked for immediate play on restore, but for payment, seeing the checkmark is good UX.
                        // I will leave the "Play Video" button for fresh payments as it confirms success visually.
                        
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
                    alert('Access Restored!');
                    unlockUI(); // Ensures gap closes
                    document.getElementById('base-login-'+id).classList.remove('active');
                } else alert('No purchase found.');
            } catch(e) {}
        };
    })();
    </script>
    <?php
    return ob_get_clean();
}


// =============================================================================
// 5. AJAX LOGIC (BASE + USDC + MORALIS)
// =============================================================================

add_action('wp_ajax_base_pv_test_moralis', 'base_pv_test_moralis_ajax');
function base_pv_test_moralis_ajax() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    if(base_pv_check_moralis()) wp_send_json_success();
    else wp_send_json_error();
}

add_action('wp_ajax_base_pv_get_test_amount', 'base_pv_get_test_amount_ajax');
function base_pv_get_test_amount_ajax() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    $reservation = base_pv_reserve_unique_amount(1.21, 'admin_test', wp_get_current_user()->user_email);
    if($reservation) wp_send_json_success(['amount' => $reservation['amount']]);
    else wp_send_json_error();
}

add_action('wp_ajax_base_pv_test_transaction', 'base_pv_test_transaction_ajax');
function base_pv_test_transaction_ajax() {
    global $wpdb;
    if (!current_user_can('manage_options')) wp_send_json_error();
    $wallet = get_option('base_pv_wallet');
    $usdc = get_option('base_pv_usdc_contract', BASE_PV_USDC_DEFAULT);
    $amt = floatval($_POST['amount']);
    
    $res = base_pv_fetch_moralis("{$wallet}/erc20/transfers", ['chain'=>'base','contract_addresses'=>[$usdc],'order'=>'DESC','limit'=>50]);
    if(is_wp_error($res)) wp_send_json_error($res->get_error_message());
    
    foreach(($res['result']??[]) as $tx) {
        $val = $tx['value'];
        $dec = intval($tx['token_decimals']);
        $target = number_format($amt * pow(10, $dec), 0, '', '');
        
        if($val === $target) {
            if(time() - strtotime($tx['block_timestamp']) > 7200) continue;
            
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}base_pv_orders WHERE transaction_id=%s", $tx['transaction_hash']));
            if($exists) { wp_send_json_success(['msg'=>'Found (Already Verified)', 'already_verified'=>true]); return; }
            
            $wpdb->insert($wpdb->prefix.'base_pv_orders', [
                'email'=>wp_get_current_user()->user_email, 'post_id'=>0, 'addon_source'=>'admin_test',
                'transaction_id'=>$tx['transaction_hash'], 'usdc_amount'=>$amt, 'usd_amount'=>$amt, 'order_status'=>'success',
                'created_at'=>current_time('mysql'), 'user_ip'=>$_SERVER['REMOTE_ADDR']
            ]);
            wp_send_json_success(['msg'=>'Success! Verified.', 'already_verified'=>false]); return;
        }
    }
    wp_send_json_error('Not found.');
}

// FRONTEND INIT
add_action('wp_ajax_base_pv_init', 'base_pv_init_ajax');
add_action('wp_ajax_nopriv_base_pv_init', 'base_pv_init_ajax');
function base_pv_init_ajax() {
    global $wpdb;
    $post_id = intval($_POST['post_id']);
    $email = sanitize_email($_POST['email']);
    
    if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}base_pv_orders WHERE email=%s AND post_id=%d AND order_status='success'", $email, $post_id))) {
        wp_send_json_success(['access_granted'=>true]); return;
    }

    if (get_post_meta($post_id, '_base_pv_is_free_test', true)) {
        $wpdb->insert($wpdb->prefix.'base_pv_orders', ['email'=>$email, 'post_id'=>$post_id, 'usdc_amount'=>0, 'usd_amount'=>0, 'order_status'=>'success', 'transaction_id'=>'free_'.time(), 'created_at'=>current_time('mysql')]);
        wp_send_json_success(['is_free'=>true]); return;
    }

    $price = get_post_meta($post_id, '_base_pv_price', true);
    $res = base_pv_reserve_unique_amount($price, 'video', $email, $post_id);
    if(!$res) wp_send_json_error(['message'=>'System busy']);
    
    $sess = wp_create_nonce('base_'.$post_id.'_'.time());
    set_transient('base_sess_'.$sess, ['order_id'=>$res['order_id'], 'post_id'=>$post_id, 'usdc_amount'=>$res['amount'], 'wallet'=>get_option('base_pv_wallet')], HOUR_IN_SECONDS);
    
    $qr = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data='.urlencode(get_option('base_pv_wallet'));
    wp_send_json_success(['session_id'=>$sess, 'usdc_amount'=>$res['amount'], 'wallet'=>get_option('base_pv_wallet'), 'qr'=>$qr]);
}

// FRONTEND VERIFY
add_action('wp_ajax_base_pv_verify', 'base_pv_verify_ajax');
add_action('wp_ajax_nopriv_base_pv_verify', 'base_pv_verify_ajax');
function base_pv_verify_ajax() {
    global $wpdb;
    $data = get_transient('base_sess_'.sanitize_text_field($_POST['session_id']));
    if(!$data) wp_send_json_error(['status'=>'expired']);
    
    $wallet = $data['wallet'];
    $amt = $data['usdc_amount'];
    $usdc = get_option('base_pv_usdc_contract', BASE_PV_USDC_DEFAULT);
    
    $res = base_pv_fetch_moralis("{$wallet}/erc20/transfers", ['chain'=>'base','contract_addresses'=>[$usdc],'order'=>'DESC','limit'=>50]);
    if(is_wp_error($res)) wp_send_json_error();
    
    foreach(($res['result']??[]) as $tx) {
        $val = $tx['value'];
        $dec = intval($tx['token_decimals']);
        $target = number_format($amt * pow(10, $dec), 0, '', '');
        
        if($val === $target) {
            if(time() - strtotime($tx['block_timestamp']) > 7200) continue;
            if($wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}base_pv_orders WHERE transaction_id=%s AND order_status='success'", $tx['transaction_hash']))) continue;
            
            $wpdb->update($wpdb->prefix.'base_pv_orders', ['order_status'=>'success', 'transaction_id'=>$tx['transaction_hash']], ['id'=>$data['order_id']]);
            // Email User
            $post_title = get_the_title($data['post_id']);
            $post_url = get_permalink($data['post_id']);
            // Re-using the simple email function for user
            base_pv_send_styled_email($wpdb->get_var($wpdb->prepare("SELECT email FROM {$wpdb->prefix}base_pv_orders WHERE id=%d", $data['order_id'])), "Access Granted", "Video Unlocked", ["You can now watch: $post_title"], ['text'=>'Watch', 'url'=>$post_url]);
            
            wp_send_json_success(['status'=>'success']); return;
        }
    }
    wp_send_json_success(['status'=>'pending']);
}

add_action('wp_ajax_base_pv_restore', 'base_pv_restore_ajax');
add_action('wp_ajax_nopriv_base_pv_restore', 'base_pv_restore_ajax');
function base_pv_restore_ajax() {
    global $wpdb;
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}base_pv_orders WHERE email=%s AND post_id=%d AND order_status='success'", $_POST['email'], $_POST['post_id']));
    if($exists) wp_send_json_success(); else wp_send_json_error();
}

// LOOKUP
add_action('wp_ajax_base_pv_lookup_orders', 'base_pv_lookup_orders_ajax');
add_action('wp_ajax_nopriv_base_pv_lookup_orders', 'base_pv_lookup_orders_ajax');
function base_pv_lookup_orders_ajax() {
    global $wpdb;
    $email = sanitize_email($_POST['email']);
    
    $post_ids = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->prefix}base_pv_orders WHERE email=%s AND order_status='success' ORDER BY created_at DESC", $email));
    $results = [];
    foreach($post_ids as $pid) {
        if(get_post_status($pid)==='publish') {
            $img = get_the_post_thumbnail_url($pid, 'medium_large');
            if (!$img) $img = 'https://via.placeholder.com/300x169/f0f0f0/cccccc?text=No+Image';
            
            $results[] = [
                'id'=>$pid, 
                'title'=>get_the_title($pid), 
                'url'=>get_permalink($pid), 
                'image'=>$img
            ];
        }
    }
    wp_send_json_success($results);
}
?>