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
            $new = self::generate_id();
            update_user_meta($uid, 'fluxa_ss_user_id', $new);
            return $new;
        }
        // Guest
        $cookie = isset($_COOKIE[self::COOKIE_NAME]) ? wp_unslash($_COOKIE[self::COOKIE_NAME]) : '';
        if ($cookie) {
            $parsed = self::parse_cookie($cookie);
            if ($parsed) {
                // Prefer continuity: even if signature is invalid, reuse the ID to avoid rotating within a session
                // Also refresh cookie to extend lifetime and normalize attributes
                self::set_cookie($parsed['id']);
                return $parsed['id'];
            }
        }
        $new = self::generate_id();
        self::set_cookie($new);
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

    /** Use AUTH_SALT (or SECURE_AUTH_SALT) as signing secret */
    private static function secret() {
        if (defined('AUTH_SALT') && AUTH_SALT) return AUTH_SALT;
        if (defined('SECURE_AUTH_SALT') && SECURE_AUTH_SALT) return SECURE_AUTH_SALT;
        // fallback to site-specific key
        return wp_salt('auth');
    }
}
