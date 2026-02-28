(function () {
  const terminalRoot = document.querySelector('.support-terminal');
  if (!terminalRoot) return;

  const api = '/api/support/chat';
  const token = localStorage.getItem('token') || '';
  let activeSessionId = '';

  const q = (id) => document.getElementById(id);
  const esc = (s) => String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

  async function req(path, opts = {}) {
    const headers = { ...(opts.headers || {}), Authorization: `Bearer ${token}` };
    const res = await fetch(`${api}${path}`, { ...opts, headers });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || 'Erro no atendimento');
    return data;
  }

  function renderMessages(messages) {
    const box = q('support-messages');
    if (!box) return;
    box.innerHTML = '';
    (messages || []).forEach((m) => {
      const row = document.createElement('div');
      row.className = 'chat-row';
      row.innerHTML = `<div><strong>${esc(m.sender_name)}</strong> <span class="muted small">${esc(m.created_at)}</span></div><div>${esc(m.message).replace(/\n/g, '<br>')}</div>`;
      if (m.attachment) {
        const a = document.createElement('a');
        a.href = m.attachment;
        a.textContent = '📎 Anexo';
        a.target = '_blank';
        row.appendChild(a);
      }
      box.appendChild(row);
    });
    box.scrollTop = box.scrollHeight;
  }

  async function loadSessions() {
    const filter = q('support-session-filter')?.value || '';
    const data = await req(`/sessions${filter ? `?status=${encodeURIComponent(filter)}` : ''}`);
    const list = q('support-sessions');
    if (!list) return;
    list.innerHTML = '';
    (data.sessions || []).forEach((s) => {
      const item = document.createElement('div');
      item.className = 'list-item';
      item.style.cursor = 'pointer';
      item.innerHTML = `<div><strong>${esc(s.customer_name || 'Cliente')}</strong> <span class="badge">${esc(s.status)}</span><p class="muted small">${esc(s.last_message || 'Sem mensagens')}</p></div>`;
      item.onclick = async () => {
        activeSessionId = s.id;
        await loadMessages();
      };
      list.appendChild(item);
    });
  }

  async function loadMessages() {
    if (!activeSessionId) return;
    const data = await req(`/messages?session_id=${encodeURIComponent(activeSessionId)}`);
    renderMessages(data.messages || []);
    const ratingBox = q('support-rating-preview');
    if (ratingBox) {
      const rating = data.session?.rating;
      const comment = data.session?.rating_comment;
      if (rating) {
        ratingBox.style.display = 'block';
        ratingBox.innerHTML = `⭐ Avaliação do cliente: <strong>${rating}/5</strong>${comment ? ` · ${comment}` : ''}`;
      } else {
        ratingBox.style.display = 'none';
        ratingBox.textContent = '';
      }
    }
  }

  async function loadAgents() {
    const data = await req('/agents');
    const sel = q('support-transfer-agent');
    if (!sel) return;
    sel.innerHTML = '<option value="">Transferir para...</option>';
    (data.agents || []).forEach((a) => {
      const opt = document.createElement('option');
      opt.value = a.id;
      opt.textContent = a.name || a.email;
      sel.appendChild(opt);
    });
  }

  async function sendReply() {
    if (!activeSessionId) return alert('Selecione um atendimento.');
    const text = q('support-reply')?.value?.trim() || '';
    const file = q('support-attachment');
    if (!text && !(file?.files?.length)) return;
    const form = new FormData();
    form.set('session_id', activeSessionId);
    form.set('message', text);
    if (file?.files?.length) form.append('attachment', file.files[0]);
    await req('/message', { method: 'POST', body: form });
    if (q('support-reply')) q('support-reply').value = '';
    if (file) file.value = '';
    await loadMessages();
    await loadSessions();
  }

  async function claim() {
    if (!activeSessionId) return;
    const form = new FormData();
    form.set('session_id', activeSessionId);
    await req('/claim', { method: 'POST', body: form });
    await loadMessages();
    await loadSessions();
  }

  async function transfer() {
    if (!activeSessionId) return;
    const aid = q('support-transfer-agent')?.value;
    if (!aid) return alert('Escolha um agente.');
    const form = new FormData();
    form.set('session_id', activeSessionId);
    form.set('agent_id', aid);
    await req('/transfer', { method: 'POST', body: form });
    await loadMessages();
    await loadSessions();
  }

  async function closeChat() {
    if (!activeSessionId) return;
    const form = new FormData();
    form.set('session_id', activeSessionId);
    await req('/close', { method: 'POST', body: form });
    await loadMessages();
    await loadSessions();
  }

  function bind() {

    q('open-support-terminal')?.addEventListener('click', () => {
      q('support-modal')?.classList.remove('hidden');
      loadSessions().catch(() => {});
    });
    q('close-support-terminal')?.addEventListener('click', () => q('support-modal')?.classList.add('hidden'));
    q('support-refresh')?.addEventListener('click', () => loadSessions().catch((e) => alert(e.message)));
    q('support-session-filter')?.addEventListener('change', () => loadSessions().catch((e) => alert(e.message)));
    q('support-send')?.addEventListener('click', () => sendReply().catch((e) => alert(e.message)));
    q('support-claim')?.addEventListener('click', () => claim().catch((e) => alert(e.message)));
    q('support-transfer')?.addEventListener('click', () => transfer().catch((e) => alert(e.message)));
    q('support-close')?.addEventListener('click', () => closeChat().catch((e) => alert(e.message)));
  }

  async function init() {
    if (!token) return;
    bind();
    await loadAgents();
    await loadSessions();
    setInterval(() => {
      loadSessions().catch(() => {});
      loadMessages().catch(() => {});
    }, 5000);
  }

  document.addEventListener('DOMContentLoaded', () => init().catch((e) => console.error(e)));
})();
