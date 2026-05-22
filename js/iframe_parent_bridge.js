/* /LKW/js/iframe_parent_bridge.js */
(() => {
  // Nur sinnvoll, wenn die Seite wirklich in einem iframe läuft
  if (window.parent === window) return;

  const TARGET_ORIGIN = window.location.origin;
  let lastY = window.scrollY || window.pageYOffset || 0;
  let ticking = false;

  function postToParent(payload) {
    try {
      window.parent.postMessage(payload, TARGET_ORIGIN);
    } catch (e) {
      // bewusst still
    }
  }

  function notifyCloseMenu() {
    postToParent({ type: 'workbench:iframe-close-menu' });
  }

  function notifyScroll(y, direction) {
    postToParent({
      type: 'workbench:iframe-scroll',
      y,
      direction
    });
  }

  // Jede Interaktion im iframe soll das Offcanvas schließen
  document.addEventListener('pointerdown', () => {
    notifyCloseMenu();
  }, true);

  // Scroll im iframe an Parent melden
  function onScroll() {
    if (ticking) return;
    ticking = true;

    requestAnimationFrame(() => {
      const y = window.scrollY || window.pageYOffset || 0;
      const diff = y - lastY;

      if (Math.abs(diff) >= 4 || y <= 4) {
        notifyScroll(y, diff > 0 ? 'down' : 'up');
        notifyCloseMenu();
        lastY = y;
      }

      ticking = false;
    });
  }

  window.addEventListener('scroll', onScroll, { passive: true });
})();