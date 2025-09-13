<?php
if (!defined('ABSPATH')) { exit; }

class Fluxa_User_ID_Service {
    const COOKIE_NAME = 'fluxa_uid';
    const COOKIE_LIFETIME = 31536000; // 1 year

    /**
     * Returns a stable chat user ID for the current visitor.
     * - Logged-in users: stored in user_meta 'fluxa_ss_user_id'
     * - Guests: stored in cookie 'fluxa_uid' with HMAC signature
     */
    public static function get_or_create_current_user_id() {
        $uid = get_current_user_id();
        if ($uid) {
            $existing = get_user_meta($uid, 'fluxa_ss_user_id', true);
            if (!empty($existing)) {
                return $existing;
            }
            // Logged-in: try to provision via Sensay API first
            $new = self::provision_external_user_id();
            if (empty($new)) { $new = self::generate_id(); }
            update_user_meta($uid, 'fluxa_ss_user_id', $new);
            return $new;
        }
        
        // Guest resolution order:
        // 1) Existing Woo session value
        // 2) Existing cookie (authoritative), and backfill into WC session
        // 3) Provision/generate new, then persist to cookie and WC session
        if (function_exists('WC') && WC()->session) {
            $wc_user_id = WC()->session->get('fluxa_user_id');
            if ($wc_user_id) {
                error_log("Fluxa Debug - Reusing WC session ID: " . $wc_user_id);
                return $wc_user_id;
            }
        }

        // Cookie method
        $cookie = isset($_COOKIE[self::COOKIE_NAME]) ? wp_unslash($_COOKIE[self::COOKIE_NAME]) : '';
        error_log("Fluxa Debug - Cookie value: " . ($cookie ? $cookie : 'EMPTY'));
        
        if ($cookie) {
            $parsed = self::parse_cookie($cookie);
            if ($parsed) {
                error_log("Fluxa Debug - Reusing existing ID: " . $parsed['id']);
                // Prefer continuity: even if signature is invalid, reuse the ID to avoid rotating within a session
                // Also refresh cookie to extend lifetime and normalize attributes
                self::set_cookie($parsed['id']);
                // Backfill into WC session if present and empty
                if (function_exists('WC') && WC()->session && !WC()->session->get('fluxa_user_id')) {
                    WC()->session->set('fluxa_user_id', $parsed['id']);
                }
                return $parsed['id'];
            }
        }
        // No session value and no cookie: provision external user if possible, otherwise generate local
        $new = self::provision_external_user_id();
        if (empty($new)) { $new = self::generate_id(); }
        error_log("Fluxa Debug - Generated new ID: " . $new);
        self::set_cookie($new);
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('fluxa_user_id', $new);
        }
        return $new;
    }

    /** Generate a 32-hex character random ID */
    public static function generate_id() {
        return bin2hex(random_bytes(16));
    }

    /** Set signed cookie for guest users */
    public static function set_cookie($id) {
        $sig = self::sign_id($id);
        $value = $id . '.' . $sig;
        $expire = time() + self::COOKIE_LIFETIME;
        // Allow JS access to read for frontend chat; do not set HttpOnly
        $paths = array(
            defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            defined('SITECOOKIEPATH') && SITECOOKIEPATH ? SITECOOKIEPATH : '/',
        );
        $domain = (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) ? COOKIE_DOMAIN : null;
        foreach (array_unique($paths) as $path) {
            $opts = array(
                'expires'  => $expire,
                'path'     => $path,
                'secure'   => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            );
            if (!empty($domain)) {
                $opts['domain'] = $domain;
            }
            @setcookie(self::COOKIE_NAME, $value, $opts);
        }
        // Also set a root-path cookie as a fallback
        if (!in_array('/', $paths, true)) {
            $opts = array(
                'expires'  => $expire,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            );
            if (!empty($domain)) {
                $opts['domain'] = $domain;
            }
            @setcookie(self::COOKIE_NAME, $value, $opts);
        }
        $_COOKIE[self::COOKIE_NAME] = $value; // make available in-request
    }

    /** Build HMAC signature for an ID */
    private static function sign_id($id) {
        $secret = self::secret();
        return hash_hmac('sha256', $id, $secret);
    }

    /** Verify HMAC signature */
    private static function verify_signature($id, $sig) {
        if (empty($id) || empty($sig)) return false;
        $calc = self::sign_id($id);
        return hash_equals($calc, $sig);
    }

    private static function parse_cookie($value) {
        $parts = explode('.', $value);
        if (count($parts) !== 2) return null;
        return array('id' => $parts[0], 'sig' => $parts[1]);
    }

    /**
     * Attempt to provision a new Sensay user via API and return its UUID.
     * Returns empty string on failure.
     */
    private static function provision_external_user_id() {
        try {
            if (!class_exists('Sensay_Client')) { return ''; }
            $client = new \Sensay_Client();
            // Minimal payload; backend may ignore extras
            $payload = array(
                'name' => 'Guest',
            );
            $res = $client->post('/v1/users', $payload);
            if (is_wp_error($res)) { return ''; }
            $code = intval($res['code'] ?? 0);
            $body = $res['body'] ?? array();
            if ($code >= 200 && $code < 300 && is_array($body)) {
                $id = $body['id'] ?? ($body['uuid'] ?? '');
                if (!empty($id)) { return (string) $id; }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return '';
    }

    /** Use AUTH_SALT (or SECURE_AUTH_SALT) as signing secret */
    private static function secret() {
        if (defined('AUTH_SALT') && AUTH_SALT) return AUTH_SALT;
        if (defined('SECURE_AUTH_SALT') && SECURE_AUTH_SALT) return SECURE_AUTH_SALT;
        // fallback to site-specific key
        return wp_salt('auth');
    }
}
