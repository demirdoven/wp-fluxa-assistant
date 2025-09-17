<?php
/**
 * Admin Tools for Fluxa eCommerce Assistant
 */
if (!defined('ABSPATH')) { exit; }

// Expect $stats provided by render_tools_page()
$counts = $stats['counts'] ?? array('events'=>0,'conversations'=>0);
$sizes  = $stats['sizes']  ?? array('events'=>0,'conversations'=>0);
$byType = $stats['by_type'] ?? array();

function fluxa_hsize($bytes){
    $bytes = (float)$bytes;
    if ($bytes < 1024) return $bytes . ' B';
    $units = array('KB','MB','GB','TB');
    $i = 0; $val = $bytes/1024;
    while ($val >= 1024 && $i < count($units)-1) { $val/=1024; $i++; }
    return number_format_i18n($val, $val >= 10 ? 0 : 2) . ' ' . $units[$i];
}
?>

<div class="wrap fluxa-tools">
  <h1><?php esc_html_e('Fluxa Tools', 'fluxa-ecommerce-assistant'); ?></h1>
  <?php settings_errors('fluxa_messages'); ?>

  <div class="fluxa-settings-layout" style="display:flex; gap:20px; align-items:flex-start;">
    <div class="fluxa-settings-main" style="flex:1 1 auto; min-width:0;">

      <div class="fluxa-card" style="background:#fff;border:1px solid #ccd0d4;border-radius:12px;overflow:hidden;">
        <style>
          .fluxa-stats{padding:14px}
          .fluxa-stats-grid{display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:14px}
          .fluxa-stat{
            border:1px solid #e5e7eb;border-radius:12px;padding:14px;background:linear-gradient(180deg,#ffffff 0%,#fafafa 100%);
          }
          .fluxa-stat h4{margin:0 0 6px 0;font-size:13px;color:#64748b;font-weight:600;letter-spacing:.2px}
          .fluxa-stat .val{display:flex;align-items:baseline;gap:10px}
          .fluxa-stat .val strong{font-size:22px;line-height:1.1;color:#0f172a}
          .fluxa-stat .val .sub{font-size:12px;color:#475569}
          .fluxa-meter{height:8px;background:#eef2ff;border-radius:999px;overflow:hidden;margin-top:10px}
          .fluxa-meter>span{display:block;height:100%;background:#4f46e5}
          .fluxa-badges-row{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
          .fluxa-chip{border:1px solid #e5e7eb;background:#f8fafc;color:#111827;border-radius:999px;padding:2px 8px;font-size:11px}
          .fluxa-top-types{margin-top:16px;border-top:1px dashed #e5e7eb;padding:12px 0 2px}
          .fluxa-top-types h3{margin:0 0 8px 0;font-size:13px;color:#374151}
          .fluxa-top-list{display:flex;flex-wrap:wrap;gap:8px}
          .fluxa-top-item{display:flex;align-items:center;gap:6px;border:1px solid #e5e7eb;border-radius:10px;padding:6px 8px;background:#fff}
          .fluxa-top-item code{background:#f3f4f6;border:1px solid #e5e7eb;border-radius:6px;padding:1px 6px}
          @media (max-width: 960px){.fluxa-stats-grid{grid-template-columns:1fr}}
        </style>
        <h2 style="margin:0;padding:14px;border-bottom:1px solid #e5e7eb;background:#f8fafc;display:flex;align-items:center;gap:10px">
          <span class="dashicons dashicons-chart-area" style="color:#4f46e5"></span>
          <?php esc_html_e('Database Statistics', 'fluxa-ecommerce-assistant'); ?>
        </h2>
        <?php $total_size = max(1,(int)$sizes['events'] + (int)$sizes['conversations']); ?>
        <div class="fluxa-stats">
          <div class="fluxa-stats-grid">
            <div class="fluxa-stat">
              <h4><?php esc_html_e('Events table', 'fluxa-ecommerce-assistant'); ?></h4>
              <div class="val">
                <strong><?php echo number_format_i18n((int)$counts['events']); ?></strong>
                <span class="sub"><?php echo esc_html(fluxa_hsize($sizes['events'])); ?> · <code><?php echo esc_html($GLOBALS['wpdb']->prefix . 'fluxa_conv_events'); ?></code></span>
              </div>
              <?php $p = min(100, round(((int)$sizes['events']/$total_size)*100)); ?>
              <div class="fluxa-meter" title="<?php echo esc_attr($p.'% of tracked table size'); ?>"><span style="width: <?php echo esc_attr($p); ?>%"></span></div>
            </div>
            <div class="fluxa-stat">
              <h4><?php esc_html_e('Conversations table', 'fluxa-ecommerce-assistant'); ?></h4>
              <div class="val">
                <strong><?php echo number_format_i18n((int)$counts['conversations']); ?></strong>
                <span class="sub"><?php echo esc_html(fluxa_hsize($sizes['conversations'])); ?> · <code><?php echo esc_html($GLOBALS['wpdb']->prefix . 'fluxa_conv'); ?></code></span>
              </div>
              <?php $p2 = min(100, round(((int)$sizes['conversations']/$total_size)*100)); ?>
              <div class="fluxa-meter" title="<?php echo esc_attr($p2.'% of tracked table size'); ?>"><span style="width: <?php echo esc_attr($p2); ?>%"></span></div>
            </div>
          </div>

          <?php if (!empty($byType)): ?>
          <div class="fluxa-top-types">
            <h3><?php esc_html_e('Top Event Types', 'fluxa-ecommerce-assistant'); ?></h3>
            <div class="fluxa-top-list">
              <?php
                $etype_labels = array(
                  'page_view' => __('Page View','fluxa-ecommerce-assistant'),
                  'category_view' => __('Category View','fluxa-ecommerce-assistant'),
                  'product_impression' => __('Product Seen','fluxa-ecommerce-assistant'),
                  'product_click' => __('Product Click','fluxa-ecommerce-assistant'),
                  'product_view' => __('Product View','fluxa-ecommerce-assistant'),
                  'add_to_cart' => __('Add to Cart','fluxa-ecommerce-assistant'),
                  'remove_from_cart' => __('Remove from Cart','fluxa-ecommerce-assistant'),
                  'update_cart_qty' => __('Update Cart Qty','fluxa-ecommerce-assistant'),
                  'cart_view' => __('Cart View','fluxa-ecommerce-assistant'),
                  'begin_checkout' => __('Begin Checkout','fluxa-ecommerce-assistant'),
                  'order_created' => __('Order Created','fluxa-ecommerce-assistant'),
                  'payment_complete' => __('Payment Complete','fluxa-ecommerce-assistant'),
                  'order_status_changed' => __('Order Status','fluxa-ecommerce-assistant'),
                  'order_refunded' => __('Order Refunded','fluxa-ecommerce-assistant'),
                  'thank_you_view' => __('Thank You View','fluxa-ecommerce-assistant'),
                  'search' => __('Search','fluxa-ecommerce-assistant'),
                  'filter_apply' => __('Filter Applied','fluxa-ecommerce-assistant'),
                  'sort_apply' => __('Sort Applied','fluxa-ecommerce-assistant'),
                  'pagination' => __('Pagination','fluxa-ecommerce-assistant'),
                  'campaign_landing' => __('Campaign Landing','fluxa-ecommerce-assistant'),
                  'js_error' => __('JS Error','fluxa-ecommerce-assistant'),
                  'api_error' => __('API Error','fluxa-ecommerce-assistant'),
                );
                foreach ($byType as $row):
                  $k = isset($row['event_type']) ? (string)$row['event_type'] : '';
                  $pretty = isset($etype_labels[$k]) ? $etype_labels[$k] : ucwords(str_replace('_',' ', $k));
              ?>
                <div class="fluxa-top-item">
                  <code title="<?php echo esc_attr($k); ?>"><?php echo esc_html($pretty); ?></code>
                  <span>·</span>
                  <span><?php echo number_format_i18n((int)$row['c']); ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="fluxa-card" style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;overflow:hidden;margin-top:18px;">
        <h2 style="margin:0;padding:12px 14px;border-bottom:1px solid #e5e7eb;background:#f8fafc;">
          <?php esc_html_e('Maintenance', 'fluxa-ecommerce-assistant'); ?>
        </h2>
        <div style="padding: 12px 14px; display:grid; gap:16px;">
          <form method="post" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <?php wp_nonce_field('fluxa_tools_action'); ?>
            <input type="hidden" name="action_type" value="purge_old">
            <label>
              <?php esc_html_e('Delete events older than', 'fluxa-ecommerce-assistant'); ?>
              <input type="number" class="small-text" name="older_than_days" min="1" value="30">
              <?php esc_html_e('days', 'fluxa-ecommerce-assistant'); ?>
            </label>
            <button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete old events?', 'fluxa-ecommerce-assistant')); ?>');">
              <?php esc_html_e('Purge old events', 'fluxa-ecommerce-assistant'); ?>
            </button>
          </form>

          <form method="post" style="display:flex; gap:10px; align-items:center;">
            <?php wp_nonce_field('fluxa_tools_action'); ?>
            <input type="hidden" name="action_type" value="purge_all">
            <button type="submit" class="button button-secondary" style="color:#b91c1c;border-color:#b91c1c;" onclick="return confirm('<?php echo esc_js(__('This will delete ALL events. Continue?', 'fluxa-ecommerce-assistant')); ?>');">
              <?php esc_html_e('Delete ALL events (truncate)', 'fluxa-ecommerce-assistant'); ?>
            </button>
          </form>

          <form method="post" style="display:flex; gap:10px; align-items:center;">
            <?php wp_nonce_field('fluxa_tools_action'); ?>
            <input type="hidden" name="action_type" value="optimize">
            <button type="submit" class="button button-secondary">
              <?php esc_html_e('Optimize tables', 'fluxa-ecommerce-assistant'); ?>
            </button>
            <span class="description"><?php esc_html_e('Reclaims space and updates table statistics for better performance.', 'fluxa-ecommerce-assistant'); ?></span>
          </form>
        </div>
      </div>

      <div class="fluxa-card" style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;overflow:hidden;margin-top:18px;">
        <h2 style="margin:0;padding:12px 14px;border-bottom:1px solid #e5e7eb;background:#f8fafc;">
          <?php esc_html_e('Tips', 'fluxa-ecommerce-assistant'); ?>
        </h2>
        <div style="padding: 12px 14px;">
          <ul style="margin:0; list-style: disc inside;">
            <li><?php esc_html_e('Use Behaviour settings to disable tracking events you don’t need.', 'fluxa-ecommerce-assistant'); ?></li>
            <li><?php esc_html_e('Regularly purge old events to keep the database lean.', 'fluxa-ecommerce-assistant'); ?></li>
            <li><?php esc_html_e('Use Optimize after large deletions for best performance.', 'fluxa-ecommerce-assistant'); ?></li>
          </ul>
        </div>
      </div>

    </div>
  </div>
</div>
