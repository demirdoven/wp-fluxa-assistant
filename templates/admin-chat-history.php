<?php
if (!defined('ABSPATH')) { exit; }

$page_url = admin_url('admin.php?page=fluxa-assistant-chat');
$rest_url = esc_url_raw( rest_url('fluxa/v1/admin/conversations') );
$labels_url = esc_url_raw( rest_url('fluxa/v1/admin/uuid-labels') );
$rest_nonce = wp_create_nonce('wp_rest');
?>

<script>
  jQuery(function($){
    $('input[type="text"]').attr('autocomplete','off');
  });
  </script>

<div class="wrap">
  <h1><?php esc_html_e('Chat History', 'wp-fluxa-ecommerce-assistant'); ?></h1>

  <!-- Chat list view uses the new loader and table below -->

  <div class="fluxa-card" style="margin-top:16px; background: unset;">
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
      .fluxa-center { text-align:center; }
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
      /* Minimalist status dot */
      .fluxa-status-dot { display:inline-block; width:10px; height:10px; min-width:10px; min-height:10px; border-radius:50%; vertical-align:middle; }
      .fluxa-status-dot.is-online { background:#16a34a; }
      .fluxa-status-dot.is-offline { background:#9ca3af; }
      .fluxa-status-cell { display:flex; flex-direction:column; align-items:center; gap:4px; }
      .fluxa-status-text { font-size:9px; line-height:1; color:#6b7280; }
      .fluxa-ua-text { font-size:10px; line-height:1; }
      /* Hide DataTables chrome: length, info and pagination */
      .dataTables_length,
      .dataTables_info,
      .dataTables_paginate { display: none !important; }
      /* Hide DataTables empty row/text */
      #fluxa-chat-table td.dataTables_empty { display:none !important; padding:0 !important; border:0 !important; height:0 !important; line-height:0 !important; font-size:0 !important; }
      #fluxa-chat-table tr:has(td.dataTables_empty) { display:none !important; }
      /* Full-page loading overlay with centered spinner */
      #fluxa-chat-loading {
        position: fixed !important;
        inset: 0 !important;
        margin: 0 !important;
        background: rgba(15, 23, 42, 0.22); /* dim the page */
        display: flex; /* JS sets to '' to show; sets to 'none' to hide */
        align-items: center;
        justify-content: center;
        z-index: 99999;
      }
      /* Hide existing colorful bars and text inside loader */
      #fluxa-chat-loading .fluxa-loader,
      #fluxa-chat-loading em { display: none !important; }
      /* Center spinner */
      #fluxa-chat-loading::after {
        content: '';
        width: 38px;
        height: 38px;
        border-radius: 50%;
        border: 3px solid #e5e7eb; /* slate-200 */
        border-top-color: #6366F1; /* indigo-500 */
        animation: flxspin 0.9s linear infinite;
        box-shadow: 0 0 0 4px rgba(255,255,255,0.6), 0 0 30px rgba(0,0,0,0.15);
      }
      @keyframes flxspin { to { transform: rotate(360deg); } }
    </style>
    <div style="background: #ffffff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04); border-radius: 10px; margin-top: 16px; padding: 2em; flex-wrap: wrap; align-items: flex-end; justify-content: space-between;">
      <div style="display:flex; align-items:center; gap:8px;margin-bottom: 10px;">
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
          <span style="display:block; font-size:12px; color:#555;margin-bottom: 5px;">&nbsp;<?php esc_html_e('Page size', 'wp-fluxa-ecommerce-assistant'); ?></span>
          <select id="fluxa-page-size">
            <option value="10">10</option>
            <option value="24" selected>24</option>
            <option value="50">50</option>
            <option value="100">100</option>
          </select>
        </label>
        <label style="display:inline-block;">
          <span style="display:block; font-size:12px; color:#555;margin-bottom: 5px;">&nbsp;<?php esc_html_e('Sort by', 'wp-fluxa-ecommerce-assistant'); ?></span>
          <select id="fluxa-sort-by">
            <option value="lastReplicaReplyAt" selected><?php esc_html_e('Last reply (agent)', 'wp-fluxa-ecommerce-assistant'); ?></option>
            <option value="firstMessageAt"><?php esc_html_e('First message', 'wp-fluxa-ecommerce-assistant'); ?></option>
            <option value="replicaReplies"><?php esc_html_e('Agent replies', 'wp-fluxa-ecommerce-assistant'); ?></option>
          </select>
        </label>
        <label style="display:inline-block;">
          <span style="display:block; font-size:12px; color:#555;margin-bottom: 5px;">&nbsp;<?php esc_html_e('Order', 'wp-fluxa-ecommerce-assistant'); ?></span>
          <select id="fluxa-sort-order">
            <option value="desc" selected><?php esc_html_e('Desc', 'wp-fluxa-ecommerce-assistant'); ?></option>
            <option value="asc"><?php esc_html_e('Asc', 'wp-fluxa-ecommerce-assistant'); ?></option>
          </select>
        </label>
        <label style="display:inline-block;">
          <span style="display:block; font-size:12px; color:#555;margin-bottom: 5px;">&nbsp;<?php esc_html_e('Min total', 'wp-fluxa-ecommerce-assistant'); ?></span>
          <input type="number" id="fluxa-min-total" class="small-text" min="0" step="1" />
        </label>
        <label style="display:inline-block;">
          <span style="display:block; font-size:12px; color:#555;margin-bottom: 5px;">&nbsp;<?php esc_html_e('Min agent replies', 'wp-fluxa-ecommerce-assistant'); ?></span>
          <input type="number" id="fluxa-min-agent" class="small-text" min="0" step="1" />
        </label>
        <label style="display:inline-block;">
          <span style="display:block; font-size:12px; color:#555;margin-bottom: 5px;">&nbsp;<?php esc_html_e('Start date', 'wp-fluxa-ecommerce-assistant'); ?></span>
          <input type="date" id="fluxa-date-start" />
        </label>
        <label style="display:inline-block;">
          <span style="display:block; font-size:12px; color:#555;margin-bottom: 5px;">&nbsp;<?php esc_html_e('End date', 'wp-fluxa-ecommerce-assistant'); ?></span>
          <input type="date" id="fluxa-date-end" />
        </label>
        <button type="button" class="button" id="fluxa-clear-filters"><?php esc_html_e('Clear', 'wp-fluxa-ecommerce-assistant'); ?></button>
        <div id="fluxa-pager" style="display:flex; gap:6px; align-items:center; margin-left:auto;">
          <button type="button" class="button" id="fluxa-prev" disabled>&larr;</button>
          <span id="fluxa-page-indicator" class="description"><?php esc_html_e('Page', 'wp-fluxa-ecommerce-assistant'); ?> <strong>1</strong></span>
          <button type="button" class="button" id="fluxa-next" disabled>&rarr;</button>
        </div>
      </div>
    </div>

    <div id="fluxa-chat-loading" style="margin:8px 0;">
      <div class="fluxa-loader" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>
      <em style="font-weight:600; letter-spacing:.2px;"><?php esc_html_e('Loading conversations…', 'wp-fluxa-ecommerce-assistant'); ?></em>
    </div>

    <table id="fluxa-chat-table" class="widefat fixed striped" style="margin-top:12px;">
      <thead>
        <tr>
          <th style="width:42px; text-align:center;" aria-label="Status"></th>
          <th style="width:120px;"><?php esc_html_e('Conversation', 'wp-fluxa-ecommerce-assistant'); ?></th>
          <th><?php esc_html_e('First message', 'wp-fluxa-ecommerce-assistant'); ?></th>
          <th><?php esc_html_e('Latest message', 'wp-fluxa-ecommerce-assistant'); ?></th>
          <th style="width:120px;" class="fluxa-right"><?php esc_html_e('All messages', 'wp-fluxa-ecommerce-assistant'); ?></th>
          <th style="width:140px;" class="fluxa-right"><?php esc_html_e('Agent replies', 'wp-fluxa-ecommerce-assistant'); ?></th>
          <th style="min-width:200px;"><?php esc_html_e('User Agent', 'wp-fluxa-ecommerce-assistant'); ?></th>
          <th style="width:160px;"><?php esc_html_e('Updated', 'wp-fluxa-ecommerce-assistant'); ?></th>
        </tr>
      </thead>
      <tbody id="fluxa-chat-tbody"></tbody>
    </table>
    <div id="fluxa-api-debug" style="margin-top:18px;">
      <details>
        <summary style="cursor:pointer; font-weight:600;">Full API response (debug)</summary>
        <div style="margin-top:10px; background:#0f172a; color:#e2e8f0; border-radius:6px; overflow:auto;">
          <pre id="fluxa-api-pre" style="margin:0; padding:12px; white-space:pre;"> </pre>
        </div>
      </details>
    </div>
  </div>
</div>

<script>
(function(){
  const tableBody = document.getElementById('fluxa-chat-tbody');
  const loadingEl = document.getElementById('fluxa-chat-loading');
  const restUrlBase = <?php echo wp_json_encode($rest_url); ?>;
  const labelsUrl = <?php echo wp_json_encode($labels_url); ?>;
  const lastSeenUrl = <?php echo wp_json_encode( esc_url_raw( rest_url('fluxa/v1/admin/last-seen') ) ); ?>;
  const restNonce = <?php echo wp_json_encode($rest_nonce); ?>;
  function fmt(ts){
    if (!ts) return '—';
    try {
      // Normalize MySQL datetime 'YYYY-MM-DD HH:MM:SS' to ISO-like for safe parsing
      let s = ts;
      if (typeof ts === 'string' && ts.indexOf('T') === -1 && ts.indexOf(' ') > 0) {
        s = ts.replace(' ', 'T');
      }
      const d = new Date(s);
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
  let currentPage = 1;
  let totalPages = 1;
  let currentPageSize = 24;
  let currentSortBy = 'lastReplicaReplyAt';
  let currentSortOrder = 'desc';
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
    renderRows(filtered, currentLabels, currentLastSeen, currentConvMeta, currentUserNames);
    const badge = document.getElementById('fluxa-count');
    const num = document.getElementById('fluxa-count-num');
    if (badge && num) { num.textContent = String(filtered.length); badge.style.display = 'inline-flex'; }
  }

  function renderRows(items, labels, lastSeenMap, convMeta, userNames){
    if (!items.length) {
      tableBody.innerHTML = '<tr class="fluxa-fade-in"><td colspan="6"><em><?php echo esc_js(__('No conversations found.', 'wp-fluxa-ecommerce-assistant')); ?></em></td></tr>';
      return;
    }
    const ONLINE_MIN = 5; // minutes; keep in sync with single view threshold
    const rows = items.map(it => {
      const uuid = String(it.uuid || '');
      const name = String(it.conversationName || '').trim();
      const source = String(it.source || '');
      const type = String(it.conversationType || '');
      const firstAt = it.firstMessageAt || '';
      const lastAt = it.lastMessageAt || '';
      const lastSeenVal = (lastSeenMap && lastSeenMap[uuid]) ? lastSeenMap[uuid] : '';
      const msgCount = Number(it.messageCount || 0);
      const repCount = Number(it.replicaReplyCount || 0);
      const viewUrl = <?php echo wp_json_encode( admin_url('admin.php?page=fluxa-assistant-chat&conversation=') ); ?> + encodeURIComponent(uuid);
      // Conversation column content: registered user -> display_name; guests -> 'Guest' + wc_session_key (9px)
      const meta = (convMeta && convMeta[uuid]) ? convMeta[uuid] : null;
      const wpUserId = meta ? parseInt(meta.wp_user_id || 0, 10) : 0;
      const wcSessionKey = meta ? String(meta.wc_session_key || '') : '';
      const wcShort = wcSessionKey ? (wcSessionKey.length > 10 ? (wcSessionKey.slice(0,10) + '....') : wcSessionKey) : '';
      const displayName = (wpUserId && userNames && userNames[wpUserId]) ? String(userNames[wpUserId]) : 'Guest';
      // Online logic: prefer local last_seen from conv table; fallback to replica lastMessageAt
      let online = false;
      try {
        let ts = null;
        if (lastSeenVal) {
          const iso = (typeof lastSeenVal === 'string' && lastSeenVal.indexOf('T') === -1 && lastSeenVal.indexOf(' ') > 0)
            ? lastSeenVal.replace(' ', 'T')
            : lastSeenVal;
          ts = Date.parse(iso);
        } else if (lastAt) {
          ts = Date.parse(lastAt);
        }
        if (ts && !isNaN(ts)) {
          online = (Date.now() - ts) <= ONLINE_MIN * 60 * 1000;
        }
      } catch(e) { online = false; }
      // If backend supplied a direct online flag for this uuid, override computed value
      try {
        if (window.__fluxaOnlineMap && Object.prototype.hasOwnProperty.call(window.__fluxaOnlineMap, uuid)) {
          online = !!window.__fluxaOnlineMap[uuid];
        }
      } catch(e) {}
      const statusColor = online ? '#16a34a' : '#9ca3af';
      const statusLabel = online ? '<?php echo esc_js(__('Online','wp-fluxa-ecommerce-assistant')); ?>' : '<?php echo esc_js(__('Offline','wp-fluxa-ecommerce-assistant')); ?>';
      const statusTitle = statusLabel + (lastSeenVal ? ' • ' + fmt(lastSeenVal) : (lastAt ? ' • ' + fmt(lastAt) : ''));
      return (
        '<tr class="fluxa-chat-row fluxa-fade-in" data-href="' + viewUrl + '" style="cursor:pointer;">' +
          '<td style="text-align:center;">' +
            '<div class="fluxa-status-cell">' +
              '<span class="fluxa-status-dot ' + (online ? 'is-online' : 'is-offline') + '" title="' + escapeHtml(statusTitle) + '" aria-hidden="true"></span>' +
              (online
                ? '<span class="fluxa-status-text"><?php echo esc_js(__('Online', 'wp-fluxa-ecommerce-assistant')); ?></span>'
                : '<span class="fluxa-status-text"><?php echo esc_js(__('Last seen:', 'wp-fluxa-ecommerce-assistant')); ?></span>' +
                  '<span class="fluxa-status-text">' + escapeHtml(fmt(lastSeenVal || lastAt)) + '</span>'
              ) +
            '</div>' +
          '</td>' +
          '<td>' +
            '<div class="fluxa-conv-cell">' +
              '<div class="fluxa-conv-title">' +
                '<a href="' + viewUrl + '"><strong>' + escapeHtml(displayName) + '</strong></a>' +
                ((wpUserId && wpUserId > 0) ? '' : (wcShort ? '<div class="fluxa-status-text" title="' + escapeHtml(wcSessionKey) + '" style="margin-top: 5px;">' + escapeHtml(wcShort) + '</div>' : '')) +
              '</div>' +
            '</div>' +
          '</td>' +
          '<td>' + escapeHtml(fmt(firstAt)) + '</td>' +
          '<td>' + escapeHtml(fmt(lastAt)) + '</td>' +
          '<td class="fluxa-center"><span class="fluxa-num fluxa-total" data-total="' + msgCount + '">' + msgCount.toLocaleString() + '</span></td>' +
          '<td class="fluxa-center"><span class="fluxa-num fluxa-agent" data-agent="' + repCount + '">' + repCount.toLocaleString() + '</span></td>' +
          '<td><span class="fluxa-ua-text">' + escapeHtml((meta && meta.last_ua) ? meta.last_ua : '') + '</span></td>' +
          '<td data-order="' + (Date.parse(lastAt)||'') + '">' + escapeHtml(fmt(lastAt)) + '</td>' +
        '</tr>'
      );
    });
    tableBody.innerHTML = rows.join('');
  }
  function buildRestUrl(){
    const u = new URL(restUrlBase, window.location.origin);
    u.searchParams.set('page', String(currentPage));
    u.searchParams.set('pageSize', String(currentPageSize));
    // Also include 'limit' for API variants that use this parameter name
    u.searchParams.set('limit', String(currentPageSize));
    u.searchParams.set('sortBy', currentSortBy);
    u.searchParams.set('sortOrder', currentSortOrder);
    return u.toString();
  }

  function updatePager(){
    const prevBtn = document.getElementById('fluxa-prev');
    const nextBtn = document.getElementById('fluxa-next');
    const ind = document.getElementById('fluxa-page-indicator');
    if (ind) ind.innerHTML = <?php echo wp_json_encode(__('Page', 'wp-fluxa-ecommerce-assistant')); ?> + ' <strong>' + currentPage + '</strong>' + (totalPages>1 ? ' / ' + totalPages : '');
    if (prevBtn) prevBtn.disabled = (currentPage <= 1);
    if (nextBtn) nextBtn.disabled = (currentPage >= totalPages);
  }

  function loadPage(page){
    currentPage = Math.max(1, page||1);
    const url = buildRestUrl();
    if (loadingEl) loadingEl.style.display = '';
    tableBody.innerHTML = '';
    fetch(url, { headers: { 'X-WP-Nonce': restNonce }, credentials: 'same-origin' })
    .then(async r => {
      const status = r.status; const raw = await r.text(); let data;
      try { data = JSON.parse(raw); } catch(e) { data = raw; }
      try {
        console.groupCollapsed('[Fluxa Admin] GET /admin/conversations');
        console.log('request.url', url);
        console.log('response.status', status);
        console.log('response.body', data);
        console.groupEnd();
      } catch(_) {}
      try {
        const pre = document.getElementById('fluxa-api-pre');
        if (pre) {
          const dbg = { request: { url: url }, response: { status: status, body: data } };
          pre.textContent = (typeof dbg.response.body === 'string') ? String(dbg.response.body) : JSON.stringify(dbg, null, 2);
        }
      } catch(_) {}
      return (typeof data === 'string') ? { ok:false, raw:data } : data;
    })
    .then(data => {
      if (loadingEl) loadingEl.style.display = 'none';
      tableBody.innerHTML = '';
      if (!data || !data.ok) {
        tableBody.innerHTML = '<tr><td colspan="6"><div class="notice notice-error" style="margin:0;"><p>' + escapeHtml((data && (data.error || (data.body && data.body.error))) || 'Failed to load conversations') + '</p></div></td></tr>';
        return;
      }
      allItems = Array.isArray(data.items) ? data.items : [];
      totalPages = Math.max(1, parseInt(data.totalPages||'1',10) || 1);
      updatePager();
      // Resolve UUID labels for users and fetch last_seen data
      const uuids = allItems.map(it => String(it.uuid||'')).filter(Boolean);
      const urlLabels = labelsUrl + '?uuids=' + encodeURIComponent(uuids.join(','));
      const urlLastSeen = lastSeenUrl + '?uuids=' + encodeURIComponent(uuids.join(','));
      Promise.all([
        fetch(urlLabels, { headers: { 'X-WP-Nonce': restNonce }, credentials: 'same-origin' }),
        fetch(urlLastSeen, { headers: { 'X-WP-Nonce': restNonce }, credentials: 'same-origin' })
      ])
        .then(async ([r1, r2]) => {
          const raw1 = await r1.text(); const raw2 = await r2.text();
          let lbl = null, seen = null;
          try { lbl = JSON.parse(raw1); } catch(e) { lbl = raw1; }
          try { seen = JSON.parse(raw2); } catch(e) { seen = raw2; }
          try {
            console.groupCollapsed('[Fluxa Admin] GET labels + last-seen');
            console.log('labels.url', urlLabels, 'status', r1.status, 'body', lbl);
            console.log('lastseen.url', urlLastSeen, 'status', r2.status, 'body', seen);
            console.groupEnd();
          } catch(_) {}
          const labels = (lbl && lbl.ok && lbl.labels) ? lbl.labels : {};
          const lastSeen = (seen && seen.ok && seen.last_seen) ? seen.last_seen : {};
          const online = (seen && seen.ok && seen.online) ? seen.online : {};
          const convMeta = (seen && seen.ok && seen.conv_meta) ? seen.conv_meta : {};
          const userNames = (seen && seen.ok && seen.user_names) ? seen.user_names : {};
          // If API provided direct online flags, we can color icons purely by that
          window.__fluxaOnlineMap = online;
          applyFiltersWithLabels(labels, lastSeen, convMeta, userNames);
          tableBody.addEventListener('click', function(e){
            const tr = e.target.closest('tr.fluxa-chat-row');
            if (tr && tr.dataset.href) { window.location = tr.dataset.href; }
          });
        })
        .catch(() => {
          applyFiltersWithLabels({}, {}, {}, {});
        });
    })
    .catch(err => {
      if (loadingEl) loadingEl.style.display = 'none';
      tableBody.innerHTML = '<tr><td colspan="6"><div class="notice notice-error" style="margin:0;"><p>Network error loading conversations</p></div></td></tr>';
      console.error('Fluxa admin conversations load error', err);
    });
  }

  // Initial load
  loadPage(1);

  // Filters wiring
  let currentLabels = {};
  let currentLastSeen = {};
  let currentConvMeta = {};
  let currentUserNames = {};
  function applyFiltersWithLabels(labels, lastSeen, convMeta, userNames){
    currentLabels = labels || {};
    currentLastSeen = lastSeen || {};
    currentConvMeta = convMeta || {};
    currentUserNames = userNames || {};
    applyFilters();
  }

  ['fluxa-chat-search','fluxa-min-total','fluxa-min-agent','fluxa-date-start','fluxa-date-end'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function(){ applyFilters(); });
    el.addEventListener('change', function(){ applyFilters(); });
  });
  // Pager controls
  const ps = document.getElementById('fluxa-page-size');
  const sb = document.getElementById('fluxa-sort-by');
  const so = document.getElementById('fluxa-sort-order');
  const prevBtn = document.getElementById('fluxa-prev');
  const nextBtn = document.getElementById('fluxa-next');
  if (ps) ps.addEventListener('change', function(){ currentPageSize = parseInt(ps.value||'24',10); loadPage(1); });
  if (sb) sb.addEventListener('change', function(){ currentSortBy = sb.value||'lastReplicaReplyAt'; loadPage(1); });
  if (so) so.addEventListener('change', function(){ currentSortOrder = so.value||'desc'; loadPage(1); });
  if (prevBtn) prevBtn.addEventListener('click', function(){ if (currentPage>1) loadPage(currentPage-1); });
  if (nextBtn) nextBtn.addEventListener('click', function(){ if (currentPage<totalPages) loadPage(currentPage+1); });
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
