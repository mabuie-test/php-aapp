(function () {
  function keepDesktopHeaderVisible() {
    const header = document.querySelector('header');
    if (!header) return;
    document.body.classList.remove('navbar-open');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', keepDesktopHeaderVisible);
  } else {
    keepDesktopHeaderVisible();
  }
})();
