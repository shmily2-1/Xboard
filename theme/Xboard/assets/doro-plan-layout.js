/* Display-only enhancements: no API calls, storage, or purchase handlers. */
(function () {
  'use strict';
  var root = document.documentElement;
  var app;
  var observer;
  var pending = false;
  var options = { childList: true, subtree: true, characterData: true };

  function isPlanPage() {
    return /^#\/plan(?:\/\d+)?\/?(?:\?.*)?$/.test(window.location.hash);
  }

  function setText(element, text) {
    if (element.textContent !== text) element.textContent = text;
  }

  function enhance() {
    pending = false;
    var active = isPlanPage();
    root.toggleAttribute('data-doro-plan-page', active);
    if (!active || !app) return;

    // Do not observe our own writes or interfere with Vue's subsequent updates.
    observer.disconnect();
    try {
      app.querySelectorAll('.card-container .n-card-header__extra .text-3xl').forEach(function (price) {
        if (price.childElementCount) return;
        var match = price.textContent.trim().match(/^([^\d+\-]*)(\d+(?:\.\d+)?)$/);
        if (!match) return;
        var amount = Number(match[2]);
        if (!Number.isFinite(amount)) return;
        setText(price, match[1] + amount.toFixed(2));
      });
      app.querySelectorAll('.card-container .n-card-header__extra .text-base').forEach(function (cycle) {
        if (cycle.childElementCount) return;
        var label = cycle.textContent.replace(/^\s*[\/\u2014]\s*/, '').trim();
        if (label) setText(cycle, label);
      });
      app.querySelectorAll('.markdown-body .ny-pack .ny-tag').forEach(function (tag) {
        // Match this exact heading only: other question marks are not damaged icons.
        var label = tag.textContent.trim();
        if (!/^(?:[?\uff1f\ufffd]|\u{1f680}\ufe0f?)?\s*套餐核心权益$/u.test(label)) return;
        if (tag.querySelector('.doro-plan-benefits-icon')) return;
        var icon = document.createElement('span');
        icon.className = 'doro-plan-benefits-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = '\u{1f680}';
        var text = document.createElement('span');
        text.textContent = '套餐核心权益';
        tag.replaceChildren(icon, document.createTextNode(' '), text);
      });
    } finally {
      observer.observe(app, options);
    }
  }

  function schedule() {
    if (pending) return;
    pending = true;
    window.requestAnimationFrame(enhance);
  }

  function start() {
    app = document.getElementById('app');
    if (!app) return;
    observer = new MutationObserver(schedule);
    observer.observe(app, options);
    window.addEventListener('hashchange', schedule);
    window.addEventListener('popstate', schedule);
    window.addEventListener('pageshow', schedule);
    enhance();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
})();
