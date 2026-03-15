const apiBase = '/api';
let authToken = localStorage.getItem('token') || '';
const params = new URLSearchParams(window.location.search);
const orderId = params.get('id');

function parseStoredFileList(value) {
  if (!value) return [];
  if (Array.isArray(value)) return value.filter(Boolean);
  if (typeof value === 'string') {
    const trimmed = value.trim();
    if (!trimmed) return [];
    if (trimmed.startsWith('[')) {
      try {
        const arr = JSON.parse(trimmed);
        if (Array.isArray(arr)) return arr.filter(Boolean);
      } catch (_) {}
    }
    return [trimmed];
  }
  return [];
}

function showInvoiceDialog(message, type = 'info') {
  const old = document.getElementById('invoice-dialog-overlay');
  if (old) old.remove();
  const overlay = document.createElement('div');
  overlay.id = 'invoice-dialog-overlay';
  overlay.style.cssText = 'position:fixed;inset:0;background:rgba(2,6,23,.65);display:flex;align-items:center;justify-content:center;z-index:9999;padding:1rem;';
  const card = document.createElement('div');
  card.style.cssText = 'max-width:430px;width:100%;background:linear-gradient(140deg,#0f172a,#1e293b);border:1px solid rgba(11,99,230,.5);border-radius:14px;padding:1rem;color:#e2e8f0;';
  card.innerHTML = `<div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.6rem;"><span style="width:34px;height:34px;border-radius:999px;background:${type==='success'?'rgba(6,214,160,.2)':'rgba(11,99,230,.2)'};display:inline-flex;align-items:center;justify-content:center;">${type==='success'?'✅':'ℹ️'}</span><strong>${type==='success'?'Sucesso':'Informação'}</strong></div><p style="margin:0 0 .9rem">${String(message||'').replace(/</g,'&lt;')}</p><button id="invoice-dialog-ok" class="primary" style="padding:.5rem .95rem;">OK</button>`;
  overlay.appendChild(card);
  document.body.appendChild(overlay);
  const close = () => overlay.remove();
  overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
  card.querySelector('#invoice-dialog-ok')?.addEventListener('click', close);
}

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

async function loadInvoice() {
  if (!requireAuth() || !orderId) return;
  try {
    const res = await fetch(`${apiBase}/orders/${orderId}`, { headers: { Authorization: `Bearer ${authToken}` } });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || 'Não foi possível carregar a fatura');
    const order = data.order;
    const body = document.getElementById('invoice-body');
    const materials = parseStoredFileList(order.materiais_uploads);
    const finalFiles = parseStoredFileList(order.final_file);
    const descriptionHtml = (order.descricao || '—').replace(/\n/g, '<br>');
    const proofForm = document.getElementById('proof-form');
    if (proofForm) {
      proofForm.dataset.invoice = order.invoice_id || order.id;
    }
    body.innerHTML = `
      <p><strong>Fatura:</strong> ${order.invoice_numero || '—'}</p>
      <p><strong>Estado:</strong> ${order.invoice_estado || 'EMITIDA'}</p>
      <p><strong>Trabalho:</strong> ${order.tipo || '—'} (${order.area || '—'})</p>
      <p><strong>Nível:</strong> ${order.nivel || '—'} · <strong>Páginas:</strong> ${order.paginas || '—'}</p>
      <p><strong>Norma:</strong> ${order.norma || '—'}</p>
      <p><strong>Complexidade:</strong> ${order.complexidade || '—'} · <strong>Urgência:</strong> ${order.urgencia || '—'}</p>
      <p><strong>Prazo desejado:</strong> ${order.prazo_entrega || '—'}</p>
      <p><strong>Descrição do pedido:</strong><br>${descriptionHtml}</p>
      <p><strong>Materiais informados:</strong> ${order.materiais_info || 'Não'}</p>
      <p><strong>Percentual de uso dos materiais:</strong> ${order.materiais_percentual || '—'}${order.materiais_percentual ? '%' : ''}</p>
      <p><strong>Valor:</strong> ${order.valor_total || order.total || '—'} MZN</p>
      <p><strong>Materiais fornecidos:</strong> ${materials.length ? materials.map((m) => `<a href="${m}" target="_blank">${m.split('/').pop()}</a>`).join(', ') : 'Nenhum'}</p>
      ${order.comprovativo ? `<p class="muted">Comprovativo já enviado: <a href="${order.comprovativo}" target="_blank">abrir</a></p>` : ''}
      <hr />
      <p><strong>Pagamento M-Pesa</strong></p>
      <p>Número: 851619970 · Titular: Maria António Chicavele</p>
      <p class="muted">Após pagar, envie o comprovativo nesta página.</p>
      ${finalFiles.length ? `<p class="success">Documento(s) final(is): ${finalFiles.map((f) => `<a href="${f}" target="_blank">${f.split('/').pop()}</a>`).join(', ')}</p>` : ''}
    `;
  } catch (err) {
    showInvoiceDialog(err.message);
  }
}

const refreshBtn = document.getElementById('refresh-invoice');
if (refreshBtn) refreshBtn.onclick = loadInvoice;
const backBtn = document.getElementById('back-dashboard');
if (backBtn) backBtn.onclick = () => (window.location.href = '/documents.html');
const pdfBtn = document.getElementById('download-pdf');
if (pdfBtn) {
  pdfBtn.onclick = async () => {
    if (!requireAuth()) return;
    const res = await fetch(`${apiBase}/orders/${orderId}/pdf`, { headers: { Authorization: `Bearer ${authToken}` } });
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `fatura-${orderId}.pdf`;
    a.click();
    URL.revokeObjectURL(url);
  };
}

const proofForm = document.getElementById('proof-form');
if (proofForm) {
  proofForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!requireAuth()) return;
    const form = new FormData();
    form.set('invoice_id', proofForm.dataset.invoice || (new URLSearchParams(window.location.search)).get('invoice_id') || '');
    form.set('order_id', orderId);
    const fileField = document.getElementById('proof-file');
    if (fileField?.files?.length) {
      form.append('comprovativo', fileField.files[0]);
    }
    const submitBtn = proofForm.querySelector('button[type="submit"]');
    const progress = window.UploadUtils?.ensureProgressUI(proofForm);
    try {
      if (submitBtn) submitBtn.disabled = true;
      const result = await window.UploadUtils.uploadWithProgress(`${apiBase}/orders/proof`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${authToken}` },
        body: form,
        onProgress: (pct) => window.UploadUtils.setProgress(progress, pct, 'A enviar comprovativo...'),
      });
      if (!result.ok) throw new Error(result.data.message || 'Falha ao enviar comprovativo');
      showInvoiceDialog('Comprovativo enviado com sucesso.', 'success');
      loadInvoice();
    } catch (err) {
      showInvoiceDialog(err.message);
    } finally {
      if (submitBtn) submitBtn.disabled = false;
      if (progress) window.UploadUtils.hideProgress(progress);
    }
  });
}

loadInvoice();
setInterval(loadInvoice, 12000);
