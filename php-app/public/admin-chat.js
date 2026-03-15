/**
 * php-app/public/admin-chat.js
 *
 * Progressive enhancement for the admin station chat (defensive + resilient).
 * Compatible with legacy admin.js by calling window.loadAdminChat() / window.sendAdminChat()
 * when available. Otherwise falls back to the same endpoints:
 *   GET  /api/admin/chat[?order_id=]
 *   POST /api/admin/chat
 *
 * Expects a subset of IDs from admin-chat.html:
 *   #admin-chat, #chat-send, #chat-refresh, #chat-file, #chat-message,
 *   #chat-order, #chat-order-assoc, #chat-sidebar, #chat-search, #sidebar-toggle,
 *   #file-preview, #typing-indicator, #admin-threads
 *
 * Defensive: every DOM access is guarded, no uncaught property-set-on-null errors.
 */

const CHAT_API_BASE = '/api';
const token = localStorage.getItem('token') || '';
const POLL_INTERVAL_MS = 8000;

let pollHandle = null;
let isSidebarCollapsed = false;
let localTypingTimer = null;

const hasLegacyLoad = typeof window.loadAdminChat === 'function';
const hasLegacySend = typeof window.sendAdminChat === 'function';

function q(id) { try { return document.getElementById(id); } catch (_) { return null; } }
function el(tag, cls, html) { const e = document.createElement(tag); if (cls) e.className = cls; if (html !== undefined) e.innerHTML = html; return e; }
function fmtDate(ts) { if (!ts) return ''; try { const d = new Date(ts); return d.toLocaleString(); } catch { return ts; } }

/* ----------------------------- Utilities ----------------------------- */
function escapeHtml(str) {
  if (str === undefined || str === null) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}
function nl2br(s) { return String(s).replace(/\n/g, '<br/>'); }
function safeSetText(elm, text) { if (!elm) return; try { elm.textContent = text; } catch (e) { console.warn('safeSetText failed', e); } }
function safeSetHTML(elm, html) { if (!elm) return; try { elm.innerHTML = html; } catch (e) { console.warn('safeSetHTML failed', e); } }

/* --------------------------- File preview UI ------------------------- */
(function initFilePreview() {
  const fileInput = q('chat-file');
  const filePreview = q('file-preview');
  if (!fileInput || !filePreview) return;

  fileInput.addEventListener('change', (e) => {
    try {
      filePreview.innerHTML = '';
      const f = e.target.files?.[0];
      if (!f) return;
      const wrap = el('div', 'preview-item', `<strong>${escapeHtml(f.name)}</strong> · ${Math.round(f.size / 1024)} KB`);
      const rm = el('button', 'ghost tiny', 'Remover');
      rm.type = 'button';
      rm.onclick = () => {
        fileInput.value = '';
        filePreview.innerHTML = '';
      };
      wrap.appendChild(rm);

      if (f.type && f.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = () => {
          try {
            const img = el('img', 'preview-thumb', '');
            img.src = reader.result;
            img.style.maxWidth = '120px';
            img.style.display = 'block';
            img.style.marginTop = '6px';
            wrap.appendChild(img);
          } catch (e) { /* ignore image render errors */ }
        };
        reader.readAsDataURL(f);
      }

      filePreview.appendChild(wrap);
    } catch (err) {
      console.error('file preview error', err);
    }
  });
})();

/* --------------------------- Sidebar toggle -------------------------- */
(function initSidebarToggle() {
  const sidebarToggle = q('sidebar-toggle');
  const sidebar = q('chat-sidebar');
  if (!sidebarToggle || !sidebar) return;
  sidebarToggle.addEventListener('click', () => {
    isSidebarCollapsed = !isSidebarCollapsed;
    sidebar.style.display = isSidebarCollapsed ? 'none' : '';
  });
})();

/* ------------------------- Typing indicator -------------------------- */
(function initTypingIndicator() {
  const typingIndicator = q('typing-indicator');
  const messageInput = q('chat-message');
  if (!messageInput) return;

  if (typingIndicator) {
    messageInput.addEventListener('input', () => {
      safeSetText(typingIndicator, 'A escrever...');
      if (localTypingTimer) clearTimeout(localTypingTimer);
      localTypingTimer = setTimeout(() => safeSetText(typingIndicator, ''), 1200);
    });
  }

  // Enter=send, Shift+Enter=newline (only attach if send button exists or legacy send exists)
  messageInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      // prefer our explicit send button if present, otherwise attempt legacy
      const sendBtn = q('chat-send');
      if (sendBtn) {
        e.preventDefault();
        sendBtn.click();
      } else if (hasLegacySend) {
        e.preventDefault();
        try { window.sendAdminChat(); } catch (err) { console.warn('legacy send failed', err); }
      }
    }
  });
})();

/* ------------------------- Send message logic ------------------------ */
(function initSendHandler() {
  const sendBtn = q('chat-send');
  if (!sendBtn) return;

  if (hasLegacySend) {
    // When legacy send exists, copy association and call legacy safely
    sendBtn.addEventListener('click', () => {
      try {
        const assoc = q('chat-order-assoc')?.value || '';
        const legacyOrderEl = q('chat-order'); // admin.js expects this id
        if (legacyOrderEl && assoc !== undefined) legacyOrderEl.value = assoc;
        // call legacy in try/catch to avoid uncaught errors bubbling
        try {
          window.sendAdminChat();
        } catch (err) {
          console.error('legacy sendAdminChat error:', err);
        }
      } catch (e) {
        console.error('legacy-aware click handler failed', e);
      }
    });
    return;
  }

  // Fallback send implementation (talks to /api/admin/chat)
  sendBtn.addEventListener('click', async () => {
    const btn = q('chat-send');
    const messageInput = q('chat-message');
    const fInput = q('chat-file');
    const assoc = (q('chat-order-assoc')?.value || '').trim() || (q('chat-order')?.value || '').trim();

    const message = messageInput ? messageInput.value.trim() : '';

    if (!message && !(fInput && fInput.files && fInput.files.length)) {
      alert('Mensagem ou anexo obrigatório');
      return;
    }

    try {
      if (btn) { btn.disabled = true; safeSetText(btn, 'Enviando...'); }

      const form = new FormData();
      form.set('message', message);
      if (assoc) form.set('order_id', assoc);
      if (fInput && fInput.files && fInput.files.length) form.append('attachment', fInput.files[0]);

      const progress = window.UploadUtils?.ensureProgressUI(q('chat-card') || document.body);
      const result = await window.UploadUtils.uploadWithProgress(`${CHAT_API_BASE}/admin/chat`, {
        method: 'POST',
        headers: token ? { Authorization: `Bearer ${token}` } : {},
        body: form,
        onProgress: (pct) => window.UploadUtils.setProgress(progress, pct, 'A enviar anexo no chat...'),
      });

      if (!result.ok) {
        throw new Error(result.data.message || 'Erro ao enviar nota');
      }

      if (messageInput) { messageInput.value = ''; messageInput.focus(); }
      if (fInput) { fInput.value = ''; const filePreview = q('file-preview'); if (filePreview) safeSetHTML(filePreview, ''); }

      await refreshMessages();
      if (progress) window.UploadUtils.hideProgress(progress);
    } catch (err) {
      alert(err.message || 'Falha ao enviar');
      console.error('send message error', err);
    } finally {
      if (btn) { btn.disabled = false; safeSetText(btn, 'Enviar'); }
    }
  });
})();

/* ------------------------- Fetch & render ---------------------------- */
async function refreshMessages() {
  // Prefer legacy loader if present
  if (hasLegacyLoad) {
    try {
      await window.loadAdminChat();
      return;
    } catch (err) {
      console.warn('legacy loadAdminChat failed; falling back', err);
    }
  }

  // Fallback: GET /api/admin/chat
  try {
    const orderIdRaw = q('chat-order')?.value;
    const orderId = orderIdRaw ? `?order_id=${encodeURIComponent(orderIdRaw)}` : '';
    const res = await fetch(`${CHAT_API_BASE}/admin/chat${orderId}`, {
      headers: token ? { Authorization: `Bearer ${token}` } : {},
    });

    let data = {};
    try { data = await res.json(); } catch (_) { data = {}; }

    if (!res.ok) {
      console.error('Failed to load messages', data);
      const board = q('admin-chat');
      if (board) safeSetHTML(board, `<div class="muted">Erro ao carregar mensagens: ${escapeHtml(data.message || 'Erro')}</div>`);
      return;
    }

    const messages = data.messages || [];
    renderMessagesFallback(messages);
    renderThreadsFromMessages(messages);
  } catch (err) {
    console.error('refreshMessages error', err);
  }
}

function renderMessagesFallback(messages) {
  const board = q('admin-chat');
  if (!board) return;

  // detect whether user is near bottom (to avoid forcing scroll when reading older messages)
  const atBottom = Math.abs(board.scrollHeight - board.clientHeight - board.scrollTop) < 40;

  // rebuild
  safeSetHTML(board, '');
  if (!messages.length) {
    board.appendChild(el('div', 'muted', 'Nenhuma mensagem ainda.'));
    return;
  }

  messages.forEach(m => {
    try {
      const row = el('div', 'chat-row');
      const headerHtml = `<strong>${escapeHtml(m.author || m.email || 'Admin')}</strong> ${m.order_id ? `<span class="badge">#${m.order_id}</span>` : ''} <span class="muted small">${fmtDate(m.created_at)}</span>`;
      const header = el('div', 'chat-meta', headerHtml);
      const body = el('div', 'chat-body', `<p>${nl2br(escapeHtml(m.message || ''))}</p>`);
      row.appendChild(header);
      row.appendChild(body);

      if (m.attachment) {
        const a = el('a', 'chat-attachment-link', `Anexo: ${escapeHtml(m.attachment.split('/').pop())}`);
        a.href = m.attachment;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        const attachWrap = el('div', 'chat-attachment', '');
        attachWrap.appendChild(a);
        row.appendChild(attachWrap);
      }

      board.appendChild(row);
    } catch (e) {
      console.warn('render message failed for', m, e);
    }
  });

  // auto-scroll only if user was at bottom
  if (atBottom) {
    try { board.scrollTop = board.scrollHeight; } catch (e) { /* ignore */ }
  }
}

/* ---------------------------- Threads UI ----------------------------- */
async function loadThreads() {
  const threadsContainer = q('admin-threads');
  if (!threadsContainer) return;
  try {
    const res = await fetch(`${CHAT_API_BASE}/admin/chat`, { headers: token ? { Authorization: `Bearer ${token}` } : {} });
    const data = await res.json();
    renderThreadsFromMessages(data.messages || []);
  } catch (err) {
    console.error('loadThreads error', err);
  }
}

function renderThreadsFromMessages(messages) {
  const container = q('admin-threads');
  if (!container) return;

  const map = new Map();
  (messages || []).forEach(m => {
    const key = m.order_id || 'no_order';
    const prev = map.get(key);
    if (!prev || new Date(m.created_at) > new Date(prev.created_at)) {
      map.set(key, m);
    }
  });

  const items = Array.from(map.entries()).sort((a, b) => new Date(b[1].created_at) - new Date(a[1].created_at));

  safeSetHTML(container, '');
  items.forEach(([orderKey, msg]) => {
    const title = orderKey === 'no_order' ? 'Geral' : `Pedido #${orderKey}`;
    const txt = (msg.message || '').slice(0, 120).replace(/\n/g, ' ');
    const row = el('div', 'list-item thread-item', `<div><strong>${escapeHtml(title)}</strong><p class="muted small">${escapeHtml(msg.author || msg.email || '')} · ${escapeHtml(txt)}</p></div>`);
    row.dataset.order = orderKey === 'no_order' ? '' : orderKey;
    row.addEventListener('click', () => {
      if (q('chat-order')) q('chat-order').value = row.dataset.order;
      if (q('chat-order-assoc')) q('chat-order-assoc').value = row.dataset.order;
      refreshMessages();
    });
    container.appendChild(row);
  });
}

/* ------------------------- Controls & Polling ------------------------ */
const refreshBtn = q('chat-refresh');
if (refreshBtn) {
  refreshBtn.addEventListener('click', () => {
    refreshMessages();
    loadThreads();
  });
}

function startPolling() {
  if (pollHandle) clearInterval(pollHandle);
  pollHandle = setInterval(() => {
    refreshMessages();
    loadThreads();
  }, POLL_INTERVAL_MS);
}
function stopPolling() {
  if (pollHandle) clearInterval(pollHandle);
  pollHandle = null;
}

/* ---------------------------- Search box ---------------------------- */
const chatSearch = q('chat-search');
if (chatSearch) {
  chatSearch.addEventListener('input', (e) => {
    const qv = e.target.value.toLowerCase();
    const threads = q('admin-threads');
    if (!threads) return;
    Array.from(threads.children).forEach(item => {
      const txt = (item.textContent || '').toLowerCase();
      item.style.display = txt.includes(qv) ? '' : 'none';
    });
  });
}

/* ---------------------------- Initialization ------------------------- */
(async function init() {
  try {
    // allow legacy UI to set up first if present
    if (hasLegacyLoad) {
      try { await window.loadAdminChat(); } catch (e) { console.warn('legacy loadAdminChat initial call failed', e); }
    } else {
      await refreshMessages();
    }

    await loadThreads();
    startPolling();

    // responsive: collapse sidebar on small screens by default
    const sidebar = q('chat-sidebar');
    const mq = window.matchMedia('(max-width: 880px)');
    function adaptSidebar() {
      if (!sidebar) return;
      if (mq.matches) { sidebar.style.display = 'none'; isSidebarCollapsed = true; }
      else { sidebar.style.display = ''; isSidebarCollapsed = false; }
    }
    adaptSidebar();
    try { mq.addEventListener('change', adaptSidebar); } catch (e) { /* older browsers */ }

    // focus composer if present
    q('chat-message')?.focus();
  } catch (err) {
    console.error('admin-chat init failed', err);
  }
})();