const apiBase = '/api';
let authToken = localStorage.getItem('token') || '';
const params = new URLSearchParams(window.location.search);
const orderId = params.get('id');

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
    const materials = order.materiais_uploads ? JSON.parse(order.materiais_uploads) : [];
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
      ${order.final_file ? `<p class="success">Documento final: <a href="${order.final_file}" target="_blank">download</a></p>` : ''}
    `;
  } catch (err) {
    alert(err.message);
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
      alert('Comprovativo enviado com sucesso.');
      loadInvoice();
    } catch (err) {
      alert(err.message);
    } finally {
      if (submitBtn) submitBtn.disabled = false;
      if (progress) window.UploadUtils.hideProgress(progress);
    }
  });
}

loadInvoice();
setInterval(loadInvoice, 12000);
