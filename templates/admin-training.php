<?php
if (!defined('ABSPATH')) { exit; }

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
function fluxa_get_kb_list() {
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
    $kb_r = fluxa_get_kb_list();
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

<script>
// Client-side validations and UX for file upload
jQuery(function($){
  const MAX_BYTES = 25 * 1024 * 1024; // 25MB
  const ALLOWED_EXT = ['pdf','docx','txt','mp3','mp4','wav','m4a','mov'];
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
      showErr('<?php echo esc_js(__('Invalid file type. Allowed: PDF, DOCX, TXT, MP3, MP4, WAV, M4A, MOV.', 'fluxa-ecommerce-assistant')); ?>');
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

  // KB list Refresh via AJAX
  const $kbJson = $('#fluxa-kb-json');
  const $toggle = $('#fluxa-toggle-json');
  $toggle.on('click', function(e){ e.preventDefault(); $kbJson.toggle(); $(this).text($kbJson.is(':visible') ? '<?php echo esc_js(__('Hide raw JSON', 'fluxa-ecommerce-assistant')); ?>' : '<?php echo esc_js(__('Show raw JSON', 'fluxa-ecommerce-assistant')); ?>'); });
});
</script>
  <?php endif; ?>

  

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
      );
    ?>
    <h2 class="nav-tab-wrapper" style="margin-top:10px;">
      <?php foreach ($tabs as $key => $label): ?>
        <a class="nav-tab <?php echo ($method === $key) ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('method', $key, $base_url)); ?>"><?php echo esc_html($label); ?></a>
      <?php endforeach; ?>
    </h2>
  <?php endif; ?>

  <?php if (!is_null($api_response)) : ?>
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
            <input type="text" id="train_url" name="train_url" class="regular-text" required placeholder="https://www.youtube.com/watch?v=...  or  dQw4w9WgXcQ">
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
      <p class="fluxa-help"><?php esc_html_e('Upload PDFs, DOCX, TXT, or audio/video for transcription (e.g., MP3, WAV, MP4).', 'fluxa-ecommerce-assistant'); ?></p>
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
            <input type="file" id="training_file" name="training_file" class="regular-text" accept=".pdf,.docx,.txt,.mp3,.mp4,.wav,.m4a,.mov" required>
            <div id="fluxa-file-error" class="notice notice-error" style="display:none;margin-top:8px;"><p></p></div>
          </td>
        </tr>
      </table>
      <div class="fluxa-actions-row">
        <?php submit_button(__('Upload File', 'fluxa-ecommerce-assistant'), 'primary', 'fluxa_train_file_upload_submit', false, array('id' => 'fluxa-upload-btn')); ?>
        <span id="fluxa-upload-status" class="description" style="font-weight:600;display:none;"></span>
      </div>
      <p class="description"><?php esc_html_e('The file is uploaded via a signed URL returned by the API.', 'fluxa-ecommerce-assistant'); ?></p>
      </form>
    </div>
  </div>

  <?php endif; ?>
  </br>
  <div class="fluxa-card" id="fluxa-kb-section">
    <div class="fluxa-card__header">
      <h3 class="fluxa-card__title"><span class="dashicons dashicons-database"></span><?php esc_html_e('Saved Training Data', 'fluxa-ecommerce-assistant'); ?></h3>
      <div class="fluxa-actions">
        <button id="fluxa-kb-refresh" class="button button-secondary"><?php esc_html_e('Refresh', 'fluxa-ecommerce-assistant'); ?></button>
      </div>
    </div>
    <div class="fluxa-card__body">
      <?php
      // Render compact table if items are present
      $kb_items = array();
      if (is_array($kb_response) && isset($kb_response['body']) && is_array($kb_response['body'])) {
          $kb_items = isset($kb_response['body']['items']) && is_array($kb_response['body']['items']) ? $kb_response['body']['items'] : array();
      }
      if (!empty($kb_items)) : ?>
        <table class="wp-list-table widefat fixed striped">
          <thead>
            <tr>
              <th><?php esc_html_e('ID', 'fluxa-ecommerce-assistant'); ?></th>
              <th><?php esc_html_e('Type', 'fluxa-ecommerce-assistant'); ?></th>
              <th><?php esc_html_e('Title', 'fluxa-ecommerce-assistant'); ?></th>
              <th><?php esc_html_e('Status', 'fluxa-ecommerce-assistant'); ?></th>
              <th><?php esc_html_e('Created', 'fluxa-ecommerce-assistant'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($kb_items as $it):
                $id = $it['id'] ?? '';
                $type = $it['type'] ?? '';
                $title = $it['title'] ?? '';
                $status_raw = $it['status'] ?? '';
                $status_up = strtoupper($status_raw);
                $badge = 'fluxa-badge is-warn';
                if (strpos($status_up, 'CREATED') !== false || strpos($status_up, 'READY') !== false || strpos($status_up, 'PROCESSED') !== false) { $badge = 'fluxa-badge is-ok'; }
                if (strpos($status_up, 'ERROR') !== false || strpos($status_up, 'FAIL') !== false || strpos($status_up, 'UNPROCESS') !== false) { $badge = 'fluxa-badge is-fail'; }
                $created = $it['createdAt'] ?? ($it['created_at'] ?? '');
            ?>
              <tr>
                <td><?php echo esc_html($id); ?></td>
                <td><?php echo esc_html($type); ?></td>
                <td><?php echo esc_html($title); ?></td>
                <td><span class="<?php echo esc_attr($badge); ?>"><?php echo esc_html($status_raw); ?></span></td>
                <td><?php echo esc_html($created); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p class="description" style="margin-top:8px;">
          <a href="#" id="fluxa-toggle-json"><?php esc_html_e('Show raw JSON', 'fluxa-ecommerce-assistant'); ?></a>
        </p>
      <?php else: ?>
        <p class="fluxa-subtle"><?php esc_html_e('No training items yet. Add content using the methods above, then refresh.', 'fluxa-ecommerce-assistant'); ?></p>
      <?php endif; ?>

      <pre id="fluxa-kb-json" style="padding:12px;background:#0b1221;color:#e5e7eb;border-radius:6px;overflow:auto;max-height:480px;">
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