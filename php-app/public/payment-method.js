const apiBase = '/api';
let authToken = localStorage.getItem('token') || '';
const params = new URLSearchParams(window.location.search);
const orderId = params.get('id');
let selectedMethod = '';
let orderData = null;

function initPaymentLogos() {
  document.querySelectorAll('.payment-logo-img').forEach((img) => {
    img.addEventListener('load', () => {
      const key = img.getAttribute('data-logo');
      document.querySelector(`[data-logo-fallback="${key}"]`)?.classList.add('hidden');
      img.classList.add('loaded');
    });
    img.addEventListener('error', () => {
      const key = img.getAttribute('data-logo');
      document.querySelector(`[data-logo-fallback="${key}"]`)?.classList.remove('hidden');
      img.classList.remove('loaded');
    });
  });
}

function requireAuth() {
  if (!authToken) {
    window.location.href = '/login.html';
    return false;
  }
  return true;
}


function applyMsisdnConstraints() {
  const msisdnInput = document.getElementById('payment-msisdn');
  const msisdnHelp = document.getElementById('payment-msisdn-help');
  if (!msisdnInput) return;

  if (selectedMethod === 'emola') {
    msisdnInput.placeholder = '86xxxxxxx';
    msisdnInput.pattern = '(86|87)[0-9]{7}';
    if (msisdnHelp) msisdnHelp.textContent = 'eMola: use 86xxxxxxx ou 87xxxxxxx';
  } else {
    msisdnInput.placeholder = '84xxxxxxx';
    msisdnInput.pattern = '(84|85)[0-9]{7}';
    if (msisdnHelp) msisdnHelp.textContent = 'M-Pesa: use 84xxxxxxx ou 85xxxxxxx';
  }
}


function setPaymentLocked(message, type = 'info') {
  const options = document.querySelectorAll('.payment-card');
  options.forEach((btn) => {
    btn.disabled = true;
    btn.classList.remove('active');
  });
  const formCard = document.getElementById('payment-form-card');
  if (formCard) formCard.classList.add('hidden');
  selectedMethod = '';
  setResult(message, type);
}

function setResult(message, type = 'info') {
  const el = document.getElementById('payment-result');
  if (!el) return;
  el.textContent = message;
  el.className = type === 'error' ? 'error' : type === 'success' ? 'success' : 'muted';
}

async function loadOrder() {
  if (!requireAuth() || !orderId) return;
  const res = await fetch(`${apiBase}/orders/${orderId}`, { headers: { Authorization: `Bearer ${authToken}` } });
  const data = await res.json();
  if (!res.ok) {
    setResult(data.message || 'Não foi possível carregar dados da fatura', 'error');
    return;
  }

  orderData = data.order;
  const invoiceStatus = String(orderData.invoice_estado || '').toUpperCase();
  if (invoiceStatus === 'PAGA') {
    setPaymentLocked('Esta fatura já está paga. Não é necessário iniciar novo débito.', 'success');
    return;
  }
  if (invoiceStatus === 'PAGAMENTO_EM_VALIDACAO') {
    setPaymentLocked('Já existe um pagamento em validação para esta fatura. Aguarde a confirmação automática.', 'info');
    return;
  }
  const amountField = document.getElementById('payment-amount');
  if (amountField) {
    amountField.value = String(orderData.valor_total || data.invoice_details?.valor_total || '').replace(',', '.');
    amountField.readOnly = true;
  }

  const refField = document.getElementById('payment-reference');
  if (refField) {
    refField.value = `Pagamento fatura ${orderData.invoice_numero || 'FAT-' + orderId}`;
  }

  const backInvoiceLink = document.getElementById('back-invoice-link');
  if (backInvoiceLink) {
    backInvoiceLink.href = `/invoice.html?id=${orderId}`;
  }
}

const logout = document.getElementById('logout');
if (logout) {
  logout.onclick = () => {
    localStorage.removeItem('token');
    window.location.href = '/login.html';
  };
}

document.querySelectorAll('.payment-card').forEach((btn) => {
  btn.addEventListener('click', () => {
    selectedMethod = btn.dataset.method || '';
    document.querySelectorAll('.payment-card').forEach((b) => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('payment-form-card')?.classList.remove('hidden');

    const title = document.getElementById('payment-title');
    const help = document.getElementById('payment-help');
    if (title) title.textContent = `Pagamento ${selectedMethod === 'emola' ? 'eMola' : 'M-Pesa'}`;
    if (help) help.textContent = `Indique o valor e o número ${selectedMethod === 'emola' ? 'eMola' : 'M-Pesa'} para receber o pedido de débito.`;
    applyMsisdnConstraints();
    setResult('');
  });
});


async function waitPaymentConfirmation(debitoReference) {
  const maxTries = 30; // ~2.5 min
  for (let i = 0; i < maxTries; i += 1) {
    await new Promise((r) => setTimeout(r, 5000));
    const res = await fetch(`${apiBase}/orders/${orderId}/debit-status?debito_reference=${encodeURIComponent(debitoReference || '')}`, {
      headers: { Authorization: `Bearer ${authToken}` },
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      if (res.status >= 500) continue;
      setResult(data.message || 'Erro ao consultar estado do pagamento', 'error');
      return false;
    }

    const normalized = String(data.status || '').toUpperCase();
    if (['SUCCESS', 'SUCCEEDED', 'COMPLETED', 'APPROVED'].includes(normalized)) {
      setResult('Pagamento confirmado com sucesso. A redirecionar para faturas...', 'success');
      setTimeout(() => { window.location.href = `/invoice.html?id=${orderId}`; }, 1200);
      return true;
    }
    if (['FAILED', 'REJECTED', 'CANCELLED', 'DECLINED', 'ERROR'].includes(normalized)) {
      setResult(`Pagamento não concluído (${normalized}). Tente novamente ou contacte o suporte.`, 'error');
      return false;
    }

    if (data.paid) {
      setResult('Pagamento confirmado com sucesso. A redirecionar para faturas...', 'success');
      setTimeout(() => { window.location.href = `/invoice.html?id=${orderId}`; }, 1200);
      return true;
    }
    const statusTxt = data.status ? ` (${data.status})` : '';
    setResult(`Aguardando confirmação do pagamento${statusTxt}...`);
  }
  setResult('Pagamento iniciado. Ainda pendente de confirmação. Pode verificar novamente em alguns instantes.', 'info');
  return false;
}

const form = document.getElementById('payment-form');
if (form) {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!requireAuth()) return;
    if (!selectedMethod) {
      setResult('Escolha primeiro o método de pagamento.', 'error');
      return;
    }

    const submitBtn = form.querySelector('button[type="submit"]');
    const msisdn = (document.getElementById('payment-msisdn')?.value || '').trim();
    const msisdnRegex = selectedMethod === 'emola' ? /^(86|87)\d{7}$/ : /^(84|85)\d{7}$/;
    if (!msisdnRegex.test(msisdn)) {
      setResult(selectedMethod === 'emola'
        ? 'Número eMola inválido. Use 86xxxxxxx ou 87xxxxxxx.'
        : 'Número M-Pesa inválido. Use 84xxxxxxx ou 85xxxxxxx.', 'error');
      return;
    }

    const payload = {
      method: selectedMethod,
      amount: Number(document.getElementById('payment-amount')?.value || 0),
      msisdn,
      reference_description: (document.getElementById('payment-reference')?.value || '').trim(),
    };

    try {
      if (submitBtn) submitBtn.disabled = true;
      setResult('A iniciar pagamento automático...');
      const res = await fetch(`${apiBase}/orders/${orderId}/debit-pay`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${authToken}`,
        },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.message || 'Não foi possível iniciar pagamento');
      const debitoRef = data.data?.debito_reference || '';
      const status = data.data?.status ? ` Status: ${data.data.status}.` : '';
      setResult(`Pedido enviado com sucesso.${debitoRef ? ` Ref: ${debitoRef}.` : ''}${status}`, 'success');
      if (debitoRef) {
        await waitPaymentConfirmation(debitoRef);
      }
    } catch (err) {
      setResult(err.message || 'Erro ao iniciar pagamento', 'error');
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  });
}

initPaymentLogos();
loadOrder();
