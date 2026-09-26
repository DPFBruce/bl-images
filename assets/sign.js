/* (BL) Images — public signing page: signature pad, contributor uploads,
 * review-the-final-text step, then sign (the server verifies the text hash). */
(function () {
  'use strict';
  var B = window.BLS || {};
  if (B.state !== 'open') return;
  var api = B.api + B.token + '/';
  var form = document.getElementById('bls-form');
  var err = document.getElementById('bls-err');
  function showErr(m) { err.textContent = m; err.hidden = !m; if (m) err.scrollIntoView({ behavior: 'smooth', block: 'center' }); }

  /* ---- signature pad ---- */
  var cv = document.getElementById('bls-sig');
  var ctx = cv.getContext('2d');
  var strokes = 0, drawing = false, last = null;
  function fit() {
    var r = cv.getBoundingClientRect(), dpr = window.devicePixelRatio || 1;
    var img = strokes ? cv.toDataURL() : null;
    cv.width = Math.round(r.width * dpr); cv.height = Math.round(r.height * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#1c2333';
    if (img) { var i = new Image(); i.onload = function () { ctx.drawImage(i, 0, 0, r.width, r.height); }; i.src = img; }
  }
  function pt(e) { var r = cv.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
  cv.addEventListener('pointerdown', function (e) { drawing = true; last = pt(e); cv.setPointerCapture(e.pointerId); e.preventDefault(); });
  cv.addEventListener('pointermove', function (e) {
    if (!drawing) return;
    var p = pt(e);
    ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
    last = p; e.preventDefault();
  });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) { cv.addEventListener(ev, function () { if (drawing) strokes++; drawing = false; }); });
  document.getElementById('bls-clear').addEventListener('click', function () { ctx.clearRect(0, 0, cv.width, cv.height); strokes = 0; });
  window.addEventListener('resize', fit); fit();

  /* ---- contributor uploads ---- */
  var files = document.getElementById('bls-files');
  var list = document.getElementById('bls-uplist');
  var uploaded = (B.uploads || []).length;
  function li(text, cls) { var l = document.createElement('li'); l.textContent = text; if (cls) l.className = cls; list.appendChild(l); return l; }
  (B.uploads || []).forEach(function (u) { li('✓ ' + u.name, 'ok'); });
  var pending = 0;
  if (files) files.addEventListener('change', function () {
    var arr = Array.prototype.slice.call(files.files);
    (function next() {
      var f = arr.shift(); if (!f) { files.value = ''; return; }
      var row = li('Uploading ' + f.name + '…'); pending++;
      var fd = new FormData(); fd.append('file', f);
      fetch(api + 'upload', { method: 'POST', body: fd }).then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.message || 'Upload failed'); return d; }); })
        .then(function (d) { row.textContent = '✓ ' + d.name + (d.dup ? ' (already added)' : ''); row.className = 'ok'; if (!d.dup) uploaded++; })
        .catch(function (e) { row.textContent = '✗ ' + f.name + ' — ' + e.message; row.className = 'bad'; })
        .then(function () { pending--; next(); });
    })();
  });

  /* ---- review + sign ---- */
  function values() {
    var fd = new FormData(form), o = {};
    fd.forEach(function (v, k) { o[k] = v; });
    o.consent_electronic = !!form.consent_electronic.checked;
    o.agree = !!form.agree.checked;
    return o;
  }
  function post(path, body) {
    return fetch(api + path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.message || 'Something went wrong'); return d; }); });
  }
  form.addEventListener('submit', function (e) {
    e.preventDefault(); showErr('');
    if (pending) { showErr('Please wait for your photos to finish uploading.'); return; }
    if (B.type === 'contributor' && !uploaded) { showErr('Please add at least one photo.'); return; }
    if (!strokes) { showErr('Please sign in the signature box.'); return; }
    var v = values();
    var btn = form.querySelector('.bls-btn'); btn.disabled = true; btn.textContent = 'Preparing…';
    post('preview', v).then(function (doc) {
      btn.disabled = false; btn.textContent = 'Sign';
      review(doc, v);
    }).catch(function (er) { btn.disabled = false; btn.textContent = 'Sign'; showErr(er.message); });
  });

  function review(doc, v) {
    var ov = document.createElement('div'); ov.className = 'bls-ov';
    var box = document.createElement('div'); box.className = 'bls-ovbox';
    var h = document.createElement('h2'); h.textContent = 'Final review — this is exactly what you are signing';
    var t = document.createElement('div'); t.className = 'bls-text'; t.textContent = doc.text;
    var row = document.createElement('div'); row.className = 'bls-ovrow';
    var back = document.createElement('button'); back.type = 'button'; back.className = 'bls-link'; back.textContent = 'Go back';
    var ok = document.createElement('button'); ok.type = 'button'; ok.className = 'bls-btn'; ok.textContent = 'Sign now';
    row.appendChild(back); row.appendChild(ok);
    box.appendChild(h); box.appendChild(t); box.appendChild(row); ov.appendChild(box); document.body.appendChild(ov);
    back.addEventListener('click', function () { ov.remove(); });
    ok.addEventListener('click', function () {
      ok.disabled = true; ok.textContent = 'Signing…';
      v.signature = cv.toDataURL('image/png');
      v.doc_sha256 = doc.sha256;
      post('sign', v).then(function () {
        ov.remove();
        var main = document.querySelector('.bls');
        main.innerHTML = '';
        var h1 = document.createElement('h1'); h1.textContent = 'Thank you';
        var p = document.createElement('p'); p.className = 'bls-msg bls-ok'; p.textContent = 'Your signature has been recorded. A signed copy has been emailed to ' + v.email + '. You can close this page.';
        main.appendChild(h1); main.appendChild(p);
        window.scrollTo(0, 0);
      }).catch(function (er) { ov.remove(); showErr(er.message); });
    });
  }
})();
