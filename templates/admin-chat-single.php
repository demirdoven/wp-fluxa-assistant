<?php
if (!defined('ABSPATH')) { exit; }

$conversation_id = isset($_GET['conversation']) ? sanitize_text_field($_GET['conversation']) : '';

// Prepare defaults
$conv = array(
    'id' => $conversation_id !== '' ? $conversation_id : 'unknown',
    'messages' => array(),
);
$api_error = '';
$api_raw = null;

// Fetch messages from Sensay API
$replica_id = get_option('fluxa_ss_replica_id', '');
if (empty($conversation_id)) {
    $api_error = __('Missing conversation ID.', 'wp-fluxa-ecommerce-assistant');
} elseif (empty($replica_id)) {
    $api_error = __('Replica is not provisioned yet.', 'wp-fluxa-ecommerce-assistant');
} else {
    if (class_exists('Sensay_Client')) {
        $client = new Sensay_Client();
        $path = '/v1/replicas/' . rawurlencode($replica_id) . '/conversations/' . rawurlencode($conversation_id) . '/messages';
        $res = $client->get($path);
        if (is_wp_error($res)) {
            $api_error = $res->get_error_message();
        } else {
            $code = (int)($res['code'] ?? 0);
            $body = $res['body'] ?? array();
            $api_raw = $body;
            if ($code >= 200 && $code < 300 && is_array($body)) {
                $items = isset($body['items']) && is_array($body['items']) ? $body['items'] : array();
                foreach ($items as $m) {
                    $role = strtolower((string)($m['role'] ?? 'user')) === 'assistant' ? 'assistant' : 'user';
                    $content = (string)($m['content'] ?? '');
                    $ts = isset($m['createdAt']) ? strtotime($m['createdAt']) : 0;
                    $conv['messages'][] = array(
                        'role' => $role,
                        'content' => $content,
                        'time' => $ts ?: current_time('timestamp'),
                        'senderName' => (string)($m['senderName'] ?? ''),
                        'senderProfileImageURL' => (string)($m['senderProfileImageURL'] ?? ''),
                    );
                }
            } else {
                $api_error = isset($body['error']) ? (string)$body['error'] : sprintf(__('HTTP %d from API', 'wp-fluxa-ecommerce-assistant'), $code);
            }
        }
    } else {
        $api_error = __('API client is unavailable.', 'wp-fluxa-ecommerce-assistant');
    }
}

$back_url = admin_url('admin.php?page=fluxa-assistant-chat');
?>

<style>
  .fluxa-conv-wrap { max-width: 1000px; }
  /* .fluxa-conv-header { display:flex; align-items:center; justify-content:space-between; gap:12px; } */
  .fluxa-chip { display:inline-flex; align-items:center; gap:6px; background:#f6f7f7; border:1px solid #dcdcde; color:#1d2327; padding:6px 10px; border-radius:999px; font-size:12px; }
  .fluxa-conv-toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom: 2em;}
  .fluxa-conv-search { min-width:260px; }
  .fluxa-thread-card { background:#fff; border:1px solid #ccd0d4; border-radius:8px; box-shadow:0 1px 1px rgba(0,0,0,.04); overflow:hidden; }
  .fluxa-thread { max-height: 65vh; overflow:auto; padding:16px; display:flex; flex-direction:column; gap:12px; background:#fbfbfc; }
  .fluxa-msg { display:flex; gap:12px; align-items:flex-start; }
  .fluxa-msg.user { flex-direction:row; }
  .fluxa-msg.assistant { flex-direction:row-reverse; }
  .fluxa-avatar { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; color:#fff; box-shadow:0 1px 2px rgba(0,0,0,.08); }
  .fluxa-avatar.user { background:#64748b; }
  .fluxa-avatar.assistant { background:#4F46E5; }
  .fluxa-bubble { max-width:72%; background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:10px 12px; position:relative; box-shadow:0 1px 2px rgba(0,0,0,.04); }
  .fluxa-msg.user .fluxa-bubble { background:#ffffff; }
  .fluxa-msg.assistant .fluxa-bubble { background:#f1f5ff; border-color:#dfe7ff; }
  .fluxa-meta { display:flex; align-items:center; justify-content:space-between; gap: 2em; margin-top:6px; color:#6b7280; font-size:11px; }
  .fluxa-actions { display:flex; gap:6px; }
  .fluxa-btn-icon { border:1px solid #dcdcde; background:#fff; border-radius:6px; padding:4px 8px; font-size:12px; cursor:pointer; }
  .fluxa-btn-icon:hover { background:#f6f7f7; }
  .fluxa-highlight { background: #fff3bf; padding:0 2px; border-radius:3px; }
</style>

<div class="wrap fluxa-conv-wrap">
  <div class="fluxa-conv-header">
    <h1 style="margin-bottom:8px;">
      <?php echo esc_html(sprintf(__('Conversation #%s', 'wp-fluxa-ecommerce-assistant'), $conv['id'])); ?>
    </h1>
    <div class="fluxa-conv-toolbar">
      <a class="button" href="<?php echo esc_url($back_url); ?>">&larr; <?php esc_html_e('Back to Conversations', 'wp-fluxa-ecommerce-assistant'); ?></a>
    </div>
  </div>

  <div style="margin:8px 0 16px 0; display:flex; gap:8px; flex-wrap:wrap; justify-content: space-between;">
    <div>
      <?php if (!empty($conv['messages'])):
        $first = $conv['messages'][0]; $last = $conv['messages'][count($conv['messages'])-1]; ?>
        <span class="fluxa-chip"><span class="dashicons dashicons-clock"></span><?php esc_html_e('Started', 'wp-fluxa-ecommerce-assistant'); ?>: <?php echo esc_html(date_i18n('Y-m-d H:i', $first['time'])); ?></span>
        <span class="fluxa-chip"><span class="dashicons dashicons-update"></span><?php esc_html_e('Last update', 'wp-fluxa-ecommerce-assistant'); ?>: <?php echo esc_html(date_i18n('Y-m-d H:i', $last['time'])); ?></span>
        <span class="fluxa-chip"><span class="dashicons dashicons-admin-comments"></span><?php esc_html_e('Messages', 'wp-fluxa-ecommerce-assistant'); ?>: <?php echo esc_html(number_format_i18n(count($conv['messages']))); ?></span>
      <?php else: ?>
        <span class="fluxa-chip"><span class="dashicons dashicons-info"></span><?php esc_html_e('No messages to display', 'wp-fluxa-ecommerce-assistant'); ?></span>
      <?php endif; ?>
    </div>
    <div>
      <input type="search" id="fluxa-conv-search" class="regular-text fluxa-conv-search" placeholder="<?php echo esc_attr__('Search in messages…', 'wp-fluxa-ecommerce-assistant'); ?>" />
    </div>
  </div>

  <div class="fluxa-thread-card">
    <div class="fluxa-thread" id="fluxa-thread">
      <?php if (!empty($api_error)): ?>
        <div class="notice notice-error" style="margin:12px;">
          <p><?php echo esc_html($api_error); ?></p>
        </div>
      <?php elseif (empty($conv['messages'])): ?>
        <div style="margin:12px; color:#555;"><em><?php esc_html_e('No messages in this conversation.', 'wp-fluxa-ecommerce-assistant'); ?></em></div>
      <?php else: foreach ($conv['messages'] as $idx => $m):
        $role = $m['role'] === 'assistant' ? 'assistant' : 'user';
        $initials = $role === 'assistant' ? 'A' : 'U';
        $text = $m['content'];
      ?>
      <div class="fluxa-msg <?php echo esc_attr($role); ?>" data-text="<?php echo esc_attr(mb_strtolower(wp_strip_all_tags($text))); ?>">
        <div class="fluxa-avatar <?php echo esc_attr($role); ?>"><?php echo esc_html($initials); ?></div>
        <div class="fluxa-bubble">
          <div class="fluxa-content" style="white-space:pre-wrap;">&nbsp;<?php echo esc_html($text); ?></div>
          <div class="fluxa-meta">
            <span><?php echo esc_html(date_i18n('Y-m-d H:i', $m['time'])); ?></span>
            <div class="fluxa-actions">
              <button type="button" class="fluxa-btn-icon" data-copy><?php esc_html_e('Copy', 'wp-fluxa-ecommerce-assistant'); ?></button>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <?php if (!empty($api_raw) || !empty($api_error)): ?>
  <div style="margin-top:18px;">
    <details>
      <summary style="cursor:pointer; font-weight:600;">Full API response (debug)</summary>
      <div style="margin-top:10px; background:#0f172a; color:#e2e8f0; border-radius:6px; overflow:auto;">
        <pre style="margin:0; padding:12px; white-space:pre;">
<?php
if (!empty($api_error)) {
    echo esc_html($api_error) . "\n\n";
}
if (!empty($api_raw)) {
    echo esc_html( wp_json_encode($api_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) );
}
?>
        </pre>
      </div>
    </details>
  </div>
  <?php endif; ?>
</div>

<script>
(function($){
  function highlight(text, term){
    if (!term) return text;
    try {
      var re = new RegExp('('+term.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\$&')+')','ig');
      return text.replace(re, '<span class="fluxa-highlight">$1</span>');
    } catch(e) { return text; }
  }

  function copyTextFallback(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.top = '-1000px';
    document.body.appendChild(ta);
    ta.select();
    ta.setSelectionRange(0, ta.value.length);
    var ok = false;
    try { ok = document.execCommand('copy'); } catch(e) { ok = false; }
    document.body.removeChild(ta);
    return ok;
  }

  function copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text).then(function(){ return true; }).catch(function(){
        return copyTextFallback(text);
      });
    }
    return Promise.resolve(copyTextFallback(text));
  }

  $('#fluxa-conv-search').on('input', function(){
    var q = ($(this).val()||'').toString().trim().toLowerCase();
    var firstMatch = null;
    $('.fluxa-msg').each(function(){
      var hay = ($(this).data('text')||'').toString();
      var $content = $(this).find('.fluxa-content');
      var original = $content.text();
      if (!q) {
        $(this).show();
        $content.html(original);
        return;
      }
      if (hay.indexOf(q) !== -1) {
        $(this).show();
        $content.html(highlight(original, q));
        if (!firstMatch) { firstMatch = this; }
      } else {
        $(this).hide();
      }
    });
    if (firstMatch) {
      var $c = $('#fluxa-thread');
      var $t = $(firstMatch);
      $c.stop().animate({ scrollTop: $c.scrollTop() + $t.position().top - 24 }, 300);
    }
  });

  $(document).on('click','[data-copy]', function(){
    var self = this;
    var text = $(self).closest('.fluxa-bubble').find('.fluxa-content').text();
    copyToClipboard(text).then(function(ok){
      var $btn = $(self);
      var old = $btn.text();
      $btn.text(ok ? 'Copied' : 'Failed');
      setTimeout(function(){ $btn.text(old); }, 1200);
    });
  });
})(jQuery);
</script>
