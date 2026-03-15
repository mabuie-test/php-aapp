const apiBase = '/api';
let authToken = localStorage.getItem('token') || '';

function requireAuth() {
  if (!authToken) {
    window.location.href = '/login.html';
    return false;
  }
  return true;
}

const logout = document.getElementById('logout');
if (logout) {
  logout.onclick = () => {
    localStorage.removeItem('token');
    window.location.href = '/login.html';
  };
}

async function loadInvoices() {
  if (!requireAuth()) return;
  const res = await fetch(`${apiBase}/orders`, { headers: { Authorization: `Bearer ${authToken}` } });
  const data = await res.json();
  const list = document.getElementById('invoice-collection');
  if (!list) return;
  list.innerHTML = '';
  if (!res.ok) {
    list.innerHTML = `<p class="muted">${data.message || 'Erro ao carregar faturas'}</p>`;
    return;
  }
  data.orders.forEach((order) => {
    const item = document.createElement('div');
    item.className = 'list-item';
    item.innerHTML = `
      <div>
        <strong>${order.invoice_numero || 'Fatura'}</strong>
        <p class="muted">${order.tipo} · ${order.estado} · ${order.invoice_estado || 'EMITIDA'}</p>
      </div>
      <div class="stacked-actions">
        <a class="ghost" href="/invoice.html?id=${order.id}" target="_blank">Abrir</a>
        <button class="ghost" data-invoice="${order.id}">Baixar PDF</button>
      </div>
    `;
    item.querySelector('button')?.addEventListener('click', () => downloadInvoice(order.id));
    list.appendChild(item);
  });
}

async function downloadInvoice(orderId) {
  const res = await fetch(`${apiBase}/orders/${orderId}/pdf`, { headers: { Authorization: `Bearer ${authToken}` } });
  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `fatura-${orderId}.pdf`;
  a.click();
  URL.revokeObjectURL(url);
}

async function loadDocuments() {
  if (!requireAuth()) return;
  const res = await fetch(`${apiBase}/orders/deliveries`, { headers: { Authorization: `Bearer ${authToken}` } });
  const data = await res.json();
  const list = document.getElementById('documents-collection');
  if (!list) return;
  list.innerHTML = '';
  if (!res.ok) {
    list.innerHTML = `<p class="muted">${data.message || 'Erro ao carregar documentos'}</p>`;
    return;
  }
  if (!data.documents.length) {
    list.innerHTML = '<p class="muted">Nenhum documento final disponível ainda.</p>';
    return;
  }
  data.documents.forEach((doc) => {
    const item = document.createElement('div');
    item.className = 'list-item';
    item.innerHTML = `
      <div>
        <strong>${doc.tipo}</strong>
        <p class="muted">Entrega pronta (${doc.estado})</p>
      </div>
      <div class="stacked-actions">
        <a class="primary" href="${doc.final_file}" target="_blank">Download</a>
        <button class="ghost" data-order="${doc.id}" data-grade="">Enviar feedback</button>
      </div>
    `;
    item.querySelector('button').onclick = () => openFeedbackModal(doc.id);
    list.appendChild(item);
  });
}

async function loadFeedback() {
  if (!requireAuth()) return;
  const res = await fetch(`${apiBase}/orders`, { headers: { Authorization: `Bearer ${authToken}` } });
  const data = await res.json();
  const list = document.getElementById('feedback-collection');
  if (!list) return;
  list.innerHTML = '';
  if (!res.ok) {
    list.innerHTML = `<p class="muted">${data.message || 'Erro ao carregar feedback'}</p>`;
    return;
  }
  const delivered = data.orders.filter((o) => o.final_file);
  if (!delivered.length) {
    list.innerHTML = '<p class="muted">Envie feedback após receber o trabalho final.</p>';
    return;
  }
  delivered.forEach((order) => {
    const block = document.createElement('div');
    block.className = 'list-item';
    block.innerHTML = `
      <div>
        <strong>${order.tipo}</strong>
        <p class="muted">${order.area}</p>
      </div>
      <div class="stacked-actions">
        <button class="ghost" data-order="${order.id}">Avaliar</button>
      </div>
    `;
    block.querySelector('button').onclick = () => openFeedbackModal(order.id);
    list.appendChild(block);
  });
}

function openFeedbackModal(orderId) {
  const overlay = document.getElementById('confirm-overlay');
  const title = document.getElementById('confirm-title');
  const text = document.getElementById('confirm-text');
  const ok = document.getElementById('confirm-ok');
  const cancel = document.getElementById('confirm-cancel');
  if (!overlay || !title || !text || !ok || !cancel) return;
  title.textContent = 'Avaliar trabalho entregue';
  text.innerHTML = '<label>Classificação (1-5)</label><input id="rating-input" type="number" min="1" max="5" value="5" /> <label>Nota obtida</label><input id="grade-input" type="text" placeholder="Ex: 17/20" /> <label>Comentário</label><textarea id="comment-input"></textarea>';
  overlay.classList.remove('hidden');
  cancel.onclick = () => overlay.classList.add('hidden');
  ok.onclick = async () => {
    const payload = {
      order_id: orderId,
      rating: Number(document.getElementById('rating-input').value || 5),
      grade: document.getElementById('grade-input').value,
      comment: document.getElementById('comment-input').value,
    };
    const res = await fetch(`${apiBase}/orders/feedback`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${authToken}` },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    overlay.classList.add('hidden');
    alert(data.message || 'Feedback enviado');
  };
}

if (document.getElementById('invoice-collection')) {
  loadInvoices();
  loadDocuments();
  loadFeedback();
  document.getElementById('refresh-invoices')?.addEventListener('click', loadInvoices);
  document.getElementById('refresh-docs')?.addEventListener('click', () => {
    loadDocuments();
    loadFeedback();
  });
  document.getElementById('refresh-feedback')?.addEventListener('click', loadFeedback);
  setInterval(() => {
    loadInvoices();
    loadDocuments();
    loadFeedback();
  }, 12000);
}
