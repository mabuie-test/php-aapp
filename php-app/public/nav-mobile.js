(function () {
  function initHamburger() {
    const header = document.querySelector('header');
    const nav = document.querySelector('header .navbar, header nav');
    if (!header || !nav) return;
    if (document.getElementById('mobile-hamburger')) return;

    const btn = document.createElement('button');
    btn.id = 'mobile-hamburger';
    btn.type = 'button';
    btn.className = 'mobile-hamburger';
    btn.setAttribute('aria-label', 'Abrir menu');
    btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML = '<span></span><span></span><span></span>';

    const closeMenu = () => {
      document.body.classList.remove('navbar-open');
      btn.setAttribute('aria-expanded', 'false');
    };

    btn.addEventListener('click', () => {
      const isOpen = document.body.classList.toggle('navbar-open');
      btn.setAttribute('aria-expanded', String(isOpen));
    });

    nav.querySelectorAll('a,button').forEach((el) => {
      el.addEventListener('click', () => {
        if (window.innerWidth <= 768) closeMenu();
      });
    });

    header.insertBefore(btn, header.firstChild);

    const mq = window.matchMedia('(min-width: 769px)');
    function onDesktop() {
      if (mq.matches) closeMenu();
    }
    onDesktop();
    try { mq.addEventListener('change', onDesktop); } catch (_) {}
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initHamburger);
  } else {
    initHamburger();
  }
})();
