const apiBase = '/api';
let authToken = localStorage.getItem('token') || '';

function syncNav() {
  const role = localStorage.getItem('role');
  document.querySelectorAll('.anon-only').forEach((el) => (el.style.display = authToken ? 'none' : 'inline-flex'));
  document.querySelectorAll('.auth-only').forEach((el) => (el.style.display = authToken ? 'inline-flex' : 'none'));
  document.querySelectorAll('.admin-only').forEach((el) => (el.style.display = role === 'admin' ? 'inline-flex' : 'none'));
}

function captureReferralAttribution() {
  const params = new URLSearchParams(window.location.search);
  const ref = params.get('ref');
  if (ref) {
    sessionStorage.setItem('referral_ref', ref);
    sessionStorage.setItem('referral_ref_ts', String(Date.now()));
    localStorage.removeItem('referral_ref');
    const banner = document.getElementById('referral-banner');
    if (banner) {
      banner.textContent = `Ligação de indicação aplicada: ${ref}`;
      banner.classList.add('pill');
    }

    let visitor = localStorage.getItem('affiliate_visitor_id');
    if (!visitor) {
      visitor = `v_${Math.random().toString(36).slice(2)}${Date.now().toString(36)}`;
      localStorage.setItem('affiliate_visitor_id', visitor);
    }

    fetch(`${apiBase}/affiliates/click`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code: ref, visitor }),
    }).catch(() => {});
  }
}

captureReferralAttribution();


function captureTrafficAttribution() {
  const params = new URLSearchParams(window.location.search);
  const keys = ['utm_source','utm_medium','utm_campaign','utm_term','utm_content','gclid','fbclid','ref'];
  const payload = { funnel_step: 'landing' };
  let hasData = false;
  keys.forEach((k) => {
    const v = params.get(k);
    if (v) {
      payload[k] = v;
      hasData = true;
      sessionStorage.setItem(`attr_${k}`, v);
    } else {
      const saved = sessionStorage.getItem(`attr_${k}`);
      if (saved) payload[k] = saved;
    }
  });

  let visitor = localStorage.getItem('mk_visitor_id');
  if (!visitor) {
    visitor = `mk_${Math.random().toString(36).slice(2)}${Date.now().toString(36)}`;
    localStorage.setItem('mk_visitor_id', visitor);
  }
  payload.visitor_id = visitor;

  if (hasData || payload.ref) {
    fetch(`${apiBase}/marketing/attribution`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).catch(() => {});
  }
}

captureTrafficAttribution();


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

function showToast(text) {
  const zone = document.getElementById('feedback');
  if (zone) {
    zone.textContent = text;
    zone.classList.add('visible');
    setTimeout(() => zone.classList.remove('visible'), 2500);
  } else {
    alert(text);
  }
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
    authToken = '';
    localStorage.removeItem('token');
    localStorage.removeItem('role');
    syncNav();
    window.location.href = '/login.html';
  };
}

const orderForm = document.getElementById('order-form');
const materialsToggle = document.getElementById('has-materials');
if (materialsToggle) {
  materialsToggle.onchange = () => {
    const box = document.getElementById('materials-extra');
    if (box) box.style.display = materialsToggle.value === 'sim' ? 'block' : 'none';
  };
  materialsToggle.onchange();
}
if (orderForm) {
  orderForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!requireAuth()) return;
    const ok = await confirmAction('Confirmar envio desta encomenda?');
    if (!ok) return;
    const raw = new FormData(orderForm);
    const payload = new FormData();
    payload.set('tipo', raw.get('workType'));
    payload.set('area', raw.get('area'));
    payload.set('nivel', raw.get('academicLevel'));
    payload.set('paginas', raw.get('pages'));
    payload.set('norma', raw.get('formatting'));
    payload.set('complexidade', raw.get('complexity'));
    payload.set('urgencia', raw.get('urgency'));
    payload.set('descricao', raw.get('description'));
    payload.set('prazo_entrega', raw.get('deliveryDeadline'));
    if (raw.get('referralCode')) {
      payload.set('referral_code', raw.get('referralCode'));
    }
    if (raw.get('hasMaterials') === 'sim') {
      payload.set('materiais_info', 'Materiais fornecidos pelo cliente');
      if (raw.get('materialsUsagePercent')) {
        payload.set('materiais_percentual', raw.get('materialsUsagePercent'));
      }
      const materialsField = document.getElementById('materialsFiles');
      if (materialsField?.files?.length) {
        Array.from(materialsField.files).forEach((file) => payload.append('materiais_uploads[]', file));
      }
    }
    const submitBtn = orderForm.querySelector('button[type="submit"]');
    const progress = window.UploadUtils?.ensureProgressUI(orderForm);
    try {
      if (submitBtn) submitBtn.disabled = true;
      const result = window.UploadUtils
        ? await window.UploadUtils.uploadWithProgress(`${apiBase}/orders`, {
            method: 'POST',
            headers: { Authorization: `Bearer ${authToken}` },
            body: payload,
            onProgress: (pct) => window.UploadUtils.setProgress(progress, pct, 'A enviar materiais...'),
          })
        : { ok: false, data: { message: 'UploadUtils indisponível' } };
      if (!result.ok) throw new Error(result.data.message || 'Erro ao criar encomenda');
      orderForm.reset();
      showToast('Encomenda criada e fatura emitida.');
      setTimeout(() => {
        window.location.href = `/invoice.html?id=${result.data.order_id}`;
      }, 300);
    } catch (err) {
      showToast(err.message);
    } finally {
      if (submitBtn) submitBtn.disabled = false;
      if (progress) window.UploadUtils.hideProgress(progress);
    }
  });
}

const quoteBtn = document.getElementById('simulate-quote');
if (quoteBtn) {
  quoteBtn.addEventListener('click', async () => {
    if (!requireAuth()) return;
    const raw = new FormData(orderForm);
    const body = {
      paginas: Number(raw.get('pages') || 0),
      nivel: raw.get('academicLevel'),
      complexidade: raw.get('complexity'),
      urgencia: raw.get('urgency'),
    };
    try {
      const res = await fetch(`${apiBase}/orders/quote`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${authToken}` },
        body: JSON.stringify(body),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.message || 'Não foi possível calcular');
      const zone = document.getElementById('quote-preview');
      if (zone) {
        zone.innerHTML = `<p><strong>Total estimado:</strong> ${data.total}</p><p>Base ${data.base} × ${body.paginas} páginas · nível x${data.levelFactor} · complexidade x${data.complexityFactor} · urgência x${data.urgencyFactor}</p>`;
      }
    } catch (err) {
      showToast(err.message);
    }
  });
}

async function loadOrders() {
  if (!requireAuth()) return;
  try {
    const res = await fetch(`${apiBase}/orders`, { headers: { Authorization: `Bearer ${authToken}` } });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || 'Erro ao carregar encomendas');
    const list = document.getElementById('orders-list');
    if (!list) return;
    list.innerHTML = '';
    data.orders.forEach((order) => {
      const item = document.createElement('div');
      item.className = 'card';
      item.innerHTML = `
        <h4>${order.tipo} · ${order.area}</h4>
        <p>Estado: <strong>${order.estado}</strong></p>
        <p>Fatura: ${order.invoice_numero || '—'} (${order.invoice_estado || 'EMITIDA'})</p>
        <p>Total: ${order.valor_total || '—'}</p>
        ${order.final_file ? `<p class="success">Trabalho final disponível: <a href="${order.final_file}" target="_blank">baixar</a></p>` : ''}
        <div class="stacked-actions">
          <a class="primary" href="/invoice.html?id=${order.id}" target="_blank">Ver fatura</a>
        </div>
      `;
      list.appendChild(item);
    });
  } catch (err) {
    showToast(err.message);
  }
}

if (document.getElementById('orders-list')) {
  loadOrders();
  setInterval(loadOrders, 8000);
}

async function loadNotifications() {
  if (!requireAuth()) return;
  try {
    const res = await fetch(`${apiBase}/notifications`, { headers: { Authorization: `Bearer ${authToken}` } });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || 'Erro ao carregar alertas');
    const list = document.getElementById('notifications-list');
    if (!list) return;
    list.innerHTML = '';
    (data.notifications || []).forEach((n) => {
      const item = document.createElement('div');
      item.className = 'list-item';
      const meta = n.meta || {};
      const hint = meta.invoice_id ? `Fatura #${meta.invoice_id}` : meta.order_id ? `Encomenda #${meta.order_id}` : '';
      item.innerHTML = `<div><strong>${n.action}</strong><p class="muted">${hint}</p></div><span class="badge">${n.created_at || ''}</span>`;
      list.appendChild(item);
    });
  } catch (err) {
    console.error(err);
  }
}

if (document.getElementById('notifications-list')) {
  loadNotifications();
  setInterval(loadNotifications, 20000);
}

async function loadAffiliate() {
  if (!requireAuth()) return;
    try {
      const res = await fetch(`${apiBase}/affiliates/summary`, { headers: { Authorization: `Bearer ${authToken}` } });
      const data = await res.json();
      const box = document.getElementById('affiliate-panel');
      if (!box) return;
      if (!res.ok) throw new Error(data.message || 'Erro no programa de afiliados');
      const shareLink = data.code ? `${window.location.origin}/register.html?ref=${data.code}` : '';
      const commissions = data.commissions || [];
      const payouts = data.payouts || [];
    box.innerHTML = `
      <div class="pill">O seu código: <strong>${data.code || '—'}</strong></div>
        <div class="share-row">
          <input id="share-link" value="${shareLink}" ${shareLink ? '' : 'placeholder="Sem código disponível"'} readonly />
          <button class="ghost" id="copy-share" ${shareLink ? '' : 'disabled'}>Copiar link</button>
        </div>
        <div class="grid metrics">
          <div><p class="muted">Aguardando validação</p><h4>${data.totals.pending} MZN</h4></div>
          <div><p class="muted">Liberado</p><h4>${data.totals.approved} MZN</h4></div>
          <div><p class="muted">Pago</p><h4>${data.totals.paid} MZN</h4></div>
        </div>
      <div class="grid metrics">
        <div><p class="muted">Saldo disponível</p><h4>${data.available ?? 0} MZN</h4></div>
        <div><p class="muted">Em pedido de levantamento</p><h4>${data.outstanding ?? 0} MZN</h4></div>
      </div>
      <div class="grid metrics">
        <div><p class="muted">Total de afiliados ativos</p><h4>${data.stats?.referred_count ?? 0}</h4></div>
        <div><p class="muted">Cliques no link (total)</p><h4>${data.stats?.clicks_total ?? 0}</h4></div>
        <div><p class="muted">Cliques únicos</p><h4>${data.stats?.clicks_unique ?? 0}</h4></div>
        <div><p class="muted">Cliques hoje</p><h4>${data.stats?.clicks_today ?? 0}</h4></div>
      </div>
      <p class="muted">Privacidade: mostramos apenas números agregados, sem revelar identidade dos clientes afiliados.</p>
      <div class="stacked">
        <label>Número M-Pesa para receber</label>
        <input type="text" id="payout-mpesa" placeholder="84/85xxxxxxx" />
        <label>Observações (opcional)</label>
        <input type="text" id="payout-notes" placeholder="Ex: preferir transferência" />
      </div>
      <button class="primary" id="request-payout">Pedir levantamento</button>
      <h4>Comissões recentes</h4>
      <div class="list">${commissions.map((c) => `<div class="list-item"><div>#${c.order_id} · ${c.amount} MZN</div><span class="badge">${c.status}</span></div>`).join('') || '<p class="muted">Sem comissões ainda</p>'}</div>
      <h4>Levantamentos</h4>
      <div class="list">${payouts.map((p) => `<div class="list-item"><div>Pedido #${p.id} · ${p.valor} MZN</div><span class="badge">${p.status}</span></div>`).join('') || '<p class="muted">Nenhum pedido</p>'}</div>
    `;
    const payoutBtn = document.getElementById('request-payout');
    if (payoutBtn) payoutBtn.onclick = () => requestPayout();
    const copyBtn = document.getElementById('copy-share');
    if (copyBtn && shareLink) {
      copyBtn.onclick = async () => {
        await navigator.clipboard.writeText(shareLink);
        showToast('Link de afiliado copiado.');
      };
    }
  } catch (err) {
    showToast(err.message);
  }
}

async function requestPayout() {
  if (!requireAuth()) return;
  try {
    const res = await fetch(`${apiBase}/affiliates/request-payout`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${authToken}` },
      body: JSON.stringify({
        notes: document.getElementById('payout-notes')?.value || 'Levantamento solicitado via painel',
        mpesa: document.getElementById('payout-mpesa')?.value || null,
      }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || 'Não foi possível registar o pedido');
    showToast('Pedido de levantamento enviado.');
    loadAffiliate();
  } catch (err) {
    showToast(err.message);
  }
}

if (document.getElementById('affiliate-panel')) {
  loadAffiliate();
}

const serviceForm = document.getElementById('service-form');
if (serviceForm) {
  serviceForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!requireAuth()) return;
    const ok = await confirmAction('Submeter este pedido especializado?');
    if (!ok) return;
    const raw = new FormData(serviceForm);
    const payload = new FormData();
    ['categoria', 'contact_name', 'contact_email', 'contact_phone', 'detalhes', 'norma_preferida', 'software_preferido'].forEach((f) => {
      if (raw.get(f)) payload.set(f, raw.get(f));
    });
    if (serviceForm.querySelector('input[name="attachment"]')?.files?.length) {
      payload.append('attachment', serviceForm.querySelector('input[name="attachment"]').files[0]);
    }
    const submitBtn = serviceForm.querySelector('button[type="submit"]');
    const progress = window.UploadUtils?.ensureProgressUI(serviceForm);
    try {
      if (submitBtn) submitBtn.disabled = true;
      const result = window.UploadUtils
        ? await window.UploadUtils.uploadWithProgress(`${apiBase}/services`, { method: 'POST', headers: { Authorization: `Bearer ${authToken}` }, body: payload, onProgress: (pct) => window.UploadUtils.setProgress(progress, pct, 'A enviar anexo...') })
        : { ok: false, data: { message: 'UploadUtils indisponível' } };
      if (!result.ok) throw new Error(result.data.message || 'Erro ao registar serviço');
      showToast('Pedido especializado enviado.');
      serviceForm.reset();
      loadMyServices();
    } catch (err) {
      showToast(err.message);
    } finally {
      if (submitBtn) submitBtn.disabled = false;
      if (progress) window.UploadUtils.hideProgress(progress);
    }
  });
}

['tcc-form', 'special-form'].forEach((id) => {
  const form = document.getElementById(id);
  if (!form) return;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!requireAuth()) return;
    const ok = await confirmAction('Confirmar envio do pedido?');
    if (!ok) return;
    const raw = new FormData(form);
    const payload = new FormData();
    const categoria = id === 'tcc-form' ? 'Acompanhamento TCC' : 'Trabalho prático especial';
    payload.set('categoria', categoria);
    payload.set('contact_name', raw.get('contactName'));
    payload.set('contact_email', raw.get('contactEmail'));
    if (raw.get('contactPhone')) payload.set('contact_phone', raw.get('contactPhone'));
    if (raw.get('details')) payload.set('detalhes', raw.get('details'));
    if (raw.get('goals')) payload.set('norma_preferida', raw.get('goals'));
    try {
      const res = await fetch(`${apiBase}/services`, { method: 'POST', headers: { Authorization: `Bearer ${authToken}` }, body: payload });
      const data = await res.json();
      if (!res.ok) throw new Error(data.message || 'Erro ao enviar pedido');
      showToast('Pedido submetido com sucesso.');
      form.reset();
      loadMyServices();
    } catch (err) {
      showToast(err.message);
    }
  });
});

async function loadMyServices() {
  const container = document.getElementById('service-list');
  if (!container) return;
  if (!authToken) {
    container.innerHTML = '<p class="muted">Inicie sessão para acompanhar os pedidos.</p>';
    return;
  }
  try {
    const res = await fetch(`${apiBase}/services`, { headers: { Authorization: `Bearer ${authToken}` } });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || 'Erro ao carregar serviços');
    if (!data.services || !data.services.length) {
      container.innerHTML = '<p class="muted">Ainda sem pedidos especializados.</p>';
      return;
    }
    container.innerHTML = '';
    data.services.forEach((svc) => {
      const row = document.createElement('div');
      row.className = 'list-row';
      row.innerHTML = `
        <div>
          <strong>${svc.categoria}</strong>
          <p class="muted">${svc.detalhes || ''}</p>
          ${svc.attachment ? `<a href="${svc.attachment}" target="_blank">Ver anexo</a>` : ''}
        </div>
        <div class="badge">${svc.status}</div>
      `;
      container.appendChild(row);
    });
  } catch (err) {
    container.innerHTML = `<p class="muted">${err.message}</p>`;
  }
}

if (document.getElementById('service-list')) {
  loadMyServices();
  setInterval(loadMyServices, 20000);
}

document.querySelectorAll('[data-service-type]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const type = btn.getAttribute('data-service-type');
    const select = document.getElementById('service-type');
    if (select) select.value = type;
    document.getElementById('service-card')?.scrollIntoView({ behavior: 'smooth' });
  });
});

syncNav();
// No main.js, adicione:
document.addEventListener('DOMContentLoaded', function() {
  const token = localStorage.getItem('token');
  if (token) {
    document.body.classList.add('logged-in');
  }
  
  // Atualizar quando fizer login/logout
  document.getElementById('logout')?.addEventListener('click', function() {
    document.body.classList.remove('logged-in');
  });
});

// ======= INJEÇÃO DE NOVOS SERVIÇOS + BOTÃO "VOLTAR" =======
(function () {
  // utilitário para criar elemento card seguindo o mesmo markup do index
  function createServiceCard(opts = {}) {
    const a = document.createElement('a');
    a.href = opts.href || '#';
    a.className = 'service-featured-card fade-in';
    a.id = opts.id || '';
    a.innerHTML = `
      <div class="service-icon"><i class="${opts.icon || 'fas fa-briefcase'}"></i></div>
      <h3>${opts.title}</h3>
      <p>${opts.description}</p>
      <ul class="service-features">
        ${(opts.features || []).map(f => `<li>${f}</li>`).join('')}
      </ul>
      <div class="service-cta"><span>${opts.ctaText || 'Saber mais'}</span><i class="fas fa-arrow-right"></i></div>
    `;
    return a;
  }

  // Serviços a inserir (IDs únicos para evitar duplicação)
  const dynamicServices = [
    {
      id: 'svc-trabalhos-campo',
      title: 'Trabalhos de Campo',
      href: '/order.html?tipo=TrabalhosCampo',
      icon: 'fas fa-mountain',
      description: 'Coleta e organização de dados de campo com relatório técnico.',
      features: ['Levantamento de dados', 'Análise e apresentação', 'Entrega formatada'],
      ctaText: 'Solicitar Trabalho de Campo'
    },
    {
      id: 'svc-trabalhos-pesquisa',
      title: 'Trabalhos de Pesquisa',
      href: '/order.html?tipo=TrabalhosPesquisa',
      icon: 'fas fa-flask',
      description: 'Pesquisa bibliográfica e escrita académica orientada por objetivo.',
      features: ['Pesquisa bibliográfica', 'Metodologia', 'Discussão e conclusão'],
      ctaText: 'Solicitar Trabalho de Pesquisa'
    },
    {
      id: 'svc-exames',
      title: 'Exames',
      href: '/order.html?tipo=Exames',
      icon: 'fas fa-pen-fancy',
      description: 'Elaboração e correção de exames, testes e fichas de avaliação.',
      features: ['Criação de enunciados', 'Grelhas de correção', 'Relatório de notas'],
      ctaText: 'Solicitar Exame'
    },
    {
      id: 'svc-relatorios-estagio',
      title: 'Relatórios de Estágio',
      href: '/career.html?service=InternshipReport',
      icon: 'fas fa-file-invoice',
      description: 'Relatórios de estágio completos, estruturados e formatados conforme normas.',
      features: ['Capa e índice', 'Relatório formatado', 'Entrega em Word/PDF'],
      ctaText: 'Solicitar Relatório de Estágio'
    }
  ];

  // insere os cartões na .services-grid-featured (se existir)
  function injectServicesIntoIndex() {
    const grid = document.querySelector('.services-grid-featured');
    if (!grid) return;

    dynamicServices.forEach((svc, idx) => {
      // evita duplicar se já existir um elemento com o mesmo id
      if (svc.id && document.getElementById(svc.id)) return;
      const card = createServiceCard(svc);
      // acrescentar stagger class para animação compatível
      card.classList.add('stagger-' + Math.min(4, idx + 1));
      grid.appendChild(card);
    });
  }

  // cria também links resumidos na secção de cards (cards-grid) sem duplicar
  // com ordenação corrigida: Ferramentas Úteis APÓS Catálogo Completo
  function injectMoreServicesCards() {
    const cardsGrid = document.querySelector('.cards-grid');
    if (!cardsGrid) return;

    // Localiza o card "Catálogo Completo" (serviços especializados)
    const catalogoCard = Array.from(cardsGrid.children).find(
      child => child.querySelector('h3')?.textContent.includes('Catálogo Completo')
    );

    // Card de Ferramentas úteis
    if (!document.getElementById('card-tools')) {
      const toolsCard = document.createElement('a');
      toolsCard.className = 'link-card card fade-in';
      toolsCard.id = 'card-tools';
      toolsCard.href = '/tools.html';
      toolsCard.innerHTML = `
        <div class="card">
          <h3>Ferramentas Úteis</h3>
          <p class="muted">Conversores e utilitários (iLovePDF, PDF24, conversor voz→texto, formatação APA).</p>
          <ul>
            <li>Conversores externos</li>
            <li>Formatação APA 6/7</li>
            <li>Minhas notas</li>
          </ul>
        </div>
      `;

      // Insere depois do card "Catálogo Completo", se existir; senão, no final
      if (catalogoCard) {
        catalogoCard.insertAdjacentElement('afterend', toolsCard);
      } else {
        cardsGrid.appendChild(toolsCard);
      }
    }

    // Card de Carreira e Estágios (permanece ao final)
    if (!document.getElementById('card-career')) {
      const careerCard = document.createElement('a');
      careerCard.className = 'link-card card fade-in';
      careerCard.id = 'card-career';
      careerCard.href = '/career.html';
      careerCard.innerHTML = `
        <div class="card">
          <h3>Carreira e Estágios</h3>
          <p class="muted">CV, cartas de apresentação e relatórios de estágio com faturação automática.</p>
          <ul>
            <li>CV profissional</li>
            <li>Carta de apresentação</li>
            <li>Relatório de estágio</li>
          </ul>
        </div>
      `;
      cardsGrid.appendChild(careerCard);
    }
  }

  // adiciona botão "Voltar" flutuante nas páginas internas, sem alterar index.html
  function addFloatingBackButton() {
    // não adicionar no index ("/" ou "/index.html")
    const path = window.location.pathname.replace(/\/$/, '');
    if (path === '' || path === '/index.html' || path === '/') return;

    if (document.querySelector('.back-floating')) return;

    // criar estilo se ainda não existir
    if (!document.getElementById('back-floating-style')) {
      const style = document.createElement('style');
      style.id = 'back-floating-style';
      style.innerHTML = `
        .back-floating {
          position: fixed;
          top: 96px;
          left: 16px;
          z-index: 1200;
          background: rgba(6, 214, 160, 0.12);
          color: var(--accent, #06d6a0);
          border: 1px solid rgba(6,214,160,0.22);
          padding: 8px 12px;
          border-radius: 8px;
          font-weight: 700;
          cursor: pointer;
          backdrop-filter: blur(6px);
          box-shadow: 0 6px 18px rgba(0,0,0,0.28);
        }
        .back-floating:hover { transform: translateY(-3px); }
      `;
      document.head.appendChild(style);
    }

    const btn = document.createElement('button');
    btn.className = 'back-floating';
    btn.innerHTML = '← Voltar';
    btn.title = 'Voltar para a página anterior';
    btn.onclick = function () {
      if (window.history.length > 1) {
        window.history.back();
      } else {
        // fallback para home se não existir histórico
        window.location.href = '/';
      }
    };
    document.body.appendChild(btn);
  }

  // insere um pequeno link "Voltar" dentro de formulários career.html (se existirem)
  function insertInlineBackOnCareer() {
    // procura forms que tenham input hidden service_type ou forms com id cv-form, cover-form, report-form
    const forms = [];
    ['cv-form', 'cover-form', 'report-form'].forEach(id => {
      const f = document.getElementById(id);
      if (f) forms.push(f);
    });
    // também pega por presence de input[name="service_type"]
    document.querySelectorAll('form').forEach(f => {
      if (f.querySelector('input[name="service_type"]')) {
        if (!forms.includes(f)) forms.push(f);
      }
    });
    if (!forms.length) return;
    forms.forEach(f => {
      if (f.querySelector('.inline-back')) return;
      const back = document.createElement('div');
      back.className = 'inline-back';
      back.style.marginBottom = '8px';
      back.innerHTML = `<a href="javascript:history.back()" style="color:var(--accent);font-weight:700;text-decoration:none">← Voltar</a>`;
      f.insertAdjacentElement('beforebegin', back);
    });
  }

  // preenche automaticamente o formulário de career.html quando existe ?service=NAME
  function prefillCareerFromQuery() {
    const params = new URLSearchParams(window.location.search);
    const svc = params.get('service') || params.get('tipo') || null;
    if (!svc) return;
    // tenta preencher hidden input service_type em career forms
    const forms = document.querySelectorAll('form');
    forms.forEach(f => {
      const input = f.querySelector('input[name="service_type"]');
      if (input) {
        input.value = svc;
        // highlight do form alvo (se quiser)
        f.style.boxShadow = '0 6px 20px rgba(6,214,160,0.08)';
        // se existir botão submit, foca-lo
        const submit = f.querySelector('button[type="submit"], input[type="submit"]');
        if (submit) {
          // scroll to form
          f.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      }
    });
  }

  // rodar injeções no DOMContentLoaded
  document.addEventListener('DOMContentLoaded', function () {
    try {
      injectServicesIntoIndex();
      injectMoreServicesCards();
      addFloatingBackButton();
      insertInlineBackOnCareer();
      prefillCareerFromQuery();
    } catch (e) {
      console.error('Inject services error', e);
    }
  });

  // caso a página seja carregada dinamicamente (SPA-like), observa mudanças no body e re-aplica
  const observer = new MutationObserver(() => {
    try {
      injectServicesIntoIndex();
      injectMoreServicesCards();
      addFloatingBackButton();
      insertInlineBackOnCareer();
      prefillCareerFromQuery();
    } catch (e) {}
  });
  observer.observe(document.body, { childList: true, subtree: true });
})();

// ======= INJETAR LINKS NO HEADER (Ferramentas, Carreira) =======
(function () {
  function ensureHeaderLinks() {
    const nav = document.querySelector('.nav-links') || document.querySelector('.navbar .nav-links');
    if (!nav) return;

    // helpers
    function makeLink(id, href, text, extraClass = '') {
      if (document.getElementById(id)) return null; // evita duplicar
      const a = document.createElement('a');
      a.id = id;
      a.href = href;
      a.className = extraClass;
      a.innerHTML = text;
      return a;
    }

    // Ferramentas: público (pode ser acessível sem login)
    const toolsLink = makeLink('nav-tools', '/tools.html', 'Ferramentas');
    if (toolsLink) {
      // inserir antes do último grupo ou no fim
      const refNode = nav.querySelector('a[href="/services.html"]') || nav.children[nav.children.length - 1];
      refNode ? nav.insertBefore(toolsLink, refNode.nextSibling) : nav.appendChild(toolsLink);
    }

    // Carreira: preferível exigir autenticação (auth-only)
    const careerLink = makeLink('nav-career', '/career.html', 'Carreira & Estágios', 'auth-only');
    if (careerLink) {
      // inserir próximo a Serviços/Afiliados
      const refNode2 = nav.querySelector('a[href="/services.html"]') || nav.querySelector('a[href="/affiliates.html"]') || null;
      refNode2 ? nav.insertBefore(careerLink, refNode2.nextSibling) : nav.appendChild(careerLink);
    }

    // Atualiza visibilidade já que o teu script controla auth-only / anon-only classes
    const token = localStorage.getItem('token');
    const role = localStorage.getItem('role');
    document.querySelectorAll('.auth-only').forEach(el => { el.style.display = token ? 'inline-flex' : 'none'; });
    document.querySelectorAll('.anon-only').forEach(el => { el.style.display = token ? 'none' : 'inline-flex'; });
    document.querySelectorAll('.admin-only').forEach(el => { el.style.display = role === 'admin' ? 'inline-flex' : 'none'; });
  }

  // roda ao carregar e quando o DOM for alterado (SPA)
  document.addEventListener('DOMContentLoaded', ensureHeaderLinks);
  const headerObserver = new MutationObserver(ensureHeaderLinks);
  headerObserver.observe(document.body, { childList: true, subtree: true });
})();
