<?php
if (!defined('ABSPATH')) { exit; }

// Mock conversations data (frontend only; replace with API later)
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

$conversations = fluxa_mock_conversations();
$page_url = admin_url('admin.php?page=fluxa-assistant-chat');
?>

<div class="wrap">
  <h1><?php esc_html_e('Chat History', 'wp-fluxa-ecommerce-assistant'); ?></h1>

  <div class="fluxa-card" style="margin-top:16px;">
    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; justify-content:space-between;">
      <p class="description" style="margin:0;">
        <?php esc_html_e('Below are recent conversations. Click a row to view the full thread.', 'wp-fluxa-ecommerce-assistant'); ?>
      </p>
      <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
        <input type="search" id="fluxa-chat-search" placeholder="<?php echo esc_attr__('Search text…', 'wp-fluxa-ecommerce-assistant'); ?>" class="regular-text" />
        <label style="display:inline-block;">
          <span style="display:block; font-size:12px; color:#555;"><?php esc_html_e('Min total', 'wp-fluxa-ecommerce-assistant'); ?></span>
          <input type="number" id="fluxa-min-total" class="small-text" min="0" step="1" />
        </label>
        <label style="display:inline-block;">
          <span style="display:block; font-size:12px; color:#555;"><?php esc_html_e('Min agent replies', 'wp-fluxa-ecommerce-assistant'); ?></span>
          <input type="number" id="fluxa-min-agent" class="small-text" min="0" step="1" />
        </label>
        <label style="display:inline-block;">
          <span style="display:block; font-size:12px; color:#555;"><?php esc_html_e('Start date', 'wp-fluxa-ecommerce-assistant'); ?></span>
          <input type="date" id="fluxa-date-start" />
        </label>
        <label style="display:inline-block;">
          <span style="display:block; font-size:12px; color:#555;"><?php esc_html_e('End date', 'wp-fluxa-ecommerce-assistant'); ?></span>
          <input type="date" id="fluxa-date-end" />
        </label>
      </div>
    </div>

    <table id="fluxa-chat-table" class="widefat fixed striped" style="margin-top:12px;">
      <thead>
        <tr>
          <th style="width:120px;"><?php esc_html_e('Conversation', 'wp-fluxa-ecommerce-assistant'); ?></th>
          <th><?php esc_html_e('First message', 'wp-fluxa-ecommerce-assistant'); ?></th>
          <th><?php esc_html_e('Latest message', 'wp-fluxa-ecommerce-assistant'); ?></th>
          <th style="width:120px; text-align:right;"><?php esc_html_e('All messages', 'wp-fluxa-ecommerce-assistant'); ?></th>
          <th style="width:140px; text-align:right;"><?php esc_html_e('Agent replies', 'wp-fluxa-ecommerce-assistant'); ?></th>
          <th style="width:160px;"><?php esc_html_e('Updated', 'wp-fluxa-ecommerce-assistant'); ?></th>
        </tr>
      </thead>
      <tbody id="fluxa-chat-tbody">
        <?php foreach ($conversations as $conv):
            $msgs = $conv['messages'];
            $first = $msgs[0];
            $last = $msgs[count($msgs)-1];
            $agent_replies = array_reduce($msgs, function($c, $m){ return $c + ($m['role'] === 'assistant' ? 1 : 0); }, 0);
            $view_url = add_query_arg(array('page' => 'fluxa-assistant-chat', 'conversation' => $conv['id']), admin_url('admin.php'));
        ?>
          <tr class="fluxa-chat-row" data-search-text="<?php echo esc_attr(wp_json_encode($msgs)); ?>" data-href="<?php echo esc_url($view_url); ?>" style="cursor:pointer;">
            <td><a href="<?php echo esc_url($view_url); ?>"><strong>#<?php echo esc_html($conv['id']); ?></strong></a></td>
            <td><?php echo esc_html(wp_trim_words(wp_strip_all_tags($first['content']), 12, '…')); ?></td>
            <td><?php echo esc_html(wp_trim_words(wp_strip_all_tags($last['content']), 12, '…')); ?></td>
            <td style="text-align:right;">&nbsp;<span class="fluxa-total" data-total="<?php echo esc_attr(count($msgs)); ?>"><?php echo esc_html(number_format_i18n(count($msgs))); ?></span></td>
            <td style="text-align:right;">&nbsp;<span class="fluxa-agent" data-agent="<?php echo esc_attr($agent_replies); ?>"><?php echo esc_html(number_format_i18n($agent_replies)); ?></span></td>
            <td data-order="<?php echo esc_attr($last['time']); ?>"><?php echo esc_html(date_i18n('Y-m-d H:i', $last['time'])); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- DataTables initialization moved to enqueue step to ensure proper load order -->
