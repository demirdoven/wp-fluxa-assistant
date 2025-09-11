<?php
if (!defined('ABSPATH')) { exit; }

if (!class_exists('Sensay_Client')) {
    class Sensay_Client {
        /**
         * Perform a POST request to the Sensay API
         * @param string $path
         * @param array $body
         * @return array|WP_Error { code:int, body:array }
         */
        public function post($path, $body = array(), $extra_headers = array()) {
            $url = $this->build_url($path);
            $headers = array_merge($this->build_headers(true), is_array($extra_headers) ? $extra_headers : array());
            $this->log_http('sensay_post', $url, $headers, $body);

            $res = wp_remote_post($url, array(
                'method'  => 'POST',
                'headers' => $headers,
                'timeout' => 30,
                'body'    => wp_json_encode($body),
            ));

            if (is_wp_error($res)) {
                $this->log_error('sensay_post', $res);
                return $res;
            }
            $out = array(
                'code' => wp_remote_retrieve_response_code($res),
                'body' => json_decode(wp_remote_retrieve_body($res), true),
            );
            $this->log_response('sensay_post', $out);
            return $out;
        }

        /**
         * Perform a GET request to the Sensay API
         * @param string $path
         * @param array $query
         * @return array|WP_Error { code:int, body:array }
         */
        public function get($path, $query = array(), $extra_headers = array()) {
            $url = $this->build_url($path);
            if (!empty($query)) { $url = add_query_arg($query, $url); }
            $headers = array_merge($this->build_headers(false), is_array($extra_headers) ? $extra_headers : array());
            $this->log_http('sensay_get', $url, $headers, null);

            $res = wp_remote_get($url, array(
                'timeout' => 30,
                'headers' => $headers,
            ));

            if (is_wp_error($res)) {
                $this->log_error('sensay_get', $res);
                return $res;
            }
            $out = array(
                'code' => wp_remote_retrieve_response_code($res),
                'body' => json_decode(wp_remote_retrieve_body($res), true),
            );
            $this->log_response('sensay_get', $out);
            return $out;
        }

        /**
         * Perform a DELETE request to the Sensay API
         * @param string $path
         * @return array|WP_Error { code:int, body:array }
         */
        public function delete($path, $extra_headers = array()) {
            $url = $this->build_url($path);
            $headers = array_merge($this->build_headers(false), is_array($extra_headers) ? $extra_headers : array());
            $this->log_http('sensay_delete', $url, $headers, null);

            $res = wp_remote_request($url, array(
                'method'  => 'DELETE',
                'timeout' => 30,
                'headers' => $headers,
            ));

            if (is_wp_error($res)) {
                $this->log_error('sensay_delete', $res);
                return $res;
            }
            $out = array(
                'code' => wp_remote_retrieve_response_code($res),
                'body' => json_decode(wp_remote_retrieve_body($res), true),
            );
            $this->log_response('sensay_delete', $out);
            return $out;
        }

        private function build_url($path) {
            $base = defined('SENSAY_API_BASE') ? SENSAY_API_BASE : 'https://api.sensay.io';
            return trailingslashit($base) . ltrim($path, '/');
        }

        private function build_headers($json = true) {
            // Always read the latest API key from options to avoid staleness
            $api_key = get_option('fluxa_api_key', '');
            $headers = array(
                'X-ORGANIZATION-SECRET' => $api_key,
                'X-API-Version'         => defined('SENSAY_API_VERSION') ? SENSAY_API_VERSION : '2025-03-25',
            );
            if ($json) {
                $headers['Content-Type'] = 'application/json';
            } else {
                $headers['Accept'] = 'application/json';
            }
            return $headers;
        }

        private function log_http($kind, $url, $headers, $body) {
            if (!function_exists('fluxa_log')) { return; }
            $masked_headers = $headers;
            if (isset($masked_headers['X-ORGANIZATION-SECRET']) && is_string($masked_headers['X-ORGANIZATION-SECRET'])) {
                $secret = $masked_headers['X-ORGANIZATION-SECRET'];
                $masked_headers['X-ORGANIZATION-SECRET'] = substr($secret, 0, 4) . '...' . substr($secret, -4);
            }
            fluxa_log($kind . ': url=' . $url . ' headers=' . wp_json_encode($masked_headers) . (isset($body) ? (' body=' . wp_json_encode($body)) : ''));
        }

        private function log_response($kind, $out) {
            if (!function_exists('fluxa_log')) { return; }
            fluxa_log($kind . ': code=' . intval($out['code']) . ' body=' . wp_json_encode($out['body']));
        }

        private function log_error($kind, $err) {
            if (!function_exists('fluxa_log')) { return; }
            fluxa_log($kind . ': wp_error=' . $err->get_error_message());
        }
    }
}
