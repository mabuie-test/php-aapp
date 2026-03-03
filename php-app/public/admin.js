const apiBase = '/api';
let authToken = localStorage.getItem('token') || '';
let statusChart;
let revenueChart;
let servicesChart;
const adminPage = document.body.dataset.page || 'dashboard';

function showDialogMessage(message, type = 'info') {
  const old = document.getElementById('admin-dialog-overlay');
  if (old) old.remove();
  const overlay = document.createElement('div');
  overlay.id = 'admin-dialog-overlay';
  overlay.style.cssText = 'position:fixed;inset:0;background:rgba(2,6,23,0.65);display:flex;align-items:center;justify-content:center;z-index:9999;padding:1rem;';
  const icon = type === 'success' ? '✅' : 'ℹ️';
  const card = document.createElement('div');
  card.style.cssText = 'max-width:430px;width:100%;background:linear-gradient(140deg,#0f172a,#1e293b);border:1px solid rgba(11,99,230,0.5);border-radius:14px;padding:1rem;color:#e2e8f0;';
  card.innerHTML = `<div style="display:flex;gap:.6rem;align-items:center;margin-bottom:.6rem;"><span style="background:${type==='success'?'rgba(6,214,160,.2)':'rgba(11,99,230,.2)'};width:34px;height:34px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;">${icon}</span><strong>${type==='success'?'Sucesso':'Informação'}</strong></div><p style="margin:0 0 .9rem">${String(message||'').replace(/</g,'&lt;')}</p><button id="admin-dialog-ok" class="primary" style="padding:.5rem .95rem;">OK</button>`;
  overlay.appendChild(card);
  document.body.appendChild(overlay);
  const close=()=>overlay.remove();
  overlay.addEventListener('click',(e)=>{ if(e.target===overlay) close(); });
  card.querySelector('#admin-dialog-ok')?.addEventListener('click', close);
}

function parseFinalFiles(value) {
  if (!value) return [];
  if (Array.isArray(value)) return value.filter(Boolean);
  if (typeof value === 'string') {
    const trimmed = value.trim();
    if (!trimmed) return [];
    if (trimmed.startsWith('[')) {
      try { const arr = JSON.parse(trimmed); return Array.isArray(arr) ? arr.filter(Boolean) : []; } catch (_) {}
    }
    return [trimmed];
  }
  return [];
}


function requireAdmin() {
  if (!authToken) {
    window.location.href = '/login.html';
    return false;
  }
  const role = localStorage.getItem('role');
  if (role !== 'admin') {
    window.location.href = '/login.html';
    return false;
  }
  return true;
}

function toast(msg) {
  const box = document.getElementById('admin-feedback');
  if (box) {
    box.textContent = msg;
    box.classList.add('visible');
    setTimeout(() => box.classList.remove('visible'), 2500);
  } else {
    showDialogMessage(msg);
  }
}

function confirmAction(message) {
  return new Promise((resolve) => {
    const modal = document.getElementById('confirm-dialog');
    const text = document.getElementById('confirm-text');
    if (!modal || !text) return resolve(confirm(message));
    text.textContent = message;
    modal.classList.remove('hidden');
    const accept = document.getElementById('confirm-accept');
    const cancel = document.getElementById('confirm-cancel');
    const cleanup = (choice) => {
      modal.classList.add('hidden');
      accept.onclick = null;
      cancel.onclick = null;
      resolve(choice);
    };
    accept.onclick = () => cleanup(true);
    cancel.onclick = () => cleanup(false);
  });
}

const logout = document.getElementById('logout');
if (logout) {
  logout.onclick = () => {
    localStorage.removeItem('token');
    window.location.href = '/login.html';
  };
}

async function downloadInvoice(orderId, invoiceNumber) {
  if (!orderId) return toast('ID da encomenda em falta');
  try {
    const res = await fetch(`${apiBase}/orders/${orderId}/pdf`, {
      headers: { Authorization: `Bearer ${authToken}` },
    });
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw new Error(data.message || 'Falha ao obter a fatura');
    }
    const blob = await res.blob();
    const filename = invoiceNumber ? `fatura-${invoiceNumber}.pdf` : `fatura-${orderId}.pdf`;
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  } catch (err) {
    toast(err.message || 'Erro ao baixar fatura');
  }
}

async function loadOrders() {
  if (!requireAdmin()) return;
  try {
    const res = await fetch(`${apiBase}/admin/orders`, { headers: { Authorization: `Bearer ${authToken}` } });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || 'Erro ao carregar encomendas');
    const list = document.getElementById('admin-orders');
    if (!list) return;
    list.innerHTML = '';
    data.orders.forEach((order) => {
      // materials: support materiais_array (from backend) or fallback to materiais_uploads JSON string
      let materials = [];
      if (Array.isArray(order.materiais_array)) {
        materials = order.materiais_array;
      } else if (order.materiais_uploads) {
        try {
          materials = typeof order.materiais_uploads === 'string' ? JSON.parse(order.materiais_uploads) : order.materiais_uploads;
          if (!Array.isArray(materials)) materials = [];
        } catch (e) {
          materials = [];
        }
      }

      // invoice fields
      const invoiceNumber = order.invoice_numero || (order.invoice_details && order.invoice_details.numero) || '';
      const invoiceEstado = order.invoice_estado || (order.invoice_details && order.invoice_details.estado) || '—';
      const invoiceId = order.invoice_id || (order.invoice_details && order.invoice_details.id) || '';

      // comprovativo link (invoice_details.comprovativo or order.comprovativo)
      const comprovativo = (order.invoice_details && order.invoice_details.comprovativo) || order.comprovativo || null;

      // feedback (array of objects)
      const feedbacks = Array.isArray(order.feedback) ? order.feedback : [];

      const card = document.createElement('div');
      card.className = 'card';

      const finalFiles = parseFinalFiles(order.final_file);
      const finalLinks = finalFiles.length ? finalFiles.map((f) => `<a href="${f}" target="_blank" rel="noopener noreferrer">${f.split('/').pop()}</a>`).join(', ') : '';
      card.innerHTML = `
        <h4>#${order.id} · ${order.tipo || '—'}</h4>
        <p>Cliente: ${order.user_name || ''} (${order.user_email || ''})</p>
        <p>Estado: <strong>${order.estado || '—'}</strong> · Fatura: ${invoiceNumber || '—'} (${invoiceEstado})</p>
        <p>Total: ${order.valor_total ?? (order.invoice_details && order.invoice_details.valor_total) ?? '—'}</p>
        ${finalFiles.length ? `<p class="success">Documento(s) final(is) submetido(s): ${finalLinks}</p>` : (invoiceEstado === 'PAGA' ? `<p class="muted">Aguardando entrega final</p>` : '')}
        <p>Materiais: ${materials.length ? materials.map((m) => `<a href="${m}" target="_blank" rel="noopener noreferrer">${m.split('/').pop()}</a>`).join(', ') : 'Nenhum'}</p>
        ${comprovativo ? `<p class="muted">Comprovativo: <a href="${comprovativo}" target="_blank" rel="noopener noreferrer">ver ficheiro</a></p>` : '<p class="muted">Comprovativo pendente</p>'}
        <div class="stacked-actions" style="margin-top:8px;"></div>
        <div class="admin-feedback-section" style="margin-top:12px;"></div>
      `;

      // actions container
      const actions = card.querySelector('.stacked-actions');

      // Approve / Reject buttons (only if invoice exists AND order not already in a terminal paid/processed state)
      if (invoiceId) {
        // If invoice already paid, we still show the download and final upload controls, but hide approve/reject.
        if (invoiceEstado !== 'PAGA') {
          const approveBtn = document.createElement('button');
          approveBtn.className = 'primary';
          approveBtn.textContent = 'Marcar pago';
          approveBtn.dataset.invoice = invoiceId;
          approveBtn.dataset.number = invoiceNumber;
          approveBtn.dataset.email = order.user_email || '';
          approveBtn.onclick = () => approveInvoice(approveBtn.dataset.invoice, approveBtn.dataset.number, approveBtn.dataset.email);
          actions.appendChild(approveBtn);

          const rejectBtn = document.createElement('button');
          rejectBtn.className = 'ghost';
          rejectBtn.textContent = 'Rejeitar';
          rejectBtn.dataset.invoice = invoiceId;
          rejectBtn.dataset.order = order.id;
          rejectBtn.onclick = () => rejectInvoice(rejectBtn.dataset.invoice, rejectBtn.dataset.order);
          actions.appendChild(rejectBtn);
        } else {
          // invoiceEstado === 'PAGA' => show download + final upload (unless final_file exists)
          const downloadBtn = document.createElement('button');
          downloadBtn.className = 'ghost';
          downloadBtn.textContent = 'Baixar fatura';
          downloadBtn.onclick = () => downloadInvoice(order.id, invoiceNumber);
          actions.appendChild(downloadBtn);
        }
      } else {
        const noInv = document.createElement('span');
        noInv.className = 'muted';
        noInv.textContent = 'Sem fatura associada';
        actions.appendChild(noInv);
      }

      // If invoice paid and no final_file yet, show upload control
      if ((invoiceEstado === 'PAGA') && finalFiles.length === 0) {
        const uploadWrap = document.createElement('div');
        uploadWrap.className = 'upload-zone';
        uploadWrap.innerHTML = `<label>Entregar documento(s) final(is)</label><input type="file" data-file="${order.id}" multiple /><button class="primary" data-action="final" data-order="${order.id}">Submeter</button>`;
        const inputFile = uploadWrap.querySelector(`input[data-file="${order.id}"]`);
        const finalBtn = uploadWrap.querySelector('button');
        finalBtn.onclick = () => uploadFinal(finalBtn.dataset.order, inputFile);
        card.appendChild(uploadWrap);
      }

      // Render feedback directly in the card
      const feedbackZone = card.querySelector('.admin-feedback-section');
      if (feedbacks.length) {
        const fbHeader = document.createElement('h4');
        fbHeader.textContent = 'Feedback recebido';
        feedbackZone.appendChild(fbHeader);
        feedbacks.forEach((fb) => {
          const fbDiv = document.createElement('div');
          fbDiv.className = 'list-item';
          const created = fb.created_at ? ` · ${fb.created_at}` : '';
          fbDiv.innerHTML = `<div><strong>${fb.rating}/5</strong><p class="muted">${fb.grade || '—'}${created}</p><p>${fb.comment || ''}</p></div>`;
          feedbackZone.appendChild(fbDiv);
        });
      } else {
        const noFb = document.createElement('p');
        noFb.className = 'muted';
        noFb.textContent = 'Sem feedback para esta encomenda';
        feedbackZone.appendChild(noFb);
      }

      list.appendChild(card);
    });
  } catch (err) {
    toast(err.message);
  }
}

async function approveInvoice(invoiceId, number, email) {
  if (!invoiceId) return;
  const ok = await confirmAction('Confirmar que o pagamento foi validado?');
  if (!ok) return;
  const form = new FormData();
  form.set('invoice_id', invoiceId);
  form.set('numero', number || '');
  form.set('email_cliente', email || '');
  const res = await fetch(`${apiBase}/admin/invoices/approve`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${authToken}` },
    body: form,
  });
  const data = await res.json();
  if (!res.ok) return toast(data.message || 'Erro ao validar pagamento');
  toast('Pagamento marcado como pago.');
  // reload orders and metrics; UI will reflect removal of approve/reject because backend returns new estado
  await loadOrders();
  await loadMetrics();
}

async function rejectInvoice(invoiceId, orderId) {
  if (!invoiceId) return;
  const ok = await confirmAction('Deseja rejeitar este comprovativo?');
  if (!ok) return;
  const reason = prompt('Motivo da rejeição (obrigatório):', 'Comprovativo ilegível ou inválido');
  if (!reason || !reason.trim()) return toast('Motivo obrigatório');
  const form = new FormData();
  form.set('invoice_id', invoiceId);
  form.set('order_id', orderId);
  form.set('reason', reason.trim());
  const res = await fetch(`${apiBase}/admin/invoices/reject`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${authToken}` },
    body: form,
  });
  const data = await res.json();
  if (!res.ok) return toast(data.message || 'Erro ao rejeitar');
  toast('Pagamento devolvido ao estado pendente.');
  await loadOrders();
  await loadMetrics();
}

async function uploadFinal(orderId, input) {
  if (!input?.files?.length) return toast('Selecione pelo menos um ficheiro');
  const ok = await confirmAction('Entregar este documento ao cliente?');
  if (!ok) return;
  const form = new FormData();
  form.set('order_id', orderId);
  Array.from(input.files).forEach((file) => form.append('final', file));
  const btn = document.querySelector(`button[data-action=\"final\"][data-order=\"${orderId}\"]`);
  const progress = window.UploadUtils?.ensureProgressUI(input.closest('.upload-zone') || input.parentElement);
  try {
    if (btn) btn.disabled = true;
    const result = await window.UploadUtils.uploadWithProgress(`${apiBase}/admin/orders/final-upload`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${authToken}` },
      body: form,
      onProgress: (pct) => window.UploadUtils.setProgress(progress, pct, 'A enviar entrega final...'),
    });
    if (!result.ok) return toast(result.data.message || 'Erro ao enviar documento');
    toast('Documento(s) final(is) submetido(s).');
    if (input) input.value = '';
    await loadOrders();
  } finally {
    if (btn) btn.disabled = false;
    if (progress) window.UploadUtils.hideProgress(progress);
  }
}

async function loadUsers() {
  if (!requireAdmin()) return;
  const res = await fetch(`${apiBase}/admin/users`, { headers: { Authorization: `Bearer ${authToken}` } });
  const data = await res.json();
  const list = document.getElementById('admin-users');
  if (!list) return;
  if (!res.ok) {
    list.innerHTML = `<p class="muted">${data.message || 'Erro'}</p>`;
    return;
  }
  list.innerHTML = '';
  const canDeleteUsers = !!data.can_delete_users;

  data.users.forEach((user) => {
    const row = document.createElement('div');
    row.className = 'list-item';
    const isAdmin = user.role === 'admin';
    row.innerHTML = `
      <div>
        <strong>${user.name}</strong>
        <p class="muted">${user.email} · ${user.role}</p>
      </div>
      <div class="stacked-actions">
        <button class="ghost" data-action="toggle">${user.active ? 'Desativar' : 'Ativar'}</button>
        ${!isAdmin ? '<button class="ghost" data-action="anon">Anonimizar</button>' : ''}${canDeleteUsers && !isAdmin ? '<button class="ghost" data-action="delete">Eliminar</button>' : ''}
      </div>
    `;

    row.querySelector('[data-action="toggle"]').onclick = () => toggleUser(user.id, !user.active);
    const anonBtn = row.querySelector('[data-action="anon"]');
    if (anonBtn) anonBtn.onclick = () => anonymizeUser(user.id, user.email);
    const delBtn = row.querySelector('[data-action="delete"]');
    if (delBtn) delBtn.onclick = () => deleteUser(user.id, user.email);
    list.appendChild(row);
  });
}


async function anonymizeUser(userId, email) {
  const ok = await confirmAction(`Anonimizar utilizador ${email}?`);
  if (!ok) return;
  const form = new FormData();
  form.set('user_id', userId);
  const res = await fetch(`${apiBase}/admin/users/anonymize`, { method: 'POST', headers: { Authorization: `Bearer ${authToken}` }, body: form });
  const data = await res.json();
  if (!res.ok) return toast(data.message || 'Erro ao anonimizar utilizador');
  toast('Utilizador anonimizado');
  loadUsers();
}

async function deleteUser(userId, email) {
  const ok = await confirmAction(`Eliminar utilizador ${email}? Esta ação não pode ser desfeita.`);
  if (!ok) return;
  const form = new FormData();
  form.set('user_id', userId);
  const res = await fetch(`${apiBase}/admin/users/delete`, { method: "POST", headers: { Authorization: `Bearer ${authToken}` }, body: form });
  const data = await res.json();
  if (!res.ok) return toast(data.message || "Erro ao eliminar utilizador");
  toast("Utilizador eliminado");
  loadUsers();
}

async function toggleUser(userId, active) {
  const form = new FormData();
  form.set('user_id', userId);
  form.set('active', active ? '1' : '0');
  const res = await fetch(`${apiBase}/admin/users/toggle`, { method: 'POST', headers: { Authorization: `Bearer ${authToken}` }, body: form });
  const data = await res.json();
  if (!res.ok) return toast(data.message || 'Erro a atualizar utilizador');
  toast('Utilizador atualizado');
  loadUsers();
}

async function loadMetrics() {
  const res = await fetch(`${apiBase}/admin/metrics`, { headers: { Authorization: `Bearer ${authToken}` } });
  const data = await res.json();
  const zone = document.getElementById('admin-metrics');
  if (!zone) return;
  if (!res.ok) {
    zone.innerHTML = `<p class="muted">${data.message || 'Erro ao carregar métricas'}</p>`;
    return;
  }
  const m = data.metrics;
  zone.innerHTML = `Pedidos: ${m.orders} · Faturas: ${m.invoices} · Pago: ${m.paid} · Pendente: ${m.pending} · Levantamentos em análise: ${m.payouts_pending}`;
  const affiliateSummary = document.getElementById('admin-affiliate-summary');
  if (affiliateSummary) {
    affiliateSummary.textContent = `Saldo pendente para afiliados: ${m.payouts_pending} MZN`;
  }
  const chartData = (data.status || []).map((s) => ({ label: s.estado, value: s.total }));
  if (window.Chart && document.getElementById('status-chart')) {
    const ctx = document.getElementById('status-chart').getContext('2d');
    if (statusChart) statusChart.destroy();
    statusChart = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: chartData.map((d) => d.label),
        datasets: [{ data: chartData.map((d) => d.value), backgroundColor: ['#1d4ed8', '#0ea5e9', '#22c55e', '#f59e0b', '#ef4444'] }],
      },
      options: { plugins: { legend: { position: 'bottom' } } },
    });
  }
  if (window.Chart && document.getElementById('revenue-chart')) {
    const ctx = document.getElementById('revenue-chart').getContext('2d');
    if (revenueChart) revenueChart.destroy();
    const trend = (data.trend || []).reverse();
    revenueChart = new Chart(ctx, {
      type: 'line',
      data: {
        labels: trend.map((t) => t.mes),
        datasets: [{ label: 'Receita mensal (MZN)', data: trend.map((t) => t.total), borderColor: '#1d4ed8', fill: false }],
      },
      options: { plugins: { legend: { display: true } } },
    });
  }
  if (window.Chart && document.getElementById('services-chart')) {
    const ctx = document.getElementById('services-chart').getContext('2d');
    if (servicesChart) servicesChart.destroy();
    servicesChart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: (data.services || []).map((s) => s.categoria),
        datasets: [{ label: 'Pedidos', data: (data.services || []).map((s) => s.total), backgroundColor: '#0ea5e9' }],
      },
      options: { indexAxis: 'y', plugins: { legend: { display: false } } },
    });
  }
  const leaders = document.getElementById('admin-affiliate-leaders');
  if (leaders) {
    leaders.innerHTML = '';
    (data.affiliates || []).forEach((a) => {
      const row = document.createElement('div');
      row.className = 'list-item';
      row.innerHTML = `<div><strong>${a.referrer_code || '—'}</strong><p class="muted">${a.total} encomendas</p></div><span class="badge">${a.valor} MZN</span>`;
      leaders.appendChild(row);
    });
  }
}

async function loadCommissions() {
  const res = await fetch(`${apiBase}/admin/commissions`, { headers: { Authorization: `Bearer ${authToken}` } });
  const data = await res.json();
  const list = document.getElementById('admin-commissions');
  if (!list) return;
  if (!res.ok) {
    list.innerHTML = `<p class="muted">${data.message || 'Erro'}</p>`;
    return;
  }
  list.innerHTML = '';
  data.commissions.forEach((c) => {
    const item = document.createElement('div');
    item.className = 'list-item';
    item.innerHTML = `<div><strong>Ref: ${c.referrer_code}</strong><p class="muted">Encomenda #${c.order_id} · ${c.amount} MZN</p></div><span class="badge">${c.status}</span>`;
    list.appendChild(item);
  });
}

async function loadPayouts() {
  const res = await fetch(`${apiBase}/admin/payouts`, { headers: { Authorization: `Bearer ${authToken}` } });
  const data = await res.json();
  const list = document.getElementById('admin-payouts');
  if (!list) return;
  if (!res.ok) {
    list.innerHTML = `<p class="muted">${data.message || 'Erro'}</p>`;
    return;
  }
  list.innerHTML = '';
  data.payouts.forEach((p) => {
    const item = document.createElement('div');
    item.className = 'list-item';

    // Render actions only when status is SOLICITADO or PENDENTE
    let actionsHtml = '';
    if (p.status === 'SOLICITADO' || p.status === 'PENDENTE') {
      actionsHtml = `<div class="stacked-actions">
        <button class="ghost" data-id="${p.id}" data-status="APROVADO">Aprovar</button>
        <button class="ghost" data-id="${p.id}" data-status="REJEITADO">Rejeitar</button>
      </div>`;
    } else {
      actionsHtml = `<div><strong>${p.status}</strong></div>`;
    }

    item.innerHTML = `
      <div>
        <strong>Pedido #${p.id}</strong>
        <p class="muted">${p.name || p.email} · ${p.valor} MZN · ${p.metodo}</p>
        <p class="muted">M-Pesa: ${p.mpesa_destino || '—'}</p>
      </div>
      ${actionsHtml}`;

    // attach handlers (if buttons exist)
    item.querySelectorAll('button').forEach((btn) => {
      btn.onclick = () => updatePayout(btn.dataset.id, btn.dataset.status, item);
    });
    list.appendChild(item);
  });
}

/**
 * Update payout and immediately update UI to remove buttons after approval/rejection.
 * itemEl is optional: if provided, we'll update it in-place (remove buttons and show final status).
 */
async function updatePayout(payoutId, status, itemEl = null) {
  const form = new FormData();
  form.set('payout_id', payoutId);
  form.set('status', status);
  form.set('notes', `Atualizado via painel para ${status}`);
  const res = await fetch(`${apiBase}/admin/payouts/update`, { method: 'POST', headers: { Authorization: `Bearer ${authToken}` }, body: form });
  const data = await res.json();
  if (!res.ok) return toast(data.message || 'Erro ao atualizar pagamento');

  // immediate UI change: if itemEl passed, replace actions with status label
  if (itemEl) {
    const statusLabel = document.createElement('div');
    statusLabel.innerHTML = `<strong>${data.status || status}</strong>`;
    const actionsNode = itemEl.querySelector('.stacked-actions');
    if (actionsNode) {
      actionsNode.replaceWith(statusLabel);
    } else {
      // fallback: append status label
      itemEl.appendChild(statusLabel);
    }
  }

  toast('Estado do levantamento atualizado');
  // refresh lists to keep everything consistent
  loadPayouts();
  loadCommissions();
}

async function loadAudits() {
  const res = await fetch(`${apiBase}/admin/audits`, { headers: { Authorization: `Bearer ${authToken}` } });
  const data = await res.json();
  const list = document.getElementById('admin-audits');
  if (!list) return;
  if (!res.ok) {
    list.innerHTML = `<p class="muted">${data.message || 'Erro'}</p>`;
    return;
  }
  list.innerHTML = '';
  (data.audits || []).forEach((a) => {
    const item = document.createElement('div');
    item.className = 'list-item';
    item.innerHTML = `<div><strong>${a.action}</strong><p class="muted">${a.email || 'anónimo'} · ${a.meta}</p></div><span class="badge">${a.created_at || ''}</span>`;
    list.appendChild(item);
  });
}

async function loadFeedbackAdmin() {
  const zone = document.getElementById('admin-feedback-list');
  if (!zone) return;
  const res = await fetch(`${apiBase}/admin/feedback`, { headers: { Authorization: `Bearer ${authToken}` } });
  const data = await res.json();
  zone.innerHTML = '';
  if (!res.ok) {
    zone.innerHTML = `<p class="muted">${data.message || 'Não foi possível carregar feedback'}</p>`;
    return;
  }
  (data.feedback || []).forEach((fb) => {
    const item = document.createElement('div');
    item.className = 'list-item';
    item.innerHTML = `<div><strong>Pedido #${fb.order_id}</strong><p class="muted">${fb.rating}/5 · ${fb.grade || '—'}</p><p>${fb.comment || ''}</p></div><span class="badge">${fb.created_at || ''}</span>`;
    zone.appendChild(item);
  });
}

async function loadServices() {
  const res = await fetch(`${apiBase}/admin/services`, { headers: { Authorization: `Bearer ${authToken}` } });
  const data = await res.json();
  const list = document.getElementById('admin-services');
  if (!list) return;
  if (!res.ok) {
    list.innerHTML = `<p class="muted">${data.message || 'Erro ao carregar serviços'}</p>`;
    return;
  }
  list.innerHTML = '';
  data.services.forEach((svc) => {
    const item = document.createElement('div');
    item.className = 'card';
    item.innerHTML = `
      <h4>${svc.categoria}</h4>
      <p class="muted">${svc.contact_name} · ${svc.contact_email} ${svc.contact_phone ? ' · ' + svc.contact_phone : ''}</p>
      <p>${svc.detalhes || ''}</p>
      <p>${svc.norma_preferida ? 'Norma: ' + svc.norma_preferida + ' · ' : ''}${svc.software_preferido ? 'Software: ' + svc.software_preferido : ''}</p>
      ${svc.attachment ? `<p><a href="${svc.attachment}" target="_blank">Ver anexo</a></p>` : ''}
      <div class="inline-group">
        <select data-service="${svc.id}">
          ${['NOVO','EM_ANALISE','RESPONDIDO','CONCLUIDO'].map((s) => `<option value="${s}" ${svc.status===s?'selected':''}>${s}</option>`).join('')}
        </select>
        <button class="ghost" data-btn="${svc.id}">Atualizar</button>
      </div>
    `;
    item.querySelector('button').onclick = () => updateServiceStatus(svc.id, item.querySelector('select').value);
    list.appendChild(item);
  });
}

async function updateServiceStatus(id, status) {
  const form = new FormData();
  form.set('service_id', id);
  form.set('status', status);
  const res = await fetch(`${apiBase}/admin/services/update`, { method: 'POST', headers: { Authorization: `Bearer ${authToken}` }, body: form });
  const data = await res.json();
  if (!res.ok) return toast(data.message || 'Erro ao atualizar serviço');
  toast('Serviço atualizado');
  loadServices();
}

async function loadAdminChat() {
  const chatBox = document.getElementById('admin-chat');
  if (!chatBox || !authToken) return;
  const filter = document.getElementById('chat-order')?.value;
  const res = await fetch(`${apiBase}/admin/chat${filter ? `?order_id=${filter}` : ''}`, { headers: { Authorization: `Bearer ${authToken}` } });
  const data = await res.json();
  chatBox.innerHTML = '';
  if (!res.ok) {
    chatBox.innerHTML = `<p class="muted">${data.message || 'Erro ao carregar estação'}</p>`;
    return;
  }
  (data.messages || []).forEach((msg) => {
    const row = document.createElement('div');
    row.className = 'chat-row';
    row.innerHTML = `
      <div>
        <strong>${msg.author || 'Admin'}</strong> ${msg.order_id ? `<span class="badge">#${msg.order_id}</span>` : ''}
        <p class="muted">${msg.created_at || ''}</p>
        <p>${msg.message || ''}</p>
        ${msg.attachment ? `<a href="${msg.attachment}" target="_blank">Ver anexo</a>` : ''}
      </div>
    `;
    chatBox.appendChild(row);
  });
}

async function sendAdminChat() {
  const msgInput = document.getElementById('chat-message');
  const fileInput = document.getElementById('chat-file');
  const orderInput = document.getElementById('chat-order');
  const sendBtn = document.getElementById('chat-send');
  const form = new FormData();
  form.set('message', msgInput?.value || '');
  if (orderInput?.value) form.set('order_id', orderInput.value);
  if (fileInput?.files?.length) form.append('attachment', fileInput.files[0]);
  const progress = window.UploadUtils?.ensureProgressUI(document.getElementById('chat-card') || document.body);
  try {
    if (sendBtn) sendBtn.disabled = true;
    const result = await window.UploadUtils.uploadWithProgress(`${apiBase}/admin/chat`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${authToken}` },
      body: form,
      onProgress: (pct) => window.UploadUtils.setProgress(progress, pct, 'A enviar anexo no chat...'),
    });
    if (!result.ok) return toast(result.data.message || 'Erro ao enviar nota');
    toast('Nota registada');
    if (msgInput) msgInput.value = '';
    if (fileInput) fileInput.value = '';
    loadAdminChat();
  } finally {
    if (sendBtn) sendBtn.disabled = false;
    if (progress) window.UploadUtils.hideProgress(progress);
  }
}

const chatSend = document.getElementById('chat-send');
if (chatSend) {
  chatSend.onclick = sendAdminChat;
  document.getElementById('chat-refresh')?.addEventListener('click', loadAdminChat);
}

switch (adminPage) {
  case 'orders':
    loadOrders();
    loadMetrics();
    loadAudits();
    loadFeedbackAdmin();
    setInterval(() => {
      loadOrders();
      loadMetrics();
      loadAudits();
      loadFeedbackAdmin();
    }, 20000);
    break;
  case 'services':
    loadServices();
    setInterval(loadServices, 20000);
    break;
  case 'users':
    loadUsers();
    break;
  case 'metrics':
    loadMetrics();
    loadAudits();
    loadGrowthInsights();
    setInterval(() => {
      loadMetrics();
      loadAudits();
      loadGrowthInsights();
    }, 20000);
    break;
  case 'affiliates':
    loadCommissions();
    loadPayouts();
    loadMetrics();
    setInterval(() => {
      loadCommissions();
      loadPayouts();
    }, 20000);
    break;
  case 'chat':
    loadAdminChat();
    document.getElementById('chat-refresh')?.addEventListener('click', loadAdminChat);
    setInterval(loadAdminChat, 15000);
    break;
  case 'promotions':
    loadPromoRecipients();
    loadPromoCampaignHistory();
    document.getElementById('promo-refresh')?.addEventListener('click', () => { loadPromoRecipients(); loadPromoCampaignHistory(); });
    document.getElementById('promo-include-admins')?.addEventListener('change', loadPromoRecipients);
    document.getElementById('promo-form')?.addEventListener('submit', sendPromoCampaign);
    break;
  default:
    loadMetrics();
    loadAudits();
}

async function loadGrowthInsights() {
  const box = document.getElementById('growth-dashboard');
  const fraudBox = document.getElementById('affiliate-fraud');
  if (!box && !fraudBox) return;

  if (box) {
    try {
      const res = await fetch(`${apiBase}/admin/growth-dashboard`, { headers: { Authorization: `Bearer ${authToken}` } });
      const data = await res.json();
      if (!res.ok) throw new Error(data.message || 'Erro no growth dashboard');
      const k = data.kpis || {};
      const channels = (data.channel_conversion || []).map((c) => `<li>${c.channel}: ${c.total}</li>`).join('') || '<li>Sem dados</li>';
      box.innerHTML = `
        <div class="list-item"><div><strong>CAC estimado</strong><p class="muted">${k.estimated_cac ?? 0}</p></div></div>
        <div class="list-item"><div><strong>ROAS estimado</strong><p class="muted">${k.estimated_roas ?? 0}x</p></div></div>
        <div class="list-item"><div><strong>LTV aproximado</strong><p class="muted">${k.approx_ltv ?? 0}</p></div></div>
        <div class="list-item"><div><strong>Lead → Pago</strong><p class="muted">${k.lead_to_paid_conversion ?? 0}%</p></div></div>
        <div class="list-item"><div><strong>Conversão por canal</strong><ul>${channels}</ul></div></div>
      `;
    } catch (err) {
      box.innerHTML = `<p class="muted">${err.message}</p>`;
    }
  }

  if (fraudBox) {
    try {
      const res = await fetch(`${apiBase}/admin/affiliates/fraud`, { headers: { Authorization: `Bearer ${authToken}` } });
      const data = await res.json();
      if (!res.ok) throw new Error(data.message || 'Erro ao carregar sinais de fraude');
      const f = data.fraud || {};
      const blocks = (f.auto_block_recommendations || []).map((r) => `<li>${r.code} · ${r.reason} (${r.severity})</li>`).join('') || '<li>Sem recomendações automáticas</li>';
      fraudBox.innerHTML = `
        <div class="list-item"><div><strong>Tentativas auto-referência</strong><p class="muted">${f.self_referral_attempts ?? 0}</p></div></div>
        <div class="list-item"><div><strong>Recomendações de bloqueio</strong><ul>${blocks}</ul></div></div>
      `;
    } catch (err) {
      fraudBox.innerHTML = `<p class="muted">${err.message}</p>`;
    }
  }
}


async function loadPromoRecipients() {
  if (!requireAdmin()) return;
  const includeAdmins = document.getElementById('promo-include-admins')?.checked ? '1' : '0';
  const countEl = document.getElementById('promo-count');
  const list = document.getElementById('promo-recipients');
  if (!countEl || !list) return;

  countEl.textContent = 'A carregar...';
  list.innerHTML = '';

  const res = await fetch(`${apiBase}/admin/marketing/recipients?include_admins=${includeAdmins}`, { headers: { Authorization: `Bearer ${authToken}` } });
  const data = await res.json();
  if (!res.ok) {
    countEl.textContent = data.message || 'Erro ao carregar destinatários';
    return;
  }

  const recipients = data.recipients || [];
  countEl.textContent = `Total elegível: ${data.count || recipients.length}`;
  if (!recipients.length) {
    list.innerHTML = '<p class="muted">Sem destinatários válidos.</p>';
    return;
  }

  recipients.slice(0, 200).forEach((u) => {
    const row = document.createElement('div');
    row.className = 'list-item';
    row.innerHTML = `<div><strong>${u.name || 'Utilizador'}</strong><p class="muted">${u.email} · ${u.role}</p></div>`;
    list.appendChild(row);
  });
  if (recipients.length > 200) {
    const more = document.createElement('p');
    more.className = 'muted';
    more.textContent = `Mostrando 200 de ${recipients.length} destinatários.`;
    list.appendChild(more);
  }
}

async function sendPromoCampaign(e) {
  e.preventDefault();
  if (!requireAdmin()) return;

  const subject = document.getElementById('promo-subject')?.value?.trim() || '';
  const html = document.getElementById('promo-html')?.value || '';
  const includeAdmins = document.getElementById('promo-include-admins')?.checked ? '1' : '0';
  const maxRecipients = document.getElementById('promo-max')?.value || '500';
  const testEmail = document.getElementById('promo-test-email')?.value?.trim() || '';
  const resultEl = document.getElementById('promo-result');
  const sendBtn = document.getElementById('promo-send');

  if (!subject || !html.trim()) {
    if (resultEl) resultEl.textContent = 'Assunto e mensagem são obrigatórios.';
    return;
  }

  const ok = await confirmAction('Confirmar envio de campanha promocional em massa?');
  if (!ok) return;

  if (sendBtn) sendBtn.disabled = true;
  if (resultEl) resultEl.textContent = 'A enviar campanha...';

  try {
    const res = await fetch(`${apiBase}/admin/marketing/campaign/send`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${authToken}` },
      body: JSON.stringify({
        subject,
        html,
        include_admins: includeAdmins,
        max_recipients: Number(maxRecipients || 500),
        send_test_to: testEmail,
      }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || 'Falha no envio');
    if (resultEl) {
      resultEl.textContent = `Campanha concluída: ${data.sent}/${data.total_targets} enviados, falhas: ${data.failed}.`;
    }
  } catch (err) {
    if (resultEl) resultEl.textContent = err.message;
  } finally {
    if (sendBtn) sendBtn.disabled = false;
    loadPromoRecipients();
    loadPromoCampaignHistory();
  }
}



async function loadPromoCampaignHistory() {
  const box = document.getElementById('promo-history');
  if (!box) return;
  box.innerHTML = '';
  try {
    const res = await fetch(`${apiBase}/admin/marketing/campaign/history?per_page=10`, { headers: { Authorization: `Bearer ${authToken}` } });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || 'Erro ao carregar histórico');
    const rows = data.history || [];
    if (!rows.length) {
      box.innerHTML = '<p class="muted">Sem campanhas ainda.</p>';
      return;
    }
    rows.forEach((r) => {
      const item = document.createElement('div');
      item.className = 'list-item';
      item.innerHTML = `<div><strong>${r.action === 'marketing:campaign:test' ? 'Teste' : 'Campanha'}</strong><p class="muted">${r.subject || 'Sem assunto'} · ${r.created_at || ''}</p><p class="muted">Enviados: ${r.sent} · Falhas: ${r.failed}${r.duration_ms ? ` · ${r.duration_ms}ms` : ''}</p></div>`;
      box.appendChild(item);
    });
  } catch (err) {
    box.innerHTML = `<p class="muted">${err.message}</p>`;
  }
}
