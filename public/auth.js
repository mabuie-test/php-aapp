const apiBase = '/api';
let authToken = localStorage.getItem('token') || '';

function showAppDialog(message, type = 'info') {
  const old = document.getElementById('app-dialog-overlay');
  if (old) old.remove();
  const overlay = document.createElement('div');
  overlay.id = 'app-dialog-overlay';
  overlay.style.cssText = 'position:fixed;inset:0;background:rgba(2,6,23,0.7);display:flex;align-items:center;justify-content:center;z-index:9999;padding:1rem;';
  const card = document.createElement('div');
  card.style.cssText = 'max-width:460px;width:100%;background:linear-gradient(145deg,#0f172a,#1e293b);border:1px solid rgba(11,99,230,0.5);border-radius:16px;padding:1rem 1.1rem;box-shadow:0 20px 40px rgba(0,0,0,0.35);color:#e2e8f0;font-family:Inter,system-ui,sans-serif;';
  const icon = type === 'success' ? '✅' : 'ℹ️';
  const iconBg = type === 'success' ? 'rgba(6,214,160,0.2)' : 'rgba(11,99,230,0.2)';
  card.innerHTML = `
    <div style="display:flex;align-items:center;gap:0.7rem;margin-bottom:0.75rem;">
      <span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:999px;background:${iconBg};font-size:1.1rem;">${icon}</span>
      <strong style="font-size:1.05rem;color:#f8fafc;">${type === 'success' ? 'Sucesso' : 'Informação'}</strong>
    </div>
    <p style="margin:0 0 1rem;line-height:1.45;color:#cbd5e1;">${String(message || '').replace(/</g, '&lt;')}</p>
    <div style="display:flex;justify-content:flex-end;">
      <button id="app-dialog-ok" style="border:none;border-radius:10px;padding:0.55rem 1rem;font-weight:700;cursor:pointer;background:linear-gradient(135deg,#0b63e6,#7c3aed);color:#fff;">OK</button>
    </div>`;
  overlay.appendChild(card);
  document.body.appendChild(overlay);
  const close = () => overlay.remove();
  overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
  card.querySelector('#app-dialog-ok')?.addEventListener('click', close);
}


function clearReferral() {
  sessionStorage.removeItem('referral_ref');
  sessionStorage.removeItem('referral_ref_ts');
  localStorage.removeItem('referral_ref');
}

function getActiveReferral() {
  const code = sessionStorage.getItem('referral_ref');
  const ts = Number(sessionStorage.getItem('referral_ref_ts') || 0);
  const TTL = 30 * 60 * 1000; // 30 minutos
  if (!code || !ts || (Date.now() - ts) > TTL) {
    clearReferral();
    return '';
  }
  return code;
}

function captureReferral() {
  const params = new URLSearchParams(window.location.search);
  const code = params.get('ref');

  if (!code) {
    // evita reaproveitar código antigo em novos registos não indicados
    clearReferral();
    return;
  }

  sessionStorage.setItem('referral_ref', code);
  sessionStorage.setItem('referral_ref_ts', String(Date.now()));
  localStorage.removeItem('referral_ref');

  let visitor = localStorage.getItem('affiliate_visitor_id');
  if (!visitor) {
    visitor = `v_${Math.random().toString(36).slice(2)}${Date.now().toString(36)}`;
    localStorage.setItem('affiliate_visitor_id', visitor);
  }

  fetch(`${apiBase}/affiliates/click`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ code, visitor }),
  }).catch(() => {});
}

captureReferral();

function toggleNav() {
  const role = localStorage.getItem('role');
  document.querySelectorAll('.anon-only').forEach((el) => (el.style.display = authToken ? 'none' : 'inline-flex'));
  document.querySelectorAll('.auth-only').forEach((el) => (el.style.display = authToken ? 'inline-flex' : 'none'));
  document.querySelectorAll('.admin-only').forEach((el) => (el.style.display = role === 'admin' ? 'inline-flex' : 'none'));
  const logout = document.getElementById('logout');
  if (logout) {
    logout.onclick = () => {
      authToken = '';
      localStorage.removeItem('token');
      localStorage.removeItem('role');
      toggleNav();
    };
  }
}

function renderReferralTag() {
  const tag = document.getElementById('referral-indicator');
  if (!tag) return;
  const code = getActiveReferral();
  if (code) {
    tag.textContent = `Indicação aplicada: ${code}`;
    tag.classList.remove('hidden');
  }
}

function handleLogin(token, role, options = {}) {
  if (token) {
    authToken = token;
    localStorage.setItem('token', token);
    if (role) localStorage.setItem('role', role);
    toggleNav();
    const destination = role === 'admin' ? '/admin.html' : '/';
    if (options.showSuccess) {
      showAppDialog(options.successMessage || 'Operação concluída com sucesso.', 'success');
      setTimeout(() => { window.location.href = destination; }, 900);
      return;
    }
    window.location.href = destination;
  }
}

async function requestReset(email) {
  const res = await fetch(`${apiBase}/auth/password/forgot`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email }),
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.message || 'Erro ao enviar email');
  return data;
}

async function resetPassword(payload) {
  const res = await fetch(`${apiBase}/auth/password/reset`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.message || 'Erro ao redefinir palavra-passe');
  return data;
}

const signupForm = document.getElementById('signup-form');
if (signupForm) {
  signupForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const payload = Object.fromEntries(new FormData(signupForm).entries());
    const ref = getActiveReferral();
    if (ref) payload.referred_by = ref;
    const res = await fetch(`${apiBase}/auth/register`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (res.ok && data.token) {
      clearReferral();
      handleLogin(data.token, data.user?.role, { showSuccess: true, successMessage: 'Conta criada com sucesso. Bem-vindo(a)!' });
    } else {
      showAppDialog(data.message || 'Erro no registo');
    }
  });
}

const signinForm = document.getElementById('signin-form');
if (signinForm) {
  signinForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const payload = Object.fromEntries(new FormData(signinForm).entries());
    const res = await fetch(`${apiBase}/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (res.ok && data.token) {
      clearReferral();
      handleLogin(data.token, data.user?.role, { showSuccess: true, successMessage: 'Login efectuado com sucesso.' });
    } else {
      showAppDialog(data.message || 'Erro no login');
    }
  });
}

const forgotForm = document.getElementById('forgot-form');
if (forgotForm) {
  forgotForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      await requestReset(forgotForm.email.value);
      showAppDialog('Enviámos um código e link para o seu email.', 'success');
    } catch (err) {
      showAppDialog(err.message);
    }
  });
}

const resetForm = document.getElementById('reset-form');
if (resetForm) {
  const params = new URLSearchParams(window.location.search);
  if (params.get('email')) resetForm.email.value = params.get('email');
  if (params.get('code')) resetForm.code.value = params.get('code');
  resetForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const payload = Object.fromEntries(new FormData(resetForm).entries());
    try {
      await resetPassword(payload);
      showAppDialog('Palavra-passe atualizada. Faça login novamente.', 'success');
      window.location.href = '/login.html';
    } catch (err) {
      showAppDialog(err.message);
    }
  });
}

toggleNav();
renderReferralTag();

const storedRole = localStorage.getItem('role');
const isAuthPage = ['/login.html', '/register.html', '/reset.html'].includes(window.location.pathname);
if (authToken && !isAuthPage && window.location.pathname !== '/') {
  window.location.href = storedRole === 'admin' ? '/admin.html' : '/';
}
