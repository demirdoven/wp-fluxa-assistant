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
// Fetch journey events for this conversation by resolving ss_user_id first, then querying events by ss_user_id
global $wpdb;
$journey_events = array();
if (!empty($conversation_id)) {
    $table_conv = $wpdb->prefix . 'fluxa_conv';
    $table_ev   = $wpdb->prefix . 'fluxa_conv_events';
    // Get identifiers for this conversation
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT ss_user_id, wc_session_key, wp_user_id FROM {$table_conv} WHERE conversation_id = %s LIMIT 1", $conversation_id),
        ARRAY_A
    );
    $ss_user_id = '';
    $wc_session_key = '';
    $wp_user_id = 0;
    if (is_array($row)) {
        $raw = isset($row['ss_user_id']) ? (string)$row['ss_user_id'] : '';
        // Normalize to UUID-only (strip signature if any)
        if ($raw !== '') {
            if (preg_match('/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/', $raw, $m)) {
                $ss_user_id = $m[0];
            } else {
                $ss_user_id = strtok($raw, '.');
            }
        }
        $wc_session_key = isset($row['wc_session_key']) ? (string)$row['wc_session_key'] : '';
        $wp_user_id = isset($row['wp_user_id']) ? (int)$row['wp_user_id'] : 0;
    }
    // Primary: query by ss_user_id
    if (!empty($ss_user_id)) {
        $journey_events = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, event_type, event_time, url, referer, product_id, variation_id, qty, price, currency, order_id, order_status, cart_total, shipping_total, discount_total, tax_total, json_payload FROM {$table_ev} WHERE ss_user_id = %s ORDER BY event_time ASC, id ASC",
                $ss_user_id
            ),
            ARRAY_A
        );
    }
    // Fallbacks: if still empty, try to resolve ss_user_id from recent events by wc_session_key or wp_user_id
    if (empty($journey_events)) {
        if (!empty($wc_session_key)) {
            $ev = $wpdb->get_row(
                $wpdb->prepare("SELECT ss_user_id FROM {$table_ev} WHERE wc_session_key = %s AND ss_user_id IS NOT NULL AND ss_user_id <> '' ORDER BY event_time DESC, id DESC LIMIT 1", $wc_session_key),
                ARRAY_A
            );
            if (!empty($ev['ss_user_id'])) {
                $ss_user_id = (string)$ev['ss_user_id'];
            }
        }
        if (empty($ss_user_id) && !empty($wp_user_id)) {
            $ev = $wpdb->get_row(
                $wpdb->prepare("SELECT ss_user_id FROM {$table_ev} WHERE user_id = %d AND ss_user_id IS NOT NULL AND ss_user_id <> '' ORDER BY event_time DESC, id DESC LIMIT 1", $wp_user_id),
                ARRAY_A
            );
            if (!empty($ev['ss_user_id'])) {
                $ss_user_id = (string)$ev['ss_user_id'];
            }
        }
        if (!empty($ss_user_id)) {
            $journey_events = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, event_type, event_time, url, referer, product_id, variation_id, qty, price, currency, order_id, order_status, cart_total, shipping_total, discount_total, tax_total, json_payload FROM {$table_ev} WHERE ss_user_id = %s ORDER BY event_time ASC, id ASC",
                    $ss_user_id
                ),
                ARRAY_A
            );
        }
    }
}
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
  /* Layout */
  .fluxa-layout { display:flex; gap:16px; align-items:flex-start; }
  .fluxa-main { flex: 1 1 64%; min-width:0; }
  .fluxa-aside { flex: 1 1 36%; position:sticky; top:64px; align-self:flex-start; }
  .fluxa-card { background:#fff; border:1px solid #ccd0d4; border-radius:8px; box-shadow:0 1px 1px rgba(0,0,0,.04); overflow:hidden; }
  .fluxa-card h3 { margin:0; padding:12px 14px; border-bottom:1px solid #e5e7eb; background:#f8fafc; font-size:14px; }
  .fluxa-journey { max-height:65vh; overflow:auto; padding:10px 12px; background:#fbfbfc; }
  .fluxa-journey-item { display:flex; align-items:flex-start; gap:10px; padding:10px 6px; border-bottom:1px dashed #e5e7eb; }
  .fluxa-journey-item:last-child { border-bottom:0; }
  .fluxa-dot { width:10px; height:10px; border-radius:50%; margin-top:5px; flex-shrink:0; }
  .fluxa-badges { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
  .fluxa-badge { background:#eef2ff; color:#1e3a8a; border:1px solid #dbe3ff; padding:2px 6px; border-radius:999px; font-size:11px; }
  .fluxa-url { color:#2563eb; text-decoration:none; word-break:break-all; }
  .fluxa-muted { color:#6b7280; font-size:11px; }
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

  <div class="fluxa-layout">
    <div class="fluxa-main">
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
    </div>
    <aside class="fluxa-aside">
      <div class="fluxa-card">
        <h3><?php esc_html_e('Customer Journey', 'wp-fluxa-ecommerce-assistant'); ?></h3>
        <div class="fluxa-journey">
          <?php if (empty($journey_events)): ?>
            <div class="fluxa-journey-item"><span class="fluxa-muted"><?php esc_html_e('No journey data yet for this conversation.', 'wp-fluxa-ecommerce-assistant'); ?></span></div>
          <?php else:
            // Map event type to colors and labels
            $labels = array(
              'page_view' => __('Page View','wp-fluxa-ecommerce-assistant'),
              'category_view' => __('Category View','wp-fluxa-ecommerce-assistant'),
              'product_impression' => __('Product Seen','wp-fluxa-ecommerce-assistant'),
              'product_click' => __('Product Click','wp-fluxa-ecommerce-assistant'),
              'product_view' => __('Product View','wp-fluxa-ecommerce-assistant'),
              'add_to_cart' => __('Add to Cart','wp-fluxa-ecommerce-assistant'),
              'remove_from_cart' => __('Remove from Cart','wp-fluxa-ecommerce-assistant'),
              'update_cart_qty' => __('Update Cart Qty','wp-fluxa-ecommerce-assistant'),
              'cart_view' => __('Cart View','wp-fluxa-ecommerce-assistant'),
              'begin_checkout' => __('Begin Checkout','wp-fluxa-ecommerce-assistant'),
              'order_created' => __('Order Created','wp-fluxa-ecommerce-assistant'),
              'payment_complete' => __('Payment Complete','wp-fluxa-ecommerce-assistant'),
              'order_status_changed' => __('Order Status','wp-fluxa-ecommerce-assistant'),
              'order_refunded' => __('Order Refunded','wp-fluxa-ecommerce-assistant'),
              'thank_you_view' => __('Thank You View','wp-fluxa-ecommerce-assistant'),
              'search' => __('Search','wp-fluxa-ecommerce-assistant'),
              'filter_apply' => __('Filter Applied','wp-fluxa-ecommerce-assistant'),
              'sort_apply' => __('Sort Applied','wp-fluxa-ecommerce-assistant'),
              'pagination' => __('Pagination','wp-fluxa-ecommerce-assistant'),
              'campaign_landing' => __('Campaign Landing','wp-fluxa-ecommerce-assistant'),
              'js_error' => __('JS Error','wp-fluxa-ecommerce-assistant'),
              'api_error' => __('API Error','wp-fluxa-ecommerce-assistant'),
            );
            foreach ($journey_events as $ev):
              $et = (string)$ev['event_type'];
              $label = isset($labels[$et]) ? $labels[$et] : ucfirst(str_replace('_',' ', $et));
              $ts = $ev['event_time'];
              $url = (string)($ev['url'] ?? '');
              $ref = (string)($ev['referer'] ?? '');
              $dot = '#4F46E5';
              if (in_array($et, array('add_to_cart','order_created','payment_complete','thank_you_view','begin_checkout'), true)) { $dot = '#16a34a'; }
              if (in_array($et, array('js_error','api_error','payment_failed'), true)) { $dot = '#dc2626'; }
              if (in_array($et, array('product_impression','product_click','product_view','page_view','category_view'), true)) { $dot = '#0ea5e9'; }
              // decode optional json_payload
              $extra = array();
              if (!empty($ev['json_payload'])) {
                $decoded = json_decode($ev['json_payload'], true);
                if (is_array($decoded)) { $extra = $decoded; }
              }
          ?>
          <div class="fluxa-journey-item">
            <div class="fluxa-dot" style="background: <?php echo esc_attr($dot); ?>;"></div>
            <div style="flex:1; min-width:0;">
              <div style="display:flex; justify-content:space-between; gap:8px;">
                <strong><?php echo esc_html($label); ?></strong>
                <span class="fluxa-muted"><?php echo esc_html(date_i18n('Y-m-d H:i', strtotime($ts))); ?></span>
              </div>
              <?php if ($url): ?>
                <div><a class="fluxa-url" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($url); ?></a></div>
              <?php endif; ?>
              <?php if (false && $ref): ?>
                <div class="fluxa-muted">↳ <?php echo esc_html($ref); ?></div>
              <?php endif; ?>
              <div class="fluxa-badges">
                <?php if (!empty($ev['product_id'])): ?><span class="fluxa-badge">PID: <?php echo (int)$ev['product_id']; ?></span><?php endif; ?>
                <?php if (!empty($ev['variation_id'])): ?><span class="fluxa-badge">VAR: <?php echo (int)$ev['variation_id']; ?></span><?php endif; ?>
                <?php if (!empty($ev['qty'])): ?><span class="fluxa-badge">Qty: <?php echo (int)$ev['qty']; ?></span><?php endif; ?>
                <?php if (!empty($ev['price'])): ?><span class="fluxa-badge"><?php echo esc_html($ev['currency'] ?: ''); ?> <?php echo esc_html(number_format_i18n((float)$ev['price'], 2)); ?></span><?php endif; ?>
                <?php if (!empty($ev['cart_total'])): ?><span class="fluxa-badge"><?php echo esc_html($ev['currency'] ?: ''); ?> <?php echo esc_html(number_format_i18n((float)$ev['cart_total'], 2)); ?></span><?php endif; ?>
                <?php if (!empty($ev['order_id'])): ?><span class="fluxa-badge">Order #<?php echo (int)$ev['order_id']; ?></span><?php endif; ?>
                <?php if (!empty($ev['order_status'])): ?><span class="fluxa-badge">Status: <?php echo esc_html($ev['order_status']); ?></span><?php endif; ?>
                <?php foreach ($extra as $k => $v): if (is_scalar($v)): ?>
                  <span class="fluxa-badge"><?php echo esc_html(ucfirst(str_replace('_',' ', $k))); ?>: <?php echo esc_html((string)$v); ?></span>
                <?php endif; endforeach; ?>
              </div>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </aside>
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
  <script>
    (function(){
      try {
        console.groupCollapsed('[Fluxa Admin] Conversation API response');
        console.log('conversation.id', <?php echo wp_json_encode($conv['id']); ?>);
        <?php if (!empty($api_error)) : ?>
        console.warn('api.error', <?php echo wp_json_encode($api_error); ?>);
        <?php endif; ?>
        <?php if (!empty($api_raw)) : ?>
        console.log('api.body', <?php echo wp_json_encode($api_raw); ?>);
        <?php endif; ?>
        console.groupEnd();
      } catch(e) {}
    })();
  </script>
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
