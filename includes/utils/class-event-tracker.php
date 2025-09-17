<?php
/**
 * Event Tracker Service
 * Handles logging user events to wp_fluxa_conv_events table
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Fluxa_Event_Tracker {
    
    /**
     * Log an event to the database
     */
    public static function log_event($event_type, $data = array()) {
        global $wpdb;
        
        // Respect tracking toggle
        if ((int) get_option('fluxa_tracking_enabled', 1) !== 1) {
            return false;
        }
        
        $table = $wpdb->prefix . 'fluxa_conv_events';
        
        // Get session info
        $wc_session_key = '';
        $user_id = null;
        $ss_user_id = '';
        
        try {
            // Get conversation ID and ss_user_id from user service (unless provided)
            if (class_exists('Fluxa_User_ID_Service')) {
                if (empty($data['ss_user_id'])) {
                    $ss_user_id = Fluxa_User_ID_Service::get_or_create_current_user_id();
                } else {
                    $ss_user_id = sanitize_text_field((string)$data['ss_user_id']);
                }
                // Normalize ss_user_id to UUID-only; if not a UUID and not dotted form, leave empty to avoid wrong IDs
                if (!empty($ss_user_id)) {
                    if (preg_match('/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/', $ss_user_id, $m)) {
                        $ss_user_id = $m[0];
                    } else {
                        // If cookie-style value contains a dot-delimited signature, strip before first dot
                        $dot = strpos($ss_user_id, '.');
                        if ($dot !== false) {
                            $ss_user_id = substr($ss_user_id, 0, $dot);
                        }
                        // After stripping, ensure it's a UUID; otherwise discard (set empty)
                        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $ss_user_id)) {
                            $ss_user_id = '';
                        }
                    }
                }
            }
            
            // Get WooCommerce session key (ensure session cookie exists for guests)
            if (function_exists('WC') && WC()) {
                $sess = WC()->session;
                if ($sess) {
                    try {
                        if (method_exists($sess, 'has_session') && !$sess->has_session()) {
                            if (method_exists($sess, 'set_customer_session_cookie')) {
                                $sess->set_customer_session_cookie(true);
                            }
                        }
                    } catch (\Throwable $e) {}
                    try {
                        if (method_exists($sess, 'get_customer_id')) {
                            $wc_session_key = (string) $sess->get_customer_id();
                        }
                    } catch (\Throwable $e) {}
                }
            }
            
            // Get WordPress user ID
            $user_id = get_current_user_id();
            if ($user_id === 0) {
                $user_id = null;
            }
            
            // No conversation_id usage anymore (we rely solely on ss_user_id)
            
        } catch (Exception $e) {
            // Silent fail for session issues
        }
        
        // Get request context; prefer explicit page context from client over REST endpoint URL
        $url = '';
        $referer = '';
        if (!empty($data['page_url'])) {
            $url = esc_url_raw((string) $data['page_url']);
        }
        if (!empty($data['page_referrer'])) {
            $referer = esc_url_raw((string) $data['page_referrer']);
        }
        if ($url === '') {
            $url = isset($_SERVER['REQUEST_URI']) ? esc_url_raw($_SERVER['REQUEST_URI']) : '';
        }
        if ($referer === '') {
            $referer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '';
        }
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 255) : '';
        
        // Get IP address
        $ip = '';
        if (class_exists('WC_Geolocation')) {
            try {
                $ip = WC_Geolocation::get_ip_address();
            } catch (Exception $e) {
                $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
            }
        } else {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        }
        
        // Convert IP to binary
        $ip_bin = null;
        if (!empty($ip) && function_exists('inet_pton')) {
            $packed = @inet_pton($ip);
            if ($packed !== false) {
                $ip_bin = $packed;
            }
        }
        
        // Backfill of conversation_id removed by requirement; matching is done via ss_user_id only

        // Prepare event data
        $event_data = array(
            'ss_user_id' => $ss_user_id,
            'user_id' => $user_id,
            'wc_session_key' => ($wc_session_key !== '' ? $wc_session_key : null),
            'event_type' => sanitize_text_field($event_type),
            'event_time' => current_time('mysql'),
            'url' => $url,
            'referer' => $referer,
            'ip' => $ip_bin,
            'user_agent' => $user_agent,
        );
        
        // Add optional fields from data array
        $optional_fields = array(
            'product_id', 'variation_id', 'qty', 'price', 'currency',
            'order_id', 'order_status', 'cart_total', 'shipping_total',
            'discount_total', 'tax_total'
        );
        
        foreach ($optional_fields as $field) {
            if (isset($data[$field]) && $data[$field] !== null && $data[$field] !== '') {
                $event_data[$field] = $data[$field];
            }
        }
        
        // Server-side fallback: if this is a product visibility event and price wasn't provided by the client,
        // look up the current catalog price so we can persist something meaningful instead of NULL.
        try {
            $needs_price = in_array($event_type, array('product_impression', 'product_view'), true);
            $has_pid = !empty($event_data['product_id']);
            $missing_price = !isset($event_data['price']) || $event_data['price'] === '' || $event_data['price'] === null || (is_numeric($event_data['price']) && (float)$event_data['price'] <= 0);
            if ($needs_price && $has_pid && $missing_price && function_exists('wc_get_product')) {
                $pid = (int)$event_data['product_id'];
                $vid = isset($event_data['variation_id']) ? (int)$event_data['variation_id'] : 0;
                $prod = wc_get_product($vid ?: $pid);
                if ($prod) {
                    $raw = $prod->get_price();
                    // Fallbacks for variable products or empty price
                    if ($raw === '' || $raw === null) {
                        if (method_exists($prod, 'get_variation_price')) {
                            $raw = $prod->get_variation_price('min', false);
                        }
                    }
                    if (($raw === '' || $raw === null) && method_exists($prod, 'get_regular_price')) {
                        $raw = $prod->get_regular_price();
                    }
                    if ($raw !== '' && $raw !== null && is_numeric($raw)) {
                        $event_data['price'] = (float)$raw;
                    }
                    if (empty($event_data['currency'])) {
                        $event_data['currency'] = get_woocommerce_currency();
                    }
                }
            }
        } catch (\Throwable $e) {}

        // Handle json_payload
        if (isset($data['json_payload']) && !empty($data['json_payload'])) {
            if (is_array($data['json_payload']) || is_object($data['json_payload'])) {
                $event_data['json_payload'] = wp_json_encode($data['json_payload']);
            } else {
                $event_data['json_payload'] = (string) $data['json_payload'];
            }
        }
        
        // Prepare formats for wpdb
        $formats = array();
        foreach ($event_data as $key => $value) {
            if (in_array($key, array('user_id', 'product_id', 'variation_id', 'qty', 'order_id'))) {
                $formats[] = '%d';
            } elseif (in_array($key, array('price', 'cart_total', 'shipping_total', 'discount_total', 'tax_total'))) {
                $formats[] = '%f';
            } elseif ($key === 'ip') {
                $formats[] = '%s'; // VARBINARY
            } else {
                $formats[] = '%s';
            }
        }
        
        // Insert into database
        $result = $wpdb->insert($table, $event_data, $formats);
        
        if ($result === false) {
            error_log('Fluxa Event Tracker: Failed to insert event ' . $event_type . ' - ' . $wpdb->last_error);
            return false;
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Get cart totals for events
     */
    public static function get_cart_totals() {
        if (!function_exists('WC') || !WC()->cart) {
            return array();
        }
        
        $cart = WC()->cart;
        $currency = get_woocommerce_currency();
        
        return array(
            'cart_total' => (float) $cart->get_total('edit'),
            'shipping_total' => (float) $cart->get_shipping_total(),
            'discount_total' => (float) $cart->get_discount_total(),
            'tax_total' => (float) $cart->get_total_tax(),
            'currency' => $currency
        );
    }
    
    /**
     * Get order totals for events
     */
    public static function get_order_totals($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return array();
        }
        
        return array(
            'cart_total' => (float) $order->get_total(),
            'shipping_total' => (float) $order->get_shipping_total(),
            'discount_total' => (float) $order->get_discount_total(),
            'tax_total' => (float) $order->get_total_tax(),
            'currency' => $order->get_currency()
        );
    }
    
    /**
     * Get product data for events
     */
    public static function get_product_data($product_id, $variation_id = null, $qty = null) {
        $product = wc_get_product($variation_id ? $variation_id : $product_id);
        if (!$product) {
            return array();
        }
        
        $data = array(
            'product_id' => (int) $product_id,
            'price' => (float) $product->get_price(),
            'currency' => get_woocommerce_currency()
        );
        
        if ($variation_id) {
            $data['variation_id'] = (int) $variation_id;
        }
        
        if ($qty !== null) {
            $data['qty'] = (int) $qty;
        }
        
        return $data;
    }
}
