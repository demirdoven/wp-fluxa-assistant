<?php
/**
 * Handles admin menu registration and callbacks
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Fluxa_Admin_Menu {
    /**
     * Initialize the class
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menus'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Add admin menus
     */
    public function add_admin_menus() {
        // Main menu
        add_menu_page(
            __('Fluxa eCommerce Assistant', 'fluxa-ecommerce-assistant'),
            __('Fluxa Assistant', 'fluxa-ecommerce-assistant'),
            'manage_options',
            'fluxa-assistant',
            array($this, 'render_dashboard_page'),
            'dashicons-format-chat',
            30
        );

        // Dashboard submenu
        add_submenu_page(
            'fluxa-assistant',
            __('Dashboard - Fluxa eCommerce Assistant', 'fluxa-ecommerce-assistant'),
            __('Dashboard', 'fluxa-ecommerce-assistant'),
            'manage_options',
            'fluxa-assistant',
            array($this, 'render_dashboard_page')
        );

        // Settings submenu
        add_submenu_page(
            'fluxa-assistant',
            __('Settings - Fluxa eCommerce Assistant', 'fluxa-ecommerce-assistant'),
            __('Settings', 'fluxa-ecommerce-assistant'),
            'manage_options',
            'fluxa-assistant-settings',
            array($this, 'render_settings_page')
        );

        // Training submenu
        add_submenu_page(
            'fluxa-assistant',
            __('Training - Fluxa eCommerce Assistant', 'fluxa-ecommerce-assistant'),
            __('Training', 'fluxa-ecommerce-assistant'),
            'manage_options',
            'fluxa-assistant-training',
            array($this, 'render_training_page')
        );

        // Chat History submenu
        add_submenu_page(
            'fluxa-assistant',
            __('Chat History - Fluxa eCommerce Assistant', 'fluxa-ecommerce-assistant'),
            __('Chat History', 'fluxa-ecommerce-assistant'),
            'manage_options',
            'fluxa-assistant-chat',
            array($this, 'render_chat_history_page')
        );

        // Tools submenu (maintenance utilities)
        add_submenu_page(
            'fluxa-assistant',
            __('Tools - Fluxa eCommerce Assistant', 'fluxa-ecommerce-assistant'),
            __('Tools', 'fluxa-ecommerce-assistant'),
            'manage_options',
            'fluxa-assistant-tools',
            array($this, 'render_tools_page')
        );

        // Quickstart submenu (added at the end)
        // Uses the existing render_quickstart_page() from the main plugin class
        add_submenu_page(
            'fluxa-assistant',
            __('Quickstart - Fluxa eCommerce Assistant', 'fluxa-ecommerce-assistant'),
            __('Quickstart', 'fluxa-ecommerce-assistant'),
            'manage_options',
            'fluxa-quickstart',
            array(Fluxa_eCommerce_Assistant::get_instance(), 'render_quickstart_page')
        );
        // Hide Quickstart from submenu unless we're currently on it
        $current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        if ($current_page !== 'fluxa-quickstart') {
            remove_submenu_page('fluxa-assistant', 'fluxa-quickstart');
        }
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Include the dashboard template
        include_once FLUXA_PLUGIN_DIR . 'templates/admin-dashboard.php';
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle form submission
        if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'POST') {
            $this->handle_settings_form();
        }
        
        // Get current settings
        $settings = $this->get_settings();
        
        // Include the settings template
        include_once FLUXA_PLUGIN_DIR . 'templates/admin-settings.php';
    }

    /**
     * Render training page
     */
    public function render_training_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        // Include the training template
        include_once FLUXA_PLUGIN_DIR . 'templates/admin-training.php';
    }

    /**
     * Render Chat History (list view or single view based on query arg)
     */
    public function render_chat_history_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $conversation_id = isset($_GET['conversation']) ? sanitize_text_field($_GET['conversation']) : '';
        if ($conversation_id !== '') {
            include_once FLUXA_PLUGIN_DIR . 'templates/admin-chat-single.php';
        } else {
            include_once FLUXA_PLUGIN_DIR . 'templates/admin-chat-history.php';
        }
    }

    /**
     * Render Tools page (maintenance & stats)
     */
    public function render_tools_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle maintenance actions
        if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'POST') {
            $this->handle_tools_actions();
        }

        global $wpdb;
        $table_ev = $wpdb->prefix . 'fluxa_conv_events';
        $table_conv = $wpdb->prefix . 'fluxa_conv';

        $counts = array('events' => 0, 'conversations' => 0);
        $sizes = array('events' => 0, 'conversations' => 0);

        // Row counts
        $counts['events'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_ev}");
        $counts['conversations'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_conv}");

        // Sizes in bytes via information_schema (best-effort, tolerant to driver casing)
        $sizes_res = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT TABLE_NAME AS tbl, (DATA_LENGTH + INDEX_LENGTH) AS sz FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN (%s, %s)",
                DB_NAME, $table_ev, $table_conv
            ), ARRAY_A
        );
        if (is_array($sizes_res)) {
            foreach ($sizes_res as $r) {
                $tbl = '';
                $sz  = 0;
                if (is_array($r)) {
                    $tbl = isset($r['tbl']) ? (string)$r['tbl'] : (isset($r['TABLE_NAME']) ? (string)$r['TABLE_NAME'] : '');
                    $sz  = isset($r['sz']) ? (int)$r['sz'] : (isset($r['SIZE_BYTES']) ? (int)$r['SIZE_BYTES'] : 0);
                } elseif (is_object($r)) {
                    $tbl = isset($r->tbl) ? (string)$r->tbl : (isset($r->TABLE_NAME) ? (string)$r->TABLE_NAME : '');
                    $sz  = isset($r->sz) ? (int)$r->sz : (isset($r->SIZE_BYTES) ? (int)$r->SIZE_BYTES : 0);
                }
                if ($tbl === $table_ev) { $sizes['events'] = $sz; }
                if ($tbl === $table_conv) { $sizes['conversations'] = $sz; }
            }
        }
        // Fallback via SHOW TABLE STATUS if information_schema is unavailable
        if (empty($sizes['events'])) {
            $row = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $table_ev), ARRAY_A);
            if (is_array($row)) {
                $dl = isset($row['Data_length']) ? (int)$row['Data_length'] : 0;
                $il = isset($row['Index_length']) ? (int)$row['Index_length'] : 0;
                $sizes['events'] = $dl + $il;
            }
        }
        if (empty($sizes['conversations'])) {
            $row = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $table_conv), ARRAY_A);
            if (is_array($row)) {
                $dl = isset($row['Data_length']) ? (int)$row['Data_length'] : 0;
                $il = isset($row['Index_length']) ? (int)$row['Index_length'] : 0;
                $sizes['conversations'] = $dl + $il;
            }
        }

        // Top event types
        $by_type = $wpdb->get_results("SELECT event_type, COUNT(*) AS c FROM {$table_ev} GROUP BY event_type ORDER BY c DESC LIMIT 10", ARRAY_A);

        $stats = array(
            'counts' => $counts,
            'sizes' => $sizes,
            'by_type' => is_array($by_type) ? $by_type : array(),
        );

        include_once FLUXA_PLUGIN_DIR . 'templates/admin-tools.php';
    }

    /**
     * Handle maintenance actions from Tools page
     */
    private function handle_tools_actions() {
        if (!current_user_can('manage_options')) { return; }
        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'fluxa_tools_action')) {
            add_settings_error('fluxa_messages', 'tools_nonce', __('Security check failed for Tools action.', 'fluxa-ecommerce-assistant'), 'error');
            return;
        }

        global $wpdb;
        $table_ev = $wpdb->prefix . 'fluxa_conv_events';
        $table_conv = $wpdb->prefix . 'fluxa_conv';

        $did = '';
        $action = isset($_POST['action_type']) ? sanitize_key($_POST['action_type']) : '';
        if ($action === 'purge_old') {
            $days = isset($_POST['older_than_days']) ? max(0, absint($_POST['older_than_days'])) : 0;
            if ($days > 0) {
                $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$table_ev} WHERE event_time < DATE_SUB(NOW(), INTERVAL %d DAY)", $days));
                $did = sprintf(__('Deleted %d old event rows (older than %d days).', 'fluxa-ecommerce-assistant'), (int)$deleted, $days);
            } else {
                $did = __('Please enter a valid number of days.', 'fluxa-ecommerce-assistant');
            }
        } elseif ($action === 'purge_all') {
            // Truncate events table
            $wpdb->query("TRUNCATE TABLE {$table_ev}");
            $did = __('All event rows have been deleted (table truncated).', 'fluxa-ecommerce-assistant');
        } elseif ($action === 'optimize') {
            // Optimize tables
            $wpdb->query("OPTIMIZE TABLE {$table_ev}");
            $wpdb->query("OPTIMIZE TABLE {$table_conv}");
            $did = __('Optimization completed for conversation and events tables.', 'fluxa-ecommerce-assistant');
        }

        if ($did !== '') {
            add_settings_error('fluxa_messages', 'tools_ok', $did, 'updated');
        }
    }

    /**
     * Handle settings form submission
     */
    private function handle_settings_form() {
        // Validate nonce via WordPress helper (no die), accept both default and legacy fields
        $valid_nonce = false;
        $has_default = isset($_POST['_wpnonce']);
        $has_legacy  = isset($_POST['fluxa_settings_nonce']);
        $len_default = $has_default ? strlen((string) $_POST['_wpnonce']) : 0;
        $len_legacy  = $has_legacy ? strlen((string) $_POST['fluxa_settings_nonce']) : 0;
        $verify_default = null;
        $verify_legacy  = null;
        if (function_exists('check_admin_referer')) {
            // Third argument false prevents WP from dying on failure
            if (($verify_default = check_admin_referer('fluxa_save_settings', '_wpnonce', false))) {
                $valid_nonce = true;
            } elseif (($verify_legacy = check_admin_referer('fluxa_save_settings', 'fluxa_settings_nonce', false))) {
                $valid_nonce = true;
            }
        }

        if (!$valid_nonce) {
            $posted_keys = implode(', ', array_map('sanitize_text_field', array_keys($_POST)));
            $diag = sprintf(
                /* translators: 1: fields, 2: has default, 3: len default, 4: verified default, 5: has legacy, 6: len legacy, 7: verified legacy */
                __('rrrrSecurity check failed. Please try again. Received fields: %1$s | _wpnonce present: %2$s (len %3$d, verified: %4$s) | fluxa_settings_nonce present: %5$s (len %6$d, verified: %7$s)', 'fluxa-ecommerce-assistant'),
                esc_html($posted_keys),
                $has_default ? 'yes' : 'no',
                (int) $len_default,
                is_null($verify_default) ? 'n/a' : ($verify_default ? 'yes' : 'no'),
                $has_legacy ? 'yes' : 'no',
                (int) $len_legacy,
                is_null($verify_legacy) ? 'n/a' : ($verify_legacy ? 'yes' : 'no')
            );
            add_settings_error(
                'fluxa_messages',
                'nonce_failed',
                $diag,
                'error'
            );
            return;
        }

        // Save API key
        if (isset($_POST['api_key'])) {
            update_option('fluxa_api_key', sanitize_text_field($_POST['api_key']));
        }

        // Save conversation types
        $conversation_types = array(
            'order_status' => isset($_POST['conversation_order_status']) ? 1 : 0,
            'order_tracking' => isset($_POST['conversation_order_tracking']) ? 1 : 0,
            'cart_abandoned' => isset($_POST['conversation_cart_abandoned']) ? 1 : 0
        );
        update_option('fluxa_conversation_types', $conversation_types);

        // Save tracking provider options (independent of enable flag; UI controls visibility)
        $allowed_providers = array('woocommerce_shipment_tracking','aftership','ast','custom');
        $provider = isset($_POST['tracking_provider']) ? sanitize_key($_POST['tracking_provider']) : '';
        if (!in_array($provider, $allowed_providers, true)) {
            $provider = '';
        }
        update_option('fluxa_tracking_provider', $provider);

        $custom_meta = isset($_POST['tracking_custom_meta']) ? sanitize_key($_POST['tracking_custom_meta']) : '';
        update_option('fluxa_tracking_custom_meta', $custom_meta);

        // Save global Target Users (general setting)
        $allowed_targets = array('all','logged_in','guests');
        $target_users = isset($_POST['target_users']) ? sanitize_key($_POST['target_users']) : 'all';
        if (!in_array($target_users, $allowed_targets, true)) { $target_users = 'all'; }
        update_option('fluxa_target_users', $target_users);

        // Save design settings with enforced palettes for non-custom themes
        $selected_theme = in_array($_POST['theme'] ?? 'light', array('light','dark','custom'), true) ? ($_POST['theme'] ?: 'light') : 'light';
        // Define default palettes
        $palette_light = array(
            'primary_color' => '#4F46E5',
            'background_color' => '#FFFFFF',
            'text_color' => '#000000',
        );
        $palette_dark = array(
            'primary_color' => '#4F46E5',
            'background_color' => '#111827',
            'text_color' => '#FFFFFF',
        );

        // Resolve colors depending on theme selection
        if ($selected_theme === 'custom') {
            $primary   = sanitize_hex_color($_POST['primary_color'] ?? '#4F46E5');
            $bg        = sanitize_hex_color($_POST['background_color'] ?? '#FFFFFF');
            $text      = sanitize_hex_color($_POST['text_color'] ?? '#000000');
        } elseif ($selected_theme === 'dark') {
            $primary = $palette_dark['primary_color'];
            $bg      = $palette_dark['background_color'];
            $text    = $palette_dark['text_color'];
        } else { // light
            $primary = $palette_light['primary_color'];
            $bg      = $palette_light['background_color'];
            $text    = $palette_light['text_color'];
        }

        $design_settings = array(
            'chatbot_name' => sanitize_text_field($_POST['chatbot_name'] ?? 'Chat Assistant'),
            'alignment' => in_array($_POST['alignment'] ?? '', array('left', 'right')) ? $_POST['alignment'] : 'right',
            'gap_from_bottom' => absint($_POST['gap_from_bottom'] ?? 20),
            'gap_from_side' => absint($_POST['gap_from_side'] ?? 20),
            'logo_url' => $this->handle_logo_upload('logo_url', 'chatbot_logo', 'remove_logo'),
            'minimized_icon_url' => $this->handle_logo_upload('minimized_icon_url', 'chatbot_minimized_icon', 'remove_minimized_icon'),
            'theme' => $selected_theme,
            'primary_color' => $primary ?: '#4F46E5',
            'background_color' => $bg ?: '#FFFFFF',
            'text_color' => $text ?: '#000000',
            // New: opening animation choice (Animate.css names or 'none')
            'animation' => (function(){
                $allowed = array(
                    'none',
                    'bounceIn','bounceInUp','bounceInLeft','bounceInRight',
                    'backInUp','backInLeft','backInRight',
                    'fadeInUp','fadeInUpBig','fadeInLeft','fadeInLeftBig','fadeInRight','fadeInRightBig',
                    'flipInX','flipInY',
                    'lightSpeedInLeft','lightSpeedInRight',
                    'jackInTheBox','rollIn',
                    'zoomIn','zoomInDown','zoomInLeft','zoomInRight','zoomInUp',
                    'slideInDown','slideInLeft','slideInRight','slideInUp'
                );
                $val = isset($_POST['animation']) ? sanitize_text_field($_POST['animation']) : 'bounceIn';
                return in_array($val, $allowed, true) ? $val : 'bounceIn';
            })(),
            // New: auto open on bot reply
            'auto_open_on_reply' => isset($_POST['auto_open_on_reply']) ? 1 : 0,
            // New: pulse launcher on new message
            'pulse_on_new' => isset($_POST['pulse_on_new']) ? 1 : 0,
        );
        update_option('fluxa_design_settings', $design_settings);

        // Save Cart Abandonment advanced settings (grouped)
        $ca = array();
        $ca['enabled'] = isset($_POST['conversation_cart_abandoned']) ? 1 : 0;
        // General
        $ca['general'] = array(
            'min_total'    => isset($_POST['ca_min_total']) ? floatval($_POST['ca_min_total']) : 0,
            'min_count'    => isset($_POST['ca_min_count']) ? absint($_POST['ca_min_count']) : 0,
            'target_users' => isset($_POST['ca_target_users']) && in_array($_POST['ca_target_users'], array('all','logged_in','guests'), true) ? sanitize_key($_POST['ca_target_users']) : 'all',
        );
        // Triggers
        $ca['trigger'] = array(
            'idle_minutes'  => isset($_POST['ca_idle_minutes']) ? absint($_POST['ca_idle_minutes']) : 0,
            'exit_intent'   => isset($_POST['ca_exit_intent']) ? 1 : 0,
            'stock_discount'=> isset($_POST['ca_stock_discount']) ? 1 : 0,
        );
        // Message / Prompt
        $strategies = array(
            'mention_shipping_threshold' => isset($_POST['ca_strat_shipping_threshold']) ? 1 : 0,
            'suggest_alternatives'       => isset($_POST['ca_strat_alternatives']) ? 1 : 0,
            'suggest_bundle'             => isset($_POST['ca_strat_bundle']) ? 1 : 0,
        );
        $ca['message'] = array(
            'system_prompt'     => isset($_POST['ca_system_prompt']) ? wp_kses_post(wp_unslash($_POST['ca_system_prompt'])) : '',
            'customer_template' => isset($_POST['ca_customer_template']) ? wp_kses_post(wp_unslash($_POST['ca_customer_template'])) : '',
            'strategies'        => $strategies,
        );
        // Coupon / Incentive
        $coupon_mode_allowed = array('disabled','fixed','auto');
        $coupon_mode = isset($_POST['ca_coupon_mode']) ? sanitize_key($_POST['ca_coupon_mode']) : 'disabled';
        if (!in_array($coupon_mode, $coupon_mode_allowed, true)) { $coupon_mode = 'disabled'; }
        $ca['coupon'] = array(
            'mode'          => $coupon_mode,
            'cap_percent'   => isset($_POST['ca_discount_cap']) ? floatval($_POST['ca_discount_cap']) : 0,
            'valid_hours'   => isset($_POST['ca_coupon_valid_hours']) ? absint($_POST['ca_coupon_valid_hours']) : 0,
            'cond_min_total'=> isset($_POST['ca_cond_min_total']) ? floatval($_POST['ca_cond_min_total']) : 0,
            'cond_new_customer' => isset($_POST['ca_cond_new_customer']) ? 1 : 0,
        );
        // Channels
        $ca['channel'] = array(
            'onsite'    => isset($_POST['ca_channel_onsite']) ? 1 : 0,
            'email'     => isset($_POST['ca_channel_email']) ? 1 : 0,
            'push'      => isset($_POST['ca_channel_push']) ? 1 : 0,
            'whatsapp'  => isset($_POST['ca_channel_whatsapp']) ? 1 : 0,
        );
        // Frequency & Suppression
        $ca['frequency'] = array(
            'max_per_day'   => isset($_POST['ca_max_per_day']) ? absint($_POST['ca_max_per_day']) : 1,
            'max_per_week'  => isset($_POST['ca_max_per_week']) ? absint($_POST['ca_max_per_week']) : 2,
            'suppress_hours'=> isset($_POST['ca_suppress_hours']) ? absint($_POST['ca_suppress_hours']) : 12,
            'stop_after_order' => isset($_POST['ca_stop_after_order']) ? 1 : 0,
            'stop_after_empty' => isset($_POST['ca_stop_after_empty']) ? 1 : 0,
        );
        // Segmentation
        $segments = array(
            'new_customers'     => isset($_POST['ca_seg_new']) ? 1 : 0,
            'returning'         => isset($_POST['ca_seg_returning']) ? 1 : 0,
            'high_value'        => isset($_POST['ca_seg_high_value']) ? 1 : 0,
            'high_value_threshold' => isset($_POST['ca_high_value_threshold']) ? floatval($_POST['ca_high_value_threshold']) : 0,
        );
        $ca['segmentation'] = $segments;
        $ca['ab_test'] = isset($_POST['ca_ab_test']) && in_array($_POST['ca_ab_test'], array('none','template_a_b','coupon_vs_no'), true) ? sanitize_key($_POST['ca_ab_test']) : 'none';
        // Privacy
        $ca['privacy'] = array(
            'consent_required' => isset($_POST['ca_consent_required']) ? 1 : 0,
            'anonymize_guests' => isset($_POST['ca_anonymize_guests']) ? 1 : 0,
        );
        update_option('fluxa_cart_abandonment', $ca);

        // Save greeting text
        $greeting = isset($_POST['greeting']) ? wp_kses_post(wp_unslash($_POST['greeting'])) : '';
        update_option('fluxa_greeting_text', $greeting);

        // Save suggested questions (array of strings)
        $suggested = array();
        if (!empty($_POST['suggested_questions']) && is_array($_POST['suggested_questions'])) {
            foreach ($_POST['suggested_questions'] as $q) {
                $q = trim(wp_strip_all_tags((string)$q));
                if ($q !== '') {
                    $suggested[] = $q;
                }
            }
        }
        update_option('fluxa_suggested_questions', $suggested);

        // Save suggestions enable/disable flag
        $suggestions_enabled = isset($_POST['suggestions_enabled']) ? 1 : 0;
        update_option('fluxa_suggestions_enabled', $suggestions_enabled);

        // Save page-load ping toggle
        $ping_on_pageload = isset($_POST['ping_on_pageload']) ? 1 : 0;
        update_option('fluxa_ping_on_pageload', $ping_on_pageload);

        // Save tracking enable/disable
        $tracking_enabled = isset($_POST['tracking_enabled']) ? 1 : 0;
        update_option('fluxa_tracking_enabled', $tracking_enabled);

        // Save granular tracking events (default all on when enabled)
        $all_events_keys = array(
            // page and catalog
            'page_view','category_view','product_view','search','pagination','filter_apply','sort_apply','campaign_landing',
            // product interactions
            'product_impression','product_click','variant_select',
            // cart & checkout
            'add_to_cart','remove_from_cart','update_cart_qty','cart_view','begin_checkout',
            // order & payment
            'order_created','payment_complete','order_status_changed','order_refunded','thank_you_view',
            // errors
            'js_error','api_error'
        );
        $tracking_events = array();
        foreach ($all_events_keys as $ek) {
            // If master tracking is enabled and specific checkbox missing, treat as 0 (user unchecked)
            // If master is disabled, store 0 to be explicit
            $tracking_events[$ek] = ($tracking_enabled && isset($_POST['track_' . $ek])) ? 1 : 0;
        }
        // If tracking was enabled but none of the keys posted (first-time enabling), default to all on
        if ($tracking_enabled) {
            $anyPosted = false;
            foreach ($all_events_keys as $ek) { if (isset($_POST['track_' . $ek])) { $anyPosted = true; break; } }
            if (!$anyPosted) {
                foreach ($all_events_keys as $ek) { $tracking_events[$ek] = 1; }
            }
        }
        update_option('fluxa_tracking_events', $tracking_events);

        // Add success message
        add_settings_error(
            'fluxa_messages',
            'settings_updated',
            __('Settings saved successfully.', 'fluxa-ecommerce-assistant'),
            'updated'
        );
    }

    /**
     * Handle logo upload
     */
    private function handle_logo_upload($url_field = 'logo_url', $file_field = 'chatbot_logo', $remove_field = 'remove_logo') {
        $design_settings = get_option('fluxa_design_settings', array());
        $current_url = $design_settings[$url_field] ?? '';

        // If a media library URL is explicitly provided, use it (preferred path)
        if (isset($_POST[$url_field])) {
            $posted_url = esc_url_raw($_POST[$url_field]);
            if ($posted_url !== '') {
                return $posted_url;
            }
        }

        // Handle file upload (not currently used in UI, but supported)
        if (!empty($_FILES[$file_field]['name'])) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');

            $upload = wp_handle_upload($_FILES[$file_field], array('test_form' => false));
            if (isset($upload['url'])) {
                return $upload['url'];
            }
        } elseif (isset($_POST[$remove_field]) && $_POST[$remove_field] === '1') {
            return '';
        }

        return $current_url;
    }

    /**
     * Get plugin settings
     */
    private function get_settings() {
        return array(
            'api_key' => get_option('fluxa_api_key', ''),
            'conversation_types' => get_option('fluxa_conversation_types', array(
                'order_status' => 0,
                'order_tracking' => 0,
                'cart_abandoned' => 0
            )),
            'design' => get_option('fluxa_design_settings', array(
                'chatbot_name' => 'Chat Assistant',
                'alignment' => 'right',
                'gap_from_bottom' => 20,
                'gap_from_side' => 20,
                'logo_url' => '',
                'minimized_icon_url' => '',
                'theme' => 'light',
                'primary_color' => '#4F46E5',
                'background_color' => '#FFFFFF',
                'text_color' => '#000000',
                'animation' => 'bounceIn',
                'auto_open_on_reply' => 1,
                'pulse_on_new' => 1
            )),
            'greeting' => get_option('fluxa_greeting_text', ''),
            'suggested_questions' => get_option('fluxa_suggested_questions', array()),
            'suggestions_enabled' => (int) get_option('fluxa_suggestions_enabled', 1),
            'ping_on_pageload' => (int) get_option('fluxa_ping_on_pageload', 1),
            'tracking_enabled' => (int) get_option('fluxa_tracking_enabled', 1),
            'tracking_events' => get_option('fluxa_tracking_events', array(
                // page and catalog
                'page_view' => 1,
                'category_view' => 1,
                'product_view' => 1,
                'search' => 1,
                'pagination' => 1,
                'filter_apply' => 1,
                'sort_apply' => 1,
                'campaign_landing' => 1,
                // product interactions
                'product_impression' => 1,
                'product_click' => 1,
                'variant_select' => 1,
                // cart & checkout
                'add_to_cart' => 1,
                'remove_from_cart' => 1,
                'update_cart_qty' => 1,
                'cart_view' => 1,
                'begin_checkout' => 1,
                // order & payment
                'order_created' => 1,
                'payment_complete' => 1,
                'order_status_changed' => 1,
                'order_refunded' => 1,
                'thank_you_view' => 1,
                // errors
                'js_error' => 1,
                'api_error' => 1,
            )),
            'tracking_provider' => get_option('fluxa_tracking_provider', ''),
            'tracking_custom_meta' => get_option('fluxa_tracking_custom_meta', ''),
            'target_users' => get_option('fluxa_target_users', 'all'),
            'cart_abandonment' => get_option('fluxa_cart_abandonment', array(
                'enabled' => 0,
                'general' => array('min_total'=>0,'min_count'=>0,'target_users'=>'all'),
                'trigger' => array('idle_minutes'=>0,'exit_intent'=>0,'stock_discount'=>0),
                'message' => array('system_prompt'=>'','customer_template'=>'','strategies'=>array('mention_shipping_threshold'=>0,'suggest_alternatives'=>0,'suggest_bundle'=>0)),
                'coupon'  => array('mode'=>'disabled','cap_percent'=>0,'valid_hours'=>0,'cond_min_total'=>0,'cond_new_customer'=>0),
                'channel' => array('onsite'=>1,'email'=>0,'push'=>0,'whatsapp'=>0),
                'frequency'=> array('max_per_day'=>1,'max_per_week'=>2,'suppress_hours'=>12,'stop_after_order'=>1,'stop_after_empty'=>1),
                'segmentation'=> array('new_customers'=>1,'returning'=>1,'high_value'=>0,'high_value_threshold'=>0),
                'ab_test' => 'none',
                'privacy' => array('consent_required'=>0,'anonymize_guests'=>1),
            )),
        );
    }
    
    /**
     * Redirect to quickstart if needed
     */
    public function maybe_redirect_to_quickstart() {
        // Redirect only on our plugin pages to avoid hijacking all admin
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        if ($page === '' || strpos($page, 'fluxa-') !== 0) {
            return;
        }
        // Don't redirect if we're already on the quickstart page
        if ($page === 'fluxa-quickstart') {
            return;
        }
        // Don't redirect on AJAX requests
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }
        // Only admins
        if (!current_user_can('manage_options')) {
            return;
        }
        // Enforce quickstart completion
        if (get_option('fluxa_show_quickstart') === '1') {
            wp_safe_redirect(admin_url('admin.php?page=fluxa-quickstart'));
            exit;
        }
    }

    /**
     * Enqueue admin styles/scripts conditionally on Fluxa pages
     */
    public function enqueue_admin_scripts($hook_suffix) {
        // Only load on our plugin pages
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        if ($page === '' || strpos($page, 'fluxa-') !== 0) {
            return;
        }

        // Determine dynamic versions for cache-busting if FLUXA_VERSION is not defined
        $admin_css_ver = defined('FLUXA_VERSION') ? FLUXA_VERSION : @filemtime(FLUXA_PLUGIN_DIR . 'assets/css/admin.css');
        $admin_js_ver  = defined('FLUXA_VERSION') ? FLUXA_VERSION : @filemtime(FLUXA_PLUGIN_DIR . 'assets/js/admin.js');
        $qs_css_ver    = defined('FLUXA_VERSION') ? FLUXA_VERSION : @filemtime(FLUXA_PLUGIN_DIR . 'assets/css/quickstart.css');

        // Base admin styles for Fluxa pages
        wp_enqueue_style(
            'fluxa-admin',
            FLUXA_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            $admin_css_ver ?: null
        );

        // Base admin JS for Fluxa pages (needed for unified checkbox switches etc.)
        wp_enqueue_script(
            'fluxa-admin',
            FLUXA_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            $admin_js_ver ?: null,
            true
        );

        // Quickstart specific styles
        if ($page === 'fluxa-quickstart') {
            wp_enqueue_style(
                'fluxa-quickstart',
                FLUXA_PLUGIN_URL . 'assets/css/quickstart.css',
                array(),
                $qs_css_ver ?: null
            );
        }

        // Settings page JS enhancements
        if ($page === 'fluxa-assistant-settings') {
            // Ensure WordPress media library scripts/styles are available
            if (function_exists('wp_enqueue_media')) {
                wp_enqueue_media();
            }
            // Enqueue WP Color Picker assets
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_script('wp-color-picker');

            // Localize strings for JS (media frame/i18n)
            wp_localize_script('fluxa-admin', 'fluxaI18n', array(
                'selectLogoTitle' => __('Select Logo', 'fluxa-ecommerce-assistant'),
                'useThisImage'    => __('Use this image', 'fluxa-ecommerce-assistant'),
                'selectLogo'      => __('Select Logo', 'fluxa-ecommerce-assistant'),
                'changeLogo'      => __('Change Logo', 'fluxa-ecommerce-assistant'),
            ));

            // Enqueue frontend chatbot styles for accurate admin live preview
            $chatbot_css_ver = defined('FLUXA_VERSION') ? FLUXA_VERSION : @filemtime(FLUXA_PLUGIN_DIR . 'assets/css/chatbot.css');
            wp_enqueue_style(
                'fluxa-chatbot-preview',
                FLUXA_PLUGIN_URL . 'assets/css/chatbot.css',
                array(),
                $chatbot_css_ver ?: null
            );
        }

        // Chat History: DataTables for advanced filtering
        if ($page === 'fluxa-assistant-chat') {
            wp_enqueue_style(
                'datatables-css',
                'https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css',
                array(),
                '1.13.6'
            );
            wp_enqueue_script(
                'datatables-js',
                'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js',
                array('jquery'),
                '1.13.6',
                true
            );
            // Initialize DataTables after the library loads
            $inline = <<<'JS'
            jQuery(function($){
              if (!$.fn.DataTable) { return; }
              var table = $('#fluxa-chat-table').DataTable({
                order: [[5, 'desc']],
                pageLength: 25,
                dom: 'lrtip'
              });

              // Custom multi-factor filter including message contents
              $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                if (settings.nTable.id !== 'fluxa-chat-table') return true;
                var row = table.row(dataIndex).node();
                var total = parseInt($(row).find('.fluxa-total').data('total')) || 0;
                var agent = parseInt($(row).find('.fluxa-agent').data('agent')) || 0;
                var updatedTs = parseInt($(row).find('td:last').attr('data-order')) || 0;
                var minTotal = parseInt($('#fluxa-min-total').val()) || 0;
                var minAgent = parseInt($('#fluxa-min-agent').val()) || 0;
                var startDate = $('#fluxa-date-start').val();
                var endDate = $('#fluxa-date-end').val();
                var q = ($('#fluxa-chat-search').val() || '').toString().trim().toLowerCase();

                if (total < minTotal) return false;
                if (agent < minAgent) return false;
                if (startDate) {
                  var startTs = Math.floor(new Date(startDate + 'T00:00:00').getTime() / 1000);
                  if (updatedTs < startTs) return false;
                }
                if (endDate) {
                  var endTs = Math.floor(new Date(endDate + 'T23:59:59').getTime() / 1000);
                  if (updatedTs > endTs) return false;
                }
                if (q) {
                  var hay = ($(row).data('search-text') || '').toString().toLowerCase();
                  hay += ' ' + ($(row).text() || '').toLowerCase();
                  if (hay.indexOf(q) === -1) return false;
                }
                return true;
              });

              // Trigger redraw on filters
              $('#fluxa-chat-search').on('input', function(){ table.draw(); });
              $('#fluxa-min-total, #fluxa-min-agent, #fluxa-date-start, #fluxa-date-end').on('input change', function(){ table.draw(); });

              // Row click navigation
              $(document).on('click', 'tr.fluxa-chat-row', function(e){
                if ($(e.target).is('input, label, a, button')) return;
                var href = $(this).data('href');
                if (href) window.location = href;
              });
            });
            JS;
            wp_add_inline_script('datatables-js', $inline);
        }
    }
}

// Initialize the admin menu
new Fluxa_Admin_Menu();
