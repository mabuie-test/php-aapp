(function () {
  function ensureProgressUI(container) {
    if (!container) return null;
    let root = container.querySelector('.upload-progress');
    if (!root) {
      root = document.createElement('div');
      root.className = 'upload-progress upload-progress-hidden';
      root.innerHTML = `
        <div class="upload-progress-label">A enviar ficheiro...</div>
        <div class="upload-progress-track"><div class="upload-progress-bar"></div></div>
        <div class="upload-progress-meta">0%</div>
      `;
      container.appendChild(root);
    }
    return root;
  }

  function setProgress(root, pct, message) {
    if (!root) return;
    root.classList.remove('upload-progress-hidden');
    const bar = root.querySelector('.upload-progress-bar');
    const meta = root.querySelector('.upload-progress-meta');
    const label = root.querySelector('.upload-progress-label');
    const safePct = Math.max(0, Math.min(100, Math.round(Number(pct) || 0)));
    if (bar) bar.style.width = `${safePct}%`;
    if (meta) meta.textContent = `${safePct}%`;
    if (label && message) label.textContent = message;
  }

  function hideProgress(root) {
    if (!root) return;
    root.classList.add('upload-progress-hidden');
  }

  function uploadWithProgress(url, { method = 'POST', headers = {}, body, onProgress } = {}) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open(method, url, true);
      Object.entries(headers || {}).forEach(([k, v]) => xhr.setRequestHeader(k, v));
      xhr.upload.onprogress = function (event) {
        if (!event.lengthComputable || typeof onProgress !== 'function') return;
        onProgress((event.loaded / event.total) * 100, event.loaded, event.total);
      };
      xhr.onload = function () {
        const raw = xhr.responseText || '{}';
        let data;
        try { data = JSON.parse(raw); } catch (_) { data = { message: raw }; }
        resolve({ ok: xhr.status >= 200 && xhr.status < 300, status: xhr.status, data, raw });
      };
      xhr.onerror = () => reject(new Error('Falha de rede durante upload'));
      xhr.send(body);
    });
  }

  window.UploadUtils = { ensureProgressUI, setProgress, hideProgress, uploadWithProgress };
})();
