const apiBase = '/api';
let authToken = localStorage.getItem('token') || '';
const params = new URLSearchParams(window.location.search);
const orderId = params.get('id');
let selectedMethod = '';
let orderData = null;

function requireAuth() {
  if (!authToken) {
    window.location.href = '/login.html';
    return false;
  }
  return true;
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
  const amountField = document.getElementById('payment-amount');
  if (amountField) {
    amountField.value = String(orderData.valor_total || data.invoice_details?.valor_total || '').replace(',', '.');
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
    setResult('');
  });
});

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
    const payload = {
      method: selectedMethod,
      amount: Number(document.getElementById('payment-amount')?.value || 0),
      msisdn: (document.getElementById('payment-msisdn')?.value || '').trim(),
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
      const debitoRef = data.data?.debito_reference ? ` Ref: ${data.data.debito_reference}.` : '';
      const status = data.data?.status ? ` Status: ${data.data.status}.` : '';
      setResult(`Pedido enviado com sucesso.${debitoRef}${status}`, 'success');
    } catch (err) {
      setResult(err.message || 'Erro ao iniciar pagamento', 'error');
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  });
}

loadOrder();
