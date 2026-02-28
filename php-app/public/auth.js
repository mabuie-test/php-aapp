const apiBase = '/api';
let authToken = localStorage.getItem('token') || '';

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

function handleLogin(token, role) {
  if (token) {
    authToken = token;
    localStorage.setItem('token', token);
    if (role) localStorage.setItem('role', role);
    toggleNav();
    window.location.href = role === 'admin' ? '/admin.html' : '/';
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
      handleLogin(data.token, data.user?.role);
    } else {
      alert(data.message || 'Erro no registo');
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
      handleLogin(data.token, data.user?.role);
    } else {
      alert(data.message || 'Erro no login');
    }
  });
}

const forgotForm = document.getElementById('forgot-form');
if (forgotForm) {
  forgotForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      await requestReset(forgotForm.email.value);
      alert('Enviámos um código e link para o seu email.');
    } catch (err) {
      alert(err.message);
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
      alert('Palavra-passe atualizada. Faça login novamente.');
      window.location.href = '/login.html';
    } catch (err) {
      alert(err.message);
    }
  });
}

toggleNav();
renderReferralTag();

const storedRole = localStorage.getItem('role');
if (authToken && window.location.pathname !== '/') {
  window.location.href = storedRole === 'admin' ? '/admin.html' : '/';
}
