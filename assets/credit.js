/* (BL) Images — shows an image's credit line on hover (front end). One
 * body-level tooltip, positioned over the hovered image; nothing is inserted
 * into page layout, so sliders/cards are never disturbed. */
(function () {
  'use strict';
  if (!('onmouseover' in window) || window.matchMedia('(hover: none)').matches) return;
  var tip = null, cur = null, hideT = null;
  var page = (window.BLIMG_CREDIT && window.BLIMG_CREDIT.page) || '';
  function ensure() {
    if (tip) return tip;
    tip = document.createElement('div');
    tip.setAttribute('role', 'note');
    tip.style.cssText = 'position:fixed;z-index:2147483000;max-width:min(460px,80vw);background:rgba(20,24,32,.86);color:#fff;font:500 12px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:5px 9px;border-radius:5px;pointer-events:auto;opacity:0;transition:opacity .15s;';
    tip.addEventListener('mouseenter', function () { clearTimeout(hideT); });
    tip.addEventListener('mouseleave', hide);
    document.body.appendChild(tip);
    return tip;
  }
  function show(img) {
    var c = img.getAttribute('data-bl-credit'); if (!c) return;
    var r = img.getBoundingClientRect(); if (r.width < 160 || r.height < 100) return;
    var t = ensure();
    t.textContent = '';
    if (page) {
      var a = document.createElement('a');
      a.href = page + '#credit-' + (img.getAttribute('data-bl-cid') || '');
      a.textContent = c; a.style.cssText = 'color:#fff;text-decoration:none;';
      t.appendChild(a);
    } else { t.textContent = c; }
    t.style.opacity = '0'; t.style.left = '0px'; t.style.top = '0px';
    var tw = t.offsetWidth, th = t.offsetHeight;
    t.style.left = Math.max(4, Math.min(r.right - tw - 8, window.innerWidth - tw - 4)) + 'px';
    t.style.top = Math.max(4, Math.min(r.bottom - th - 8, window.innerHeight - th - 4)) + 'px';
    t.style.opacity = '1';
    cur = img;
  }
  function hide() { hideT = setTimeout(function () { if (tip) tip.style.opacity = '0'; cur = null; }, 120); }
  document.addEventListener('mouseover', function (e) {
    var img = e.target && e.target.closest ? e.target.closest('img[data-bl-credit]') : null;
    if (img && img !== cur) { clearTimeout(hideT); show(img); }
  });
  document.addEventListener('mouseout', function (e) {
    var img = e.target && e.target.closest ? e.target.closest('img[data-bl-credit]') : null;
    if (img && (!e.relatedTarget || e.relatedTarget !== tip)) hide();
  });
  window.addEventListener('scroll', function () { if (tip) tip.style.opacity = '0'; cur = null; }, { passive: true });
})();
