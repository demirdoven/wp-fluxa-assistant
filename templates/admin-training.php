<?php
if (!defined('ABSPATH')) { exit; }

// Helper to fetch single training item details
function fluxa_get_kb_item($item_id) {
    $api_key    = get_option('fluxa_api_key', '');
    $replica_id = get_option('fluxa_ss_replica_id', '');
    if (empty($api_key)) {
        return new WP_Error('missing_api_key', __('API key is missing.', 'fluxa-ecommerce-assistant'));
    }
    if (empty($replica_id)) {
        return new WP_Error('missing_replica', __('Replica ID is missing.', 'fluxa-ecommerce-assistant'));
    }
    $item_id = trim((string)$item_id);
    if ($item_id === '') {
        return new WP_Error('missing_id', __('Training item ID is required.', 'fluxa-ecommerce-assistant'));
    }
    $base = defined('SENSAY_API_BASE') ? SENSAY_API_BASE : 'https://api.sensay.io';
    $headers = array(
        'X-ORGANIZATION-SECRET' => $api_key,
        'X-API-Version'         => defined('SENSAY_API_VERSION') ? SENSAY_API_VERSION : '2025-03-25',
    );
    $url = trailingslashit($base) . 'v1/replicas/' . rawurlencode($replica_id) . '/knowledge-base/' . rawurlencode($item_id);
    $res = wp_remote_get($url, array('timeout' => 20, 'headers' => $headers));
    if (is_wp_error($res)) { return $res; }
    $code = (int) wp_remote_retrieve_response_code($res);
    $body_raw = wp_remote_retrieve_body($res);
    $body = json_decode($body_raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($body) && isset($body['error']) ? (string)$body['error'] : ('HTTP ' . $code);
        return new WP_Error('http_error', $msg, array('status' => $code, 'body' => ($body !== null ? $body : $body_raw)));
    }
    return array(
        'status'  => $code,
        'body'    => ($body !== null ? $body : $body_raw),
        'headers' => wp_remote_retrieve_headers($res),
    );
}

// AJAX: details for a single training item (admin only)
add_action('wp_ajax_fluxa_kb_item_details', function(){
    if (!current_user_can('manage_options')) { wp_send_json_error(array('error' => 'forbidden'), 403); }
    if (!check_ajax_referer('fluxa_kb_item_details', '_ajax_nonce', false)) {
        wp_send_json_error(array('error' => 'bad_nonce'), 400);
    }
    $id = isset($_GET['id']) ? sanitize_text_field(wp_unslash($_GET['id'])) : '';
    if ($id === '') { wp_send_json_error(array('error' => 'missing_id'), 400); }
    $r = fluxa_get_kb_item($id);
    if (is_wp_error($r)) {
        wp_send_json_error(array(
            'message' => $r->get_error_message(),
            'data'    => $r->get_error_data(),
        ), 500);
    }
    // Provide a concise demo payload plus raw body
    $demo = array();
    if (is_array($r) && isset($r['body']) && is_array($r['body'])) {
        $b = $r['body'];
        $demo = array(
            'id'        => $b['id'] ?? $id,
            'type'      => $b['type'] ?? '',
            'title'     => $b['title'] ?? '',
            'status'    => $b['status'] ?? '',
            'createdAt' => $b['createdAt'] ?? ($b['created_at'] ?? ''),
        );
    }
    wp_send_json_success(array(
        'demo' => $demo,
        'raw'  => $r,
    ));
});

// AJAX: delete a single training item (admin only)
add_action('wp_ajax_fluxa_kb_item_delete', function(){
    if (!current_user_can('manage_options')) { wp_send_json_error(array('error' => 'forbidden'), 403); }
    if (!check_ajax_referer('fluxa_kb_item_delete', '_ajax_nonce', false)) {
        wp_send_json_error(array('error' => 'bad_nonce'), 400);
    }
    $id = isset($_REQUEST['id']) ? sanitize_text_field(wp_unslash($_REQUEST['id'])) : '';
    if ($id === '') { wp_send_json_error(array('error' => 'missing_id'), 400); }
    $api_key    = get_option('fluxa_api_key', '');
    $replica_id = get_option('fluxa_ss_replica_id', '');
    if (empty($api_key) || empty($replica_id)) {
        wp_send_json_error(array('error' => 'missing_config'), 400);
    }
    $base = defined('SENSAY_API_BASE') ? SENSAY_API_BASE : 'https://api.sensay.io';
    $url  = trailingslashit($base) . 'v1/replicas/' . rawurlencode($replica_id) . '/knowledge-base/' . rawurlencode($id);
    $headers = array(
        'X-ORGANIZATION-SECRET' => $api_key,
        'X-API-Version'         => defined('SENSAY_API_VERSION') ? SENSAY_API_VERSION : '2025-03-25',
    );
    $res = wp_remote_request($url, array('timeout' => 20, 'headers' => $headers, 'method' => 'DELETE'));
    if (is_wp_error($res)) { wp_send_json_error(array('error' => $res->get_error_message()), 502); }
    $code = (int) wp_remote_retrieve_response_code($res);
    $body_raw = wp_remote_retrieve_body($res);
    $body = json_decode($body_raw, true);
    if ($code === 200 || $code === 202) {
        $msg = '';
        if (is_array($body) && !empty($body['message'])) { $msg = (string)$body['message']; }
        wp_send_json_success(array('ok' => true, 'status' => $code, 'message' => $msg));
    }
    $err = is_array($body) && isset($body['error']) ? (string)$body['error'] : ('HTTP ' . $code);
    wp_send_json_error(array('error' => $err, 'status' => $code, 'body' => ($body !== null ? $body : $body_raw)), $code ?: 500);
});

// Handle YouTube submit (same as URL method, different labels)
if (isset($_POST['fluxa_train_youtube_submit'])) {
    check_admin_referer('fluxa_train_youtube', 'fluxa_train_youtube_nonce');
    $title = sanitize_text_field($_POST['url_title'] ?? '');
    $input_raw = trim((string)($_POST['train_url'] ?? ''));
    $url = esc_url_raw($input_raw);
    $converted = false;
    // If not a valid URL, check if it's a likely YouTube video ID and convert
    if (!$url || !wp_http_validate_url($url)) {
        if (preg_match('/^[A-Za-z0-9_-]{10,}$/', $input_raw)) {
            $url = 'https://www.youtube.com/watch?v=' . $input_raw;
            $converted = true;
        }
    }
    if ($title === '') {
        $notice_msg = __('Please enter a title.', 'fluxa-ecommerce-assistant');
        $notice_class = 'notice-error';
    } elseif (!$url || !wp_http_validate_url($url)) {
        $notice_msg = __('Please enter a valid URL or YouTube video ID.', 'fluxa-ecommerce-assistant');
        $notice_class = 'notice-error';
    } else {
        $payload = array(
            'title'       => $title,
            'autoRefresh' => false,
            'url'         => $url,
        );
        $r = fluxa_send_kb_request($payload);
        if (is_wp_error($r)) {
            $notice_msg = __('YouTube training failed: ', 'fluxa-ecommerce-assistant') . $r->get_error_message();
            $notice_class = 'notice-error';
            $api_response = array(
                'ok' => false,
                'error' => $r->get_error_message(),
                'data' => $r->get_error_data(),
            );
        } else {
            $notice_msg = $converted
                ? sprintf(__('YouTube training sent successfully. Converted ID to URL: %s', 'fluxa-ecommerce-assistant'), $url)
                : __('YouTube training sent successfully.', 'fluxa-ecommerce-assistant');
            $notice_class = 'notice-success';
            $api_response = $r;
        }
    }
}

// Handle Q&A submit (composes pairs into a single text payload)
if (isset($_POST['fluxa_train_qa_submit'])) {
    check_admin_referer('fluxa_train_qa', 'fluxa_train_qa_nonce');
    $qs = isset($_POST['qa_q']) && is_array($_POST['qa_q']) ? array_map('sanitize_text_field', $_POST['qa_q']) : array();
    $as = isset($_POST['qa_a']) && is_array($_POST['qa_a']) ? array_map('wp_kses_post', $_POST['qa_a']) : array();
    $pairs = array();
    for ($i = 0; $i < max(count($qs), count($as)); $i++) {
        $q = trim($qs[$i] ?? '');
        $a = trim(wp_strip_all_tags($as[$i] ?? ''));
        if ($q !== '' && $a !== '') {
            $pairs[] = array($q, $a);
        }
    }
    if (empty($pairs)) {
        $notice_msg = __('Please enter at least one question and answer.', 'fluxa-ecommerce-assistant');
        $notice_class = 'notice-error';
    } else {
        // Compose a simple, readable text format
        $lines = array();
        foreach ($pairs as $p) {
            $lines[] = 'Q: ' . $p[0];
            $lines[] = 'A: ' . $p[1];
            $lines[] = '';
        }
        $full_text = implode("\n", $lines);
        // Title format: QA DATETIME (site-local time)
        $now_ts = function_exists('current_time') ? current_time('timestamp') : time();
        $title_dt = function_exists('date_i18n') ? date_i18n('Y-m-d H:i', $now_ts) : date('Y-m-d H:i', $now_ts);
        $payload = array(
            'title'       => 'QA ' . $title_dt,
            'autoRefresh' => false,
            'text'        => $full_text,
        );
        $r = fluxa_send_kb_request($payload);
        if (is_wp_error($r)) {
            $notice_msg = __('Q&A training failed: ', 'fluxa-ecommerce-assistant') . $r->get_error_message();
            $notice_class = 'notice-error';
            $api_response = array(
                'ok' => false,
                'error' => $r->get_error_message(),
                'data' => $r->get_error_data(),
            );
        } else {
            $notice_msg = __('Q&A training sent successfully.', 'fluxa-ecommerce-assistant');
            $notice_class = 'notice-success';
            $api_response = $r;
        }
    }
}

// Helper to fetch training data list (per docs: GET /v1/replicas/{replicaUUID}/knowledge-base)
function fluxa_get_kb_list($type = '') {
    $api_key    = get_option('fluxa_api_key', '');
    $replica_id = get_option('fluxa_ss_replica_id', '');
    if (empty($api_key)) {
        return new WP_Error('missing_api_key', __('API key is missing.', 'fluxa-ecommerce-assistant'));
    }
    if (empty($replica_id)) {
        return new WP_Error('missing_replica', __('Replica ID is missing.', 'fluxa-ecommerce-assistant'));
    }
    $base = defined('SENSAY_API_BASE') ? SENSAY_API_BASE : 'https://api.sensay.io';
    $headers = array(
        'X-ORGANIZATION-SECRET' => $api_key,
        'X-API-Version'         => defined('SENSAY_API_VERSION') ? SENSAY_API_VERSION : '2025-03-25',
    );

    // Primary: replicas knowledge-base per docs
    $url_replica = trailingslashit($base) . 'v1/replicas/' . rawurlencode($replica_id) . '/knowledge-base';
    if (!empty($type)) {
        $url_replica = add_query_arg(array('type' => $type), $url_replica);
    }
    $res = wp_remote_get($url_replica, array('timeout' => 20, 'headers' => $headers));
    if (!is_wp_error($res)) {
        $code = (int) wp_remote_retrieve_response_code($res);
        $body_raw = wp_remote_retrieve_body($res);
        $body = json_decode($body_raw, true);
        if ($code >= 200 && $code < 300) {
            return array(
                'status'  => $code,
                'body'    => ($body !== null ? $body : $body_raw),
                'headers' => wp_remote_retrieve_headers($res),
            );
        }
    }

    // Fallback: organization-wide list (if supported in your env)
    $url_fallback = trailingslashit($base) . 'v1/training';
    if (!empty($type)) {
        $url_fallback = add_query_arg(array('type' => $type), $url_fallback);
    }
    $res2 = wp_remote_get($url_fallback, array('timeout' => 20, 'headers' => $headers));
    if (is_wp_error($res2)) { return $res2; }
    $code2 = (int) wp_remote_retrieve_response_code($res2);
    $body2_raw = wp_remote_retrieve_body($res2);
    $body2 = json_decode($body2_raw, true);
    if ($code2 < 200 || $code2 >= 300) {
        $msg = is_array($body2) && isset($body2['error']) ? (string)$body2['error'] : ('HTTP ' . $code2);
        return new WP_Error('http_error', $msg, array('status' => $code2, 'body' => ($body2 !== null ? $body2 : $body2_raw)));
    }
    return array(
        'status'  => $code2,
        'body'    => ($body2 !== null ? $body2 : $body2_raw),
        'headers' => wp_remote_retrieve_headers($res2),
        'note'    => 'Listed via fallback /v1/training',
    );
}

// Minimal Training page with three loaders: Text, URL, File (filename only)
if (!current_user_can('manage_options')) { return; }

$notice_msg = '';
$notice_class = 'notice-info';
$api_response = null; // store last API response to display
$kb_response  = null; // store last KB list response

// Router: which method view to show (must be defined before any rendering below)
$method = isset($_GET['method']) ? sanitize_key($_GET['method']) : '';
$base_url = admin_url('admin.php?page=fluxa-assistant-training');

// Helper to call Sensay API
function fluxa_send_kb_request($payload) {
    $replica_id = get_option('fluxa_ss_replica_id', '');
    $api_key    = get_option('fluxa_api_key', '');
    if (empty($replica_id) || empty($api_key)) {
        return new WP_Error('missing_config', __('Replica ID or API key is missing.', 'fluxa-ecommerce-assistant'));
    }
    $base = defined('SENSAY_API_BASE') ? SENSAY_API_BASE : 'https://api.sensay.io';
    $url  = trailingslashit($base) . 'v1/replicas/' . rawurlencode($replica_id) . '/knowledge-base';
    $headers = array(
        'X-ORGANIZATION-SECRET' => $api_key,
        'Content-Type'          => 'application/json',
        'X-API-Version'         => defined('SENSAY_API_VERSION') ? SENSAY_API_VERSION : '2025-03-25',
    );
    $res = wp_remote_post($url, array(
        'timeout' => 20,
        'headers' => $headers,
        'body'    => wp_json_encode($payload),
    ));
    if (is_wp_error($res)) { return $res; }
    $code = (int) wp_remote_retrieve_response_code($res);
    $body_raw = wp_remote_retrieve_body($res);
    $body = json_decode($body_raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($body) && isset($body['error']) ? (string)$body['error'] : ('HTTP ' . $code);
        return new WP_Error('http_error', $msg, array('status' => $code, 'body' => ($body !== null ? $body : $body_raw)));
    }
    return array(
        'status' => $code,
        'body' => ($body !== null ? $body : $body_raw),
        'headers' => wp_remote_retrieve_headers($res),
    );
}

// Handle Text submit
if (isset($_POST['fluxa_train_text_submit'])) {
    check_admin_referer('fluxa_train_text', 'fluxa_train_text_nonce');
    $title = sanitize_text_field($_POST['text_title'] ?? '');
    $text  = trim(wp_strip_all_tags($_POST['text_body'] ?? ''));
    if ($title === '') {
        $notice_msg = __('Please enter a title.', 'fluxa-ecommerce-assistant');
        $notice_class = 'notice-error';
    } elseif ($text === '') {
        $notice_msg = __('Please enter some text.', 'fluxa-ecommerce-assistant');
        $notice_class = 'notice-error';
    } else {
        $payload = array(
            'title'       => $title,
            'autoRefresh' => false,
            'text'        => $text,
        );
        $r = fluxa_send_kb_request($payload);
        if (is_wp_error($r)) {
            $notice_msg = __('Text training failed: ', 'fluxa-ecommerce-assistant') . $r->get_error_message();
            $notice_class = 'notice-error';
            $api_response = array(
                'ok' => false,
                'error' => $r->get_error_message(),
                'data' => $r->get_error_data(),
            );
        } else {
            $notice_msg = __('Text training sent successfully.', 'fluxa-ecommerce-assistant');
            $notice_class = 'notice-success';
            $api_response = $r;
        }
    }
}

// Handle URL submit
if (isset($_POST['fluxa_train_url_submit'])) {
    check_admin_referer('fluxa_train_url', 'fluxa_train_url_nonce');
    $title = sanitize_text_field($_POST['url_title'] ?? '');
    $url   = esc_url_raw($_POST['train_url'] ?? '');
    if ($title === '') {
        $notice_msg = __('Please enter a title.', 'fluxa-ecommerce-assistant');
        $notice_class = 'notice-error';
    } elseif (!$url || !wp_http_validate_url($url)) {
        $notice_msg = __('Please enter a valid URL.', 'fluxa-ecommerce-assistant');
        $notice_class = 'notice-error';
    } else {
        $payload = array(
            'title'       => $title,
            'autoRefresh' => false,
            'url'         => $url,
        );
        $r = fluxa_send_kb_request($payload);
        if (is_wp_error($r)) {
            $notice_msg = __('URL training failed: ', 'fluxa-ecommerce-assistant') . $r->get_error_message();
            $notice_class = 'notice-error';
            $api_response = array(
                'ok' => false,
                'error' => $r->get_error_message(),
                'data' => $r->get_error_data(),
            );
        } else {
            $notice_msg = __('URL training sent successfully.', 'fluxa-ecommerce-assistant');
            $notice_class = 'notice-success';
            $api_response = $r;
        }
    }
}

// Handle File upload (two-step per API: get signedURL then PUT the file)
if (isset($_POST['fluxa_train_file_upload_submit'])) {
    check_admin_referer('fluxa_train_file_upload', 'fluxa_train_file_upload_nonce');
    $title = sanitize_text_field($_POST['file_title'] ?? '');
    if (empty($_FILES['training_file']['name'])) {
        $notice_msg = __('Please choose a file to upload.', 'fluxa-ecommerce-assistant');
        $notice_class = 'notice-error';
    } elseif ($title === '') {
        $notice_msg = __('Please enter a title.', 'fluxa-ecommerce-assistant');
        $notice_class = 'notice-error';
    } else {
        $file = $_FILES['training_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $notice_msg = __('Upload error. Please try again.', 'fluxa-ecommerce-assistant');
            $notice_class = 'notice-error';
        } else {
            // Basic validations
            $max_bytes = 25 * 1024 * 1024; // 25MB cap
            if (!empty($file['size']) && $file['size'] > $max_bytes) {
                $notice_msg = __('File is too large. Maximum allowed size is 25 MB.', 'fluxa-ecommerce-assistant');
                $notice_class = 'notice-error';
            } else {
                $orig_name = sanitize_file_name($file['name']);
                // Determine MIME
                $mime_type = '';
                if (function_exists('finfo_open')) {
                    $fi = finfo_open(FILEINFO_MIME_TYPE);
                    if ($fi) { $mime_type = finfo_file($fi, $file['tmp_name']) ?: ''; finfo_close($fi); }
                }
                if ($mime_type === '') {
                    $wpft = wp_check_filetype($orig_name);
                    $mime_type = !empty($wpft['type']) ? $wpft['type'] : 'application/octet-stream';
                }
                // Step 1: create training entry to get signed URL
                $payload = array(
                    'title'       => $title,
                    'autoRefresh' => false,
                    'filename'    => $orig_name,
                );
                $r = fluxa_send_kb_request($payload);
                $api_response = $r; // show what the API returned
                if (is_wp_error($r)) {
                    $notice_msg = __('Failed to create training entry: ', 'fluxa-ecommerce-assistant') . $r->get_error_message();
                    $notice_class = 'notice-error';
                } else {
                    // Try to extract signedURL
                    $signed_url = '';
                    $body = is_array($r) && isset($r['body']) ? $r['body'] : array();
                    if (is_array($body)) {
                        if (!empty($body['results']) && is_array($body['results'])) {
                            $first = $body['results'][0];
                            if (is_array($first) && !empty($first['signedURL'])) {
                                $signed_url = $first['signedURL'];
                            }
                        } elseif (!empty($body['signedURL'])) {
                            $signed_url = $body['signedURL'];
                        }
                    }
                    if ($signed_url === '') {
                        $notice_msg = __('Training created but no signed URL returned.', 'fluxa-ecommerce-assistant');
                        $notice_class = 'notice-warning';
                    } else {
                        // Step 2: PUT file to signed URL
                        $put = wp_remote_request($signed_url, array(
                            'method'  => 'PUT',
                            'headers' => array('Content-Type' => $mime_type),
                            'timeout' => 60,
                            'body'    => file_get_contents($file['tmp_name']),
                        ));
                        if (is_wp_error($put)) {
                            $notice_msg = __('File upload failed: ', 'fluxa-ecommerce-assistant') . $put->get_error_message();
                            $notice_class = 'notice-error';
                        } else {
                            $code = (int) wp_remote_retrieve_response_code($put);
                            if ($code >= 200 && $code < 300) {
                                $notice_msg = __('File uploaded successfully and queued for processing.', 'fluxa-ecommerce-assistant');
                                $notice_class = 'notice-success';
                            } else {
                                $notice_msg = sprintf(__('File upload returned HTTP %d.', 'fluxa-ecommerce-assistant'), $code);
                                $notice_class = 'notice-error';
                            }
                        }
                    }
                }
            }
        }
    }
}

?>

<?php
// Fetch replica training data on every render so it shows without submitting
if (is_null($kb_response)) {
    // Map current method to server-side type parameter
    $type_param = '';
    if ($method === 'text' || $method === 'qa') { $type_param = 'text'; }
    elseif ($method === 'url') { $type_param = 'website'; }
    elseif ($method === 'file') { $type_param = 'file'; }
    elseif ($method === 'youtube') { $type_param = 'youtube'; }

    $kb_r = fluxa_get_kb_list($type_param);
    if (is_wp_error($kb_r)) {
        $kb_response = array(
            'ok' => false,
            'error' => $kb_r->get_error_message(),
            'data' => $kb_r->get_error_data(),
        );
    } else {
        $kb_response = $kb_r;
    }
}
?>

<div class="wrap">
  <h1><?php echo esc_html__('Train Chatbot', 'fluxa-ecommerce-assistant'); ?></h1>

  <?php if ($notice_msg !== '') : ?>
    <div class="notice <?php echo esc_attr($notice_class); ?> is-dismissible"><p><?php echo esc_html($notice_msg); ?></p></div>

  <?php endif; ?>

  <script>
      // Client-side validations and UX for file upload
      jQuery(function($){
    // Decode HTML entities (e.g., &trade; -> ™) safely for display
    function decodeEntities(s){
      if (s === null || s === undefined) return '';
      try {
        const ta = document.createElement('textarea');
        ta.innerHTML = String(s);
        return ta.value;
      } catch(e){ return String(s); }
    }
        const MAX_BYTES = 25 * 1024 * 1024; // 25MB
        // Supported file types (per API docs)
        const ALLOWED_EXT = [
          // Documents
          'doc','docx','rtf','pdf','pdfa',
          // Spreadsheets & tabular
          'csv','tsv','xls','xlsx','xlsm','xlsb','ods','dta','sas7bdat','xpt',
          // Presentations
          'ppt','pptx',
          // Text files
          'txt','md','htm','html','css','js','xml',
          // Data text files
          'json','yml','yaml',
          // E-books
          'epub',
          // Images
          'png','jpg','jpeg','webp','heic','heif','tiff','bmp',
          // Audio
          'mp3','wav','aac','ogg','flac',
          // Video (max duration 90m enforced server-side)
          'mp4','mpeg','mov','avi','mpg','webm','mkv'
        ];
        const $file = $('#training_file');
        const $btn = $('#fluxa-upload-btn');
        const $status = $('#fluxa-upload-status');
        const $err = $('#fluxa-file-error');

        function showErr(msg){ $err.show().find('p').text(msg); }
        function clearErr(){ $err.hide().find('p').text(''); }

        $file.on('change', function(){
            clearErr();
            const f = this.files && this.files[0];
            if (!f) return;
            const ext = (f.name.split('.').pop() || '').toLowerCase();
            if (ALLOWED_EXT.indexOf(ext) === -1){
              showErr('<?php echo esc_js(__('Invalid file type. Supported: documents, spreadsheets, presentations, text, data files, e-books, images, audio, and video (see docs).', 'fluxa-ecommerce-assistant')); ?>');
              this.value = '';
              return;
            }
            if (f.size > MAX_BYTES){
              showErr('<?php echo esc_js(__('File is too large. Maximum allowed size is 25 MB.', 'fluxa-ecommerce-assistant')); ?>');
              this.value = '';
              return;
            }
          });

        $('#fluxa-file-upload-form').on('submit', function(){
          clearErr();
          $btn.prop('disabled', true);
          $status.text('<?php echo esc_js(__('Uploading...', 'fluxa-ecommerce-assistant')); ?>').css('color','#2563eb').show();
        });

        // KB list: Raw JSON toggle
        const $kbJson = $('#fluxa-kb-json');
        const $toggle = $('#fluxa-toggle-json');
        function updateKbToggleLabel(){
          $toggle.text($kbJson.is(':visible') ? '<?php echo esc_js(__('Hide raw JSON', 'fluxa-ecommerce-assistant')); ?>' : '<?php echo esc_js(__('Show raw JSON', 'fluxa-ecommerce-assistant')); ?>');
        }
        // Initialize label based on current visibility
        updateKbToggleLabel();
        // Toggle handler
      
        $($toggle).on('click', function(e){
          e.preventDefault();
          $kbJson.toggle();
          updateKbToggleLabel();
          return false;
        });
      
      });
    </script>
  <?php // $method and $base_url defined above ?>

  <?php if ($method === ''): ?>
    <style>
      .fluxa-method-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
      @media(max-width: 900px){.fluxa-method-grid{grid-template-columns:1fr}}
    </style>
    </br>
    <div class="fluxa-card">
      <div class="fluxa-card__header">
        <h3 class="fluxa-card__title"><span class="dashicons dashicons-welcome-learn-more"></span><?php esc_html_e('How to train your chatbot', 'fluxa-ecommerce-assistant'); ?></h3>
      </div>
      <div class="fluxa-card__body">
        <p class="description" style="margin-top:0;">
          <?php esc_html_e('Choose one or more methods below to provide knowledge to your chatbot.', 'fluxa-ecommerce-assistant'); ?>
        </p>
        <br />
        <div class="fluxa-method-grid">
          <div class="fluxa-card">
            <div class="fluxa-card__body">
              <h4 style="margin-top:0;">&nbsp;<?php esc_html_e('Training Text', 'fluxa-ecommerce-assistant'); ?></h4>
              <p class="description">&nbsp;<?php esc_html_e('Paste short text snippets or notes. Great for quick additions.', 'fluxa-ecommerce-assistant'); ?></p>
              <a class="button" href="<?php echo esc_url(add_query_arg('method','text',$base_url)); ?>"><?php esc_html_e('Add Text', 'fluxa-ecommerce-assistant'); ?></a>
            </div>
          </div>
          <div class="fluxa-card">
            <div class="fluxa-card__body">
              <h4 style="margin-top:0;"><?php esc_html_e('Upload Files', 'fluxa-ecommerce-assistant'); ?></h4>
              <p class="description"><?php esc_html_e('Upload PDFs, DOCX, TXT, or audio/video for transcription.', 'fluxa-ecommerce-assistant'); ?></p>
              <a class="button" href="<?php echo esc_url(add_query_arg('method','file',$base_url)); ?>"><?php esc_html_e('Upload Files', 'fluxa-ecommerce-assistant'); ?></a>
            </div>
          </div>
          <div class="fluxa-card">
            <div class="fluxa-card__body">
              <h4 style="margin-top:0;">&nbsp;<?php esc_html_e('Links', 'fluxa-ecommerce-assistant'); ?></h4>
              <p class="description">&nbsp;<?php esc_html_e('Add a URL. We fetch and convert supported pages/media into knowledge.', 'fluxa-ecommerce-assistant'); ?></p>
              <a class="button" href="<?php echo esc_url(add_query_arg('method','url',$base_url)); ?>"><?php esc_html_e('Add URL', 'fluxa-ecommerce-assistant'); ?></a>
            </div>
          </div>
          <div class="fluxa-card">
            <div class="fluxa-card__body">
              <h4 style="margin-top:0;">&nbsp;<?php esc_html_e('YouTube Video', 'fluxa-ecommerce-assistant'); ?></h4>
              <p class="description">&nbsp;<?php esc_html_e('Add a YouTube link. We will fetch and convert the video/transcript into knowledge.', 'fluxa-ecommerce-assistant'); ?></p>
              <a class="button" href="<?php echo esc_url(add_query_arg('method','youtube',$base_url)); ?>"><?php esc_html_e('Add YouTube Video', 'fluxa-ecommerce-assistant'); ?></a>
            </div>
          </div>
          <div class="fluxa-card">
            <div class="fluxa-card__body">
              <h4 style="margin-top:0;"><?php esc_html_e('Q&A', 'fluxa-ecommerce-assistant'); ?></h4>
              <p class="description"><?php esc_html_e('Add specific questions and answers to guide responses.', 'fluxa-ecommerce-assistant'); ?></p>
              <a class="button" href="<?php echo esc_url(add_query_arg('method','qa',$base_url)); ?>"><?php esc_html_e('Open Q&A', 'fluxa-ecommerce-assistant'); ?></a>
            </div>
          </div>
          <div class="fluxa-card">
            <div class="fluxa-card__body">
              <h4 style="margin-top:0;"><?php esc_html_e('Import Content', 'fluxa-ecommerce-assistant'); ?></h4>
              <p class="description"><?php esc_html_e('Select and import Posts, Pages, Products, and Menus to text.', 'fluxa-ecommerce-assistant'); ?></p>
              <a class="button disabled" aria-disabled="true"><?php esc_html_e('Coming soon', 'fluxa-ecommerce-assistant'); ?></a>
            </div>
          </div>
          <div class="fluxa-card">
            <div class="fluxa-card__body">
              <h4 style="margin-top:0;"><?php esc_html_e('Auto Imports', 'fluxa-ecommerce-assistant'); ?></h4>
              <p class="description"><?php esc_html_e('Create rules to import content automatically on a schedule.', 'fluxa-ecommerce-assistant'); ?></p>
              <a class="button disabled" aria-disabled="true"><?php esc_html_e('Coming soon', 'fluxa-ecommerce-assistant'); ?></a>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($method !== ''): ?>
    <?php
      $tabs = array(
        'text' => __('Add Text', 'fluxa-ecommerce-assistant'),
        'file' => __('Upload File', 'fluxa-ecommerce-assistant'),
        'qa'   => __('Add Q&A', 'fluxa-ecommerce-assistant'),
        'url'  => __('Add URL', 'fluxa-ecommerce-assistant'),
        'youtube' => __('Add YouTube Video', 'fluxa-ecommerce-assistant'),
        'products' => __('Add Products', 'fluxa-ecommerce-assistant'),
      );
    ?>
    <h2 class="nav-tab-wrapper" style="margin-top:10px;">
      <?php foreach ($tabs as $key => $label): ?>
        <a class="nav-tab <?php echo ($method === $key) ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('method', $key, $base_url)); ?>"><?php echo esc_html($label); ?></a>
      <?php endforeach; ?>
    </h2>
  <?php endif; ?>

  </br>

  <?php if ( false && !is_null($api_response)) : ?>
    <div class="fluxa-card">
      <h2><?php esc_html_e('Last API Response', 'fluxa-ecommerce-assistant'); ?></h2>
      <pre style="padding:12px;background:#111;color:#eee;border-radius:6px;overflow:auto;max-height:480px;">
<?php echo esc_html( wp_json_encode($api_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ); ?>
      </pre>
    </div>
  <?php endif; ?>
  </br>
  <?php if ($method === 'qa'): ?>
  <div class="fluxa-card">
    <div class="fluxa-card__body fluxa-form">
      <h2 class="fluxa-method-header"><span class="dashicons dashicons-editor-help"></span><?php esc_html_e('Q&A', 'fluxa-ecommerce-assistant'); ?></h2>
      <p class="fluxa-help"><?php esc_html_e('Add specific questions and answers to guide your bot. We will save them as formatted text.', 'fluxa-ecommerce-assistant'); ?></p>
      <form method="post">
        <?php wp_nonce_field('fluxa_train_qa', 'fluxa_train_qa_nonce'); ?>
        <table class="form-table" id="fluxa-qa-table">
          <tbody>
            <tr>
              <th scope="row"><label><?php esc_html_e('Question', 'fluxa-ecommerce-assistant'); ?></label></th>
              <td><input type="text" name="qa_q[]" class="regular-text" placeholder="<?php echo esc_attr__('e.g., What is your return policy?', 'fluxa-ecommerce-assistant'); ?>" required></td>
            </tr>
            <tr>
              <th scope="row"><label><?php esc_html_e('Answer', 'fluxa-ecommerce-assistant'); ?></label></th>
              <td><textarea name="qa_a[]" class="large-text" rows="4" placeholder="<?php echo esc_attr__('Provide the answer...', 'fluxa-ecommerce-assistant'); ?>" required></textarea></td>
            </tr>
          </tbody>
        </table>
        <p>
          <button type="button" class="button" id="fluxa-qa-add"><?php esc_html_e('Add another Q&A', 'fluxa-ecommerce-assistant'); ?></button>
        </p>
        <div class="fluxa-actions-row">
          <?php submit_button(__('Save Q&A', 'fluxa-ecommerce-assistant'), 'primary', 'fluxa_train_qa_submit', false); ?>
        </div>
      </form>
    </div>
  </div>
  <script>
    jQuery(function($){
      // Facts-specific renderer: parses labeled sections (Title, Summary, Fact, Context)
      // and QA inline labels (Q:/A:) into clean key/value blocks.
      // kind can be 'qa' to enable Q:/A: section parsing
      window.fluxaRenderFacts = function(input, kind){
        const esc = (s)=> $('<div>').text(String(s||'')).html();
        const txt = String(input||'').replace(/\r\n?/g,'\n');
        const lines = txt.split('\n');
        const sections = [];
        const pushSection = (label, contentArr)=>{
          let raw = contentArr.join('\n');
          // Remove any stray heading markers left inside the section body (including bullet-prefixed)
          raw = raw.replace(/^\s*(?:[\-*•]\s+)?#{1,3}\s+.+$/gm, '');
          raw = raw.trim();
          if (!raw) return;
          const safe = esc(raw)
            .replace(/\n{2,}/g,'</p><p>')
            .replace(/\n/g,'<br>');
          sections.push({ label, html: '<p>'+safe+'</p>' });
        };
        let buf = [];
        let current = '';
        const headingMap = {
          'title':'Title', 'summary':'Summary', 'fact':'Fact', 'context':'Context'
        };
        // Extend map for QA
        if ((kind||'').toLowerCase() === 'qa') {
          headingMap['q'] = 'Question';
          headingMap['question'] = 'Question';
          headingMap['a'] = 'Answer';
          headingMap['answer'] = 'Answer';
        }
        function isHeading(line){
          // Allow optional bullet then heading, e.g., "- # Title", "* # Summary", "• ## Context"
          const cleaned = line.replace(/^\s*[\-*•]\s+(#)/, '$1');
          // Recognize markdown headings
          let m = cleaned.trim().match(/^([#]{1,2})\s*(.+)$/);
          // Recognize QA style labels like "Q: ..." or "A: ..."
          if (!m && (kind||'').toLowerCase() === 'qa') {
            const qa = cleaned.trim().match(/^(q|question|a|answer)\s*:\s*(.*)$/i);
            if (qa) {
              const key = qa[1].toLowerCase();
              const rest = qa[2] || '';
              return { label: headingMap[key] || key, inline: rest };
            }
          }
          if (!m) return null;
          const raw = m[2].trim().toLowerCase();
          const key = raw.replace(/:$/,'');
          if (headingMap[key]) return headingMap[key];
          return null;
        }
        for (let i=0;i<lines.length;i++){
          let ln = lines[i];
          // normalize bullet then heading like "- # Title", "* # Title", or "• # Title"
          ln = ln.replace(/^\s*[\-*•]\s+(#)/,'$1');
          const h = isHeading(ln);
          if (h){
            // flush previous
            if (buf.length){ pushSection(current || 'Note', buf); buf = []; }
            if (typeof h === 'object' && h.label){
              current = h.label;
              // If the label had inline content on same line, seed buffer with it
              if (h.inline) { buf.push(h.inline); }
            } else {
              current = h;
            }
          } else {
            buf.push(ln);
          }
        }
        if (buf.length){ pushSection(current || 'Note', buf); }
        if (!sections.length){
          // Fallback: strip heading hashes and convert to paragraphs
          const normalized = txt
            .replace(/^[\s]*#{1,3}\s+/gm, '') // drop heading markers
            .trim();
          const safe = esc(normalized)
            .replace(/\n{2,}/g,'</p><p>')
            .replace(/\n/g,'<br>');
          return '<p>'+ safe +'</p>';
        }
        // build HTML blocks with labels
        return sections.map(function(s){
          return '<div class="fluxa-kv">'
              +   '<div class="fluxa-kv__label">'+ esc(s.label) +'</div>'
              +   '<div class="fluxa-kv__value">'+ s.html +'</div>'
              + '</div>';
        }).join('');
      }
      $('#fluxa-qa-add').on('click', function(){
        const $tbody = $('#fluxa-qa-table tbody');
        const block = `\
          <tr>\
            <th scope=\"row\"><label><?php echo esc_js(__('Question', 'fluxa-ecommerce-assistant')); ?></label></th>\
            <td><input type=\"text\" name=\"qa_q[]\" class=\"regular-text\" placeholder=\"<?php echo esc_js(__('e.g., What are your shipping times?', 'fluxa-ecommerce-assistant')); ?>\" required></td>\
          </tr>\
          <tr>\
            <th scope=\"row\"><label><?php echo esc_js(__('Answer', 'fluxa-ecommerce-assistant')); ?></label></th>\
            <td><textarea name=\"qa_a[]\" class=\"large-text\" rows=\"4\" placeholder=\"<?php echo esc_js(__('Provide the answer...', 'fluxa-ecommerce-assistant')); ?>\" required></textarea></td>\
          </tr>`;
        $tbody.append(block);
      });
    });
  </script>
  <?php endif; ?>

  <?php if ($method === 'products'): ?>
  <div class="fluxa-card">
    <div class="fluxa-card__body fluxa-form">
      <h2 class="fluxa-method-header"><span class="dashicons dashicons-products"></span><?php esc_html_e('Add Products', 'fluxa-ecommerce-assistant'); ?></h2>
      <p class="fluxa-help"><?php esc_html_e('Build a product training payload by filtering and choosing which fields to include. This is frontend-only for preview; it will not send data yet.', 'fluxa-ecommerce-assistant'); ?></p>

      <form id="fluxa-products-form" onsubmit="return false;">
        <table class="form-table">
          <tr>
            <th scope="row"><?php esc_html_e('Filter Type', 'fluxa-ecommerce-assistant'); ?></th>
            <td>
              <fieldset>
                <label><input type="radio" name="prd_filter_type" value="all" checked> <?php esc_html_e('All Products', 'fluxa-ecommerce-assistant'); ?></label>&nbsp;&nbsp;
                <label><input type="radio" name="prd_filter_type" value="categories"> <?php esc_html_e('Categories', 'fluxa-ecommerce-assistant'); ?></label>&nbsp;&nbsp;
                <label><input type="radio" name="prd_filter_type" value="tags"> <?php esc_html_e('Tags', 'fluxa-ecommerce-assistant'); ?></label>&nbsp;&nbsp;
                <label><input type="radio" name="prd_filter_type" value="ids"> <?php esc_html_e('Product IDs', 'fluxa-ecommerce-assistant'); ?></label>
              </fieldset>
              <p class="description"><?php esc_html_e('Choose how you want to select products.', 'fluxa-ecommerce-assistant'); ?></p>
            </td>
          </tr>
          <tr data-prd-field="categories">
            <th scope="row"><label for="prd_categories"><?php esc_html_e('Categories', 'fluxa-ecommerce-assistant'); ?></label></th>
            <td>
              <input type="text" id="prd_categories" list="prd_cat_suggest" class="regular-text" placeholder="<?php echo esc_attr__('type to search categories…', 'fluxa-ecommerce-assistant'); ?>">
              <datalist id="prd_cat_suggest"></datalist>
              <div id="prd_cat_chips" aria-live="polite" style="margin-top:6px;"></div>
              <p class="description"><?php esc_html_e('Start typing to autocomplete categories. Use commas for multiple.', 'fluxa-ecommerce-assistant'); ?></p>
            </td>
          </tr>
          <tr data-prd-field="tags" style="display:none;">
            <th scope="row"><label for="prd_tags"><?php esc_html_e('Tags', 'fluxa-ecommerce-assistant'); ?></label></th>
            <td>
              <input type="text" id="prd_tags" list="prd_tag_suggest" class="regular-text" placeholder="<?php echo esc_attr__('type to search tags…', 'fluxa-ecommerce-assistant'); ?>">
              <datalist id="prd_tag_suggest"></datalist>
              <div id="prd_tag_chips" aria-live="polite" style="margin-top:6px;"></div>
              <p class="description"><?php esc_html_e('Start typing to autocomplete tags. Use commas for multiple.', 'fluxa-ecommerce-assistant'); ?></p>
            </td>
          </tr>
          <tr data-prd-field="ids" style="display:none;">
            <th scope="row"><label for="prd_ids"><?php esc_html_e('Product IDs', 'fluxa-ecommerce-assistant'); ?></label></th>
            <td>
              <textarea id="prd_ids" rows="3" class="large-text" placeholder="<?php echo esc_attr__('Enter product IDs separated by commas or new lines', 'fluxa-ecommerce-assistant'); ?>"></textarea>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('Filters', 'fluxa-ecommerce-assistant'); ?></th>
            <td>
              <label><input type="checkbox" id="prd_instock" checked> <?php esc_html_e('In stock only', 'fluxa-ecommerce-assistant'); ?></label>&nbsp;&nbsp;
              <label><input type="checkbox" id="prd_onsale"> <?php esc_html_e('On sale only', 'fluxa-ecommerce-assistant'); ?></label>
              <div style="margin-top:28px;">
                <label><?php esc_html_e('Price min', 'fluxa-ecommerce-assistant'); ?> <input type="number" step="0.01" id="prd_price_min" style="width:120px;"> </label>&nbsp;&nbsp;
                <label><?php esc_html_e('Price max', 'fluxa-ecommerce-assistant'); ?> <input type="number" step="0.01" id="prd_price_max" style="width:120px;"> </label>
              </div>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('Limit', 'fluxa-ecommerce-assistant'); ?></th>
            <td>
              <input type="number" id="prd_limit" value="20" min="1" max="500" style="width:120px;"> &nbsp;
              <input type="number" id="prd_offset" value="0" min="0" style="width:120px;"> 
              <p class="description"><?php esc_html_e('First is count (max items), second is offset (start from).', 'fluxa-ecommerce-assistant'); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('Product Types', 'fluxa-ecommerce-assistant'); ?></th>
            <td>
              <label><input type="radio" name="prd_type" value="all" checked> <?php esc_html_e('All', 'fluxa-ecommerce-assistant'); ?></label>&nbsp;&nbsp;
              <label><input type="radio" name="prd_type" value="parent"> <?php esc_html_e('Parent', 'fluxa-ecommerce-assistant'); ?></label>&nbsp;&nbsp;
              <label><input type="radio" name="prd_type" value="variation"> <?php esc_html_e('Variation', 'fluxa-ecommerce-assistant'); ?></label>
              <p class="description"><?php esc_html_e('Choose which product types to include in the selection.', 'fluxa-ecommerce-assistant'); ?></p>
            </td>
          </tr>
        </table>
        <div class="fluxa-actions-row">
          <button type="button" class="button button-primary" id="prd-preview-btn"><?php esc_html_e('Preview Selection', 'fluxa-ecommerce-assistant'); ?></button>
          <button type="reset" class="button" id="prd-reset"><?php esc_html_e('Reset', 'fluxa-ecommerce-assistant'); ?></button>
        </div>
      </form>

      <div class="fluxa-card" style="margin-top:12px;">
        <div class="fluxa-card__body">
          <h3 style="margin-top:0;"><?php esc_html_e('Preview (local data)', 'fluxa-ecommerce-assistant'); ?></h3>
          <table class="widefat fixed striped" id="prd-preview-table" style="display:none;">
            <thead>
              <tr id="prd-preview-head-row">
                <th><?php esc_html_e('Title', 'fluxa-ecommerce-assistant'); ?></th>
                <th><?php esc_html_e('SKU', 'fluxa-ecommerce-assistant'); ?></th>
                <th><?php esc_html_e('Price', 'fluxa-ecommerce-assistant'); ?></th>
                <th><?php esc_html_e('Sale Price', 'fluxa-ecommerce-assistant'); ?></th>
                <th><?php esc_html_e('Categories', 'fluxa-ecommerce-assistant'); ?></th>
                <th><?php esc_html_e('Status', 'fluxa-ecommerce-assistant'); ?></th>
                <th><?php esc_html_e('Description', 'fluxa-ecommerce-assistant'); ?></th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
          <p class="description" id="prd-preview-empty"><?php esc_html_e('Click Preview Selection to see product data based on your filters.', 'fluxa-ecommerce-assistant'); ?></p>
        </div>
      </div>

      <div class="fluxa-card" style="margin-top:12px;">
        <div class="fluxa-card__body">
          <h3 style="margin-top:0;"><?php esc_html_e('JSON Payload (to be sent to API)', 'fluxa-ecommerce-assistant'); ?></h3>
          <pre class="fluxa-pre" id="prd-payload" style="display:none;"></pre>
          <p class="description"><?php esc_html_e('This is a generated preview of the payload. No request will be sent in this step.', 'fluxa-ecommerce-assistant'); ?></p>
        </div>
      </div>
    </div>
  </div>

  <script>
  jQuery(function($){
    // Ensure decodeEntities is available in this scope
    const decodeEntities = (function(){
      if (typeof window.decodeEntities === 'function') { return window.decodeEntities; }
      return function(s){
        if (s === null || s === undefined) return '';
        try { const ta = document.createElement('textarea'); ta.innerHTML = String(s); return ta.value; } catch(e){ return String(s); }
      };
    })();
    // Toggle filter inputs by type
    $(document).on('change', 'input[name=prd_filter_type]', function(){
      const val = $('input[name=prd_filter_type]:checked').val();
      $('[data-prd-field]').hide();
      $('[data-prd-field='+ val +']').show();
    });
    // Ensure correct initial visibility on load
    (function(){
      const val = $('input[name=prd_filter_type]:checked').val();
      $('[data-prd-field]').hide();
      if (val && $('[data-prd-field='+ val +']').length){ $('[data-prd-field='+ val +']').show(); }
    })();

    // Hold last previewed products and terms
    let lastProducts = [];
    let lastTerms = { categories: [], tags: [] };
    // Selected chips (arrays of objects {slug,name})
    let selectedCats = [];
    let selectedTags = [];

    // Chip rendering helpers
    function renderChips($wrap, items, type){
      $wrap.empty();
      items.forEach(function(it){
        const $chip = $('<span class="fluxa-chip">')
          .append($('<span class="fluxa-chip__label">').text(it.name || it.slug))
          .append($('<button type="button" class="fluxa-chip__rm" aria-label="Remove">×</button>').data('slug', it.slug).data('type', type));
        $wrap.append($chip);
      });
    }
    // Basic styles for chips (scoped)
    const chipCss = '\n.fluxa-chip{display:inline-flex;align-items:center;background:#eef2ff;color:#1f2937;border:1px solid #c7d2fe;border-radius:14px;padding:2px 8px;margin:4px 6px 0 0;font-size:12px}\n.fluxa-chip__rm{background:transparent;border:0;color:#374151;margin-left:6px;cursor:pointer;line-height:1;font-weight:600}\n.fluxa-chip__rm:hover{color:#111827}';
    $('<style>').text(chipCss).appendTo(document.head);

    // Manage chip add/remove
    $(document).on('click', '.fluxa-chip__rm', function(){
      const slug = $(this).data('slug');
      const type = $(this).data('type');
      if (type === 'cat') { selectedCats = selectedCats.filter(s=>s.slug!==slug); renderChips($('#prd_cat_chips'), selectedCats, 'cat'); }
      if (type === 'tag') { selectedTags = selectedTags.filter(s=>s.slug!==slug); renderChips($('#prd_tag_chips'), selectedTags, 'tag'); }
    });

    // --- Autocomplete for Categories / Tags via admin-ajax ---
    function debounce(fn, t){ let h; return function(){ clearTimeout(h); const args = arguments; const ctx = this; h = setTimeout(function(){ fn.apply(ctx,args); }, t||180); }; }
    let lastCatOptions = [];
    let lastTagOptions = [];
    function fillDatalist($dl, items, type){
      $dl.empty();
      (items||[]).forEach(function(it){
        const opt = $('<option>').attr('value', it.slug).text(it.name);
        $dl.append(opt);
      });
      if (type === 'cat') lastCatOptions = items || []; else if (type === 'tag') lastTagOptions = items || [];
    }
    $('#prd_categories').on('input', debounce(function(){
      const raw = String($(this).val()||'');
      const part = raw.split(',').pop().trim();
      if (part.length < 2) return;
      $.get(ajaxurl, { action:'fluxa_term_suggest', tax:'product_cat', q: part })
        .done(function(resp){ if (resp && resp.success){ fillDatalist($('#prd_cat_suggest'), resp.data.items||[], 'cat'); } });
    }, 220));
    // Instant add to chips when datalist option is selected (no Enter needed)
    $('#prd_categories').on('input', function(){
      const raw = String($(this).val()||'');
      const token = raw.split(',').pop().trim();
      if (!token) return;
      if ((lastCatOptions||[]).some(o => o.slug === token)) {
        addChipFromInput($(this), lastCatOptions, selectedCats, $('#prd_cat_chips'), 'cat');
      }
    });
    $('#prd_tags').on('input', debounce(function(){
      const raw = String($(this).val()||'');
      const part = raw.split(',').pop().trim();
      if (part.length < 2) return;
      $.get(ajaxurl, { action:'fluxa_term_suggest', tax:'product_tag', q: part })
        .done(function(resp){ if (resp && resp.success){ fillDatalist($('#prd_tag_suggest'), resp.data.items||[], 'tag'); } });
    }, 220));
    // Instant add to chips for tags when a suggestion is picked
    $('#prd_tags').on('input', function(){
      const raw = String($(this).val()||'');
      const token = raw.split(',').pop().trim();
      if (!token) return;
      if ((lastTagOptions||[]).some(o => o.slug === token)) {
        addChipFromInput($(this), lastTagOptions, selectedTags, $('#prd_tag_chips'), 'tag');
      }
    });

    // Convert datalist selection or comma/enter into chips
    function addChipFromInput($input, options, selArr, $chipsWrap, type){
      let raw = String($input.val()||'');
      let token = raw.split(',').pop().trim();
      if (!token) return;
      // Find by slug from last options; otherwise fallback to token as name
      const found = (options||[]).find(o=>o.slug===token);
      const it = found ? { slug: found.slug, name: found.name } : { slug: token, name: token };
      if (!selArr.some(s=>s.slug===it.slug)) { selArr.push(it); }
      // Clean last token from input
      const parts = raw.split(','); parts.pop(); raw = parts.join(',');
      $input.val(raw ? (raw.endsWith(',') ? raw : (raw + ',')) : '');
      // Render
      renderChips($chipsWrap, selArr, type);
      // Clear suggestions so they don't persist visually
      if (type === 'cat') { $('#prd_cat_suggest').empty(); lastCatOptions = []; }
      if (type === 'tag') { $('#prd_tag_suggest').empty(); lastTagOptions = []; }
    }
    $('#prd_categories').on('keydown', function(e){ if (e.key==='Enter' || e.key===','){ e.preventDefault(); addChipFromInput($(this), lastCatOptions, selectedCats, $('#prd_cat_chips'), 'cat'); } });
    $('#prd_tags').on('keydown', function(e){ if (e.key==='Enter' || e.key===','){ e.preventDefault(); addChipFromInput($(this), lastTagOptions, selectedTags, $('#prd_tag_chips'), 'tag'); } });
    // When input loses focus and has a token, add it as chip
    $('#prd_categories').on('blur', function(){ addChipFromInput($(this), lastCatOptions, selectedCats, $('#prd_cat_chips'), 'cat'); });
    $('#prd_tags').on('blur', function(){ addChipFromInput($(this), lastTagOptions, selectedTags, $('#prd_tag_chips'), 'tag'); });
    // On focus, reset suggestion list so it doesn't show stale items until user types
    $('#prd_categories').on('focus', function(){ $('#prd_cat_suggest').empty(); lastCatOptions = []; });
    $('#prd_tags').on('focus', function(){ $('#prd_tag_suggest').empty(); lastTagOptions = []; });

    function sampleProducts(n){
      n = Math.max(1, Math.min(n||5, 10));
      const cats = ($('#prd_categories').val()||'').split(',').map(s=>s.trim()).filter(Boolean);
      const onSale = $('#prd_onsale').is(':checked');
      const inStock = $('#prd_instock').is(':checked');
      const arr = [];
      for (let i=0;i<n;i++){
        arr.push({
          title: 'Sample Product ' + (i+1),
          sku: 'SKU-' + (1000+i),
          price: (onSale ? (49.99-i) : (59.99+i)).toFixed(2),
          categories: cats.length ? cats.join(', ') : 'category-a, category-b',
          status: inStock ? 'in-stock' : 'backorder'
        });
      }
      return arr;
    }

    function buildPayload(){
      const filterType = $('input[name=prd_filter_type]:checked').val();
      const filters = {
        type: filterType,
        categories: $('#prd_categories').val()||'',
        tags: $('#prd_tags').val()||'',
        ids: $('#prd_ids').val()||'',
        in_stock: $('#prd_instock').is(':checked'),
        on_sale: $('#prd_onsale').is(':checked'),
        price_min: parseFloat($('#prd_price_min').val()||'') || null,
        price_max: parseFloat($('#prd_price_max').val()||'') || null,
        limit: parseInt($('#prd_limit').val()||'20',10),
        offset: parseInt($('#prd_offset').val()||'0',10)
      };
      const fields = $('.prd_field:checked').map(function(){ return this.value; }).get();
      const strategy = $('input[name=prd_strategy]:checked').val();
      const descLen = parseInt($('#prd_desc_len').val()||'300',10);
      const payload = {
        mode: 'products',
        strategy: strategy,
        filters: filters,
        include_fields: fields,
        options: { max_description_length: descLen }
      };
      return payload;
    }

    function buildAndShowPayload(){
      const selType = $('input[name=prd_filter_type]:checked').val();
      const productType = $('input[name=prd_type]:checked').val();
      const base = {
        mode: 'products',
        selection: selType,
        product_type: productType,
        filters: {
          categories: selectedCats.map(s=>s.slug),
          tags: selectedTags.map(s=>s.slug),
          ids: $('#prd_ids').val()||'',
          in_stock: $('#prd_instock').is(':checked'),
          on_sale: $('#prd_onsale').is(':checked'),
          price_min: parseFloat($('#prd_price_min').val()||'') || null,
          price_max: parseFloat($('#prd_price_max').val()||'') || null,
          limit: parseInt($('#prd_limit').val()||'20',10),
          offset: parseInt($('#prd_offset').val()||'0',10)
        }
      };
      function mapProduct(p){
        return {
          id: p.id,
          title: p.title,
          sku: p.sku,
          price: p.price,
          stock: { status: p.stock_status, quantity: p.stock_quantity },
          categories: p.categories||[],
          tags: p.tags||[],
          permalink: p.permalink,
          attributes: p.attributes||{},
          description: p.description||''
        };
      }
      let payload = {};
      if (selType === 'all' || selType === 'ids') {
        payload = Object.assign({}, base, { products: (lastProducts||[]).map(mapProduct) });
      } else if (selType === 'categories') {
        const groups = (lastTerms.categories||[]).map(function(t){
          const prods = (lastProducts||[]).filter(function(p){ return (p.categories||[]).includes(t.name); }).map(mapProduct);
          return { category: { id: t.id, slug: t.slug, name: t.name, description: t.description }, products: prods };
        });
        payload = Object.assign({}, base, { groups });
      } else if (selType === 'tags') {
        const groups = (lastTerms.tags||[]).map(function(t){
          const prods = (lastProducts||[]).filter(function(p){ return (p.tags||[]).includes(t.name); }).map(mapProduct);
          return { tag: { id: t.id, slug: t.slug, name: t.name, description: t.description }, products: prods };
        });
        payload = Object.assign({}, base, { groups });
      }
      $('#prd-payload').text(JSON.stringify(payload, null, 2)).show();
    }

    $('#prd-preview-btn').on('click', function(){
      const $tb = $('#prd-preview-table');
      const $tbody = $tb.find('tbody').empty();
      const filters = {
        type: $('input[name=prd_filter_type]:checked').val(),
        categories: selectedCats.map(s=>s.slug).join(','),
        tags: selectedTags.map(s=>s.slug).join(','),
        ids: $('#prd_ids').val()||'',
        in_stock: $('#prd_instock').is(':checked') ? 1 : 0,
        on_sale: $('#prd_onsale').is(':checked') ? 1 : 0,
        price_min: $('#prd_price_min').val(),
        price_max: $('#prd_price_max').val(),
        limit: parseInt($('#prd_limit').val()||'20',10),
        offset: parseInt($('#prd_offset').val()||'0',10),
        product_type: $('input[name=prd_type]:checked').val()
      };
      $tbody.append('<tr><td colspan="5">Loading…</td></tr>');
      $.post(ajaxurl, Object.assign({ action: 'fluxa_products_preview', _ajax_nonce: '<?php echo wp_create_nonce('fluxa_products_preview'); ?>' }, filters))
        .done(function(resp){
          $tbody.empty();
          if (resp && resp.success && Array.isArray(resp.data.items)){
            lastProducts = resp.data.items;
            lastTerms = resp.data.terms || { categories: [], tags: [] };
            if (lastProducts.length === 0){
              $('#prd-preview-empty').text('<?php echo esc_js(__('No products match your filters.', 'fluxa-ecommerce-assistant')); ?>').show();
              $tb.hide();
              $('#prd-payload').hide().text('');
              return;
            }
            // Build dynamic attribute columns
            const attrSet = new Set();
            lastProducts.forEach(function(p){
              if (p.attributes){
                Object.keys(p.attributes).forEach(function(k){
                  if (String(k).toLowerCase() !== 'pattern') { attrSet.add(k); }
                });
              }
            });
            const attrCols = Array.from(attrSet);
            const $headRow = $('#prd-preview-head-row').empty();
            $headRow.append('<th><?php echo esc_js(__('Title', 'fluxa-ecommerce-assistant')); ?></th>');
            $headRow.append('<th><?php echo esc_js(__('SKU', 'fluxa-ecommerce-assistant')); ?></th>');
            $headRow.append('<th><?php echo esc_js(__('Price', 'fluxa-ecommerce-assistant')); ?></th>');
            $headRow.append('<th><?php echo esc_js(__('Sale Price', 'fluxa-ecommerce-assistant')); ?></th>');
            $headRow.append('<th><?php echo esc_js(__('Categories', 'fluxa-ecommerce-assistant')); ?></th>');
            attrCols.forEach(function(col){ $headRow.append($('<th>').text(col)); });
            $headRow.append('<th><?php echo esc_js(__('Status', 'fluxa-ecommerce-assistant')); ?></th>');
            $headRow.append('<th><?php echo esc_js(__('Description', 'fluxa-ecommerce-assistant')); ?></th>');
            lastProducts.slice(0, Math.min(filters.limit, 50)).forEach(function(p){
              const tr = $('<tr>');
              const cur = (p.currency_symbol && String(p.currency_symbol).trim()) ? p.currency_symbol : (p.currency_code || '');
              const priceTxt = (p.price!==undefined && p.price!==null && p.price!=='') ? (String(cur) + String(p.price)) : '';
              const saleTxt  = (p.sale_price) ? (String(cur) + String(p.sale_price)) : '';
              // Description with limit
              const descRaw = (p.description || p.short_description || '')
                .toString()
                .replace(/\s+/g,' ')
                .trim()
                .slice(0, 180);
              const desc = decodeEntities(descRaw);
              tr.append($('<td>').text(decodeEntities(p.title||'')));
              tr.append($('<td>').text(decodeEntities(p.sku||'')));
              tr.append($('<td>').text(priceTxt));
              tr.append($('<td>').text(saleTxt));
              tr.append($('<td>').text(decodeEntities((p.categories||[]).join(', '))));
              // Insert dynamic attribute cells in the same order as headers
              attrCols.forEach(function(col){
                const val = (p.attributes && p.attributes[col]) ? p.attributes[col] : '';
                tr.append($('<td>').text(decodeEntities(val)));
              });
              tr.append($('<td>').text(decodeEntities(p.stock_status||'')));
              tr.append($('<td>').text(desc));
              $tbody.append(tr);
            });
            $('#prd-preview-empty').hide();
            $tb.show();
            // Build and show payload once preview is ready
            buildAndShowPayload();
          } else {
            $('#prd-preview-empty').text('<?php echo esc_js(__('Failed to load preview.', 'fluxa-ecommerce-assistant')); ?>').show();
            $tb.hide();
            $('#prd-payload').hide().text('');
          }
        })
        .fail(function(){
          $tbody.empty();
          $('#prd-preview-empty').text('<?php echo esc_js(__('Network error while loading preview.', 'fluxa-ecommerce-assistant')); ?>').show();
          $tb.hide();
          $('#prd-payload').hide().text('');
        });
    });

    // After a successful preview render, build and show payload

    $('#prd-reset').on('click', function(){
      setTimeout(function(){
        $('[data-prd-field]').hide();
        $('[data-prd-field=categories]').show();
        $('#prd-preview-table').hide();
        $('#prd-preview-empty').show();
        $('#prd-payload').hide().text('');
      }, 0);
    });
  });
  </script>
  <?php endif; ?>

  <?php if ($method === 'text'): ?>
  <div class="fluxa-card">
    <div class="fluxa-card__body fluxa-form">
      <h2 class="fluxa-method-header"><span class="dashicons dashicons-text"></span><?php esc_html_e('Training Text', 'fluxa-ecommerce-assistant'); ?></h2>
      <p class="fluxa-help"><?php esc_html_e('Paste short notes, announcements, or policies to instantly enrich your bot knowledge.', 'fluxa-ecommerce-assistant'); ?></p>
      <form method="post">
      <?php wp_nonce_field('fluxa_train_text', 'fluxa_train_text_nonce'); ?>
      <table class="form-table">
        <tr>
          <th scope="row"><label for="text_title"><?php esc_html_e('Title', 'fluxa-ecommerce-assistant'); ?></label></th>
          <td><input type="text" id="text_title" name="text_title" class="regular-text" required placeholder="<?php echo esc_attr__('Enter a title…', 'fluxa-ecommerce-assistant'); ?>"></td>
        </tr>
        <tr>
          <th scope="row"><label for="text_body"><?php esc_html_e('Text', 'fluxa-ecommerce-assistant'); ?></label></th>
          <td><textarea id="text_body" name="text_body" rows="8" class="large-text" required placeholder="<?php echo esc_attr__('Paste or type your text...', 'fluxa-ecommerce-assistant'); ?>"></textarea></td>
        </tr>
      </table>
      <div class="fluxa-actions-row">
        <?php submit_button(__('Add Text', 'fluxa-ecommerce-assistant'), 'primary', 'fluxa_train_text_submit', false); ?>
      </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($method === 'url'): ?>
  <div class="fluxa-card">
    <div class="fluxa-card__body fluxa-form">
      <h2 class="fluxa-method-header"><span class="dashicons dashicons-admin-site-alt3"></span><?php esc_html_e('Import from URL', 'fluxa-ecommerce-assistant'); ?></h2>
      <p class="fluxa-help"><?php esc_html_e('We will fetch the page (or supported media) and convert it into knowledge for your bot.', 'fluxa-ecommerce-assistant'); ?></p>
      <form method="post">
      <?php wp_nonce_field('fluxa_train_url', 'fluxa_train_url_nonce'); ?>
      <table class="form-table">
        <tr>
          <th scope="row"><label for="url_title"><?php esc_html_e('Title', 'fluxa-ecommerce-assistant'); ?></label></th>
          <td><input type="text" id="url_title" name="url_title" class="regular-text" required placeholder="<?php echo esc_attr__('Enter a title…', 'fluxa-ecommerce-assistant'); ?>"></td>
        </tr>
        <tr>
          <th scope="row"><label for="train_url"><?php esc_html_e('URL', 'fluxa-ecommerce-assistant'); ?></label></th>
          <td><input type="url" id="train_url" name="train_url" class="regular-text" required placeholder="https://example.com"></td>
        </tr>
      </table>
      <div class="fluxa-actions-row">
        <?php submit_button(__('Add Link', 'fluxa-ecommerce-assistant'), 'primary', 'fluxa_train_url_submit', false); ?>
      </div>
    </form>
    </div>
  </div>

  <?php endif; ?>

  <?php if ($method === 'youtube'): ?>
  <div class="fluxa-card">
    <div class="fluxa-card__body fluxa-form">
      <h2 class="fluxa-method-header"><span class="dashicons dashicons-video-alt3"></span><?php esc_html_e('Add YouTube Video', 'fluxa-ecommerce-assistant'); ?></h2>
      <p class="fluxa-help"><?php esc_html_e('Paste a YouTube URL or just the Video ID. We will fetch and convert the video/transcript into knowledge for your bot.', 'fluxa-ecommerce-assistant'); ?></p>
      <form method="post">
      <?php wp_nonce_field('fluxa_train_youtube', 'fluxa_train_youtube_nonce'); ?>
      <table class="form-table">
        <tr>
          <th scope="row"><label for="url_title"><?php esc_html_e('Title', 'fluxa-ecommerce-assistant'); ?></label></th>
          <td><input type="text" id="url_title" name="url_title" class="regular-text" required placeholder="<?php echo esc_attr__('Enter a title…', 'fluxa-ecommerce-assistant'); ?>"></td>
        </tr>
        <tr>
          <th scope="row"><label for="train_url"><?php esc_html_e('YouTube URL or Video ID', 'fluxa-ecommerce-assistant'); ?></label></th>
          <td>
            <input type="text" id="train_url" name="train_url" class="regular-text" required placeholder="">
            <em id="yt-id-note" class="fluxa-subtle" style="display:none;"></em>
          </td>
        </tr>
      </table>
      <div class="fluxa-yt-preview" id="fluxa-yt-preview" style="display:none;">
        <div class="fluxa-yt-thumb">
          <img id="yt-thumb" alt="YouTube thumbnail" />
        </div>
        <div class="fluxa-yt-meta">
          <div class="fluxa-yt-title" id="yt-title">&nbsp;</div>
          <div class="fluxa-yt-url" id="yt-url"></div>
        </div>
      </div>
      <div class="fluxa-actions-row">
        <?php submit_button(__('Add YouTube Video', 'fluxa-ecommerce-assistant'), 'primary', 'fluxa_train_youtube_submit', false); ?>
      </div>
    </form>
    </div>
  </div>

  <?php endif; ?>

  <?php if ($method === 'youtube'): ?>
  <script>
    jQuery(function($){
      const $input = $('#train_url');
      const $note = $('#yt-id-note');
      const $preview = $('#fluxa-yt-preview');
      const $thumb = $('#yt-thumb');
      const $title = $('#yt-title');
      const $urlOut = $('#yt-url');

      function maybeConvert(val){
        const v = (val||'').trim();
        if (/^[A-Za-z0-9_-]{10,}$/.test(v)){
          const url = 'https://www.youtube.com/watch?v=' + v;
          $input.val(url);
          $note.text('<?php echo esc_js(__('Detected YouTube ID. Converted to URL.', 'fluxa-ecommerce-assistant')); ?>').show();
        } else {
          $note.hide().text('');
        }
      }

      function parseYouTubeId(val){
        const s = (val||'').trim();
        if (!s) return '';
        // If looks like ID
        if (/^[A-Za-z0-9_-]{10,}$/.test(s)) return s;
        // Try parse as URL
        try {
          const u = new URL(s);
          if (u.hostname.includes('youtu.be')) {
            return u.pathname.replace(/^\//,'');
          }
          if (u.hostname.includes('youtube.com')) {
            const v = u.searchParams.get('v');
            if (v) return v;
            // Shorts format
            const m = u.pathname.match(/\/shorts\/([A-Za-z0-9_-]{10,})/);
            if (m) return m[1];
          }
        } catch(e) {/* not a URL */}
        return '';
      }

      function updatePreview(val){
        const id = parseYouTubeId(val);
        if (!id){ $preview.hide(); return; }
        const url = 'https://www.youtube.com/watch?v=' + id;
        const thumbUrl = 'https://img.youtube.com/vi/' + id + '/hqdefault.jpg';
        $thumb.attr('src', thumbUrl);
        $urlOut.text(url);
        $preview.show();
        // Fetch title via oEmbed
        const oembed = 'https://www.youtube.com/oembed?format=json&url=' + encodeURIComponent(url);
        $.getJSON(oembed).done(function(data){
          if (data && data.title){ $title.text(data.title); }
        }).fail(function(){ $title.text(''); });
      }

      // Live preview as they type/paste
      $input.on('input', function(){ updatePreview($(this).val()); });
      // Convert simple ID on blur for consistency
      $input.on('blur', function(){ maybeConvert($(this).val()); updatePreview($(this).val()); });

      // Initial check if field has value (e.g., after back navigation)
      if ($input.val()){ updatePreview($input.val()); }
    });
  </script>
  <?php endif; ?>

  <?php if ($method === 'file'): ?>
  <div class="fluxa-card">
    <div class="fluxa-card__body fluxa-form">
      <h2 class="fluxa-method-header"><span class="dashicons dashicons-media-document"></span><?php esc_html_e('Upload Files', 'fluxa-ecommerce-assistant'); ?></h2>
      <p class="fluxa-help"><?php esc_html_e('Upload documents, spreadsheets, presentations, text, data files, e-books, images, audio, or video. Max size 25MB.', 'fluxa-ecommerce-assistant'); ?></p>
      <p class="fluxa-note"><?php esc_html_e('Tip: Give your file a descriptive title for easier discovery later. Max size 25MB.', 'fluxa-ecommerce-assistant'); ?></p>
      <form id="fluxa-file-upload-form" method="post" enctype="multipart/form-data">
      <?php wp_nonce_field('fluxa_train_file_upload', 'fluxa_train_file_upload_nonce'); ?>
      <table class="form-table">
        <tr>
          <th scope="row"><label for="file_title"><?php esc_html_e('Title', 'fluxa-ecommerce-assistant'); ?></label></th>
          <td><input type="text" id="file_title" name="file_title" class="regular-text" required placeholder="<?php echo esc_attr__('Enter a title…', 'fluxa-ecommerce-assistant'); ?>"></td>
        </tr>
        <tr>
          <th scope="row"><label for="training_file"><?php esc_html_e('Select file', 'fluxa-ecommerce-assistant'); ?></label></th>
          <td>
            <input type="file" id="training_file" name="training_file" class="regular-text" accept=".doc,.docx,.rtf,.pdf,.pdfa,.csv,.tsv,.xls,.xlsx,.xlsm,.xlsb,.ods,.dta,.sas7bdat,.xpt,.ppt,.pptx,.txt,.md,.htm,.html,.css,.js,.xml,.json,.yml,.yaml,.epub,.png,.jpg,.jpeg,.webp,.heic,.heif,.tiff,.bmp,.mp3,.wav,.aac,.ogg,.flac,.mp4,.mpeg,.mov,.avi,.mpg,.webm,.mkv" required>
            <div id="fluxa-file-error" class="notice notice-error" style="display:none;margin-top:8px;"><p></p></div>
            <details style="margin-top:8px;">
              <summary style="cursor:pointer;"><?php esc_html_e('Supported file types (click to expand)', 'fluxa-ecommerce-assistant'); ?></summary>
              <div style="margin-top:8px;">
                <ul style="margin:6px 0 0 18px;">
                  <li><strong><?php esc_html_e('Documents', 'fluxa-ecommerce-assistant'); ?>:</strong> .doc, .docx, .rtf, .pdf, .pdfa</li>
                  <li><strong><?php esc_html_e('Spreadsheets and Tabular Data', 'fluxa-ecommerce-assistant'); ?>:</strong> .csv, .tsv, .xls, .xlsx, .xlsm, .xlsb, .ods, .dta, .sas7bdat, .xpt</li>
                  <li><strong><?php esc_html_e('Presentations', 'fluxa-ecommerce-assistant'); ?>:</strong> .ppt, .pptx</li>
                  <li><strong><?php esc_html_e('Text Files', 'fluxa-ecommerce-assistant'); ?>:</strong> .txt, .md, .htm, .html, .css, .js, .xml</li>
                  <li><strong><?php esc_html_e('Data Text Files', 'fluxa-ecommerce-assistant'); ?>:</strong> .json, .yml, .yaml</li>
                  <li><strong><?php esc_html_e('E-books', 'fluxa-ecommerce-assistant'); ?>:</strong> .epub</li>
                  <li><strong><?php esc_html_e('Images', 'fluxa-ecommerce-assistant'); ?>:</strong> .png, .jpg, .jpeg, .webp, .heic, .heif, .tiff, .bmp</li>
                  <li><strong><?php esc_html_e('Audio Files', 'fluxa-ecommerce-assistant'); ?>:</strong> .mp3, .wav, .aac, .ogg, .flac</li>
                  <li><strong><?php esc_html_e('Video Files', 'fluxa-ecommerce-assistant'); ?>:</strong> .mp4, .mpeg, .mov, .avi, .mpg, .webm, .mkv (<?php esc_html_e('Maximum duration: 90 minutes', 'fluxa-ecommerce-assistant'); ?>)</li>
                </ul>
              </div>
            </details>
          </td>
        </tr>
      </table>
      <div class="fluxa-actions-row">
        <input type="hidden" name="fluxa_train_file_upload_submit" value="1">
        <?php submit_button(__('Upload File', 'fluxa-ecommerce-assistant'), 'primary', 'fluxa_train_file_upload_submit', false, array('id' => 'fluxa-upload-btn')); ?>
        <span id="fluxa-upload-status" class="description" style="font-weight:600;display:none;"></span>
      </div>
      <p class="description"><?php esc_html_e('The file is uploaded via a signed URL returned by the API.', 'fluxa-ecommerce-assistant'); ?></p>
      </form>
    </div>
  </div>
  
  <?php endif; ?>

  <div class="fluxa-training-rows-between"><span class="dashicons dashicons-arrow-down-alt2"></span></div>

  <div class="fluxa-card" id="fluxa-kb-section">
    <?php
      $section_title = __('All Saved Training Data', 'fluxa-ecommerce-assistant');
      if ($method === 'text') {
        $section_title = __('Saved Training Text', 'fluxa-ecommerce-assistant');
      } elseif ($method === 'file') {
        $section_title = __('Saved Training Files', 'fluxa-ecommerce-assistant');
      } elseif ($method === 'url') {
        $section_title = __('Saved Training URLs', 'fluxa-ecommerce-assistant');
      } elseif ($method === 'youtube') {
        $section_title = __('Saved YouTube Videos', 'fluxa-ecommerce-assistant');
      } elseif ($method === 'qa') {
        $section_title = __('Saved Training Q&A', 'fluxa-ecommerce-assistant');
      }
    ?>
    <div class="fluxa-card__header">
      <h3 class="fluxa-card__title"><span class="dashicons dashicons-database"></span><?php echo esc_html($section_title); ?></h3>
    </div>
    <div class="fluxa-card__body">
      <?php
      // Render compact table if items are present
      $kb_items = array();
      if (is_array($kb_response) && isset($kb_response['body']) && is_array($kb_response['body'])) {
          $kb_items = isset($kb_response['body']['items']) && is_array($kb_response['body']['items']) ? $kb_response['body']['items'] : array();
      }
      // Filter by method-specific constraints beyond server-side type
      if (!empty($kb_items) && $method !== '') {
          // If URL method and API returns type 'url' instead of 'website', accept it
          if ($method === 'url') {
              $kb_items = array_values(array_filter($kb_items, function($it){
                  $t = isset($it['type']) ? strtolower((string)$it['type']) : '';
                  return ($t === 'website' || $t === 'url');
              }));
          }
          // Additional Text filter: exclude titles starting with 'QA'
          if ($method === 'text') {
              $kb_items = array_values(array_filter($kb_items, function($it){
                  $title = isset($it['title']) ? (string)$it['title'] : '';
                  return !(stripos($title, 'QA') === 0);
              }));
          }
          // Additional QA filter: title must start with 'QA'
          if ($method === 'qa' && !empty($kb_items)) {
              $kb_items = array_values(array_filter($kb_items, function($it){
                  $title = isset($it['title']) ? (string)$it['title'] : '';
                  return (stripos($title, 'QA') === 0);
              }));
          }
      }
      if (!empty($kb_items)) : ?>
        <table class="wp-list-table widefat fixed striped">
          <thead>
            <tr>
              <th><?php esc_html_e('Title', 'fluxa-ecommerce-assistant'); ?></th>
              <?php if ($method === 'file'): ?>
                <th><?php esc_html_e('File', 'fluxa-ecommerce-assistant'); ?></th>
                <th><?php esc_html_e('Size', 'fluxa-ecommerce-assistant'); ?></th>
              <?php elseif ($method === 'url'): ?>
                <th><?php esc_html_e('URL', 'fluxa-ecommerce-assistant'); ?></th>
              <?php elseif ($method === 'youtube'): ?>
                <th style="width:40%;"><?php esc_html_e('Video', 'fluxa-ecommerce-assistant'); ?></th>
              <?php endif; ?>
              <th><?php esc_html_e('Status', 'fluxa-ecommerce-assistant'); ?></th>
              <th><?php esc_html_e('Created', 'fluxa-ecommerce-assistant'); ?></th>
              <th><?php esc_html_e('Actions', 'fluxa-ecommerce-assistant'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($kb_items as $it):
                $id = $it['id'] ?? '';
                $type = $it['type'] ?? '';
                $title = $it['title'] ?? '';
                $status_raw = $it['status'] ?? '';
                $status_up = strtoupper($status_raw);
                // Default yellow, override below
                $badge = 'fluxa-badge is-warn';
                // Treat VECTOR_CREATED explicitly as yellow (do NOT fall into generic CREATED=green)
                if (strpos($status_up, 'VECTOR_CREATED') !== false) {
                  $badge = 'fluxa-badge is-warn';
                } else {
                  if (strpos($status_up, 'CREATED') !== false || strpos($status_up, 'READY') !== false || strpos($status_up, 'PROCESSED') !== false) { $badge = 'fluxa-badge is-ok'; }
                  if (strpos($status_up, 'ERROR') !== false || strpos($status_up, 'FAIL') !== false || strpos($status_up, 'UNPROCESS') !== false) { $badge = 'fluxa-badge is-fail'; }
                }
                $created = $it['createdAt'] ?? ($it['created_at'] ?? '');
                $file_row = isset($it['file']) && is_array($it['file']) ? $it['file'] : array();
                $file_name = $file_row['name'] ?? '';
                $file_size = isset($file_row['size']) ? (int)$file_row['size'] : 0;
                $file_mime = $file_row['mimeType'] ?? '';
                $file_url  = $file_row['downloadURL'] ?? '';
            ?>
              <tr>
                <td>
                  <?php echo esc_html($title); ?>
                  <?php if ($method !== 'file' && strtolower($type) === 'file' && ($file_name || $file_url)) : ?>
                    <div class="description" style="margin-top:4px;">
                      <?php if ($file_url) : ?>
                        <a href="<?php echo esc_url($file_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($file_name ?: __('Download file', 'fluxa-ecommerce-assistant')); ?></a>
                      <?php else : ?>
                        <?php echo esc_html($file_name); ?>
                      <?php endif; ?>
                      <?php if ($file_mime || $file_size) : ?>
                        <span class="fluxa-subtle">
                          <?php
                            $parts = array();
                            if ($file_mime) { $parts[] = $file_mime; }
                            if ($file_size) {
                              // Human readable size
                              $sz = size_format($file_size, 1);
                              $parts[] = $sz;
                            }
                            echo esc_html('('. implode(', ', $parts) .')');
                          ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </td>
                <?php if ($method === 'youtube'): ?>
                  <?php
                    // Resolve YouTube URL and title if present on the list item
                    $yt_url = '';
                    if (!empty($it['url'])) { $yt_url = (string)$it['url']; }
                    elseif (!empty($it['youtube']['url'])) { $yt_url = (string)$it['youtube']['url']; }
                    // Prefer the video's real title if provided; fallback to training title
                    $yt_title = !empty($it['youtube']['title']) ? (string)$it['youtube']['title'] : $title;
                    // Extract YouTube video ID for thumbnail
                    $vid = '';
                    if ($yt_url) {
                      // Basic parsing for youtu.be and youtube.com
                      $p = wp_parse_url($yt_url);
                      if (!empty($p['host'])) {
                        if (stripos($p['host'], 'youtu.be') !== false && !empty($p['path'])) {
                          $vid = ltrim($p['path'], '/');
                        } elseif (stripos($p['host'], 'youtube.com') !== false) {
                          if (!empty($p['query'])) {
                            parse_str($p['query'], $q);
                            if (!empty($q['v'])) { $vid = (string)$q['v']; }
                          }
                          if (!$vid && !empty($p['path'])) {
                            if (preg_match('#/shorts/([A-Za-z0-9_-]{10,})#', $p['path'], $m)) { $vid = $m[1]; }
                          }
                        }
                      }
                    }
                    $thumb = $vid ? ('https://i.ytimg.com/vi/' . $vid . '/hqdefault.jpg') : '';
                  ?>
                  <td style="width:40%;">
                    <div class="fluxa-yt-mini" style="display:flex;gap:8px;align-items:flex-start;">
                      <?php if ($thumb): ?>
                        <a href="<?php echo esc_url($yt_url ?: '#'); ?>" target="_blank" rel="noopener noreferrer">
                          <img src="<?php echo esc_url($thumb); ?>" alt="YouTube thumbnail" style="width:120px;height:68px;object-fit:cover;border-radius:4px;display:block;"/>
                        </a>
                      <?php endif; ?>
                      <div>
                        <div class="fluxa-yt-title" style="font-weight:600;">
                          <?php if ($yt_url): ?>
                            <a href="<?php echo esc_url($yt_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($yt_title); ?></a>
                          <?php else: ?>
                            <?php echo esc_html($yt_title); ?>
                          <?php endif; ?>
                        </div>
                        <?php if ($yt_url): ?>
                          <div class="fluxa-yt-url" style="font-family:ui-monospace, SFMono-Regular, Menlo, monospace;font-size:12px;color:#475569;word-break:break-all;">
                            <?php echo esc_html($yt_url); ?>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                <?php endif; ?>
                <?php if ($method === 'url'): ?>
                  <?php
                    // Try to resolve the canonical URL from the list item
                    $item_url = '';
                    if (!empty($it['url'])) { $item_url = (string)$it['url']; }
                    elseif (!empty($it['website']['url'])) { $item_url = (string)$it['website']['url']; }
                    elseif (!empty($it['webpage']['url'])) { $item_url = (string)$it['webpage']['url']; }
                  ?>
                  <td>
                    <?php if ($item_url) : ?>
                      <a href="<?php echo esc_url($item_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($item_url); ?></a>
                    <?php else : ?>
                      &mdash;
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
                <?php if ($method === 'file'): ?>
                  <td>
                    <?php if ($file_url) : ?>
                      <a href="<?php echo esc_url($file_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($file_name ?: __('Download file', 'fluxa-ecommerce-assistant')); ?></a>
                    <?php else : ?>
                      <?php echo esc_html($file_name ?: __('(no file name)', 'fluxa-ecommerce-assistant')); ?>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php echo $file_size ? esc_html( size_format($file_size, 1) ) : '&mdash;'; ?>
                  </td>
                <?php endif; ?>
                <td><span class="<?php echo esc_attr($badge); ?>"><?php echo esc_html(str_replace('_', ' ', $status_up)); ?></span></td>
                <td><?php echo esc_html($created); ?></td>
                <td>
                  <div class="fluxa-actions-row">
                    <div><?php if (strtolower($type) === 'file' && $file_url): ?>
                      <a class="button button-small" href="<?php echo esc_url($file_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Download', 'fluxa-ecommerce-assistant'); ?></a>
                    <?php endif; ?>
                    <button type="button" class="button button-small button-link-delete fluxa-kb-delete" data-id="<?php echo esc_attr($id); ?>"><?php esc_html_e('Delete', 'fluxa-ecommerce-assistant'); ?></button>
                    </div>
                    <button type="button" class="button button-small fluxa-kb-details" data-id="<?php echo esc_attr($id); ?>" data-kind="<?php echo esc_attr($method); ?>" aria-expanded="false" title="<?php echo esc_attr__('Details', 'fluxa-ecommerce-assistant'); ?>">
                      <span class="dashicons dashicons-arrow-down-alt2"></span>
                    </button>
                  </div>
                </td>
              </tr>
              <tr class="fluxa-kb-details-row" id="fluxa-kb-details-<?php echo esc_attr($id); ?>" style="display:none;">
                <td colspan="<?php echo ($method === 'file') ? 6 : (($method === 'url' || $method === 'youtube') ? 5 : 4); ?>">
                  <div class="fluxa-kb-details__inner">
                    <em><?php esc_html_e('Loading details...', 'fluxa-ecommerce-assistant'); ?></em>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p class="description" style="margin-top:28px;">
          <button class="button button-small " id="fluxa-toggle-json" style="cursor:pointer;"><?php esc_html_e('Show raw JSON', 'fluxa-ecommerce-assistant'); ?></button>
        </p>
      <?php else: ?>
        <p class="fluxa-subtle">
          <?php
          if ($method === 'text') {
              esc_html_e('No text items yet. Add one using Add Free Text.', 'fluxa-ecommerce-assistant');
          } elseif ($method === 'url') {
              esc_html_e('No URL items yet. Add one using Add URL.', 'fluxa-ecommerce-assistant');
          } elseif ($method === 'file') {
              esc_html_e('No file items yet. Upload one using Upload File.', 'fluxa-ecommerce-assistant');
          } elseif ($method === 'youtube') {
              esc_html_e('No YouTube items yet. Add one using Add YouTube Video.', 'fluxa-ecommerce-assistant');
          } elseif ($method === 'qa') {
              esc_html_e('No Q&A items yet. Add one using Add Q&A.', 'fluxa-ecommerce-assistant');
          } else {
              esc_html_e('No training items yet. Add content using the methods above.', 'fluxa-ecommerce-assistant');
          }
          ?>
        </p>
      <?php endif; ?>

      <pre id="fluxa-kb-json" style="display: none; padding:12px;background:#0b1221;color:#e5e7eb;border-radius:6px;overflow:auto;max-height:480px;">
<?php
if (is_null($kb_response)) {
    echo esc_html__('No response yet. Reload the page to fetch training data.', 'fluxa-ecommerce-assistant');
} else {
    echo esc_html( wp_json_encode($kb_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) );
}
?>
      </pre>
    </div>
  </div>

  <script>
  jQuery(function($){
    // Expand/collapse details row and fetch details via AJAX (frontend only for now)
    $(document).on('click', '.fluxa-kb-details', function(){
      const $btn = $(this);
      const id = String($btn.data('id') || '');
      const rowSel = '#fluxa-kb-details-' + id.replace(/[^A-Za-z0-9_-]/g,'');
      const $row = $(rowSel);
      const $mainRow = $btn.closest('tr');
      const expanded = $btn.attr('aria-expanded') === 'true';
      if (expanded) {
        $row.hide();
        $btn.attr('aria-expanded','false');
        $btn.find('.dashicons').removeClass('rotate-180');
        $mainRow.removeClass('is-expanded');
        return;
      }
      $btn.attr('aria-expanded','true');
      $btn.find('.dashicons').addClass('rotate-180');
      $row.show();
      $mainRow.addClass('is-expanded');
      const $inner = $row.find('.fluxa-kb-details__inner');
      if (!$row.data('loaded')){
        $inner.html('<em><?php echo esc_js(__('Loading details...', 'fluxa-ecommerce-assistant')); ?></em>');
        $.get(ajaxurl, { action:'fluxa_kb_item_details', id:id, _ajax_nonce:'<?php echo wp_create_nonce('fluxa_kb_item_details'); ?>' })
         .done(function(resp){
            if (resp && resp.success){
              const data = resp.data || {};
              const raw = data.raw || {};
              const esc = function(s){ return $('<div>').text(String(s||'')).html(); };
              // Helper: extract YouTube ID from URL
              const ytIdFromUrl = function(u){
                try {
                  const url = new URL(String(u));
                  if (url.hostname === 'youtu.be') { return url.pathname.replace(/^\//,''); }
                  if (url.hostname.includes('youtube.com')) { return url.searchParams.get('v') || ''; }
                } catch(e) {}
                // Fallback simple regex
                const m = String(u||'').match(/[?&]v=([A-Za-z0-9_-]{6,})|youtu\.be\/([A-Za-z0-9_-]{6,})/);
                return (m && (m[1] || m[2])) ? (m[1] || m[2]) : '';
              };
              let html = '';
              html += '<div class="fluxa-details-cards">';
              if (raw.generatedTitle){
                html += '<div class="fluxa-detail-card">'
                     +  '<div class="fluxa-detail-card__title">'+esc('<?php echo esc_html__('Generated Title', 'fluxa-ecommerce-assistant'); ?>')+'</div>'
                     +  '<div class="fluxa-detail-card__body">'+esc(raw.generatedTitle)+'</div>'
                     +  '</div>';
              }
              // YouTube specific card (if available in raw)
              if (raw && (raw.youtube || (raw.url && /youtube\.com|youtu\.be/i.test(raw.url)))){
                const yt = raw.youtube || { url: raw.url, title: '', description: '', transcription: '' };
                const vid = ytIdFromUrl(yt.url || raw.url || '');
                const thumb = vid ? 'https://i.ytimg.com/vi/'+ vid +'/hqdefault.jpg' : '';
                const title = yt.title || '';
                const desc  = yt.description || '';
                html += '<div class="fluxa-detail-card">'
                     +   '<div class="fluxa-detail-card__title">'+ esc('<?php echo esc_html__('Video Details', 'fluxa-ecommerce-assistant'); ?>') +'</div>'
                     +   '<div class="fluxa-detail-card__body">'
                     +     '<div class="fluxa-yt-preview">'
                     +       (thumb ? ('<div class="fluxa-yt-thumb"><img src="'+ esc(thumb) +'" alt="YouTube thumbnail"></div>') : '')
                     +       '<div class="fluxa-yt-meta">'
                     +         (title ? ('<div class="fluxa-yt-title">'+ esc(title) +'</div>') : '')
                     +         (yt.url ? ('<div class="fluxa-yt-url">'+ esc(yt.url) +'</div>') : '')
                     +         (desc ? ('<div class="fluxa-yt-desc">'+ esc(desc) +'</div>') : '')
                     +         (vid ? ('<div><button type="button" class="button button-small fluxa-yt-toggle" data-yt-id="'+ esc(vid) +'" id="yt-prev-'+ esc(vid) +'"><?php echo esc_js(__('Preview', 'fluxa-ecommerce-assistant')); ?></button></div>') : '')
                     +       '</div>'
                     +     '</div>'
                     +     (vid ? ('<div class="fluxa-yt-embed" id="yt-embed-'+ esc(vid) +'" style="display:none;margin-top:8px;"><div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:6px;"><iframe width="560" height="315" src="https://www.youtube.com/embed/'+ esc(vid) +'?rel=0&modestbranding=1" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen style="position:absolute;top:0;left:0;width:100%;height:100%;"></iframe></div></div>') : '')
                     +   '</div>'
                     + '</div>';
              }
              // Webpage card (URL type)
              if (raw && (raw.webpage || raw.website)){
                const w = raw.webpage || raw.website || {};
                const wUrl = w.url || '';
                const wTitle = w.title || '';
                const wDesc = w.description || '';
                const wShot = w.screenshotURL || '';
                const links = Array.isArray(w.links) ? w.links.slice(0, 20) : [];
                const text  = w.text || '';
                const idSeed = Math.random().toString(36).slice(2,9);
                html += '<div class="fluxa-detail-card fluxa-detail-card--website">'
                     +  '<div class="fluxa-detail-card__title">'+esc('<?php echo esc_html__('Webpage', 'fluxa-ecommerce-assistant'); ?>')+'</div>'
                     +  '<div class="fluxa-detail-card__body">'
                     +    '<div class="fluxa-web-head">'
                     +       (wShot ? ('<div class="fluxa-web-thumb"><img src="'+esc(wShot)+'" alt="Webpage screenshot" width="100%"></div>') : '')
                     +       '<div class="fluxa-web-meta">'
                     +         (wTitle ? ('<div class="fluxa-web-title">'+esc(wTitle)+'</div>') : '')
                     +         (wUrl ? ('<div class="fluxa-web-url">'+esc(wUrl)+'</div>') : '')
                     +         (wDesc ? ('<div class="fluxa-web-desc">'+esc(wDesc)+'</div>') : '')
                     +       '</div>'
                     +    '</div>'
                     +    (links.length ? (
                             '<div class="fluxa-web-links" id="web-links-'+idSeed+'">'
                           +   '<div class="fluxa-kv">'
                           +     '<div class="fluxa-kv__label"><?php echo esc_js(__('Top Links', 'fluxa-ecommerce-assistant')); ?></div>'
                           +     '<div class="fluxa-kv__value">'
                           +       links.map(function(u, idx){ return '<div class="fluxa-link-item'+(idx>5?' is-hidden':'')+'"><a href="'+esc(u)+'" target="_blank" rel="noopener noreferrer">'+esc(u)+'</a></div>'; }).join('')
                           +       (links.length>6 ? ('<button type="button" class="button button-small fluxa-toggle-links" data-target="web-links-'+idSeed+'" data-state="collapsed"><?php echo esc_js(__('Show more', 'fluxa-ecommerce-assistant')); ?></button>') : '')
                           +     '</div>'
                           +   '</div>'
                           + '</div>'
                           ) : '')
                     +    (text ? (
                             '<div class="fluxa-web-text" id="web-text-'+idSeed+'">'
                           +   '<div class="fluxa-kv">'
                           +     '<div class="fluxa-kv__label"><?php echo esc_js(__('Text', 'fluxa-ecommerce-assistant')); ?></div>'
                           +     '<div class="fluxa-kv__value">'
                           +       '<div class="fluxa-web-text-content">'+ esc(String(text)).slice(0, 600).replace(/\n{2,}/g,'</p><p>').replace(/\n/g,'<br>') + (String(text).length>600 ? '…' : '') +'</div>'
                           +       (String(text).length>600 ? ('<button type="button" class="button button-small fluxa-toggle-text" data-full="'+ esc(String(text)).replace(/"/g,'&quot;') +'" data-target="web-text-'+idSeed+'" data-state="collapsed"><?php echo esc_js(__('Show more', 'fluxa-ecommerce-assistant')); ?></button>') : '')
                           +     '</div>'
                           +   '</div>'
                           + '</div>'
                           ) : '')
                     +  '</div>'
                     +  '</div>';
                // Removed Screenshot card as requested
              }
              
              if (Array.isArray(raw.generatedFacts) && raw.generatedFacts.length){
                html += '<div class="fluxa-detail-card fluxa-detail-card--facts">'
                     +  '<div class="fluxa-detail-card__title">'+esc('<?php echo esc_html__('Generated Facts', 'fluxa-ecommerce-assistant'); ?>')+'</div>'
                     +  '<div class="fluxa-detail-card__body">'
                     +    '<div class="fluxa-facts">'
                     +      raw.generatedFacts.map(function(f){
                              const kind = ($btn.data('kind')||'qa'); // use QA-style rendering for all types
                              const rendered = (window.fluxaRenderFacts ? window.fluxaRenderFacts(f, kind) : $('<div>').text(String(f||'')) .html().replace(/\n/g,'<br>'));
                              return '<div class="fluxa-fact"><div class="fluxa-fact-block">'+ rendered +'</div></div>';
                            }).join('')
                     +    '</div>'
                     +  '</div>'
                     +  '</div>';
              }
              if (raw.summary){
                html += '<div class="fluxa-detail-card">'
                     +  '<div class="fluxa-detail-card__title">'+esc('<?php echo esc_html__('Generated Summary', 'fluxa-ecommerce-assistant'); ?>')+'</div>'
                     +  '<div class="fluxa-detail-card__body">'+esc(raw.summary)+'</div>'
                     +  '</div>';
              }
              if (raw.rawText){
                html += '<div class="fluxa-detail-card">'
                     +  '<div class="fluxa-detail-card__title">'+esc('<?php echo esc_html__('Raw Text', 'fluxa-ecommerce-assistant'); ?>')+'</div>'
                     +  '<pre class="fluxa-pre">'+esc(raw.rawText)+'</pre>'
                     +  '</div>';
              }
              html += '</div>';

              // Keep raw JSON visible for now for inspection
              const json = JSON.stringify(resp.data, null, 2);
              html += '<details class="fluxa-raw-details"><summary><?php echo esc_js(__('Raw API response', 'fluxa-ecommerce-assistant')); ?></summary>'
                   +  '<pre>'+ esc(json) +'</pre>'
                   +  '</details>';

              $inner.html(html);
              $row.data('loaded', true);
            } else {
              $inner.html('<em><?php echo esc_js(__('Details endpoint not implemented yet.', 'fluxa-ecommerce-assistant')); ?></em>');
            }
         })
         .fail(function(){
            $inner.html('<em><?php echo esc_js(__('Details endpoint not implemented yet.', 'fluxa-ecommerce-assistant')); ?></em>');
         });
      }
    });

    // Delete: call WP AJAX which calls Sensay DELETE endpoint
    $(document).on('click', '.fluxa-kb-delete', function(){
      const id = String($(this).data('id') || '');
      if (!id) return;
      if (!confirm('<?php echo esc_js(__('Are you sure you want to delete this training item? This cannot be undone.', 'fluxa-ecommerce-assistant')); ?>')) return;
      const $btn = $(this);
      const $row = $btn.closest('tr');
      const detSel = '#fluxa-kb-details-' + id.replace(/[^A-Za-z0-9_-]/g,'');
      const $details = $(detSel);
      const origText = $btn.text();
      $btn.prop('disabled', true).text('<?php echo esc_js(__('Deleting…', 'fluxa-ecommerce-assistant')); ?>');
      $.post(ajaxurl, { action:'fluxa_kb_item_delete', id:id, _ajax_nonce:'<?php echo wp_create_nonce('fluxa_kb_item_delete'); ?>' })
        .done(function(resp){
          if (resp && resp.success) {
            // Remove both main row and its details row
            if ($details.length) { $details.remove(); }
            $row.remove();
          } else {
            const msg = (resp && resp.data && resp.data.error) ? String(resp.data.error) : '<?php echo esc_js(__('Delete failed. Please try again.', 'fluxa-ecommerce-assistant')); ?>';
            alert(msg);
            $btn.prop('disabled', false).text(origText);
          }
        })
        .fail(function(){
          alert('<?php echo esc_js(__('Network error while deleting. Please try again.', 'fluxa-ecommerce-assistant')); ?>');
          $btn.prop('disabled', false).text(origText);
        });
    });

    // Make the entire row act as a toggle for Details (except details-row itself or when clicking controls)
    $(document).on('click', '#fluxa-kb-section table.wp-list-table tbody tr', function(e){
      // Ignore clicks on interactive elements to prevent double triggers
      if ($(e.target).closest('button, a, .button').length) return;
      const $row = $(this);
      if ($row.hasClass('fluxa-kb-details-row')) return;
      const $btn = $row.find('.fluxa-kb-details').first();
      if ($btn.length) { $btn.trigger('click'); }
    });

    // Toggle YouTube inline preview
    $(document).on('click', '.fluxa-yt-toggle', function(){
      const vid = String($(this).data('yt-id') || '');
      if (!vid) return;
      const $embed = $('#yt-embed-' + vid);
      const showing = $embed.is(':visible');
      // After click, we want the opposite state
      const willShow = !showing;
      $embed.toggle(willShow);
      // Button label should reflect the action now available
      // If video is now shown, offer "Hide Preview"; otherwise "Preview"
      $(this)
        .text(willShow ? '<?php echo esc_js(__('Hide Preview', 'fluxa-ecommerce-assistant')); ?>' : '<?php echo esc_js(__('Preview', 'fluxa-ecommerce-assistant')); ?>')
        .attr('aria-expanded', willShow ? 'true' : 'false');
    });

    // Toggle Website links list
    $(document).on('click', '.fluxa-toggle-links', function(){
      const target = $(this).data('target');
      const $wrap = $('#'+target);
      const state = $(this).data('state');
      if (!$wrap.length) return;
      if (state === 'collapsed'){
        $wrap.find('.fluxa-link-item').removeClass('is-hidden');
        $(this).data('state','expanded').text('<?php echo esc_js(__('Show less', 'fluxa-ecommerce-assistant')); ?>');
      } else {
        $wrap.find('.fluxa-link-item').each(function(i){ if (i>5) $(this).addClass('is-hidden'); });
        $(this).data('state','collapsed').text('<?php echo esc_js(__('Show more', 'fluxa-ecommerce-assistant')); ?>');
      }
    });

    // Toggle Website text
    $(document).on('click', '.fluxa-toggle-text', function(){
      const target = $(this).data('target');
      const $wrap = $('#'+target);
      const full  = String($(this).data('full')||'');
      const state = $(this).data('state');
      if (!$wrap.length) return;
      const $content = $wrap.find('.fluxa-web-text-content');
      if (state === 'collapsed'){
        $content.html($('<div>').text(full).html().replace(/\n{2,}/g,'</p><p>').replace(/\n/g,'<br>'));
        $(this).data('state','expanded').text('<?php echo esc_js(__('Show less', 'fluxa-ecommerce-assistant')); ?>');
      } else {
        const short = full.slice(0, 600) + (full.length>600 ? '…' : '');
        $content.html($('<div>').text(short).html().replace(/\n{2,}/g,'</p><p>').replace(/\n/g,'<br>'));
        $(this).data('state','collapsed').text('<?php echo esc_js(__('Show more', 'fluxa-ecommerce-assistant')); ?>');
      }
    });
  });
  </script>