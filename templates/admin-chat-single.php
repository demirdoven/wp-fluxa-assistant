<?php
if (!defined('ABSPATH')) { exit; }

$conversation_id = isset($_GET['conversation']) ? sanitize_text_field($_GET['conversation']) : '';

// Mock single conversation using the same generator as list, fallback if needed
if (!function_exists('fluxa_mock_conversations')) {
    function fluxa_mock_conversations() {
        $now = current_time('timestamp');
        $make_conv = function($id, $minutes_ago_start, $count) use ($now) {
            $messages = [];
            for ($i = 0; $i < $count; $i++) {
                $role = $i % 2 === 0 ? 'user' : 'assistant';
                $messages[] = [
                    'role' => $role,
                    'content' => ($role === 'user' ? 'Customer: ' : 'Agent: ') . 'Sample message #' . ($i+1) . ' for conversation ' . $id,
                    'time' => $now - (($minutes_ago_start - $i) * MINUTE_IN_SECONDS),
                ];
            }
            return [
                'id' => (string)$id,
                'messages' => $messages,
            ];
        };

        return [
            $make_conv(101, 240, 6),
            $make_conv(102, 120, 4),
            $make_conv(103, 90, 8),
            $make_conv(104, 30, 3),
            $make_conv(105, 10, 5),
        ];
    }
}

$conv = null;
foreach (fluxa_mock_conversations() as $c) {
    if ($c['id'] === $conversation_id) { $conv = $c; break; }
}
if (!$conv) {
    // Create a minimal mock if ID is unknown
    $conv = [
        'id' => $conversation_id !== '' ? $conversation_id : 'unknown',
        'messages' => [
            ['role' => 'user', 'content' => 'Hello, I need help with my order.', 'time' => current_time('timestamp') - 600],
            ['role' => 'assistant', 'content' => 'Sure! Could you share your order number?', 'time' => current_time('timestamp') - 580],
            ['role' => 'user', 'content' => 'Order #12345', 'time' => current_time('timestamp') - 560],
        ],
    ];
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
  .fluxa-meta { display:flex; align-items:center; justify-content:space-between; margin-top:6px; color:#6b7280; font-size:11px; }
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
      <?php $first = $conv['messages'][0]; $last = $conv['messages'][count($conv['messages'])-1]; ?>
      <span class="fluxa-chip"><span class="dashicons dashicons-clock"></span><?php esc_html_e('Started', 'wp-fluxa-ecommerce-assistant'); ?>: <?php echo esc_html(date_i18n('Y-m-d H:i', $first['time'])); ?></span>
      <span class="fluxa-chip"><span class="dashicons dashicons-update"></span><?php esc_html_e('Last update', 'wp-fluxa-ecommerce-assistant'); ?>: <?php echo esc_html(date_i18n('Y-m-d H:i', $last['time'])); ?></span>
      <span class="fluxa-chip"><span class="dashicons dashicons-admin-comments"></span><?php esc_html_e('Messages', 'wp-fluxa-ecommerce-assistant'); ?>: <?php echo esc_html(number_format_i18n(count($conv['messages']))); ?></span>
    </div>
    <div>
      <input type="search" id="fluxa-conv-search" class="regular-text fluxa-conv-search" placeholder="<?php echo esc_attr__('Search in messages…', 'wp-fluxa-ecommerce-assistant'); ?>" />
    </div>
  </div>

  <div class="fluxa-thread-card">
    <div class="fluxa-thread" id="fluxa-thread">
      <?php foreach ($conv['messages'] as $idx => $m):
        $role = $m['role'] === 'assistant' ? 'assistant' : 'user';
        $initials = $role === 'assistant' ? 'A' : 'U';
        $text = $m['content'];
      ?>
      <div class="fluxa-msg <?php echo esc_attr($role); ?>" data-text="<?php echo esc_attr(mb_strtolower(wp_strip_all_tags($text))); ?>">
        <div class="fluxa-avatar <?php echo esc_attr($role); ?>"><?php echo esc_html($initials); ?></div>
        <div class="fluxa-bubble">
          <div class="fluxa-content" style="white-space:pre-wrap;"><?php echo esc_html($text); ?></div>
          <div class="fluxa-meta">
            <span><?php echo esc_html(date_i18n('Y-m-d H:i', $m['time'])); ?></span>
            <div class="fluxa-actions">
              <button type="button" class="fluxa-btn-icon" data-copy><?php esc_html_e('Copy', 'wp-fluxa-ecommerce-assistant'); ?></button>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
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
