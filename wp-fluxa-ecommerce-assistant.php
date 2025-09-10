<?php
/**
 * Plugin Name: WP Fluxa eCommerce Assistant
 * Description: AI-powered eCommerce assistant with chatbot functionality, order tracking, and customer support features.
 * Version: 1.0.0
 * Author: Selman Demirdoven
 * Text Domain: fluxa-ecommerce-assistant
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Simple file logger for plugin diagnostics
if (!function_exists('fluxa_log')) {
    function fluxa_log($message) {
        // Resolve uploads directory (wp-content/uploads)
        $uploads = wp_upload_dir();
        $base    = isset($uploads['basedir']) ? rtrim($uploads['basedir'], '/\r\n ') : WP_CONTENT_DIR . '/uploads';
        $dir     = $base . '/fluxa-ecommerce-assistant/logs';
        $file    = $dir . '/log.txt';
        if (!is_dir($dir)) {
            if (!function_exists('wp_mkdir_p')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            wp_mkdir_p($dir);
        }
        $ts = date('c');
        $line = "[$ts] " . (is_string($message) ? $message : wp_json_encode($message)) . "\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}

// Plugin version
define('FLUXA_VERSION', '1.0.0');

// Plugin Folder Path
define('FLUXA_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Plugin Folder URL
define('FLUXA_PLUGIN_URL', plugin_dir_url(__FILE__));

// Plugin Root File
define('FLUXA_PLUGIN_FILE', __FILE__);

// Sensay API constants
if (!defined('SENSAY_ORG_SECRET')) {
    // Bind org secret to saved API key (fallback to default placeholder)
    $fluxa_saved_key = get_option('fluxa_api_key', '');
    if (empty($fluxa_saved_key)) {
        $fluxa_saved_key = '8fa5d504c1ebe6f17436c72dd602d3017a4fe390eb5963e38a1999675c9c7ad3';
    }
    define('SENSAY_ORG_SECRET', $fluxa_saved_key);
}
if (!defined('SENSAY_API_VERSION')) {
    define('SENSAY_API_VERSION', '2025-03-25');
}
if (!defined('SENSAY_API_BASE')) {
    define('SENSAY_API_BASE', 'https://api.sensay.io');
}

// Include required files
require_once FLUXA_PLUGIN_DIR . 'includes/admin/class-admin-menu.php';
// API client
require_once FLUXA_PLUGIN_DIR . 'includes/api/class-sensay-client.php';
// Replica service
require_once FLUXA_PLUGIN_DIR . 'includes/api/class-sensay-replica-service.php';
// Utils: user id service
require_once FLUXA_PLUGIN_DIR . 'includes/utils/class-user-id-service.php';

/**
 * Main Plugin Class
 */
class Fluxa_eCommerce_Assistant {

    /**
     * Instance of this class
     */
    private static $instance = null;

    /** @var Sensay_Client|null */
    private $sensay_client = null;
    /** @var \Fluxa\API\Sensay_Replica_Service|null */
    private $replica_service = null;

    /**
     * Get the singleton instance of this class
     */
    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Load text domain for translations
        add_action('plugins_loaded', array($this, 'load_plugin_textdomain'));
        if (function_exists('fluxa_log')) { fluxa_log('lifecycle: constructor'); }
        
        // Initialize plugin
        add_action('init', array($this, 'init'));
        
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Register admin-related hooks
        add_action('admin_init', array($this, 'handle_quickstart_actions'));
        // Handle one-time activation redirect early
        add_action('admin_init', array($this, 'maybe_activation_redirect'), 1);
        
        // Quickstart menu is registered under the main menu in Fluxa_Admin_Menu::add_admin_menus
        
        // AJAX handlers
        add_action('wp_ajax_fluxa_dismiss_notice', array($this, 'ajax_dismiss_notice'));

        // API key validity notice
        add_action('admin_notices', array($this, 'admin_api_key_notice'));
    }

    /**
     * Load plugin text domain for translations
     */
    public function load_plugin_textdomain() {
        load_plugin_textdomain(
            'fluxa-ecommerce-assistant',
            false,
            dirname(plugin_basename(FLUXA_PLUGIN_FILE)) . '/languages/'
        );
    }

    /**
     * Verify the Fluxa Assistant licence/API key.
     *
     * TODO: Integrate remote licence verification API here.
     *
     * @param string $api_key
     * @return bool True if licence is valid; false otherwise.
     */
    private function verify_licence($api_key) {
        // Placeholder logic for now; replace with remote API check later
        $required = '8fa5d504c1ebe6f17436c72dd602d3017a4fe390eb5963e38a1999675c9c7ad3';
        $ok = is_string($api_key) && $api_key === $required;
        if (function_exists('fluxa_log')) {
            $len = is_string($api_key) ? strlen($api_key) : 0;
            fluxa_log('licence: verify len=' . $len . ' result=' . ($ok ? 'valid' : 'invalid'));
        }
        return $ok;
    }

    /**
     * Show an admin error notice when API key is missing or invalid
     */
    public function admin_api_key_notice() {
        // Only in admin for users who can manage options
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        // Avoid showing during AJAX
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }
        // Don't show on the Quickstart page
        $current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        if ($current_page === 'fluxa-quickstart') {
            return;
        }
        // If we're on our settings page during a POST, prefer the just-submitted key
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        $api_key = '';
        if ($page === 'fluxa-assistant-settings' && isset($_POST['api_key'])) {
            $api_key = sanitize_text_field($_POST['api_key']);
        } else {
            $api_key = get_option('fluxa_api_key', '');
        }

        if (!$this->verify_licence($api_key)) {
            echo '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__("Fluxa Assistant API key is not valid. Please set it on the Fluxa Assistant → Settings page.", 'fluxa-ecommerce-assistant')
                . '</p></div>';
        }
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        if (function_exists('fluxa_log')) { fluxa_log('lifecycle: init is_admin=' . (is_admin() ? '1' : '0')); }
        // Initialize admin menu
        if (is_admin()) {
            // Admin menu is initialized in the included class
            // Ensure Sensay owner user is provisioned (runs once)
            add_action('admin_init', array($this, 'maybe_provision_owner_user')); 
            // Ensure Sensay chatbox replica is provisioned (runs once)
            add_action('admin_init', array($this, 'maybe_provision_replica'));
            // Trace that admin_init will fire
            add_action('admin_init', function(){ if (function_exists('fluxa_log')) { fluxa_log('lifecycle: admin_init fired'); } }, 0);
        }
        
        // Ensure every visitor (logged-in or guest) has a stable chat user id early in the request
        add_action('init', function(){
            if (class_exists('Fluxa_User_ID_Service')) {
                \Fluxa_User_ID_Service::get_or_create_current_user_id();
            }
        }, 0);

        // When a guest registers, attach their current chat user id to the new account
        add_action('user_register', array($this, 'attach_chat_user_id_to_new_user'), 10, 1);

        // Add frontend hooks
        $this->init_frontend();
    }

    /**
     * Lazily get a shared Sensay_Client instance
     * @return Sensay_Client
     */
    private function get_sensay_client() {
        if (!$this->sensay_client) {
            $this->sensay_client = new Sensay_Client();
        }
        return $this->sensay_client;
    }

    /**
     * Lazily get a shared Sensay_Replica_Service instance
     * @return \Fluxa\API\Sensay_Replica_Service
     */
    private function get_replica_service() {
        if (!$this->replica_service) {
            $this->replica_service = new \Fluxa\API\Sensay_Replica_Service($this->get_sensay_client());
        }
        return $this->replica_service;
    }
    
    /**
     * Initialize frontend functionality
     */
    private function init_frontend() {
        // Add frontend hooks here
        add_action('wp_footer', array($this, 'echo_current_user_id_test'), 1);
        add_action('wp_footer', array($this, 'add_chatbot_widget'));
    }

    /**
     * On user register, copy the current visitor chat id (cookie-backed) into user_meta 'fluxa_ss_user_id'.
     * @param int $user_id
     */
    public function attach_chat_user_id_to_new_user($user_id) {
        if (!class_exists('Fluxa_User_ID_Service')) { return; }
        // If user already has one, respect it
        $existing = get_user_meta($user_id, 'fluxa_ss_user_id', true);
        if (!empty($existing)) { return; }
        $chat_id = \Fluxa_User_ID_Service::get_or_create_current_user_id();
        if (!empty($chat_id)) {
            update_user_meta($user_id, 'fluxa_ss_user_id', $chat_id);
            if (function_exists('fluxa_log')) { fluxa_log('user_register: saved fluxa_ss_user_id for user ' . $user_id); }
        }
    }
    
    /**
     * Add chatbot widget to frontend
     */
    public function add_chatbot_widget() {
        if (is_admin()) {
            return;
        }
        
        // TEST: output current chat user id for verification
        if (class_exists('Fluxa_User_ID_Service')) {
            $chat_id = \Fluxa_User_ID_Service::get_or_create_current_user_id();
            if (!empty($chat_id)) {
                echo "<!-- fluxa_chat_user_id: {$chat_id} -->\n";
                $chat_id_js = esc_js($chat_id);
                $secure_flag = is_ssl() ? '; secure' : '';
                echo "<script>(function(){\n".
                     "  window.FLUXA_CHAT_USER_ID='{$chat_id_js}';\n".
                     "  try {\n".
                     "    var m = document.cookie.match(/(?:^|; )fluxa_uid=([^;]+)/);\n".
                     "    if (!m) {\n".
                     "      var stored = null;\n".
                     "      var sess = null;\n".
                     "      try { sess = sessionStorage.getItem('fluxa_uid_value'); } catch(e) {}\n".
                     "      try { stored = localStorage.getItem('fluxa_uid_value'); } catch(e) {}\n".
                     "      var seed = sess || stored;\n".
                     "      if (seed) {\n".
                     "        // Restore from localStorage\n".
                     "        var expires1 = new Date(Date.now() + 31536000000).toUTCString();\n".
                     "        document.cookie = 'fluxa_uid=' + seed + '; path=/; samesite=lax' + '" . ($secure_flag) . "' + '; expires=' + expires1;\n".
                     "        try { sessionStorage.setItem('fluxa_uid_value', seed); } catch(e) {}\n".
                     "        try { localStorage.setItem('fluxa_uid_value', seed); } catch(e) {}\n".
                     "      } else {\n".
                     "        var value = '{$chat_id_js}.' + '" . esc_js(hash_hmac('sha256', $chat_id, wp_salt('auth'))) . "';\n".
                     "        var expires2 = new Date(Date.now() + 31536000000).toUTCString();\n".
                     "        document.cookie = 'fluxa_uid=' + value + '; path=/; samesite=lax' + '" . ($secure_flag) . "' + '; expires=' + expires2;\n".
                     "        try { sessionStorage.setItem('fluxa_uid_value', value); } catch(e) {}\n".
                     "        try { localStorage.setItem('fluxa_uid_value', value); } catch(e) {}\n".
                     "      }\n".
                     "    } else {\n".
                     "      // Ensure storages mirror cookie value for future tabs\n".
                     "      var val = decodeURIComponent(m[1]);\n".
                     "      try { sessionStorage.setItem('fluxa_uid_value', val); } catch(e) {}\n".
                     "      try { localStorage.setItem('fluxa_uid_value', val); } catch(e) {}\n".
                     "    }\n".
                     "    console.log('FLUXA_CHAT_USER_ID', window.FLUXA_CHAT_USER_ID);\n".
                     "  } catch(e) {}\n".
                     "})();</script>\n";
            }
        }

        // Get design settings
        $design_settings = get_option('fluxa_design_settings', array(
            'chatbot_name' => 'Chat Assistant',
            'alignment' => 'right',
            'gap_from_bottom' => 20,
            'logo_url' => '',
            'animation' => 'bounceIn'
        ));
        
        // Enqueue frontend scripts and styles
        wp_enqueue_style(
            'fluxa-chatbot-style',
            FLUXA_PLUGIN_URL . 'assets/css/chatbot.css',
            array(),
            FLUXA_VERSION
        );
        
        wp_enqueue_script(
            'fluxa-chatbot-script',
            FLUXA_PLUGIN_URL . 'assets/js/chatbot.js',
            array('jquery'),
            FLUXA_VERSION,
            true
        );
        
        // Localize script with settings (clean base)
        wp_localize_script('fluxa-chatbot-script', 'fluxaChatbot', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'settings' => $design_settings,
            'i18n' => array(
                'chatWithUs' => __('Chat with us', 'fluxa-ecommerce-assistant'),
                'send' => __('Send', 'fluxa-ecommerce-assistant'),
                'typeMessage' => __('Type your message...', 'fluxa-ecommerce-assistant'),
                'error' => __('An error occurred. Please try again.', 'fluxa-ecommerce-assistant')
            )
        ));
        
        // Include chatbot HTML
        include FLUXA_PLUGIN_DIR . 'templates/chatbot-widget.php';
    }

    /**
     * TEST helper: echo the current chat user id in the footer (PHP side)
     */
    public function echo_current_user_id_test() {
        if (is_admin()) { return; }
        if (!class_exists('Fluxa_User_ID_Service')) { return; }
        $id = \Fluxa_User_ID_Service::get_or_create_current_user_id();
        if (!empty($id)) {
            echo "\n<!-- fluxa_php_user_id: {$id} -->\n";
            echo '<div style="position:fixed;left:10px;bottom:10px;background:#111;color:#0f0;padding:6px 10px;font:12px/1.4 monospace;z-index:999999;opacity:0.8;border-radius:4px;">Fluxa PHP User ID: ' . esc_html($id) . '</div>' . "\n";
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options if they don't exist
        if (false === get_option('fluxa_api_key')) {
            update_option('fluxa_api_key', '');
        }
        
        if (false === get_option('fluxa_conversation_types')) {
            update_option('fluxa_conversation_types', array(
                'order_status' => 1,
                'order_tracking' => 1,
                'cart_abandoned' => 1
            ));
        }
        
        if (false === get_option('fluxa_design_settings')) {
            update_option('fluxa_design_settings', array(
                'chatbot_name' => __('Chat Assistant', 'fluxa-ecommerce-assistant'),
                'alignment' => 'right',
                'gap_from_bottom' => 20,
                'logo_url' => ''
            ));
        }
        // Seed default greeting if missing
        if (false === get_option('fluxa_greeting_text')) {
            // License validation handled by admin_api_key_notice(); do not echo notices here
            $sitename = get_bloginfo('name');
            $default_greet = sprintf(
                /* translators: %s: Site name */
                __("Hello! I'm %s customer assistant. How can I help you today?", 'fluxa-ecommerce-assistant'),
                $sitename
            );
            update_option('fluxa_greeting_text', $default_greet);
        }
        
        // Always set the quickstart flag on activation so admins are redirected to setup
        update_option('fluxa_show_quickstart', '1');
        // Set a one-time activation redirect flag
        update_option('fluxa_do_activation_redirect', '1');
        
        // Create necessary database tables if needed
        $this->create_tables();
    }
    
    /**
     * Add admin notice for quickstart
     */
    public function admin_notices() {
        // Only show on admin pages and not on our plugin pages
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'fluxa-') !== false) {
            return;
        }
        
        // Check if we should show the notice
        if (get_option('fluxa_show_quickstart') !== '1' || 
            get_option('fluxa_quickstart_completed') === '1' ||
            !current_user_can('manage_options') ||
            get_transient('fluxa_notice_dismissed_quickstart') === '1') {
            return;
        }
        
        $quickstart_url = admin_url('admin.php?page=fluxa-quickstart');
        ?>
        <div class="notice notice-info fluxa-quickstart-notice" style="position: relative; padding-right: 38px;">
            <div style="max-width: 800px;">
                <h3 style="margin: 0.5em 0;">
                    <?php _e('👋 Welcome to Fluxa eCommerce Assistant!', 'fluxa-ecommerce-assistant'); ?>
                </h3>
                <p style="margin: 0.5em 0 1em;">
                    <?php _e('Get started with our quick setup wizard to configure your chatbot in just a few minutes.', 'fluxa-ecommerce-assistant'); ?>
                </p>
                <p style="margin: 1em 0;">
                    <a href="<?php echo esc_url($quickstart_url); ?>" class="button button-primary" style="margin-right: 10px;">
                        <?php _e('Start Quick Setup', 'fluxa-ecommerce-assistant'); ?>
                    </a>
                </p>
            </div>
        </div>
        
        <?php
    }
    
    /**
     * Handle quickstart skip action
     */
    public function handle_quickstart_actions() {
        // Skip actions are disabled to enforce mandatory completion

        // Handle explicit request to restart Quickstart from Dashboard notice
        if (isset($_GET['fluxa_restart_quickstart']) && $_GET['fluxa_restart_quickstart'] === '1') {
            // Capability check
            if (!current_user_can('manage_options')) {
                return;
            }
            // Nonce check
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'fluxa_restart_quickstart')) {
                wp_die(__('aaaSecurity check failed.', 'fluxa-ecommerce-assistant'));
            }
            // Reset flags
            update_option('fluxa_show_quickstart', '1');
            update_option('fluxa_quickstart_completed', '0');
            // Redirect to first step of the wizard
            wp_safe_redirect(admin_url('admin.php?page=fluxa-quickstart&step=1'));
            exit;
        }
    }

    /**
     * One-time redirect to Quickstart after activation
     */
    public function maybe_activation_redirect() {
        // Only run in admin and for users who can manage options
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        // Only on first load after activation
        if (get_option('fluxa_do_activation_redirect') !== '1') {
            return;
        }
        // Do not redirect on network admin or during bulk activation
        if (is_network_admin() || isset($_GET['activate-multi'])) {
            delete_option('fluxa_do_activation_redirect');
            return;
        }
        // Avoid redirect loop if already on quickstart
        if (isset($_GET['page']) && $_GET['page'] === 'fluxa-quickstart') {
            delete_option('fluxa_do_activation_redirect');
            return;
        }
        // Perform redirect and clear the flag
        delete_option('fluxa_do_activation_redirect');
        wp_safe_redirect(admin_url('admin.php?page=fluxa-quickstart'));
        exit;
    }
    
    /**
     * AJAX handler for dismissing notices
     */
    public function ajax_dismiss_notice() {
        // Verify nonce
        check_ajax_referer('fluxa_dismiss_notice', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'fluxa-ecommerce-assistant'));
        }
        
        $notice = isset($_POST['notice']) ? sanitize_key($_POST['notice']) : '';
        
        if ('quickstart' === $notice) {
            // Set a transient to hide the notice for 7 days
            set_transient('fluxa_notice_dismissed_quickstart', '1', WEEK_IN_SECONDS);
            wp_send_json_success();
        }
        
        wp_send_json_error(__('Invalid notice.', 'fluxa-ecommerce-assistant'));
    }
    
    /**
     * Register quickstart menu
     */
    public function register_quickstart_menu() {
        add_menu_page(
            __('Fluxa Quickstart', 'fluxa-ecommerce-assistant'),
            __('Fluxa', 'fluxa-ecommerce-assistant'),
            'manage_options',
            'fluxa-quickstart',
            array($this, 'render_quickstart_page'),
            'dashicons-format-chat',
            30
        );
        
        // Remove the duplicate submenu
        remove_submenu_page('fluxa-quickstart', 'fluxa-quickstart');
    }
    
    /**
     * Render quickstart page
     */
    public function render_quickstart_page() {
        // Enqueue quickstart styles
        wp_enqueue_style(
            'fluxa-quickstart-style',
            FLUXA_PLUGIN_URL . 'assets/css/quickstart.css',
            array(),
            FLUXA_VERSION
        );
        
        // Include the quickstart template
        include FLUXA_PLUGIN_DIR . 'templates/quickstart.php';
    }
    
    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = array();
        
        // Add your table creation SQL here if needed
        // Example:
        // $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fluxa_chat_history (
        //     id bigint(20) NOT NULL AUTO_INCREMENT,
        //     user_id bigint(20) NOT NULL,
        //     message text NOT NULL,
        //     response text NOT NULL,
        //     created_at datetime DEFAULT CURRENT_TIMESTAMP,
        //     PRIMARY KEY  (id),
        //     KEY user_id (user_id)
        // ) $charset_collate;";
        
        if (!empty($sql)) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }

    /**
     * Provision a Sensay owner user when licence is valid and no owner exists yet.
     * Saves resulting ID to option 'fluxa_ss_owner_user_id'.
     */
    public function maybe_provision_owner_user() {
        if (!current_user_can('manage_options')) {
            if (function_exists('fluxa_log')) { fluxa_log('provision_owner: skipped (insufficient capability)'); }
            return;
        }
        $owner_id = get_option('fluxa_ss_owner_user_id', '');
        if (!empty($owner_id)) {
            if (function_exists('fluxa_log')) { fluxa_log('provision_owner: already exists id=' . $owner_id); }
            return;
        }
        $api_key = get_option('fluxa_api_key', '');
        if (!$this->verify_licence($api_key)) {
            if (function_exists('fluxa_log')) { fluxa_log('provision_owner: skipped (invalid licence)'); }
            return;
        }
        if (function_exists('fluxa_log')) { fluxa_log('provision_owner: start'); }
        // Use service for payload building + API call
        $service = $this->get_replica_service();
        if ($service) {
            $res = $service->provision_owner();
            if (is_wp_error($res)) {
                update_option('fluxa_last_error', 'Sensay user create failed: ' . $res->get_error_message(), false);
                if (function_exists('fluxa_log')) { fluxa_log('provision_owner: error ' . $res->get_error_message()); }
                return;
            }
            $code = intval($res['code'] ?? 0);
            $body = $res['body'] ?? array();
            if (function_exists('fluxa_log')) { fluxa_log('provision_owner: response code=' . $code . ' body=' . wp_json_encode($body)); }
            if (in_array($code, array(200, 201), true)) {
                $new_id = $body['id'] ?? ($body['uuid'] ?? '');
                if (!empty($new_id)) {
                    update_option('fluxa_ss_owner_user_id', $new_id, false);
                    // Also store on the current admin user meta for reference
                    $uid = get_current_user_id();
                    if ($uid) {
                        update_user_meta($uid, 'fluxa_ss_user_id', $new_id);
                        if (function_exists('fluxa_log')) { fluxa_log('provision_owner: saved user_meta fluxa_ss_user_id for user ' . $uid); }
                    }
                    if (function_exists('fluxa_log')) { fluxa_log('provision_owner: success id=' . $new_id); }
                } else {
                    update_option('fluxa_last_error', 'Sensay user create returned success without id', false);
                    if (function_exists('fluxa_log')) { fluxa_log('provision_owner: success without id field'); }
                }
            } else {
                update_option('fluxa_last_error', 'Sensay user create HTTP ' . $code . ' ' . wp_json_encode($body), false);
                if (function_exists('fluxa_log')) { fluxa_log('provision_owner: http_error code=' . $code); }
            }
        } else {
            if (function_exists('fluxa_log')) { fluxa_log('provision_owner: Sensay_Replica_Service missing'); }
        }
    }

    /**
     * Provision a Sensay chatbox replica when licence is valid and no replica exists yet.
     * Saves resulting ID to option 'fluxa_ss_replica_id'.
     */
    public function maybe_provision_replica() {

        if (function_exists('fluxa_log')) { fluxa_log('provision_replica: starting'); }


        if (!current_user_can('manage_options')) {
            if (function_exists('fluxa_log')) { fluxa_log('provision_replica: skipped (insufficient capability)'); }
            return;
        }
        $replica_id = get_option('fluxa_ss_replica_id', '');
        if (!empty($replica_id)) {
            if (function_exists('fluxa_log')) { fluxa_log('provision_replica: already exists id=' . $replica_id); }
            return;
        }
        $owner_id = get_option('fluxa_ss_owner_user_id', '');
        if (empty($owner_id)) {
            if (function_exists('fluxa_log')) { fluxa_log('provision_replica: skipped (missing owner user id)'); }
            return;
        }
        $api_key = get_option('fluxa_api_key', '');
        if (!$this->verify_licence($api_key)) {
            if (function_exists('fluxa_log')) { fluxa_log('provision_replica: skipped (invalid licence)'); }
            return;
        }
        $site_name = get_bloginfo('name') ?: 'store';
        $design = get_option('fluxa_design_settings', array());
        // Use service for payload building + API call
        $service = $this->get_replica_service();
        if ($service) {
            $res = $service->provision_replica($owner_id, $site_name, $design);
            if (is_wp_error($res)) {
                update_option('fluxa_last_error', 'Sensay replica create failed: ' . $res->get_error_message(), false);
                if (function_exists('fluxa_log')) { fluxa_log('provision_replica: error ' . $res->get_error_message()); }
                return;
            }
            $code = intval($res['code'] ?? 0);
            $body = $res['body'] ?? array();
            if (function_exists('fluxa_log')) { fluxa_log('provision_replica: response code=' . $code . ' body=' . wp_json_encode($body)); }
            if (in_array($code, array(200, 201), true)) {
                $new_id = $body['id'] ?? ($body['uuid'] ?? '');
                if (!empty($new_id)) {
                    update_option('fluxa_ss_replica_id', $new_id, false);
                    // Ensure current admin user also has the owner id stored if missing
                    $uid = get_current_user_id();
                    if ($uid) {
                        $existing = get_user_meta($uid, 'fluxa_ss_user_id', true);
                        if (empty($existing) && !empty($owner_id)) {
                            update_user_meta($uid, 'fluxa_ss_user_id', $owner_id);
                            if (function_exists('fluxa_log')) { fluxa_log('provision_replica: ensured user_meta fluxa_ss_user_id for user ' . $uid); }
                        }
                    }
                    if (function_exists('fluxa_log')) { fluxa_log('provision_replica: success id=' . $new_id); }
                } else {
                    update_option('fluxa_last_error', 'Sensay replica create returned success without id', false);
                    if (function_exists('fluxa_log')) { fluxa_log('provision_replica: success without id field'); }
                }
            } else {
                update_option('fluxa_last_error', 'Sensay replica create HTTP ' . $code . ' ' . wp_json_encode($body), false);
                if (function_exists('fluxa_log')) { fluxa_log('provision_replica: http_error code=' . $code); }
            }
        } else {
            if (function_exists('fluxa_log')) { fluxa_log('provision_replica: Sensay_Replica_Service missing'); }
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Unschedule any auto import rules
        $rules = get_option('fluxa_auto_import_rules', []);
        if (is_array($rules)) {
            foreach ($rules as $rid => $rule) {
                while ($ts = wp_next_scheduled('fluxa_auto_import_run', ['rule_id' => $rid])) {
                    wp_unschedule_event($ts, 'fluxa_auto_import_run', ['rule_id' => $rid]);
                }
            }
        }
    }
}

// Initialize the plugin
function fluxa_ecommerce_assistant() {
    return Fluxa_eCommerce_Assistant::get_instance();
}

// Start the plugin
fluxa_ecommerce_assistant();


/**
 * Ensure additional cron schedules are available
 */
add_filter('cron_schedules', function($schedules){
    if (!isset($schedules['weekly'])) {
        $schedules['weekly'] = [
            'interval' => 7 * DAY_IN_SECONDS,
            'display'  => __('Once Weekly', 'wp-fluxa-ecommerce-assistant')
        ];
    }
    if (!isset($schedules['monthly'])) {
        $schedules['monthly'] = [
            'interval' => 30 * DAY_IN_SECONDS,
            'display'  => __('Once Monthly', 'wp-fluxa-ecommerce-assistant')
        ];
    }
    return $schedules;
});

/**
 * Handler for Auto Imports cron events
 *
 * @param string $rule_id The rule ID passed when scheduling
 */
function fluxa_handle_auto_import_rule($rule_id) {
    if (empty($rule_id)) { return; }
    $rules = get_option('fluxa_auto_import_rules', []);
    if (!is_array($rules) || empty($rules[$rule_id])) { return; }

    $rule = $rules[$rule_id];

    // Execute import based on source
    $ok = true;
    switch ($rule['source'] ?? 'wordpress_content') {
        case 'wordpress_content':
        default:
            /**
             * Developers can hook into this action to perform the actual import.
             * The callback should return a boolean to indicate success (optional).
             */
            do_action('fluxa_auto_import_execute', $rule_id, $rule);
            $ok = true;
            break;
    }

    // Update bookkeeping
    $rules[$rule_id]['last_run'] = time();
    $rules[$rule_id]['total_runs'] = (int)($rules[$rule_id]['total_runs'] ?? 0) + 1;
    update_option('fluxa_auto_import_rules', $rules);

    return $ok;
}
add_action('fluxa_auto_import_run', 'fluxa_handle_auto_import_rule', 10, 1);


add_action('wp_footer', function(){
    $plugin = sca_detect_tracking_plugin();

switch ($plugin) {
    case 'shipment_tracking':
        echo "WooCommerce Shipment Tracking is active";
        break;
    case 'aftership':
        echo "AfterShip is active";
        break;
    case 'ast':
        echo "Advanced Shipment Tracking (AST) is active";
        break;
    default:
        echo "No known shipment tracking plugin detected";
}

});

function flx_collect_tracking_info($order_id) {
    $tracking = [];

    // Get the order via CRUD (works for posts + HPOS tables)
    $order = wc_get_order($order_id);
    if (!$order) {
        return $tracking;
    }

    // 1) WooCommerce Shipment Tracking (official)
    $wst_items = $order->get_meta('_wc_shipment_tracking_items', true);
    if (!empty($wst_items) && is_array($wst_items)) {
        foreach ($wst_items as $t) {
            $tracking[] = [
                'provider' => $t['tracking_provider']   ?? '',
                'number'   => $t['tracking_number']     ?? '',
                'url'      => $t['custom_tracking_link'] ?? ($t['tracking_link'] ?? ''),
                'date'     => $t['date_shipped']        ?? '',
                'source'   => 'WooCommerce Shipment Tracking',
            ];
        }
    }

    // 2) AfterShip
    $aftership_number   = $order->get_meta('_aftership_tracking_number', true);
    $aftership_provider = $order->get_meta('_aftership_tracking_provider', true);
    $aftership_url      = $order->get_meta('_aftership_tracking_url', true);
    if ($aftership_number) {
        $tracking[] = [
            'provider' => $aftership_provider ?: '',
            'number'   => $aftership_number,
            'url'      => $aftership_url ?: '',
            'date'     => '',
            'source'   => 'AfterShip',
        ];
    }

    // 3) Advanced Shipment Tracking (AST)
    $ast_items = $order->get_meta('_ast_tracking_items', true);
    if (!empty($ast_items) && is_array($ast_items)) {
        foreach ($ast_items as $t) {
            $tracking[] = [
                'provider' => $t['ast_tracking_provider'] ?? '',
                'number'   => $t['ast_tracking_number']   ?? '',
                'url'      => $t['ast_tracking_link']     ?? '',
                'date'     => $t['ast_date_shipped']      ?? '',
                'source'   => 'Advanced Shipment Tracking',
            ];
        }
    }

    // 4) Custom meta fallback (if your own plugin/theme saved keys)
    $custom_number = $order->get_meta('_tracking_number', true);
    if ($custom_number) {
        $tracking[] = [
            'provider' => (string) $order->get_meta('_tracking_provider', true),
            'number'   => (string) $custom_number,
            'url'      => (string) $order->get_meta('_tracking_url', true),
            'date'     => '',
            'source'   => 'Custom Meta',
        ];
    }

    return $tracking;
}

/**
 * Detect which shipment tracking plugin is active.
 *
 * @return string One of: 'shipment_tracking', 'aftership', 'ast', 'none'
 */
function sca_detect_tracking_plugin() {
    // Make sure we can use is_plugin_active outside wp-admin
    if ( !function_exists('is_plugin_active') ) {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    // 1) WooCommerce Shipment Tracking (official)
    if ( is_plugin_active('woocommerce-shipment-tracking/woocommerce-shipment-tracking.php') 
        || class_exists('WC_Shipment_Tracking') ) {
        return 'shipment_tracking';
    }

    // 2) AfterShip
    if ( is_plugin_active('aftership-woocommerce-tracking/aftership-woocommerce-tracking.php') 
        || class_exists('AfterShip') ) {
        return 'aftership';
    }

    // 3) Advanced Shipment Tracking (AST)
    if ( is_plugin_active('woo-advanced-shipment-tracking/woocommerce-advanced-shipment-tracking.php') 
        || class_exists('WC_Advanced_Shipment_Tracking') ) {
        return 'ast';
    }

    // None found
    return 'none';
}

function generate_random_email() {

    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $max   = strlen($chars) - 1;
    
    $domain = 'sddsadsadsadsad.com';

    $local = '';

    for ($i = 0; $i < 15; $i++) {
        $local .= $chars[random_int(0, $max)]; 
    }

    return $local . '@' . $domain;
}