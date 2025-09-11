<?php
if (!defined('ABSPATH')) { exit; }

$page_url = admin_url('admin.php?page=fluxa-assistant-chat');
$rest_url = esc_url_raw( rest_url('fluxa/v1/admin/conversations') );
$labels_url = esc_url_raw( rest_url('fluxa/v1/admin/uuid-labels') );
$rest_nonce = wp_create_nonce('wp_rest');
?>

<div class="wrap">
  <h1><?php esc_html_e('Chat History', 'wp-fluxa-ecommerce-assistant'); ?></h1>

  <div class="fluxa-card" style="margin-top:16px;">
    <style>
      /* Table polish */
      #fluxa-chat-table { border-radius: 10px; overflow: hidden; }
      #fluxa-chat-table thead th { background: #f8fafc; border-bottom: 1px solid #e5e7eb; font-weight: 600; }
      #fluxa-chat-table.widefat tr:hover td { background: #f9fbff; }
      #fluxa-chat-table td, #fluxa-chat-table th { vertical-align: middle; }
      .fluxa-conv-cell { display:flex; align-items:center; gap:10px; }
      .fluxa-conv-title { line-height:1.2; }
      .fluxa-conv-title strong { font-size:13px; color:#0f172a; }
      .fluxa-chip { display:inline-flex; align-items:center; gap:6px; background:#f1f5f9; border:1px solid #e2e8f0; color:#0f172a; padding:2px 8px; border-radius:999px; font-size:11px; }
      .fluxa-cell-sub { color:#6b7280; font-size:11px; margin-top:2px; }
      .fluxa-num { font-variant-numeric: tabular-nums; }
      .fluxa-right { text-align:right; }
      /* External loader */
      #fluxa-chat-loading { display:flex; align-items:center; gap:10px; color:#334155; padding:10px 0; }
      .fluxa-loader { display:inline-flex; gap:6px; align-items:flex-end; }
      .fluxa-loader span { display:block; width:6px; height:6px; background:#6366F1; border-radius:2px; animation:flxbar 900ms ease-in-out infinite; }
      .fluxa-loader span:nth-child(2){ animation-delay: 100ms; background:#22c55e; }
      .fluxa-loader span:nth-child(3){ animation-delay: 200ms; background:#f59e0b; }
      .fluxa-loader span:nth-child(4){ animation-delay: 300ms; background:#ec4899; }
      .fluxa-loader span:nth-child(5){ animation-delay: 400ms; background:#06b6d4; }
      @keyframes flxbar { 0%,100% { height:6px; opacity:.6; } 50% { height:16px; opacity:1; } }
      /* Fade-in rows */
      .fluxa-fade-in { animation: flxFade 180ms ease-out; }
      @keyframes flxFade { from {opacity:0; transform: translateY(2px);} to { opacity:1; transform: translateY(0);} }
      /* Header badge */
      .fluxa-count-badge { display:inline-flex; align-items:center; gap:6px; margin-left:10px; font-size:12px; color:#334155; background:#eef2ff; border:1px solid #e0e7ff; padding:2px 8px; border-radius:999px; }
    </style>
    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; justify-content:space-between;">
      <div style="display:flex; align-items:center; gap:8px;">
        <p class="description" style="margin:0;">
          <?php esc_html_e('Below are recent conversations. Click a row to view the full thread.', 'wp-fluxa-ecommerce-assistant'); ?>
        </p>
        <span id="fluxa-count" class="fluxa-count-badge" style="display:none;">
          <span class="dashicons dashicons-admin-comments"></span>
          <span><strong id="fluxa-count-num">0</strong> <?php esc_html_e('conversations', 'wp-fluxa-ecommerce-assistant'); ?></span>
        </span>
      </div>
      <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
        <input type="search" id="fluxa-chat-search" placeholder="<?php echo esc_attr__('Search text…', 'wp-fluxa-ecommerce-assistant'); ?>" class="regular-text" />
        <label style="display:inline-block;">
          <span style="display:block; font-size:12px; color:#555;">&nbsp;<?php esc_html_e('Min total', 'wp-fluxa-ecommerce-assistant'); ?></span>
          <input type="number" id="fluxa-min-total" class="small-text" min="0" step="1" />
        </label>
        <label style="display:inline-block;">
          <span style="display:block; font-size:12px; color:#555;">&nbsp;<?php esc_html_e('Min agent replies', 'wp-fluxa-ecommerce-assistant'); ?></span>
          <input type="number" id="fluxa-min-agent" class="small-text" min="0" step="1" />
        </label>
        <label style="display:inline-block;">
          <span style="display:block; font-size:12px; color:#555;">&nbsp;<?php esc_html_e('Start date', 'wp-fluxa-ecommerce-assistant'); ?></span>
          <input type="date" id="fluxa-date-start" />
        </label>
        <label style="display:inline-block;">
          <span style="display:block; font-size:12px; color:#555;">&nbsp;<?php esc_html_e('End date', 'wp-fluxa-ecommerce-assistant'); ?></span>
          <input type="date" id="fluxa-date-end" />
        </label>
        <button type="button" class="button" id="fluxa-clear-filters"><?php esc_html_e('Clear', 'wp-fluxa-ecommerce-assistant'); ?></button>
      </div>
    </div>

    <div id="fluxa-chat-loading" style="margin:8px 0;">
      <div class="fluxa-loader" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>
      <em style="font-weight:600; letter-spacing:.2px;"><?php esc_html_e('Loading conversations…', 'wp-fluxa-ecommerce-assistant'); ?></em>
    </div>

    <table id="fluxa-chat-table" class="widefat fixed striped" style="margin-top:12px;">
      <thead>
        <tr>
          <th style="width:120px;"><?php esc_html_e('Conversation', 'wp-fluxa-ecommerce-assistant'); ?></th>
          <th><?php esc_html_e('First message', 'wp-fluxa-ecommerce-assistant'); ?></th>
          <th><?php esc_html_e('Latest message', 'wp-fluxa-ecommerce-assistant'); ?></th>
          <th style="width:120px;" class="fluxa-right"><?php esc_html_e('All messages', 'wp-fluxa-ecommerce-assistant'); ?></th>
          <th style="width:140px;" class="fluxa-right"><?php esc_html_e('Agent replies', 'wp-fluxa-ecommerce-assistant'); ?></th>
          <th style="width:160px;"><?php esc_html_e('Updated', 'wp-fluxa-ecommerce-assistant'); ?></th>
        </tr>
      </thead>
      <tbody id="fluxa-chat-tbody"></tbody>
    </table>
  </div>
</div>

<script>
(function(){
  const tableBody = document.getElementById('fluxa-chat-tbody');
  const loadingEl = document.getElementById('fluxa-chat-loading');
  const restUrl = <?php echo wp_json_encode($rest_url); ?>;
  const labelsUrl = <?php echo wp_json_encode($labels_url); ?>;
  const restNonce = <?php echo wp_json_encode($rest_nonce); ?>;
  function fmt(ts){
    if (!ts) return '—';
    try {
      const d = new Date(ts);
      if (!isNaN(d.getTime())) {
        // Render as yyyy-mm-dd hh:mm (local)
        const pad = n => String(n).padStart(2,'0');
        return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
      }
    } catch(e) {}
    return '—';
  }
  function escapeHtml(s){
    const div = document.createElement('div');
    div.innerText = s == null ? '' : String(s);
    return div.innerHTML;
  }
  function toDate(value){
    if (!value) return null;
    const d = new Date(value);
    return isNaN(d.getTime()) ? null : d;
  }

  let allItems = [];
  function applyFilters(){
    const q = (document.getElementById('fluxa-chat-search').value || '').toLowerCase().trim();
    const minTotal = parseInt(document.getElementById('fluxa-min-total').value || '0', 10) || 0;
    const minAgent = parseInt(document.getElementById('fluxa-min-agent').value || '0', 10) || 0;
    const ds = toDate(document.getElementById('fluxa-date-start').value);
    const de = toDate(document.getElementById('fluxa-date-end').value);
    const filtered = allItems.filter(it => {
      const total = Number(it.messageCount || 0);
      const agent = Number(it.replicaReplyCount || 0);
      if (total < minTotal) return false;
      if (agent < minAgent) return false;
      // Date range
      const lastAt = it.lastMessageAt ? new Date(it.lastMessageAt) : null;
      if (ds && lastAt && lastAt < ds) return false;
      if (de && lastAt && lastAt > new Date(de.getFullYear(), de.getMonth(), de.getDate(), 23,59,59)) return false;
      // Text
      if (q) {
        const hay = ((it.conversationName||'') + ' ' + (it.source||'') + ' ' + (it.conversationType||'') + ' ' + (it.uuid||'')).toLowerCase();
        if (hay.indexOf(q) === -1) return false;
      }
      return true;
    });
    renderRows(filtered, currentLabels);
    const badge = document.getElementById('fluxa-count');
    const num = document.getElementById('fluxa-count-num');
    if (badge && num) { num.textContent = String(filtered.length); badge.style.display = 'inline-flex'; }
  }

  function renderRows(items, labels){
    if (!items.length) {
      tableBody.innerHTML = '<tr class="fluxa-fade-in"><td colspan="6"><em><?php echo esc_js(__('No conversations found.', 'wp-fluxa-ecommerce-assistant')); ?></em></td></tr>';
      return;
    }
    const rows = items.map(it => {
      const uuid = String(it.uuid || '');
      const name = String(it.conversationName || '').trim();
      const source = String(it.source || '');
      const type = String(it.conversationType || '');
      const firstAt = it.firstMessageAt || '';
      const lastAt = it.lastMessageAt || '';
      const msgCount = Number(it.messageCount || 0);
      const repCount = Number(it.replicaReplyCount || 0);
      const viewUrl = <?php echo wp_json_encode( admin_url('admin.php?page=fluxa-assistant-chat&conversation=') ); ?> + encodeURIComponent(uuid);
      const label = labels && labels[uuid] ? labels[uuid] : 'Guest';
      const displayName = escapeHtml(label);
      return (
        '<tr class="fluxa-chat-row fluxa-fade-in" data-href="' + viewUrl + '" style="cursor:pointer;">' +
          '<td>' +
            '<div class="fluxa-conv-cell">' +
              '<div class="fluxa-conv-title">' +
                '<a href="' + viewUrl + '"><strong>' + uuid + '</strong></a>' +
              '</div>' +
            '</div>' +
          '</td>' +
          '<td>' + escapeHtml(fmt(firstAt)) + '</td>' +
          '<td>' + escapeHtml(fmt(lastAt)) + '</td>' +
          '<td class="fluxa-right"><span class="fluxa-num fluxa-total" data-total="' + msgCount + '">' + msgCount.toLocaleString() + '</span></td>' +
          '<td class="fluxa-right"><span class="fluxa-num fluxa-agent" data-agent="' + repCount + '">' + repCount.toLocaleString() + '</span></td>' +
          '<td data-order="' + (Date.parse(lastAt)||'') + '">' + escapeHtml(fmt(lastAt)) + '</td>' +
        '</tr>'
      );
    });
    tableBody.innerHTML = rows.join('');
  }
  fetch(restUrl, { headers: { 'X-WP-Nonce': restNonce } })
    .then(r => r.json())
    .then(data => {
      if (loadingEl) loadingEl.style.display = 'none';
      tableBody.innerHTML = '';
      if (!data || !data.ok) {
        tableBody.innerHTML = '<tr><td colspan="6"><div class="notice notice-error" style="margin:0;"><p>' + escapeHtml((data && (data.error || (data.body && data.body.error))) || 'Failed to load conversations') + '</p></div></td></tr>';
        return;
      }
      allItems = Array.isArray(data.items) ? data.items : [];
      // Resolve UUID labels for users
      const uuids = allItems.map(it => String(it.uuid||'')).filter(Boolean);
      const url = labelsUrl + '?uuids=' + encodeURIComponent(uuids.join(','));
      fetch(url, { headers: { 'X-WP-Nonce': restNonce } })
        .then(r => r.json())
        .then(lbl => {
          const labels = (lbl && lbl.ok && lbl.labels) ? lbl.labels : {};
          // Render with filters (which call renderRows internally)
          applyFiltersWithLabels(labels);
          tableBody.addEventListener('click', function(e){
            const tr = e.target.closest('tr.fluxa-chat-row');
            if (tr && tr.dataset.href) { window.location = tr.dataset.href; }
          });
        })
        .catch(() => {
          applyFiltersWithLabels({});
        });
    })
    .catch(err => {
      if (loadingEl) loadingEl.style.display = 'none';
      tableBody.innerHTML = '<tr><td colspan="6"><div class="notice notice-error" style="margin:0;"><p>Network error loading conversations</p></div></td></tr>';
      console.error('Fluxa admin conversations load error', err);
    });

  // Filters wiring
  let currentLabels = {};
  function applyFiltersWithLabels(labels){ currentLabels = labels || {}; applyFilters(); }

  ['fluxa-chat-search','fluxa-min-total','fluxa-min-agent','fluxa-date-start','fluxa-date-end'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function(){ applyFilters(); });
    el.addEventListener('change', function(){ applyFilters(); });
  });
  const clearBtn = document.getElementById('fluxa-clear-filters');
  if (clearBtn) {
    clearBtn.addEventListener('click', function(){
      document.getElementById('fluxa-chat-search').value = '';
      document.getElementById('fluxa-min-total').value = '';
      document.getElementById('fluxa-min-agent').value = '';
      document.getElementById('fluxa-date-start').value = '';
      document.getElementById('fluxa-date-end').value = '';
      applyFilters();
    });
  }
})();
</script>

<!-- DataTables initialization moved to enqueue step to ensure proper load order -->
