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

// Plugin version
define('FLUXA_VERSION', '1.0.0');

// Plugin Folder Path
define('FLUXA_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Plugin Folder URL
define('FLUXA_PLUGIN_URL', plugin_dir_url(__FILE__));

// Plugin Root File
define('FLUXA_PLUGIN_FILE', __FILE__);

// Sensay API constants
if (!defined('SENSAY_API_VERSION')) {
    define('SENSAY_API_VERSION', '2025-03-25');
}
if (!defined('SENSAY_API_BASE')) {
    define('SENSAY_API_BASE', 'https://api.sensay.io');
}

// Include required files
require_once FLUXA_PLUGIN_DIR . 'includes/admin/class-admin-menu.php';
require_once FLUXA_PLUGIN_DIR . 'includes/api/class-sensay-client.php';
require_once FLUXA_PLUGIN_DIR . 'includes/api/class-sensay-replica-service.php';
require_once FLUXA_PLUGIN_DIR . 'includes/utils/class-user-id-service.php';
require_once FLUXA_PLUGIN_DIR . 'includes/utils/class-event-tracker.php';

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
     * AJAX (admin): fetch a page of conversation messages using beforeUUID cursor
     */
    public function ajax_admin_conv_messages() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'forbidden'), 403);
        }
        $replica_id = get_option('fluxa_ss_replica_id', '');
        $conversation_id = isset($_POST['conversation_id']) ? sanitize_text_field(wp_unslash($_POST['conversation_id'])) : '';
        $before = isset($_POST['beforeUUID']) ? sanitize_text_field(wp_unslash($_POST['beforeUUID'])) : '';
        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 100;
        if ($limit <= 0 || $limit > 100) { $limit = 100; }
        if (empty($replica_id) || empty($conversation_id)) {
            wp_send_json_error(array('error' => 'missing_params'), 400);
        }
        if (!class_exists('Sensay_Client')) {
            wp_send_json_error(array('error' => 'client_unavailable'), 500);
        }
        $client = new Sensay_Client();
        $path = '/v1/replicas/' . rawurlencode($replica_id) . '/conversations/' . rawurlencode($conversation_id) . '/messages?limit=' . $limit;
        if ($before !== '') {
            $path .= '&beforeUUID=' . rawurlencode($before);
        }
        $res = $client->get($path);
        if (is_wp_error($res)) {
            wp_send_json_error(array('error' => $res->get_error_message()), 502);
        }
        $code = (int)($res['code'] ?? 0);
        $body = $res['body'] ?? array();
        if ($code < 200 || $code >= 300 || !is_array($body)) {
            $msg = isset($body['error']) ? (string)$body['error'] : ('HTTP ' . $code);
            wp_send_json_error(array('error' => $msg, 'status' => $code), $code ?: 500);
        }
        $items = isset($body['items']) && is_array($body['items']) ? $body['items'] : array();
        // Normalize
        $out = array();
        foreach ($items as $m) {
            $out[] = array(
                'id' => (string)($m['id'] ?? ($m['uuid'] ?? '')),
                'role' => strtolower((string)($m['role'] ?? 'user')) === 'assistant' ? 'assistant' : 'user',
                'content' => (string)($m['content'] ?? ''),
                'createdAt' => (string)($m['createdAt'] ?? ''),
                'senderName' => (string)($m['senderName'] ?? ''),
                'senderProfileImageURL' => (string)($m['senderProfileImageURL'] ?? ''),
            );
        }
        wp_send_json_success(array('items' => $out));
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
        // REST API routes
        add_action('rest_api_init', array($this, 'register_routes'));
        
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Register admin-related hooks
        add_action('admin_init', array($this, 'handle_quickstart_actions'));
        // Handle one-time activation redirect early
        add_action('admin_init', array($this, 'maybe_activation_redirect'), 1);
        // Merge guest events into user on login if enabled
        add_action('wp_login', array($this, 'merge_guest_events_on_login'), 20, 2);
        
        // Quickstart menu is registered under the main menu in Fluxa_Admin_Menu::add_admin_menus
        
        // AJAX handlers
        add_action('wp_ajax_fluxa_dismiss_notice', array($this, 'ajax_dismiss_notice'));
        // Training list refresh (admin only)
        add_action('wp_ajax_fluxa_kb_list', array($this, 'ajax_kb_list'));
        // Admin: fetch older conversation messages (pagination)
        add_action('wp_ajax_fluxa_admin_conv_messages', array($this, 'ajax_admin_conv_messages'));

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

        // Initialize event tracking hooks
        $this->init_event_tracking();

        // Add frontend hooks
        $this->init_frontend();
        
        // Add REST API route for event tracking
        add_action('rest_api_init', array($this, 'register_event_tracking_route'));
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        register_rest_route('fluxa/v1', '/chat', array(
            array(
                'methods'  => 'POST',
                'permission_callback' => '__return_true',
                'args' => array(
                    'content' => array(
                        'required' => true,
                        'type' => 'string',
                    ),
                    'skip_chat_history' => array(
                        'required' => false,
                        'type' => 'boolean',
                        'default' => false,
                    ),
                ),
                'callback' => array($this, 'rest_chat_completion'),
            ),
        ));

        // Admin: list conversations (AJAX for Chat History page)
        register_rest_route('fluxa/v1', '/admin/conversations', array(
            array(
                'methods'  => 'GET',
                'permission_callback' => function(){ return current_user_can('manage_options'); },
                'callback' => array($this, 'rest_list_conversations'),
            ),
        ));

        // Admin: resolve UUIDs to user labels
        register_rest_route('fluxa/v1', '/admin/uuid-labels', array(
            array(
                'methods'  => 'GET',
                'permission_callback' => function(){ return current_user_can('manage_options'); },
                'args' => array(
                    'uuids' => array('required' => true, 'type' => 'string'),
                ),
                'callback' => array($this, 'rest_uuid_labels'),
            ),
        ));

        // Admin: fetch presence info for conversations (last_seen and recent events by ss_user_id)
        register_rest_route('fluxa/v1', '/admin/last-seen', array(
            array(
                'methods'  => 'GET',
                'permission_callback' => function(){ return current_user_can('manage_options'); },
                'args' => array(
                    'uuids' => array('required' => true, 'type' => 'string'),
                ),
                'callback' => array($this, 'rest_conversation_last_seen'),
            ),
        ));

        // Frontend: get chat history for current visitor
        register_rest_route('fluxa/v1', '/chat/history', array(
            array(
                'methods'  => 'GET',
                'permission_callback' => '__return_true',
                'callback' => array($this, 'rest_chat_history'),
            ),
        ));

        // Public: track conversation appearance and upsert conv mapping
        register_rest_route('fluxa/v1', '/conversation/track', array(
            array(
                'methods'  => 'POST',
                'permission_callback' => '__return_true',
                'args' => array(
                    'conversation_id' => array('required' => true, 'type' => 'string'),
                    'ss_user_id' => array('required' => false, 'type' => 'string'),
                ),
                'callback' => array($this, 'rest_track_conversation'),
            ),
        ));

        // Public: fetch WooCommerce session key (best-effort) for clients without cookies on REST
        register_rest_route('fluxa/v1', '/wc/session', array(
            array(
                'methods'  => 'GET',
                'permission_callback' => '__return_true',
                'callback' => array($this, 'rest_get_wc_session_key'),
            ),
        ));

        // Admin: per-user preferences (e.g., newest-first for journey)
        register_rest_route('fluxa/v1', '/admin/prefs', array(
            array(
                'methods'  => 'GET',
                'permission_callback' => function(){ return current_user_can('manage_options'); },
                'callback' => function(\WP_REST_Request $request){
                    $user_id = get_current_user_id();
                    $newest_first = (bool) get_user_meta($user_id, 'fluxa_admin_newest_first', true);
                    return new \WP_REST_Response(array('ok' => true, 'newest_first' => $newest_first), 200);
                }
            ),
            array(
                'methods'  => 'POST',
                'permission_callback' => function(){ return current_user_can('manage_options'); },
                'args' => array(
                    'newest_first' => array('required' => false, 'type' => 'boolean'),
                ),
                'callback' => function(\WP_REST_Request $request){
                    $user_id = get_current_user_id();
                    $nf = $request->get_param('newest_first');
                    if ($nf !== null) {
                        update_user_meta($user_id, 'fluxa_admin_newest_first', (bool)$nf);
                    }
                    $newest_first = (bool) get_user_meta($user_id, 'fluxa_admin_newest_first', true);
                    return new \WP_REST_Response(array('ok' => true, 'newest_first' => $newest_first), 200);
                }
            ),
        ));

        // Feedback: submit rating
        register_rest_route('fluxa/v1', '/feedback', array(
            'methods'  => 'POST',
            'permission_callback' => '__return_true',
            'args' => array(
                'conversation_id' => array(
                    'required' => true,
                    'type' => 'string',
                ),
                'rating_point' => array(
                    'required' => true,
                    'type' => 'integer',
                ),
            ),
            'callback' => array($this, 'rest_submit_feedback')
        ));
    }

    /**
     * REST: Submit feedback rating
     */
    public function rest_submit_feedback(\WP_REST_Request $request) {
        global $wpdb;
        $table = $wpdb->prefix . 'fluxa_feedback';
        $conversation_id = trim((string)$request->get_param('conversation_id'));
        $rating = (int)$request->get_param('rating_point');
        // Optional page context to avoid logging REST URL in events
        $page_url = $request->get_param('page_url');
        $page_ref = $request->get_param('page_referrer');
        $page_url = is_string($page_url) ? esc_url_raw($page_url) : '';
        $page_ref = is_string($page_ref) ? esc_url_raw($page_ref) : '';
        if ($conversation_id === '' || $rating < 1 || $rating > 5) {
            return new \WP_REST_Response(array('ok' => false, 'error' => 'invalid_params'), 400);
        }
        $data = array(
            'conversation_id' => $conversation_id,
            'rating_point' => $rating,
            'created_at' => current_time('mysql', true),
        );
        $ok = (bool)$wpdb->insert($table, $data, array('%s','%d','%s'));
        if (!$ok) {
            return new \WP_REST_Response(array('ok' => false, 'error' => 'db_insert_failed', 'db_error' => $wpdb->last_error), 500);
        }
        // Also log as a tracked event for analytics
        $evt_id = false;
        if (class_exists('Fluxa_Event_Tracker')) {
            try {
                $evt_payload = array(
                    'json_payload' => array(
                        'conversation_id' => $conversation_id,
                        'rating_point'    => $rating,
                    )
                );
                if ($page_url !== '') { $evt_payload['page_url'] = $page_url; }
                if ($page_ref !== '') { $evt_payload['page_referrer'] = $page_ref; }
                $evt_id = \Fluxa_Event_Tracker::log_event('feedback_given', $evt_payload);
            } catch (\Throwable $e) {
                // Silent: do not fail response because of event logging
            }
        }
        return new \WP_REST_Response(array('ok' => true, 'id' => (int)$wpdb->insert_id, 'event_id' => $evt_id ? (int)$evt_id : null), 200);
    }

    /**
     * REST: List replica conversations for admin (with paging and sorting)
     */
    public function rest_list_conversations(\WP_REST_Request $request) {
        $replica_id = get_option('fluxa_ss_replica_id', '');
        if (empty($replica_id)) {
            return new \WP_REST_Response(array('ok' => false, 'error' => 'replica_not_ready'), 503);
        }
        $client = $this->get_sensay_client();
        // Read paging/sorting params and clamp
        $page = max(1, (int)$request->get_param('page'));
        $pageSize = (int)$request->get_param('pageSize');
        if ($pageSize <= 0 || $pageSize > 100) { $pageSize = 24; }
        $sortBy = (string)($request->get_param('sortBy') ?? 'lastReplicaReplyAt');
        $sortOrder = strtolower((string)($request->get_param('sortOrder') ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $qs = http_build_query(array(
            'page' => $page,
            'pageSize' => $pageSize,
            'sortBy' => $sortBy,
            'sortOrder' => $sortOrder,
        ));
        $path = '/v1/replicas/' . rawurlencode($replica_id) . '/conversations?' . $qs;
        $res = $client->get($path);
        if (is_wp_error($res)) {
            return new \WP_REST_Response(array('ok' => false, 'error' => $res->get_error_message()), 502);
        }
        $code = (int)($res['code'] ?? 0);
        $body = $res['body'] ?? array();
        if ($code < 200 || $code >= 300) {
            return new \WP_REST_Response(array('ok' => false, 'status' => $code, 'body' => $body), $code ?: 502);
        }
        $items = isset($body['items']) && is_array($body['items']) ? $body['items'] : array();
        $total = (int)($body['total'] ?? count($items));
        return new \WP_REST_Response(array(
            'ok' => true,
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'sortBy' => $sortBy,
            'sortOrder' => $sortOrder,
            'totalPages' => ($pageSize > 0 ? (int)ceil($total / $pageSize) : 1),
        ), 200);
    }

/**
 * REST: map UUIDs to user display names (from user meta fluxa_ss_user_id). If no match, label 'Guest'.
 */
public function rest_uuid_labels(\WP_REST_Request $request) {
    $uuids_param = (string)$request->get_param('uuids');
    $uuids = array_filter(array_map('trim', explode(',', $uuids_param)));
    $labels = array();
    if (empty($uuids)) {
        return new \WP_REST_Response(array('ok' => true, 'labels' => $labels), 200);
    }
    global $wpdb;
    // Fetch users that have meta fluxa_ss_user_id in the given set
    $placeholders = implode(',', array_fill(0, count($uuids), '%s'));
    $meta_key = 'fluxa_ss_user_id';
    $sql = "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value IN ($placeholders)";
    $prepared = $wpdb->prepare($sql, array_merge(array($meta_key), $uuids));
    $rows = $wpdb->get_results($prepared, ARRAY_A);
    $map = array();
    if ($rows) {
        foreach ($rows as $r) {
            $map[$r['meta_value']] = (int)$r['user_id'];
        }
    }
    foreach ($uuids as $u) {
        if (isset($map[$u])) {
            $user = get_user_by('id', $map[$u]);
            $labels[$u] = $user ? (string)$user->display_name : 'Guest';
        } else {
            $labels[$u] = 'Guest';
        }
    }
    return new \WP_REST_Response(array('ok' => true, 'labels' => $labels), 200);
}

    /**
     * REST: return last_seen timestamps for a set of conversations (admin)
     */
    public function rest_conversation_last_seen(\WP_REST_Request $request) {
        global $wpdb;
        $uuids_param = (string)$request->get_param('uuids');
        $uuids = array_filter(array_map('trim', explode(',', $uuids_param)));
        $result = array();
        $events_recent = array();
        $online_map = array();
        if (empty($uuids)) {
            return new \WP_REST_Response(array('ok' => true, 'last_seen' => $result, 'events_recent' => $events_recent), 200);
        }
        $table_conv = $wpdb->prefix . 'fluxa_conv';
        $table_ev = $wpdb->prefix . 'fluxa_conv_events';
        $placeholders = implode(',', array_fill(0, count($uuids), '%s'));
        $sql = "SELECT conversation_id, last_seen, ss_user_id, wp_user_id, wc_session_key, last_ua FROM {$table_conv} WHERE conversation_id IN ($placeholders)";
        $prepared = $wpdb->prepare($sql, $uuids);
        $rows = $wpdb->get_results($prepared, ARRAY_A);
        $ss_ids = array();
        $conv_meta = array();
        $wp_user_ids = array();
        if ($rows) {
            foreach ($rows as $r) {
                $cid = (string)$r['conversation_id'];
                $result[$cid] = $r['last_seen'];
                $ssid = isset($r['ss_user_id']) ? trim((string)$r['ss_user_id']) : '';
                if ($ssid !== '') { $ss_ids[$ssid] = true; }
                $wp_uid = isset($r['wp_user_id']) ? (int)$r['wp_user_id'] : 0;
                $wc_sess = isset($r['wc_session_key']) ? (string)$r['wc_session_key'] : '';
                $last_ua = isset($r['last_ua']) ? (string)$r['last_ua'] : '';
                $conv_meta[$cid] = array('wp_user_id' => $wp_uid, 'wc_session_key' => $wc_sess, 'last_ua' => $last_ua);
                if ($wp_uid > 0) { $wp_user_ids[$wp_uid] = true; }
            }
        }
        // Compute events_recent within fallback window for gathered ss_user_id values
        if (!empty($ss_ids)) {
            $window_min = (int) apply_filters('fluxa_online_events_window_minutes', 2);
            $now_mysql = current_time('mysql');
            $ss_list = array_keys($ss_ids);
            $ph = implode(',', array_fill(0, count($ss_list), '%s'));
            $sql2 = "SELECT ss_user_id, MAX(event_time) AS last_ev FROM {$table_ev} WHERE ss_user_id IN ($ph) AND event_time >= DATE_SUB(%s, INTERVAL %d MINUTE) GROUP BY ss_user_id";
            $prepared2 = $wpdb->prepare($sql2, array_merge($ss_list, array($now_mysql, max(1, $window_min))));
            $rows2 = $wpdb->get_results($prepared2, ARRAY_A);
            if ($rows2) {
                foreach ($rows2 as $r2) {
                    $sid = (string)$r2['ss_user_id'];
                    $events_recent[$sid] = true;
                }
            }
        }
        // Resolve user display names for registered users appearing in this batch
        $user_names = array();
        if (!empty($wp_user_ids)) {
            $ids = array_keys($wp_user_ids);
            $phu = implode(',', array_fill(0, count($ids), '%d'));
            $sqlu = "SELECT ID, display_name FROM {$wpdb->users} WHERE ID IN ($phu)";
            $rowsu = $wpdb->get_results($wpdb->prepare($sqlu, $ids), ARRAY_A);
            if ($rowsu) {
                foreach ($rowsu as $ru) {
                    $user_names[(int)$ru['ID']] = (string)$ru['display_name'];
                }
            }
        }

        // Compute online per conversation id: last_seen within threshold OR events_recent for its ss_user_id
        $threshold_minutes = (int) apply_filters('fluxa_online_threshold_minutes', 5);
        $now_ts = current_time('timestamp');
        if ($rows) {
            foreach ($rows as $r) {
                $cid = (string)$r['conversation_id'];
                $ssid = isset($r['ss_user_id']) ? trim((string)$r['ss_user_id']) : '';
                $ls = isset($r['last_seen']) ? (string)$r['last_seen'] : '';
                $ls_ts = $ls ? strtotime($ls) : 0;
                $is_online = false;
                if ($ls_ts && ($now_ts - $ls_ts) <= max(1, $threshold_minutes) * 60) {
                    $is_online = true;
                } elseif ($ssid !== '' && !empty($events_recent[$ssid])) {
                    $is_online = true;
                }
                $online_map[$cid] = $is_online ? 1 : 0;
            }
        }
        return new \WP_REST_Response(array(
            'ok' => true,
            'last_seen' => $result,
            'events_recent' => $events_recent,
            'online' => $online_map,
            'conv_meta' => $conv_meta,
            'user_names' => $user_names,
        ), 200);
    }

    /**
     * REST callback: forward a chat completion to Sensay API
     */
    public function rest_chat_completion(\WP_REST_Request $request) {
        $content = trim((string)$request->get_param('content'));
        $skip = (bool)$request->get_param('skip_chat_history');
        $phase = (string)($request->get_param('phase') ?? '');

        if ($content === '') {
            return new \WP_REST_Response(array('ok' => false, 'error' => 'empty_content'), 400);
        }

        $replica_id = get_option('fluxa_ss_replica_id', '');
        if (empty($replica_id)) {
            return new \WP_REST_Response(array('ok' => false, 'error' => 'replica_not_ready'), 503);
        }

        if (!class_exists('Fluxa_User_ID_Service')) {
            return new \WP_REST_Response(array('ok' => false, 'error' => 'uid_service_unavailable'), 500);
        }
        $user_id = \Fluxa_User_ID_Service::get_or_create_current_user_id();
        if (empty($user_id)) {
            return new \WP_REST_Response(array('ok' => false, 'error' => 'user_id_missing'), 400);
        }

        // If our local visitor ID is not a UUID, create a Sensay user and persist returned UUID
        $newly_provisioned_uuid = '';
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $user_id)) {
            $client = $this->get_sensay_client();
            $site_name_raw = get_bloginfo('name') ?: 'Website';
            // Allow letters, numbers, space, parentheses, dot, comma, single-quote, dash, slash; remove everything else
            $pattern = "~[^A-Za-z0-9 \(\)\.,'\-\/]~";
            $site_name = trim(preg_replace($pattern, '', $site_name_raw));
            if ($site_name === '') { $site_name = 'Website'; }
            $visitor_name = trim($site_name . ' Visitor');
            $payload_user = array(
                'email' => function_exists('generate_random_email') ? generate_random_email() : (wp_generate_password(15, false) . '@example.com'),
                'name'  => $visitor_name,
            );
            $res_user = $client->post('/v1/users', $payload_user);
            if (!is_wp_error($res_user)) {
                $code_u = (int)($res_user['code'] ?? 0);
                $body_u = $res_user['body'] ?? array();
                if (in_array($code_u, array(200,201), true)) {
                    $sensay_uid = $body_u['id'] ?? ($body_u['uuid'] ?? '');
                    if (!empty($sensay_uid)) {
                        $newly_provisioned_uuid = $sensay_uid;
                        // Persist for current visitor
                        $wp_uid = get_current_user_id();
                        if ($wp_uid) {
                            update_user_meta($wp_uid, 'fluxa_ss_user_id', $sensay_uid);
                        } else {
                            \Fluxa_User_ID_Service::set_cookie($sensay_uid);
                        }
                        $user_id = $sensay_uid;
                    }
                } else {
                    // Stop here with a helpful error so we don't send an invalid X-USER-ID
                    return new \WP_REST_Response(array(
                        'ok' => false,
                        'error' => 'user_provision_failed',
                        'status' => $code_u,
                        'body' => $body_u,
                    ), $code_u ?: 500);
                }
            } else {
                return new \WP_REST_Response(array(
                    'ok' => false,
                    'error' => $res_user->get_error_message(),
                ), 502);
            }
        }

        // DECISION STEP: ask the model to return a one-line JSON for routing
        $client = $this->get_sensay_client();
        $headers = array('X-USER-ID' => $user_id);
        $path = '/v1/replicas/' . rawurlencode($replica_id) . '/chat/completions';

        $decision_prompt = "You are a router. Return exactly one line of JSON with keys action, args, interim_message, final_if_none.\n" .
            "Schema: {\"action\":\"none|get_order_status|get_tracking\",\"args\":{},\"interim_message\":\"\",\"final_if_none\":\"\"}.\n" .
            "User: " . $content . "\n" .
            "Return only JSON, no other text.";

        $res_dec = $client->post($path, array(
            'content' => $decision_prompt,
            'skip_chat_history' => true,
            'source' => 'web'
        ), $headers);
        if (is_wp_error($res_dec)) {
            return new \WP_REST_Response(array('ok' => false, 'error' => $res_dec->get_error_message()), 502);
        }
        $code_dec = (int)($res_dec['code'] ?? 0);
        $body_dec = $res_dec['body'] ?? array();
        if ($code_dec < 200 || $code_dec >= 300) {
            return new \WP_REST_Response(array('ok' => false, 'status' => $code_dec, 'body' => $body_dec), $code_dec ?: 502);
        }
        $decision_text = '';
        if (is_array($body_dec)) {
            if (isset($body_dec['content']) && is_string($body_dec['content'])) {
                $decision_text = trim($body_dec['content']);
            } elseif (isset($body_dec['message']['content'])) {
                $decision_text = trim((string)$body_dec['message']['content']);
            } elseif (isset($body_dec['text'])) {
                $decision_text = trim((string)$body_dec['text']);
            }
        }
        // Parse decision JSON (best-effort)
        $decision = null;
        $interim_msg = '';
        if ($decision_text !== '') {
            // Extract first JSON-looking segment
            $maybe = $decision_text;
            $start = strpos($maybe, '{'); $end = strrpos($maybe, '}');
            if ($start !== false && $end !== false && $end >= $start) {
                $maybe = substr($maybe, $start, $end - $start + 1);
            }
            $decoded = json_decode($maybe, true);
            if (is_array($decoded)) {
                $decision = $decoded;
                $interim_msg = isset($decoded['interim_message']) && is_string($decoded['interim_message']) ? $decoded['interim_message'] : '';
            }
        }
        if (!is_array($decision)) {
            // Fallback to normal chat if decision failed
            $payload = array('content' => $content, 'skip_chat_history' => $skip, 'source' => 'web');
            $res = $client->post($path, $payload, $headers);
            if (is_wp_error($res)) {
                return new \WP_REST_Response(array('ok' => false, 'error' => $res->get_error_message()), 502);
            }
            $code = (int)($res['code'] ?? 0);
            $body = $res['body'] ?? array();
            if ($code < 200 || $code >= 300) {
                return new \WP_REST_Response(array('ok' => false, 'status' => $code, 'body' => $body), $code ?: 502);
            }
            $text = '';
            if (isset($body['content'])) { $text = (string)$body['content']; }
            elseif (isset($body['message']['content'])) { $text = (string)$body['message']['content']; }
            elseif (isset($body['text'])) { $text = (string)$body['text']; }
            // Try to capture conversation id to persist messages
            $conv_id = '';
            if (is_array($body)) {
                $b = $body;
                if (!empty($b['conversation_uuid'])) { $conv_id = (string)$b['conversation_uuid']; }
                elseif (!empty($b['message']['conversation_uuid'])) { $conv_id = (string)$b['message']['conversation_uuid']; }
                elseif (!empty($b['message']['conversation']['uuid'])) { $conv_id = (string)$b['message']['conversation']['uuid']; }
                elseif (!empty($b['conversation']['uuid'])) { $conv_id = (string)$b['conversation']['uuid']; }
            }
            if ($conv_id !== '') {
                // Log user and assistant messages
                try { $this->insert_chat_message($conv_id, 'user', $content, false, $user_id, 'web'); } catch (\Throwable $e) {}
                try { $this->insert_chat_message($conv_id, 'assistant', $text, false, $user_id, 'web'); } catch (\Throwable $e) {}
            }
            return new \WP_REST_Response(array('ok' => true, 'text' => $text, 'mode' => 'sync', 'interim' => $interim_msg), 200);
        }

        $action = isset($decision['action']) ? (string)$decision['action'] : 'none';
        // If client asked only for decision phase, return early with interim + parsed args
        if ($phase === 'decision') {
            $args = isset($decision['args']) && is_array($decision['args']) ? $decision['args'] : array();
            // Try to enrich args with order_id from content for better UX
            if (empty($args['order_id'])) {
                if (preg_match('/(?:order\s*(?:id|#)\s*(?:is)?\s*)(\d{3,})/i', $content, $mm0)) {
                    $args['order_id'] = absint($mm0[1]);
                } elseif (preg_match('/\b(\d{4,})\b/', $content, $mm1)) {
                    $args['order_id'] = absint($mm1[1]);
                }
            }
            return new \WP_REST_Response(array(
                'ok' => true,
                'mode' => 'decision',
                'interim' => $interim_msg,
                'action' => $action,
                'args' => $args,
            ), 200);
        }
        // Conversation continuity: if router said 'none' but the user provided a bare order id, treat it as get_order_status
        if ($action === 'none') {
            $extracted_id = 0;
            if (preg_match('/(?:order\s*(?:id|#)\s*(?:is)?\s*)(\d{3,})/i', $content, $mm)) {
                $extracted_id = absint($mm[1]);
            } elseif (preg_match('/^\s*(\d{4,})\s*$/', $content, $mm2)) {
                $extracted_id = absint($mm2[1]);
            }
            if ($extracted_id) {
                $action = 'get_order_status';
                $decision['action'] = $action;
                $decision['args'] = array('order_id' => $extracted_id);
            }
        }
        if ($action === 'none') {
            $final_text = isset($decision['final_if_none']) && is_string($decision['final_if_none']) && $decision['final_if_none'] !== ''
                ? $decision['final_if_none']
                : __('Is there anything else I can help you with?', 'fluxa-ecommerce-assistant');
            // Without a known conversation id from the decision step, we can't persist reliably here
            return new \WP_REST_Response(array('ok' => true, 'text' => $final_text, 'mode' => 'sync', 'interim' => $interim_msg), 200);
        }

        // TOOL CALL: currently we support order status or tracking via WooCommerce
        $args = isset($decision['args']) && is_array($decision['args']) ? $decision['args'] : array();
        $order_id = isset($args['order_id']) ? absint($args['order_id']) : 0;
        // Fallback: extract order id from the user's message if missing
        if (!$order_id) {
            if (preg_match('/(?:order\s*(?:id|#)\s*(?:is)?\s*)(\d{3,})/i', $content, $m)) {
                $order_id = absint($m[1]);
            } elseif (preg_match('/\b(\d{4,})\b/', $content, $m2)) {
                // Last resort: any 4+ digit number
                $order_id = absint($m2[1]);
            }
        }
        if (!$order_id) {
            // Ask for order id/email clearly if still missing
            $ask = __('Could you please provide your order ID (or the email address used for the order) so I can look it up?', 'fluxa-ecommerce-assistant');
            return new \WP_REST_Response(array('ok' => true, 'text' => $ask, 'mode' => 'sync', 'interim' => $interim_msg), 200);
        }

        $tool_payload = $this->tool_get_order_status_payload($order_id);

        // If tool failed or order not found, reply gracefully without final model call
        if (empty($tool_payload['ok'])) {
            $msg = sprintf(__('I could not find details for order #%d right now. Please check the ID and try again, or share the email address on the order.', 'fluxa-ecommerce-assistant'), $order_id);
            return new \WP_REST_Response(array('ok' => true, 'text' => $msg, 'mode' => 'sync', 'interim' => $interim_msg), 200);
        }

        // FINAL STEP: Ask Sensay to formulate the answer using tool output
        $final_content = "System: You will be given TOOL_OUTPUT (JSON). Use it to answer clearly and naturally.\n" .
            "- Do NOT use tables or lists.\n" .
            "- If tracking information is present, reply with one or two concise sentences that summarize the order status.\n" .
            "- You may emphasize key details (such as the shipping provider, tracking number, shipped/delivery dates, or current status) using <strong> tags around just those values.\n" .
            "- If at least one tracking URL exists, append one sentence at the end: 'You can see the live tracking info by clicking this link.' where the words <strong>this link</strong> are a single <a> link. Prefer TOOL_OUTPUT.tracking_url if present; otherwise use the first available trackings[i].url. If no URL exists, omit this sentence.\n" .
            "- Keep the HTML minimal (no inline styles), and avoid any tabular formatting.\n" .
            "User: " . $content . "\n" .
            "User: TOOL_OUTPUT: " . wp_json_encode($tool_payload) . "\n" .
            "User: PRIMARY_TRACKING_URL: " . (isset($tool_payload['tracking_url']) ? (string)$tool_payload['tracking_url'] : '');

        $res_final = $client->post($path, array(
            'content' => $final_content,
            // Do not store this special prompt+answer in Sensay history to avoid showing the system instructions on refresh
            'skip_chat_history' => true,
            'source' => 'web'
        ), $headers);
        if (is_wp_error($res_final)) {
            return new \WP_REST_Response(array('ok' => false, 'error' => $res_final->get_error_message()), 502);
        }
        $code_f = (int)($res_final['code'] ?? 0);
        $body_f = $res_final['body'] ?? array();
        if ($code_f < 200 || $code_f >= 300) {
            return new \WP_REST_Response(array('ok' => false, 'status' => $code_f, 'body' => $body_f), $code_f ?: 502);
        }
        $final_text = '';
        if (isset($body_f['content'])) { $final_text = (string)$body_f['content']; }
        elseif (isset($body_f['message']['content'])) { $final_text = (string)$body_f['message']['content']; }
        elseif (isset($body_f['text'])) { $final_text = (string)$body_f['text']; }
        // Try to capture conversation id to persist messages (user -> interim(optional) -> final)
        $conv_id = '';
        if (is_array($body_f)) {
            $b = $body_f;
            if (!empty($b['conversation_uuid'])) { $conv_id = (string)$b['conversation_uuid']; }
            elseif (!empty($b['message']['conversation_uuid'])) { $conv_id = (string)$b['message']['conversation_uuid']; }
            elseif (!empty($b['message']['conversation']['uuid'])) { $conv_id = (string)$b['message']['conversation']['uuid']; }
            elseif (!empty($b['conversation']['uuid'])) { $conv_id = (string)$b['conversation']['uuid']; }
        }
        if ($conv_id !== '') {
            try { $this->insert_chat_message($conv_id, 'user', $content, false, $user_id, 'web'); } catch (\Throwable $e) {}
            if (!empty($interim_msg)) { try { $this->insert_chat_message($conv_id, 'assistant', $interim_msg, true, $user_id, 'web'); } catch (\Throwable $e) {} }
            try { $this->insert_chat_message($conv_id, 'assistant', $final_text, false, $user_id, 'web', array('order_id'=>$order_id)); } catch (\Throwable $e) {}
        }
        return new \WP_REST_Response(array('ok' => true, 'text' => $final_text, 'mode' => 'sync', 'interim' => $interim_msg), 200);
    }

    /**
     * REST: Fetch chat history for the current visitor from Sensay
     */
    public function rest_chat_history(\WP_REST_Request $request) {
        $replica_id = get_option('fluxa_ss_replica_id', '');
        if (empty($replica_id)) {
            return new \WP_REST_Response(array('ok' => false, 'error' => 'replica_not_ready'), 503);
        }
        if (!class_exists('Fluxa_User_ID_Service')) {
            return new \WP_REST_Response(array('ok' => false, 'error' => 'uid_service_unavailable'), 500);
        }
        $user_id = \Fluxa_User_ID_Service::get_or_create_current_user_id();
        if (empty($user_id)) {
            return new \WP_REST_Response(array('ok' => false, 'error' => 'user_id_missing'), 400);
        }
        // Auto-provision if not a UUID yet (guest first visit)
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $user_id)) {
            $client = $this->get_sensay_client();
            $site_name_raw = get_bloginfo('name') ?: 'Website';
            $pattern = "~[^A-Za-z0-9 \(\)\.,'\-\/]~";
            $site_name = trim(preg_replace($pattern, '', $site_name_raw));
            if ($site_name === '') { $site_name = 'Website'; }
            $visitor_name = trim($site_name . ' Visitor');
            $payload_user = array(
                'email' => function_exists('generate_random_email') ? generate_random_email() : (wp_generate_password(15, false) . '@example.com'),
                'name'  => $visitor_name,
            );
            $res_user = $client->post('/v1/users', $payload_user);
            if (!is_wp_error($res_user)) {
                $code_u = (int)($res_user['code'] ?? 0);
                $body_u = $res_user['body'] ?? array();
                if (in_array($code_u, array(200,201), true)) {
                    $sensay_uid = $body_u['id'] ?? ($body_u['uuid'] ?? '');
                    if (!empty($sensay_uid)) {
                        $wp_uid = get_current_user_id();
                        if ($wp_uid) {
                            update_user_meta($wp_uid, 'fluxa_ss_user_id', $sensay_uid);
                        } else {
                            \Fluxa_User_ID_Service::set_cookie($sensay_uid);
                        }
                        $user_id = $sensay_uid;
                    }
                } else {
                    return new \WP_REST_Response(array(
                        'ok' => false,
                        'error' => 'user_provision_failed',
                        'status' => $code_u,
                        'body' => $body_u,
                    ), $code_u ?: 500);
                }
            } else {
                return new \WP_REST_Response(array('ok' => false, 'error' => $res_user->get_error_message()), 502);
            }
        }
        $client = $this->get_sensay_client();
        $headers = array('X-USER-ID' => $user_id);
        $path = '/v1/replicas/' . rawurlencode($replica_id) . '/chat/history';
        $res = $client->get($path, array(), $headers);
        if (is_wp_error($res)) {
            return new \WP_REST_Response(array('ok' => false, 'error' => $res->get_error_message()), 502);
        }
        $code = (int)($res['code'] ?? 0);
        $body = $res['body'] ?? array();
        if ($code < 200 || $code >= 300) {
            return new \WP_REST_Response(array('ok' => false, 'status' => $code, 'body' => $body), $code ?: 502);
        }
        $items = isset($body['items']) && is_array($body['items']) ? $body['items'] : array();
        return new \WP_REST_Response(array('ok' => true, 'items' => $items), 200);
    }

    /**
     * Register event tracking REST API route
     */
    public function register_event_tracking_route() {
        register_rest_route('fluxa/v1', '/events', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_track_event'),
            'permission_callback' => '__return_true',
            'args' => array(
                'event_type' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));
    }

    /**
     * Helper: build a tool payload for WooCommerce order status/tracking (HPOS compatible)
     */
    private function tool_get_order_status_payload($order_id) {
        $out = array('ok' => false);
        $order_id = absint($order_id);
        if (!$order_id || !function_exists('wc_get_order')) {
            return $out;
        }
        try {
            $order = wc_get_order($order_id);
            if (!$order) { return $out; }
            $status = method_exists($order, 'get_status') ? (string)$order->get_status() : '';

            // Build tracking entries, supporting Advanced Shipment Tracking (AST) plugin
            $tracking_entries = array();
            if (method_exists($order, 'get_meta')) {
                // AST meta usually stored under _wc_shipment_tracking_items
                $ast_meta = $order->get_meta('_wc_shipment_tracking_items', true);
                if (!empty($ast_meta)) {
                    // Some installs store as array, others as JSON string – normalize to array
                    if (is_string($ast_meta)) {
                        $decoded = json_decode($ast_meta, true);
                        if (is_array($decoded)) { $ast_meta = $decoded; }
                    }
                    if (is_array($ast_meta)) {
                        foreach ($ast_meta as $it) {
                            // Common AST fields: tracking_provider, custom_tracking_provider, provider_slug, tracking_number, date_shipped, tracking_url
                            $provider = '';
                            if (!empty($it['tracking_provider'])) { $provider = (string)$it['tracking_provider']; }
                            elseif (!empty($it['custom_tracking_provider'])) { $provider = (string)$it['custom_tracking_provider']; }
                            elseif (!empty($it['custom_tracking_provider_name'])) { $provider = (string)$it['custom_tracking_provider_name']; }
                            elseif (!empty($it['shipping_provider'])) { $provider = (string)$it['shipping_provider']; }
                            elseif (!empty($it['carrier_name'])) { $provider = (string)$it['carrier_name']; }
                            $provider = trim($provider);
                            $num   = isset($it['tracking_number']) ? trim((string)$it['tracking_number']) : '';
                            $pslug = '';
                            if (!empty($it['provider_slug'])) { $pslug = (string)$it['provider_slug']; }
                            elseif (!empty($it['provider'])) { $pslug = (string)$it['provider']; }
                            $pslug = trim(strtolower($pslug));
                            $dship = '';
                            if (!empty($it['date_shipped'])) {
                                // date_shipped often unix ts or yyyy-mm-dd
                                if (is_numeric($it['date_shipped'])) { $dship = gmdate('c', (int)$it['date_shipped']); }
                                else { $dship = gmdate('c', strtotime((string)$it['date_shipped'])); }
                            }
                            // Try multiple url fields used by AST / themes / plugins
                            $turl  = '';
                            if (!empty($it['tracking_url'])) { $turl = (string)$it['tracking_url']; }
                            elseif (!empty($it['tracking_link'])) { $turl = (string)$it['tracking_link']; }
                            elseif (!empty($it['formatted_tracking_link'])) { $turl = (string)$it['formatted_tracking_link']; }
                            elseif (!empty($it['ast_tracking_link'])) { $turl = (string)$it['ast_tracking_link']; }
                            if ($turl === '' && ($num !== '')) {
                                // Compute a likely URL using provider name/slug mapping (case-insensitive)
                                $guessed = $this->ats_get_tracking_url($provider, $num, $pslug);
                                if ($guessed) { $turl = $guessed; }
                            }
                            if ($provider !== '' || $num !== '') {
                                $tracking_entries[] = array(
                                    'carrier'       => $provider,
                                    'code'          => $num,
                                    'provider_slug' => $pslug,
                                    'date_shipped'  => $dship,
                                    'url'           => $turl,
                                );
                            }
                        }
                    }
                }
                // Fallback meta commonly used by other setups
                if (empty($tracking_entries)) {
                    $carrier = trim((string) $order->get_meta('_shipping_company', true));
                    $code    = trim((string) $order->get_meta('_tracking_code', true));
                    if ($carrier !== '' || $code !== '') {
                        $turl = '';
                        if ($carrier !== '' && $code !== '') {
                            $guess = $this->ats_get_tracking_url($carrier, $code, '');
                            if ($guess) { $turl = $guess; }
                        }
                        $tracking_entries[] = array(
                            'carrier' => $carrier,
                            'code'    => $code,
                            'url'     => $turl,
                        );
                    }
                }
                // Post-pass: ensure each entry has a URL if possible
                foreach ($tracking_entries as &$te) {
                    if (empty($te['url']) && !empty($te['code'])) {
                        $g = $this->ats_get_tracking_url($te['carrier'] ?? '', $te['code'], $te['provider_slug'] ?? '');
                        if ($g) { $te['url'] = $g; }
                    }
                }
                unset($te);
            }

            $updated_gmt = '';
            try {
                if (method_exists($order, 'get_date_modified')) {
                    $dm = $order->get_date_modified('edit');
                    if ($dm) { $updated_gmt = gmdate('c', $dm->getTimestamp()); }
                }
            } catch (\Throwable $e) {}

            $primary = !empty($tracking_entries) ? $tracking_entries[0] : null;
            // Choose the first non-empty URL across entries as primary URL
            $primary_url = '';
            if (!empty($tracking_entries)) {
                foreach ($tracking_entries as $te) {
                    if (!empty($te['url'])) { $primary_url = (string)$te['url']; break; }
                }
            }
            if ($primary_url === '' && function_exists('fluxa_log')) {
                try { fluxa_log('tool_get_order_status_payload: no tracking_url resolved', array('order_id'=>$order_id, 'entries'=>$tracking_entries)); } catch(\Throwable $e) {}
            }
            $out = array(
                'ok'       => true,
                'order_id' => (int)$order_id,
                'status'   => $status !== '' ? $status : null,
                // Back-compat: provide single primary object at 'tracking'
                'tracking' => $primary,
                // New: provide complete list under 'trackings'
                'trackings' => $tracking_entries,
                'tracking_url' => $primary_url,
                'updated'  => $updated_gmt,
            );
            return $out;
        } catch (\Throwable $e) {
            return array('ok' => false, 'error' => 'exception');
        }
    }

    /**
     * Helper: map known providers to a public tracking URL
     */
    private function ats_get_tracking_url($provider, $tracking_code, $provider_slug = '') {
        $nameRaw = trim((string)$provider);
        $slugRaw = strtolower(trim((string)$provider_slug));
        $code = (string)$tracking_code;
        if ($code === '') { return ''; }
        // Normalize name for fuzzy contains checks
        $nameL = strtolower($nameRaw);
        $nameNorm = preg_replace('~[^a-z0-9]+~', '-', $nameL);
        $slug = preg_replace('~[^a-z0-9]+~', '-', $slugRaw);
        // Name map: case-insensitive
        $providers = array(
            'DHL Express UK' => 'https://uk.express.dhl.com/track-a-parcel?AWB=%s',
            'DPD Czech Republic' => 'https://www.dpd.com/cz/en/track-parcel/?parcelNumber=%s',
            'Chronopost' => 'https://www.chronopost.fr/en/track-your-parcel?listeNumerosLT=%s',
            'Mondial Relay' => 'https://www.mondialrelay.com/en-gb/parcel-tracking?shipmentNumber=%s',
            'Deutsche Post' => 'https://www.deutschepost.de/sendung/simpleQuery.html?locale=en&form.sendungsnummer=%s',
            'TNT UK' => 'https://www.tnt.com/express/en_gb/site/shipping-tools/tracking.html?searchType=CON&cons=%s',
            'TNT Reference' => 'https://www.tnt.com/express/en_gb/site/shipping-tools/tracking.html?searchType=REF&ref=%s',
            'DPD Germany' => 'https://www.dpd.com/de/en/parcel-tracking/?parcelNumber=%s',
            'Hermes Germany' => 'https://www.myhermes.de/empfangen/sendungsverfolgung/sendungsinformation/%s',
            'UPS Germany' => 'https://www.ups.com/track?tracknum=%s&loc=en_DE',
            'ABF' => 'https://arcb.com/tools/tracking?pro=%s',
            'DPD Netherlands' => 'https://www.dpd.com/nl/en/track-trace/?parcelNumber=%s',
            'PostNL International' => 'https://www.internationalparceltracking.com/#/search/%s',
            'Portugal Post - CTT' => 'https://www.ctt.pt/feapl_2/app/open/objectSearch/objectSearch.jspx?objects=%s',
            'DHLParcel NL' => 'https://www.dhlparcel.nl/en/consumer/track-and-trace/%s',
            'DPD UK' => 'https://www.dpd.co.uk/service/tracking?parcel=%s',
            'FedEx' => 'https://www.fedex.com/fedextrack/?trknbr=%s',
            'DHL Express' => 'https://www.dhl.com/global-en/home/tracking.html?tracking-id=%s',
            'TNT Italy' => 'https://www.tnt.it/express/it_it/site/servizi/ricerca_spedizioni.html?searchType=CON&cons=%s',
            'TNT Click' => 'https://www.tnt-click.it/tracking?number=%s',
            'DHL Germany' => 'https://www.dhl.de/en/privatkunden/dhl-sendungsverfolgung.html?piececode=%s',
            'Overseas Territory FR EMS' => 'https://www.laposte.fr/outremer/ems?parcel=%s',
            'TNT France' => 'https://www.tnt.com/express/fr_fr/site/outils-expedition/suivi.html?searchType=CON&cons=%s',
            'GLS Italy' => 'https://gls-group.com/IT/it/servizi-online/ricerca-spedizioni?match=%s',
            'GLS Europe' => 'https://gls-group.eu/EU/en/parcel-tracking?match=%s',
            'GLS Paket' => 'https://gls-pakete.de/sendungsverfolgung?tracking=%s',
            'GLS Germany' => 'https://gls-pakete.de/sendungsverfolgung?tracking=%s',
            'Direct Link' => 'https://tracking.directlink.com/?packageid=%s',
        );
        // Slug map (lowercase keys)
        $slug_map = array(
            'gls' => 'https://gls-group.eu/EU/en/parcel-tracking?match=%s',
            'gls-paket' => 'https://gls-pakete.de/sendungsverfolgung?tracking=%s',
            'gls-pakete' => 'https://gls-pakete.de/sendungsverfolgung?tracking=%s',
            'gls-de' => 'https://gls-pakete.de/sendungsverfolgung?tracking=%s',
            'gls-germany' => 'https://gls-pakete.de/sendungsverfolgung?tracking=%s',
            'gls-deutschland' => 'https://gls-pakete.de/sendungsverfolgung?tracking=%s',
            'gls-italy' => 'https://gls-group.com/IT/it/servizi-online/ricerca-spedizioni?match=%s',
            'dhl' => 'https://www.dhl.com/global-en/home/tracking.html?tracking-id=%s',
            'dhl-germany' => 'https://www.dhl.de/en/privatkunden/dhl-sendungsverfolgung.html?piececode=%s',
            'dpd' => 'https://www.dpd.com/de/en/parcel-tracking/?parcelNumber=%s',
            'fedex' => 'https://www.fedex.com/fedextrack/?trknbr=%s',
            'ups' => 'https://www.ups.com/track?tracknum=%s',
        );
        // Try slug first if available
        if ($slug !== '' && isset($slug_map[$slug])) {
            return sprintf($slug_map[$slug], rawurlencode($code));
        }
        // Try case-insensitive name match
        foreach ($providers as $k => $tpl) {
            if (strcasecmp($k, $nameRaw) === 0) {
                return sprintf($tpl, rawurlencode($code));
            }
        }
        // Heuristics for common carriers if exact match failed
        // GLS family
        if (strpos($nameL, 'gls') !== false || strpos($slug, 'gls') !== false) {
            // Prefer German GLS (paket/pakete/de keywords), else EU generic
            if (strpos($nameNorm, 'paket') !== false || strpos($nameNorm, 'pakete') !== false ||
                strpos($slug, 'paket') !== false || strpos($slug, 'pakete') !== false ||
                strpos($nameNorm, 'de') !== false || strpos($slug, 'de') !== false ||
                strpos($nameNorm, 'germany') !== false || strpos($slug, 'germany') !== false) {
                return sprintf('https://gls-pakete.de/sendungsverfolgung?tracking=%s', rawurlencode($code));
            }
            return sprintf('https://gls-group.eu/EU/en/parcel-tracking?match=%s', rawurlencode($code));
        }
        // DPD
        if (strpos($nameL, 'dpd') !== false || strpos($slug, 'dpd') !== false) {
            return sprintf('https://www.dpd.com/de/en/parcel-tracking/?parcelNumber=%s', rawurlencode($code));
        }
        // DHL
        if (strpos($nameL, 'dhl') !== false || strpos($slug, 'dhl') !== false) {
            if (strpos($nameL, 'germany') !== false || strpos($slug, 'germany') !== false || strpos($nameNorm, 'de') !== false) {
                return sprintf('https://www.dhl.de/en/privatkunden/dhl-sendungsverfolgung.html?piececode=%s', rawurlencode($code));
            }
            return sprintf('https://www.dhl.com/global-en/home/tracking.html?tracking-id=%s', rawurlencode($code));
        }
        // UPS
        if (strpos($nameL, 'ups') !== false || strpos($slug, 'ups') !== false) {
            return sprintf('https://www.ups.com/track?tracknum=%s', rawurlencode($code));
        }
        // FedEx
        if (strpos($nameL, 'fedex') !== false || strpos($slug, 'fedex') !== false) {
            return sprintf('https://www.fedex.com/fedextrack/?trknbr=%s', rawurlencode($code));
        }
        return '';
    }

    /**
     * REST: Track frontend events
     */
    public function rest_track_event(\WP_REST_Request $request) {
        $event_type = $request->get_param('event_type');
        $data = array();
        
        // Extract optional parameters
        $optional_fields = array(
            'product_id', 'variation_id', 'qty', 'price', 'currency',
            'order_id', 'order_status', 'cart_total', 'shipping_total',
            'discount_total', 'tax_total', 'json_payload',
            // new: allow client to provide page URL/referrer so we don't log REST endpoint URL
            'page_url', 'page_referrer',
                // allow client to provide canonical visitor id to avoid race conditions
            'ss_user_id'
        );
        
        foreach ($optional_fields as $field) {
            $value = $request->get_param($field);
            if ($value !== null && $value !== '') {
                $data[$field] = $value;
            }
        }
        
        $event_id = Fluxa_Event_Tracker::log_event($event_type, $data);
        
        if ($event_id) {
            return new \WP_REST_Response(array('ok' => true, 'event_id' => $event_id), 200);
        } else {
            return new \WP_REST_Response(array('ok' => false, 'error' => 'Failed to log event'), 500);
        }
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
     * REST: Track a conversation and upsert it into wp_fluxa_conv
     */
    public function rest_track_conversation(\WP_REST_Request $request) {
        global $wpdb;
        $table = $wpdb->prefix . 'fluxa_conv';
        $conversation_id = trim((string)$request->get_param('conversation_id'));
        $ss_user_id = trim((string)$request->get_param('ss_user_id'));
        if ($conversation_id === '') {
            return new \WP_REST_Response(array('ok' => false, 'error' => 'missing_conversation_id'), 400);
        }

        // Ensure table exists (in case activation didn't run)
        $like = str_replace(array('\\','%','_'), array('\\\\','\\%','\\_'), $table);
        $maybe_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        if (!$maybe_table) {
            if (method_exists($this, 'create_tables')) {
                $this->create_tables();
                // Re-check
                $maybe_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
            }
        }
        if (!$maybe_table) {
            return new \WP_REST_Response(array('ok' => false, 'error' => 'table_missing', 'table' => $table), 500);
        }

        // Collect env details
        $wp_user_id = get_current_user_id();
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
        $ip = '';
        if (class_exists('WC_Geolocation')) {
            try { $ip = (string)\WC_Geolocation::get_ip_address(); } catch (\Throwable $e) { $ip = ''; }
        }
        if (!$ip && !empty($_SERVER['REMOTE_ADDR'])) { $ip = (string)$_SERVER['REMOTE_ADDR']; }
        // Normalize IP to binary (IPv4/IPv6) or null
        $ip_bin = null;
        if (!empty($ip) && function_exists('inet_pton')) {
            $packed = @inet_pton($ip);
            if ($packed !== false) { $ip_bin = $packed; }
        }
        $wc_session_key = '';
        // Prefer client-provided wc_session_key if present (already sanitized client-side)
        $wc_from_req = trim((string)$request->get_param('wc_session_key'));
        if ($wc_from_req !== '') {
            $wc_session_key = substr($wc_from_req, 0, 64);
        }
        // Prefer Woo session API when available; attempt to ensure cookie/session is initialized
        if ($wc_session_key === '' && function_exists('WC') && WC()) {
            try {
                $session = WC()->session;
                if ($session) {
                    // Ensure a customer session cookie exists (in REST contexts this may not be set yet)
                    if (method_exists($session, 'set_customer_session_cookie')) {
                        $session->set_customer_session_cookie(true);
                    }
                    if (method_exists($session, 'get_customer_id')) {
                        $wc_session_key = (string) $session->get_customer_id();
                    }
                }
            } catch (\Throwable $e) {}
        }
        // Fallback: parse wp_woocommerce_session_* cookie when session object is not available
        if ($wc_session_key === '' && !empty($_COOKIE)) {
            foreach ($_COOKIE as $ckey => $cval) {
                if (strpos($ckey, 'wp_woocommerce_session_') === 0) {
                    // Cookie format: customer_id||session_expiry||session_token||session_expiration_variant
                    $raw = is_string($cval) ? $cval : '';
                    $dec = urldecode($raw);
                    $parts = explode('||', $dec);
                    if (!empty($parts[0])) {
                        $wc_session_key = (string) $parts[0];
                        break;
                    }
                }
            }
        }

        $now = current_time('mysql');
        // Upsert logic
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id, first_seen FROM {$table} WHERE conversation_id = %s", $conversation_id), ARRAY_A);
        if ($existing) {
            $data = array(
                'last_seen' => $now,
                'last_ua' => $ua,
            );
            if ($ip_bin !== null) { $data['last_ip'] = $ip_bin; }
            if (!empty($wc_session_key)) { $data['wc_session_key'] = $wc_session_key; }
            if (!empty($ss_user_id)) { $data['ss_user_id'] = $ss_user_id; }
            if (!empty($wp_user_id)) { $data['wp_user_id'] = (int)$wp_user_id; }
            // Build formats dynamically
            $formats = array();
            foreach ($data as $k => $v) {
                if ($k === 'wp_user_id') { $formats[] = '%d'; }
                elseif ($k === 'last_ip') { $formats[] = '%s'; } // VARBINARY
                else { $formats[] = '%s'; }
            }
            $res = $wpdb->update($table, $data, array('id' => (int)$existing['id']), $formats, array('%d'));
            if ($res === false) {
                return new \WP_REST_Response(array('ok' => false, 'error' => 'db_update_failed', 'db_error' => $wpdb->last_error), 500);
            }
            return new \WP_REST_Response(array('ok' => true, 'updated' => true, 'id' => (int)$existing['id']), 200);
        } else {
            $data = array(
                'conversation_id' => $conversation_id,
                'first_seen' => $now,
                'last_seen' => $now,
                'last_ua' => $ua,
            );
            if ($ip_bin !== null) { $data['last_ip'] = $ip_bin; }
            if (!empty($wc_session_key)) { $data['wc_session_key'] = $wc_session_key; }
            if (!empty($ss_user_id)) { $data['ss_user_id'] = $ss_user_id; }
            if (!empty($wp_user_id)) { $data['wp_user_id'] = (int)$wp_user_id; }
            // Build formats dynamically
            $formats = array();
            foreach ($data as $k => $v) {
                if ($k === 'wp_user_id') { $formats[] = '%d'; }
                elseif ($k === 'last_ip') { $formats[] = '%s'; } // VARBINARY
                else { $formats[] = '%s'; }
            }
            $ok = $wpdb->insert($table, $data, $formats);
            if ($ok) {
                return new \WP_REST_Response(array('ok' => true, 'inserted' => true, 'id' => (int)$wpdb->insert_id), 200);
            } else {
                return new \WP_REST_Response(array(
                    'ok' => false,
                    'error' => 'db_insert_failed',
                    'db_error' => $wpdb->last_error,
                    'last_query' => $wpdb->last_query,
                ), 500);
            }
        }
    }
    
    /**
     * Initialize frontend functionality
     */
    private function init_frontend() {
        // Add frontend hooks here
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
                     "        try { var only=(function(s){var m=String(s).match(/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/);return m&&m[0]?m[0]:String(s).split('.')[0];})(value); sessionStorage.setItem('fluxa_ss_user_id', only); } catch(e) {}\n".
                     "      }\n".
                     "    } else {\n".
                     "      // Ensure storages mirror cookie value for future tabs\n".
                     "      var val = decodeURIComponent(m[1]);\n".
                     "      try { sessionStorage.setItem('fluxa_uid_value', val); } catch(e) {}\n".
                     "      try { localStorage.setItem('fluxa_uid_value', val); } catch(e) {}\n".
                     "      try { var only=(function(s){var m=String(s).match(/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/);return m&&m[0]?m[0]:String(s).split('.')[0];})(val); sessionStorage.setItem('fluxa_ss_user_id', only); } catch(e) {}\n".
                     "    }\n".
                     "    // Ensure globals/storages are populated; finalize try-block cleanly\n".
                     "  } catch(e) {}\n".
                     "})();</script>\n";
                // Visual debug chip in the footer to show Sensay user id (visible to everyone)
                echo "<div id=\"fluxa-debug-ssuid\" style=\"position:fixed; left:12px; bottom:12px; background:rgba(15,23,42,.9); color:#e2e8f0; padding:6px 10px; border-radius:8px; font:12px/1.4 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif; z-index:999999; box-shadow:0 2px 6px rgba(0,0,0,.25);\">SS User ID: <span data-val>loading…</span></div>\n";
                echo "<script>(function(){try{var el=document.getElementById('fluxa-debug-ssuid');if(!el) return;var out=el.querySelector('[data-val]');var val=null;try{val=localStorage.getItem('fluxa_uid_value')||'';}catch(e){}if(!val){try{val=sessionStorage.getItem('fluxa_uid_value')||'';}catch(e){}}if(!val){var m=document.cookie.match(/(?:^|; )fluxa_uid=([^;]+)/);if(m){val=decodeURIComponent(m[1]);}}if(!val&&window.FLUXA_CHAT_USER_ID){val=String(window.FLUXA_CHAT_USER_ID);}var uuidOnly='';if(val){var match=String(val).match(/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89ab][0-9a-fA-F]{3}-[0-9a-fA-F]{12}/);uuidOnly=match&&match[0]?match[0]:String(val).split('.')[0];}if(out){out.textContent=uuidOnly?uuidOnly:'(not set)';}}catch(e){}})();</script>\n";
            }
        }

        // Expose REST feedback endpoint and nonce to frontend
        $rest_feedback = esc_url_raw( rest_url('fluxa/v1/feedback') );
        $wp_nonce = wp_create_nonce('wp_rest');
        // Inject early via inline script (kept for safety in case themes move scripts)
        echo '<script>(function(){try{window.fluxaChatbot=window.fluxaChatbot||{};window.fluxaChatbot.rest_feedback="'.esc_js($rest_feedback).'";window.fluxaChatbot.nonce="'.esc_js($wp_nonce).'";}catch(e){}})();</script>' . "\n";

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
        
        // Enqueue chatbot script
        wp_enqueue_script(
            'fluxa-chatbot-script',
            FLUXA_PLUGIN_URL . 'assets/js/chatbot.js',
            array(),
            FLUXA_VERSION,
            true
        );
        // Also localize via wp_add_inline_script so the value is guaranteed even if inline above is moved
        $inline = 'window.fluxaChatbot=window.fluxaChatbot||{};window.fluxaChatbot.rest_feedback='.wp_json_encode($rest_feedback).';window.fluxaChatbot.nonce='.wp_json_encode($wp_nonce).';';
        wp_add_inline_script('fluxa-chatbot-script', $inline, 'before');
        
        // Enqueue event tracking script
        wp_enqueue_script(
            'fluxa-event-tracker',
            FLUXA_PLUGIN_URL . 'assets/js/event-tracker.js',
            array(),
            FLUXA_VERSION,
            true
        );
        
        // Compute current WC session key for frontend (best-effort)
        $wc_session_key = '';
        if (function_exists('WC') && WC()) {
            try {
                $sess = WC()->session;
                if ($sess) {
                    // Ensure a customer session cookie exists (in REST contexts this may not be set yet)
                    if (method_exists($sess, 'set_customer_session_cookie')) {
                        $sess->set_customer_session_cookie(true);
                    }
                    if (method_exists($sess, 'get_customer_id')) {
                        $wc_session_key = (string) $sess->get_customer_id();
                    }
                }
            } catch (\Throwable $e) {}
        }
        if ($wc_session_key === '' && !empty($_COOKIE)) {
            foreach ($_COOKIE as $ckey => $cval) {
                if (strpos($ckey, 'wp_woocommerce_session_') === 0) {
                    // Cookie format: customer_id||session_expiry||session_token||session_expiration_variant
                    $raw = is_string($cval) ? $cval : '';
                    $dec = urldecode($raw);
                    $parts = explode('||', $dec);
                    if (!empty($parts[0])) { $wc_session_key = (string) $parts[0]; break; }
                }
            }
        }

        // Localize script with settings (clean base)
        $scheme = is_ssl() ? 'https' : 'http';
        // Feedback settings (seconds-based with back-compat)
        $feedback_settings = get_option('fluxa_feedback_settings', array());
        if (!is_array($feedback_settings)) { $feedback_settings = array(); }
        $fb_enabled = !empty($feedback_settings['enabled']) ? 1 : 0;
        // Derive seconds, converting legacy minutes if needed
        $fb_delay_seconds = 120;
        if (isset($feedback_settings['delay_seconds'])) {
            $fb_delay_seconds = max(0, (int)$feedback_settings['delay_seconds']);
        } elseif (isset($feedback_settings['delay_minutes'])) {
            $fb_delay_seconds = max(0, (int)$feedback_settings['delay_minutes']) * 60;
        }
        wp_localize_script('fluxa-chatbot-script', 'fluxaChatbot', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'rest' => esc_url_raw( rest_url('fluxa/v1/chat', $scheme) ),
            'rest_history' => esc_url_raw( rest_url('fluxa/v1/chat/history', $scheme) ),
            'rest_track' => esc_url_raw( rest_url('fluxa/v1/conversation/track', $scheme) ),
            'nonce' => wp_create_nonce('wp_rest'),
            'wc_session_key' => $wc_session_key,
            'ping_on_pageload' => (int) get_option('fluxa_ping_on_pageload', 1),
            'settings' => array_merge($design_settings, array(
                'feedback_enabled' => $fb_enabled,
                'feedback_delay_seconds' => $fb_delay_seconds,
            )),
            'i18n' => array(
                'chatWithUs' => __('Chat with us', 'fluxa-ecommerce-assistant'),
                'send' => (!empty($feedback_settings['send_text']) ? $feedback_settings['send_text'] : __('Send', 'fluxa-ecommerce-assistant')),
                'typeMessage' => __('Type your message...', 'fluxa-ecommerce-assistant'),
                'error' => __('An error occurred. Please try again.', 'fluxa-ecommerce-assistant'),
                'feedback_title' => (!empty($feedback_settings['title']) ? $feedback_settings['title'] : __('Were we helpful?', 'fluxa-ecommerce-assistant')),
                'thanks' => (!empty($feedback_settings['thanks_text']) ? $feedback_settings['thanks_text'] : __('Thanks for your feedback!', 'fluxa-ecommerce-assistant')),
            )
        ));
        
        // Localize event tracker script with behavior settings
        $tracking_enabled_opt = (int) get_option('fluxa_tracking_enabled', 1);
        $tracking_events_opt  = get_option('fluxa_tracking_events', array(
            'product_impression' => 1,
            'product_click' => 1,
            'variant_select' => 1,
            'sort_apply' => 1,
            'filter_apply' => 1,
            'pagination' => 1,
            'js_error' => 1,
        ));
        wp_localize_script('fluxa-event-tracker', 'fluxaEventTracker', array(
            'restUrl' => esc_url_raw( rest_url('fluxa/v1/events', $scheme) ),
            'nonce' => wp_create_nonce('wp_rest'),
            'enabled' => (int) $tracking_enabled_opt,
            'events'  => $tracking_events_opt,
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
        // Defaults for guest->user merge behavior
        if (false === get_option('fluxa_merge_guest_on_login')) {
            update_option('fluxa_merge_guest_on_login', '1');
        }
        if (false === get_option('fluxa_merge_window_days')) {
            update_option('fluxa_merge_window_days', 30);
        }
        
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

        $table = $wpdb->prefix . 'fluxa_conv';
        $sql = array();
        // Use dbDelta-compatible SQL (no IF NOT EXISTS required, but harmless if present)
        $sql[] = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id VARCHAR(64) NOT NULL,
            wc_session_key VARCHAR(64) NULL,
            ss_user_id VARCHAR(64) NULL,
            wp_user_id BIGINT(20) UNSIGNED NULL,
            last_ip VARBINARY(16) NULL,
            last_ua VARCHAR(255) NULL,
            first_seen DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            seen_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_conv (conversation_id),
            KEY wc_session_key (wc_session_key),
            KEY last_seen (last_seen)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Create events table
        $events_table = $wpdb->prefix . 'fluxa_conv_events';
        $sql_events = "CREATE TABLE {$events_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ss_user_id VARCHAR(64) NULL,
            wc_session_key VARCHAR(64) NULL,
            user_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(40) NOT NULL,
            event_time DATETIME NOT NULL,
            url TEXT NULL,
            referer TEXT NULL,
            ip VARBINARY(16) NULL,
            user_agent VARCHAR(255) NULL,
            product_id BIGINT UNSIGNED NULL,
            variation_id BIGINT UNSIGNED NULL,
            qty INT NULL,
            price DECIMAL(18,6) NULL,
            currency VARCHAR(16) NULL,
            order_id BIGINT UNSIGNED NULL,
            order_status VARCHAR(40) NULL,
            cart_total DECIMAL(18,6) NULL,
            shipping_total DECIMAL(18,6) NULL,
            discount_total DECIMAL(18,6) NULL,
            tax_total DECIMAL(18,6) NULL,
            json_payload LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY evt_type (event_type),
            KEY order_id (order_id),
            KEY wc_session_key (wc_session_key),
            KEY ss_user_id (ss_user_id),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        dbDelta(array($sql_events));

        // Create feedback table
        $feedback_table = $wpdb->prefix . 'fluxa_feedback';
        $sql_feedback = "CREATE TABLE {$feedback_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id VARCHAR(64) NOT NULL,
            rating_point TINYINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        dbDelta(array($sql_feedback));

        // Chat messages table: stores user, assistant (interim and final) messages by conversation
        $messages_table = $wpdb->prefix . 'fluxa_messages';
        $sql[] = "CREATE TABLE {$messages_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id VARCHAR(64) NOT NULL,
            role VARCHAR(16) NOT NULL,
            content LONGTEXT NULL,
            is_interim TINYINT(1) NOT NULL DEFAULT 0,
            ss_user_id VARCHAR(64) NULL,
            source VARCHAR(32) NULL,
            created_at DATETIME NOT NULL,
            meta LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY conversation_id (conversation_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        dbDelta($sql);

        // Defensive migrations for existing installs where the table already exists
        // 1) Rename user_id -> wp_user_id if needed
        $col_wp_user = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'wp_user_id'));
        $col_user = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'user_id'));
        if (!$col_wp_user && $col_user) {
            $wpdb->query("ALTER TABLE `{$table}` CHANGE `user_id` `wp_user_id` BIGINT(20) UNSIGNED NULL");
        }
        // 2) Add ss_user_id if missing
        $col_ss_user = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'ss_user_id'));
        if (!$col_ss_user) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `ss_user_id` VARCHAR(64) NULL AFTER `wc_session_key`");
        }
        // 3) Ensure events table has ss_user_id and helpful indexes
        $col_ev_ss = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$events_table}` LIKE %s", 'ss_user_id'));
        if (!$col_ev_ss) {
            // If missing, create it first (place it at the very beginning later)
            $wpdb->query("ALTER TABLE `{$events_table}` ADD COLUMN `ss_user_id` VARCHAR(64) NULL");
        }
        // Ensure ss_user_id is the second column (right after id)
        @$wpdb->query("ALTER TABLE `{$events_table}` MODIFY COLUMN `ss_user_id` VARCHAR(64) NULL AFTER `id`");
        // Drop conversation_id column and related index if they exist
        $col_conv = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$events_table}` LIKE %s", 'conversation_id'));
        if ($col_conv) {
            @ $wpdb->query("ALTER TABLE `{$events_table}` DROP COLUMN `conversation_id`");
        }
        // Attempt to drop possible composite index using conversation_id
        @ $wpdb->query("ALTER TABLE `{$events_table}` DROP INDEX `conv_time`");
        // Add missing secondary indexes (ignore errors if exist)
        @$wpdb->query("ALTER TABLE `{$events_table}` ADD INDEX `wc_session_key` (`wc_session_key`)");
        @$wpdb->query("ALTER TABLE `{$events_table}` ADD INDEX `ss_user_id` (`ss_user_id`)");
        @$wpdb->query("ALTER TABLE `{$events_table}` ADD INDEX `user_id` (`user_id`)");

        // Ensure messages table has indexes
        @$wpdb->query("ALTER TABLE `{$messages_table}` ADD INDEX `conversation_id` (`conversation_id`)");
        @$wpdb->query("ALTER TABLE `{$messages_table}` ADD INDEX `created_at` (`created_at`)");
    }

    /**
     * Internal: insert a chat message into wp_fluxa_messages
     */
    private function insert_chat_message($conversation_id, $role, $content, $is_interim = false, $ss_user_id = '', $source = 'web', $meta = null) {
        if (!is_string($conversation_id) || $conversation_id === '') { return false; }
        global $wpdb;
        $table = $wpdb->prefix . 'fluxa_messages';
        $data = array(
            'conversation_id' => (string)$conversation_id,
            'role' => substr((string)$role, 0, 16),
            'content' => (string)$content,
            'is_interim' => $is_interim ? 1 : 0,
            'ss_user_id' => $ss_user_id ? (string)$ss_user_id : null,
            'source' => $source ? (string)$source : 'web',
            'created_at' => current_time('mysql', true),
            'meta' => $meta ? wp_json_encode($meta) : null,
        );
        $fmt = array('%s','%s','%s','%d','%s','%s','%s','%s');
        return (bool)$wpdb->insert($table, $data, $fmt);
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

    /**
     * Initialize event tracking hooks
     */
    private function init_event_tracking() {
        // Page view tracking
        add_action('template_redirect', array($this, 'track_page_views'));
        
        // Cart events
        add_action('woocommerce_add_to_cart', array($this, 'track_add_to_cart'), 10, 6);
        add_action('woocommerce_remove_cart_item', array($this, 'track_remove_from_cart'), 10, 2);
        add_action('woocommerce_after_cart_item_quantity_update', array($this, 'track_update_cart_qty'), 10, 4);
        add_action('woocommerce_applied_coupon', array($this, 'track_apply_coupon'), 10, 1);
        add_action('woocommerce_removed_coupon', array($this, 'track_remove_coupon'), 10, 1);
        
        // Checkout events
        add_action('woocommerce_before_checkout_form', array($this, 'track_begin_checkout'));
        add_action('woocommerce_checkout_order_processed', array($this, 'track_order_created'), 10, 3);
        add_action('woocommerce_payment_complete', array($this, 'track_payment_complete'), 10, 1);
        // Removed per request: stop tracking order status changes and refunds
        // add_action('woocommerce_order_status_changed', array($this, 'track_order_status_changed'), 10, 4);
        add_action('woocommerce_thankyou', array($this, 'track_thank_you_view'), 10, 1);
        // add_action('woocommerce_order_refunded', array($this, 'track_order_refunded'), 10, 2);
        
        // User events
        add_action('wp_login', array($this, 'track_login'), 10, 2);
        add_action('wp_logout', array($this, 'track_logout'));
        add_action('user_register', array($this, 'track_sign_up_completed'), 10, 1);
    }

    /**
     * Track page views
     */
    public function track_page_views() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        // Skip non-HTML or REST requests (e.g., favicon, REST, assets)
        $req = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($req) {
            // Ignore favicon
            if (preg_match('~(?:^|/)favicon\\.ico(?:$|\?)~i', $req)) { return; }
            // Ignore REST API calls
            if (strpos($req, '/wp-json/') === 0 || strpos($req, '/?rest_route=') === 0) { return; }
            // Ignore common static assets
            if (preg_match('~\.(png|jpe?g|gif|svg|webp|css|js|woff2?|ttf|eot)(?:$|\?)~i', $req)) { return; }
        }
        
        $page_type = 'page';
        $json_payload = array();
        
        if (is_product()) {
            $page_type = 'product';
            // Resolve product ID safely without relying on global $product
            $product_id = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
            if (!$product_id && function_exists('get_the_ID')) {
                $product_id = (int) get_the_ID();
            }
            if ($product_id > 0) {
                $data = Fluxa_Event_Tracker::get_product_data($product_id);
                $data['json_payload'] = array('page_type' => 'product');
                Fluxa_Event_Tracker::log_event('product_view', $data);
            }
            return;
        } elseif (is_product_category()) {
            $page_type = 'category';
            $term = get_queried_object();
            if ($term) {
                $json_payload = array(
                    'page_type' => 'category',
                    'term_id' => $term->term_id,
                    'term_slug' => $term->slug
                );
                Fluxa_Event_Tracker::log_event('category_view', array('json_payload' => $json_payload));
            }
            return;
        } elseif (is_cart()) {
            $page_type = 'cart';
            $data = Fluxa_Event_Tracker::get_cart_totals();
            $data['json_payload'] = array('page_type' => 'cart');
            Fluxa_Event_Tracker::log_event('cart_view', $data);
            return;
        } elseif (is_checkout()) {
            $page_type = 'checkout';
            $json_payload = array('page_type' => 'checkout');
        } elseif (is_search()) {
            $page_type = 'search';
            global $wp_query;
            $json_payload = array(
                'page_type' => 'search',
                'query' => get_search_query(),
                'results_count' => $wp_query->found_posts
            );
            Fluxa_Event_Tracker::log_event('search', array('json_payload' => $json_payload));
            return;
        } elseif (is_home() || is_front_page()) {
            $page_type = 'home';
            $json_payload = array('page_type' => 'home');
        }
        
        // Check for UTM parameters on first page view
        if (!empty($_GET['utm_source']) || !empty($_GET['utm_medium']) || !empty($_GET['utm_campaign'])) {
            $utm_data = array(
                'utm_source' => sanitize_text_field($_GET['utm_source'] ?? ''),
                'utm_medium' => sanitize_text_field($_GET['utm_medium'] ?? ''),
                'utm_campaign' => sanitize_text_field($_GET['utm_campaign'] ?? ''),
                'utm_term' => sanitize_text_field($_GET['utm_term'] ?? ''),
                'utm_content' => sanitize_text_field($_GET['utm_content'] ?? '')
            );
            Fluxa_Event_Tracker::log_event('campaign_landing', array('json_payload' => $utm_data));
        }
        
        // Log general page view
        Fluxa_Event_Tracker::log_event('page_view', array('json_payload' => $json_payload));
    }

    /**
     * Track add to cart
     */
    public function track_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        $data = Fluxa_Event_Tracker::get_product_data($product_id, $variation_id, $quantity);
        Fluxa_Event_Tracker::log_event('add_to_cart', $data);
    }

    /**
     * Track remove from cart
     */
    public function track_remove_from_cart($cart_item_key, $cart) {
        $cart_item = $cart->removed_cart_contents[$cart_item_key] ?? null;
        if ($cart_item) {
            $data = Fluxa_Event_Tracker::get_product_data(
                $cart_item['product_id'],
                $cart_item['variation_id'] ?? null,
                $cart_item['quantity']
            );
            Fluxa_Event_Tracker::log_event('remove_from_cart', $data);
        }
    }

    /**
     * Track cart quantity update
     */
    public function track_update_cart_qty($cart_item_key, $quantity, $old_quantity, $cart) {
        $cart_item = $cart->get_cart_item($cart_item_key);
        if ($cart_item) {
            $data = Fluxa_Event_Tracker::get_product_data(
                $cart_item['product_id'],
                $cart_item['variation_id'] ?? null,
                $quantity
            );
            Fluxa_Event_Tracker::log_event('update_cart_qty', $data);
        }
    }

    /**
     * Track coupon application
     */
    public function track_apply_coupon($coupon_code) {
        Fluxa_Event_Tracker::log_event('apply_coupon', array(
            'json_payload' => array('code' => $coupon_code)
        ));
    }

    /**
     * Track coupon removal
     */
    public function track_remove_coupon($coupon_code) {
        Fluxa_Event_Tracker::log_event('remove_coupon', array(
            'json_payload' => array('code' => $coupon_code)
        ));
    }

    /**
     * Track begin checkout
     */
    public function track_begin_checkout() {
        $data = Fluxa_Event_Tracker::get_cart_totals();
        Fluxa_Event_Tracker::log_event('begin_checkout', $data);
    }

    /**
     * Track order created
     */
    public function track_order_created($order_id, $posted_data, $order) {
        $data = Fluxa_Event_Tracker::get_order_totals($order_id);
        $data['order_id'] = $order_id;
        Fluxa_Event_Tracker::log_event('order_created', $data);
    }

    /**
     * Track payment complete
     */
    public function track_payment_complete($order_id) {
        $order = wc_get_order($order_id);
        if ($order) {
            $data = Fluxa_Event_Tracker::get_order_totals($order_id);
            $data['order_id'] = $order_id;
            $data['order_status'] = $order->get_status();
            Fluxa_Event_Tracker::log_event('payment_complete', $data);
        }
    }

    /**
     * Track order status changes
     */
    public function track_order_status_changed($order_id, $old_status, $new_status, $order) {
        $data = array(
            'order_id' => $order_id,
            'order_status' => $new_status
        );
        
        // Special handling for failed payments
        if ($new_status === 'failed') {
            Fluxa_Event_Tracker::log_event('payment_failed', $data);
        }
        
        Fluxa_Event_Tracker::log_event('order_status_changed', $data);
    }

    /**
     * Track thank you page view
     */
    public function track_thank_you_view($order_id) {
        if ($order_id) {
            Fluxa_Event_Tracker::log_event('thank_you_view', array('order_id' => $order_id));
        }
    }

    /**
     * Track order refund
     */
    public function track_order_refunded($order_id, $refund_id) {
        $refund = wc_get_order($refund_id);
        $amount = $refund ? abs($refund->get_amount()) : 0;
        
        Fluxa_Event_Tracker::log_event('order_refunded', array(
            'order_id' => $order_id,
            'json_payload' => array('amount' => $amount)
        ));
    }

    /**
     * Track user login
     */
    public function track_login($user_login, $user) {
        Fluxa_Event_Tracker::log_event('login', array('user_id' => $user->ID));
    }

    /**
     * Merge guest session events into the logged-in user's stream on login.
     * Controlled by option 'fluxa_merge_guest_on_login' (1/0) and window 'fluxa_merge_window_days'.
     */
    public function merge_guest_events_on_login($user_login, $user) {
        try {
            if (get_option('fluxa_merge_guest_on_login', '1') !== '1') { return; }
            if (!($user && isset($user->ID))) { return; }
            $uid = (int)$user->ID;
            // Require an existing saved ss user id on the account
            $user_ss = get_user_meta($uid, 'fluxa_ss_user_id', true);
            if (empty($user_ss)) { return; }
            // Extract guest UUID (no signature) from cookie if present
            $guest_ss = '';
            if (isset($_COOKIE['fluxa_uid'])) {
                $raw = wp_unslash($_COOKIE['fluxa_uid']);
                if (is_string($raw) && $raw !== '') {
                    if (preg_match('/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/', $raw, $m)) {
                        $guest_ss = $m[0];
                    } else {
                        $parts = explode('.', $raw);
                        $guest_ss = $parts[0] ?? '';
                    }
                }
            }
            if (empty($guest_ss) || strcasecmp($guest_ss, $user_ss) === 0) { return; }
            $days = (int)get_option('fluxa_merge_window_days', 30);
            if ($days < 0) { $days = 0; }
            if ($days > 365) { $days = 365; }
            global $wpdb;
            $table = $wpdb->prefix . 'fluxa_conv_events';
            // Merge recent guest rows into the user's identity
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET ss_user_id = %s, user_id = %d WHERE ss_user_id = %s AND event_time >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                    $user_ss,
                    $uid,
                    $guest_ss,
                    $days
                )
            );
            // Also switch the browser cookie to the user's UUID so future events are consistent
            if (class_exists('Fluxa_User_ID_Service')) {
                \Fluxa_User_ID_Service::set_cookie($user_ss);
            }
        } catch (\Throwable $e) {
            // Silent
        }
    }

    /**
     * Track user logout
     */
    public function track_logout() {
        $user_id = get_current_user_id();
        Fluxa_Event_Tracker::log_event('logout', array('user_id' => $user_id > 0 ? $user_id : null));
    }

    /**
     * Track sign up completed
     */
    public function track_sign_up_completed($user_id) {
        Fluxa_Event_Tracker::log_event('sign_up_completed', array(
            'user_id' => $user_id,
            'json_payload' => array('method' => 'email')
        ));
    }
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

/**
 * Collect tracking/shipment information for an order from popular plugins/metadata.
 *
 * @param int $order_id
 * @return array[] Each item: [provider, number, url, date, source]
 */
function flx_collect_tracking_info($order_id) {
    $tracking = array();

    // Get the order via CRUD (works for posts + HPOS tables)
    $order = wc_get_order($order_id);
    if (!$order) {
        return $tracking;
    }

    // 1) WooCommerce Shipment Tracking (official)
    $wst_items = $order->get_meta('_wc_shipment_tracking_items', true);
    if (!empty($wst_items) && is_array($wst_items)) {
        foreach ($wst_items as $t) {
            $tracking[] = array(
                'provider' => $t['tracking_provider']   ?? '',
                'number'   => $t['tracking_number']     ?? '',
                'url'      => $t['custom_tracking_link'] ?? ($t['tracking_link'] ?? ''),
                'date'     => $t['date_shipped']        ?? '',
                'source'   => 'WooCommerce Shipment Tracking',
            );
        }
    }

    // 2) AfterShip
    $aftership_number   = $order->get_meta('_aftership_tracking_number', true);
    $aftership_provider = $order->get_meta('_aftership_tracking_provider', true);
    $aftership_url      = $order->get_meta('_aftership_tracking_url', true);
    if ($aftership_number) {
        $tracking[] = array(
            'provider' => $aftership_provider ?: '',
            'number'   => $aftership_number,
            'url'      => $aftership_url ?: '',
            'date'     => '',
            'source'   => 'AfterShip',
        );
    }

    // 3) Advanced Shipment Tracking (AST)
    $ast_items = $order->get_meta('_ast_tracking_items', true);
    if (!empty($ast_items) && is_array($ast_items)) {
        foreach ($ast_items as $t) {
            $tracking[] = array(
                'provider' => $t['ast_tracking_provider'] ?? '',
                'number'   => $t['ast_tracking_number']   ?? '',
                'url'      => $t['ast_tracking_link']     ?? '',
                'date'     => $t['ast_date_shipped']      ?? '',
                'source'   => 'Advanced Shipment Tracking',
            );
        }
    }

    // 4) Custom meta fallback (if your own plugin/theme saved keys)
    $custom_number = $order->get_meta('_tracking_number', true);
    if ($custom_number) {
        $tracking[] = array(
            'provider' => (string) $order->get_meta('_tracking_provider', true),
            'number'   => (string) $custom_number,
            'url'      => (string) $order->get_meta('_tracking_url', true),
            'date'     => '',
            'source'   => 'Custom Meta',
        );
    }

    return $tracking;
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

// Initialize the plugin
Fluxa_eCommerce_Assistant::get_instance();