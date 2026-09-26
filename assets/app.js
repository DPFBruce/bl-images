/* (BL) Images — admin app (vanilla JS, no build step).
 * Views: find · library (+ item detail) · releases · audit · tools · settings.
 * Also exposes window.BLImages.mount() (used by the wp.media tab) and
 * window.BLImages.open() (a standalone picker other plugins can call).
 */
(function () {
  'use strict';
  var C = window.BLIMG || {};

  /* ------------------------------------------------------------------ helpers */
  function api(path, opt) {
    opt = opt || {};
    var init = { method: opt.method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce } };
    if (opt.form) { init.body = opt.form; }
    else if (opt.body !== undefined) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opt.body); }
    return fetch(C.root + path, init).then(function (r) {
      return r.text().then(function (t) {
        var d; try { d = t ? JSON.parse(t) : {}; } catch (e) { throw new Error('Server error (' + r.status + '). ' + t.replace(/<[^>]+>/g, ' ').slice(0, 160)); }
        if (!r.ok) { throw new Error(d && d.message ? d.message : 'Request failed (' + r.status + ')'); }
        return d;
      });
    });
  }
  function qs(o) { var a = []; for (var k in o) { if (o[k] !== '' && o[k] != null) a.push(encodeURIComponent(k) + '=' + encodeURIComponent(o[k])); } return a.length ? '?' + a.join('&') : ''; }
  function h(tag, attrs) {
    var el = document.createElement(tag);
    if (attrs) for (var k in attrs) {
      var v = attrs[k];
      if (v == null || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k === 'text') el.textContent = v;
      else if (k === 'html') el.innerHTML = v;
      else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
      else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
      else if (v === true) el.setAttribute(k, '');
      else el.setAttribute(k, v);
    }
    for (var i = 2; i < arguments.length; i++) add(el, arguments[i]);
    return el;
  }
  function add(el, c) {
    if (c == null || c === false) return;
    if (Array.isArray(c)) { c.forEach(function (x) { add(el, x); }); return; }
    el.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
  }
  function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); return el; }
  function bytes(n) { n = +n || 0; return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }
  function badge(tier, label) { return h('span', { class: 'bl-img-badge bl-img-badge--' + (tier || 'none'), text: label || tierLabel(tier) }); }
  function tierLabel(t) { return { pd: 'Safe anywhere', credit: 'Safe with credit', dpf: 'Owned / licensed to ' + (C.orgShort || 'DPF') }[t] || 'No provenance'; }
  var toastEl;
  function toast(msg, bad) {
    if (!toastEl) { toastEl = h('div', { class: 'bl-img-toast' }); document.body.appendChild(toastEl); }
    toastEl.textContent = msg; toastEl.className = 'bl-img-toast is-on' + (bad ? ' is-bad' : '');
    clearTimeout(toast.t); toast.t = setTimeout(function () { toastEl.className = 'bl-img-toast'; }, bad ? 6000 : 3000);
  }
  function btn(label, onclick, cls) { return h('button', { type: 'button', class: 'button ' + (cls || ''), onclick: onclick }, label); }
  function busy(b, on, label) { if (!b) return; if (on) { b.dataset.l = b.textContent; b.textContent = label || 'Working…'; b.disabled = true; } else { b.textContent = b.dataset.l || b.textContent; b.disabled = false; } }
  function field(label, input, help) { return h('label', { class: 'bl-img-field' }, h('span', { class: 'bl-img-field__l', text: label }), input, help ? h('small', { class: 'bl-img-help', text: help }) : null); }
  function select(name, opts, val) {
    var s = h('select', { name: name });
    opts.forEach(function (o) { var op = h('option', { value: o[0], text: o[1] }); if (String(o[0]) === String(val)) op.selected = true; s.appendChild(op); });
    return s;
  }
  function copy(text) { if (navigator.clipboard) navigator.clipboard.writeText(text).then(function () { toast('Copied.'); }); }
  function chevron(open) { return h('span', { class: 'bl-img-chev' + (open ? ' is-open' : ''), html: '<svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path d="M6 3l5 5-5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' }); }
  function section(title, body, open) {
    var wrap = h('section', { class: 'bl-img-sec' + (open === false ? '' : ' is-open') });
    var head = h('button', { type: 'button', class: 'bl-img-sec__h', onclick: function () { wrap.classList.toggle('is-open'); } }, chevron(true), h('span', { text: title }));
    wrap.appendChild(head); wrap.appendChild(h('div', { class: 'bl-img-sec__b' }, body));
    return wrap;
  }
  var PEOPLE = [['none', 'No identifiable people'], ['released', 'Release(s) on file'], ['event', 'Covered by event photography notice'], ['editorial', 'Editorial / newsworthy use only'], ['unknown', 'Identifiable people — needs a release or decision']];

  /* ================================================================ FIND */
  function viewFind(root, opts) {
    opts = opts || {};
    var state = { q: '', page: 1, tier: 'all', orientation: '', category: '', source: 'all', items: [], selected: {}, more: false };
    var sub = h('div', { class: 'bl-img-subtabs' });
    var body = h('div');
    var tabs = [['search', 'Search free images'], ['upload', 'Upload your own']];
    var cur = 'search';
    function renderTabs() {
      clear(sub);
      tabs.forEach(function (t) { sub.appendChild(h('button', { type: 'button', class: cur === t[0] ? 'is-on' : '', onclick: function () { cur = t[0]; renderTabs(); draw(); } }, t[1])); });
    }
    function draw() { clear(body); if (cur === 'search') drawSearch(); else drawUpload(body, opts); }

    var queue = createQueue(opts);

    function drawSearch() {
      var q = h('input', { type: 'search', class: 'bl-img-q', placeholder: 'Search public-domain & Creative Commons images — e.g. "courthouse", "San Quentin", "protest 1960s"', value: state.q });
      var tier = select('tier', [['all', 'Safe anywhere + Safe with credit'], ['pd', 'Safe anywhere only (public domain / CC0)']].concat(C.ccBy ? [['credit', 'Safe with credit only (CC BY)']] : []), state.tier);
      var ori = select('orientation', [['', 'Any shape'], ['wide', 'Wide'], ['tall', 'Tall'], ['square', 'Square']], state.orientation);
      var cat = select('category', [['', 'Any kind'], ['photograph', 'Photographs'], ['illustration', 'Illustrations'], ['digitized_artwork', 'Artwork / archival']], state.category);
      var src = select('source', [['all', 'All sources'], ['openverse', 'Openverse (museums, archives, NASA…)'], ['wikimedia', 'Wikimedia Commons']], state.source);
      var go = h('button', { type: 'submit', class: 'button button-primary' }, 'Search');
      var form = h('form', { class: 'bl-img-searchbar', onsubmit: function (e) { e.preventDefault(); state.q = q.value.trim(); state.page = 1; state.tier = tier.value; state.orientation = ori.value; state.category = cat.value; state.source = src.value; state.items = []; run(); } }, q, go, h('div', { class: 'bl-img-filters' }, tier, ori, cat, src));
      var grid = h('div', { class: 'bl-img-grid' });
      var foot = h('div', { class: 'bl-img-foot' });
      var bar = h('div', { class: 'bl-img-selbar' });
      body.appendChild(form); body.appendChild(bar); body.appendChild(grid); body.appendChild(foot); body.appendChild(queue.el);

      function drawBar() {
        clear(bar);
        var n = Object.keys(state.selected).length;
        if (!n) { bar.appendChild(h('span', { class: 'bl-img-muted', text: state.items.length ? 'Click images to select them, then import.' : '' })); return; }
        var hint = h('input', { type: 'text', class: 'bl-img-hint', placeholder: 'Optional context for Claude (e.g. "Folsom Prison, 1950s; for an article on wrongful convictions")' });
        bar.appendChild(h('strong', { text: n + ' selected' }));
        bar.appendChild(hint);
        bar.appendChild(btn('Import ' + n + ' into Media Library', function () {
          var items = Object.keys(state.selected).map(function (k) { return state.selected[k]; });
          state.selected = {}; drawBar(); drawGrid();
          items.forEach(function (it) { queue.addRemote(it, hint.value); });
        }, 'button-primary'));
        bar.appendChild(btn('Clear', function () { state.selected = {}; drawBar(); drawGrid(); }));
      }
      function card(it) {
        var sel = !!state.selected[it.uid];
        var warn = (it.restrictions || []).length ? h('span', { class: 'bl-img-warn', title: 'Wikimedia restrictions: ' + it.restrictions.join(', '), text: (it.restrictions.indexOf('personality') > -1 ? 'People: personality rights' : 'Restrictions: ' + it.restrictions.join(', ')) }) : null;
        return h('figure', { class: 'bl-img-card' + (sel ? ' is-sel' : '') + (it.existing ? ' is-have' : ''), tabindex: 0, onclick: function () {
            if (it.existing) { if (opts.onPickExisting) opts.onPickExisting(it.existing); else toast('Already in the Media Library (#' + it.existing + ').'); return; }
            if (state.selected[it.uid]) delete state.selected[it.uid]; else state.selected[it.uid] = it;
            drawBar(); drawGrid();
          } },
          h('div', { class: 'bl-img-card__img', style: { backgroundImage: 'url("' + it.thumb + '")' } }, sel ? h('span', { class: 'bl-img-check', text: '✓' }) : null, it.existing ? h('span', { class: 'bl-img-have', text: 'In library' }) : null),
          h('figcaption', null,
            h('div', { class: 'bl-img-card__t', title: it.title, text: it.title || '(untitled)' }),
            h('div', { class: 'bl-img-card__m' }, badge(it.tier), ' ', h('span', { text: it.license.short })),
            h('div', { class: 'bl-img-card__s', text: (it.creator ? it.creator + ' · ' : '') + it.provider_name }),
            h('div', { class: 'bl-img-card__s', text: it.width && it.height ? it.width + '×' + it.height : '' }),
            warn,
            h('a', { href: it.landing_url, target: '_blank', rel: 'noopener', class: 'bl-img-src', onclick: function (e) { e.stopPropagation(); } }, 'Source page ↗')));
      }
      function drawGrid() { clear(grid); state.items.forEach(function (it) { grid.appendChild(card(it)); }); }
      function run() {
        if (!state.q) return;
        clear(foot).appendChild(h('span', { class: 'bl-img-muted', text: 'Searching…' }));
        api('search' + qs({ q: state.q, page: state.page, tier: state.tier, orientation: state.orientation, category: state.category, source: state.source })).then(function (d) {
          state.items = state.items.concat(d.items); state.more = d.more;
          drawGrid(); drawBar(); clear(foot);
          if (d.errors && d.errors.length) foot.appendChild(h('div', { class: 'bl-img-err', text: d.errors.join(' · ') }));
          if (!state.items.length) foot.appendChild(h('p', { class: 'bl-img-muted', text: 'No safe-licensed results. Try broader words, or "Safe anywhere + Safe with credit".' }));
          if (d.hidden) foot.appendChild(h('p', { class: 'bl-img-muted', text: d.hidden + ' result(s) hidden because their license is not Safe anywhere or Safe with credit.' }));
          if (state.more) foot.appendChild(btn('Load more', function () { state.page++; run(); }));
        }).catch(function (e) { clear(foot).appendChild(h('div', { class: 'bl-img-err', text: e.message })); });
      }
      drawBar(); drawGrid();
      if (state.q && !state.items.length) run();
      setTimeout(function () { q.focus(); }, 30);
    }
    root.appendChild(h('div', { class: 'bl-img-find' }, sub, body));
    renderTabs(); draw();
    return queue;
  }

  /* ---------------------------------------------------------------- import queue */
  function createQueue(opts) {
    var el = h('div', { class: 'bl-img-queue' });
    var jobs = [];
    var running = 0;
    function row(job) {
      job.row = h('div', { class: 'bl-img-job' });
      el.appendChild(job.row); paint(job);
    }
    function paint(job) {
      var r = clear(job.row);
      r.className = 'bl-img-job is-' + job.status;
      r.appendChild(h('div', { class: 'bl-img-job__img', style: { backgroundImage: job.thumb ? 'url("' + job.thumb + '")' : '' } }));
      var info = h('div', { class: 'bl-img-job__i' }, h('strong', { text: job.title || 'Image' }));
      var steps = { queued: 'Waiting…', staging: 'Downloading original, hashing, snapshotting the source, making WebP…', describing: 'Claude is writing alt text & checking for people…', review: 'Review the metadata, then import.', committing: 'Adding to Media Library, embedding rights metadata, writing ledger…', done: 'Imported', dup: 'Already in the Media Library', error: job.error || 'Failed' };
      info.appendChild(h('div', { class: 'bl-img-job__s', text: steps[job.status] }));
      if (job.status === 'review' && job.stage && job.stage.meta) info.appendChild(reviewForm(job));
      if (job.status === 'done' && job.result) {
        info.appendChild(h('div', { class: 'bl-img-job__d' }, badge(job.result.tier), ' ', h('span', { text: job.result.caption })));
        info.appendChild(h('div', { class: 'bl-img-job__d bl-img-muted', text: 'Alt: ' + job.result.alt }));
        if (job.stage && job.stage.meta && job.stage.meta.people === 'unknown') info.appendChild(h('div', { class: 'bl-img-flag', text: 'Identifiable people detected — link a release or mark editorial use in the Library.' }));
        if (job.stage && job.stage.meta && job.stage.meta.ai_note) info.appendChild(h('div', { class: 'bl-img-muted', text: 'Note: ' + job.stage.meta.ai_note + ' (used source metadata instead)' }));
        info.appendChild(h('div', null, h('a', { href: C.adminUrl + 'admin.php?page=bl-images-library&item=' + job.result.id, target: opts && opts.embedded ? '_blank' : null }, 'Open in Library')));
      }
      if (job.status === 'dup') info.appendChild(h('div', null, h('a', { href: C.adminUrl + 'admin.php?page=bl-images-library&item=' + job.dup, target: '_blank' }, 'Open existing #' + job.dup)));
      r.appendChild(info);
    }
    function reviewForm(job) {
      var m = job.stage.meta;
      var t = h('input', { type: 'text', value: m.title || '' });
      var a = h('textarea', { rows: 2 }, m.alt || '');
      var d = h('textarea', { rows: 3 }, m.description || '');
      var p = select('people', PEOPLE, m.people);
      return h('div', { class: 'bl-img-review' },
        field('Title', t), field('Alt text', a, 'Describe what matters in the image, ≤125 characters.'), field('Description', d), field('People in image', p),
        h('div', { class: 'bl-img-review__c' }, h('span', { class: 'bl-img-muted', text: 'Caption (credit, automatic): ' }), m.credit_line),
        btn('Import', function () { job.meta = { title: t.value, alt: a.value, description: d.value, people: p.value }; running++; commit(job); }, 'button-primary'),
        ' ', btn('Skip', function () { job.status = 'error'; job.error = 'Skipped'; paint(job); }));
    }
    function set(job, s) { job.status = s; paint(job); }
    function fail(job, e) { job.status = 'error'; job.error = e.message || String(e); paint(job); running--; pump(); }
    function next() { running--; pump(); }
    function commit(job) {
      set(job, 'committing');
      api('commit', { method: 'POST', body: { token: job.stage.token, meta: job.meta || {} } }).then(function (res) {
        job.result = res; set(job, 'done');
        if (opts && opts.onImported) opts.onImported([res]);
        next();
      }).catch(function (e) { fail(job, e); });
    }
    function start(job) {
      running++;
      set(job, 'staging');
      var p = job.remote ? api('stage', { method: 'POST', body: { source: job.remote.source, source_id: job.remote.source_id } }) : api('stage-upload', { method: 'POST', form: job.form });
      p.then(function (st) {
        if (st.duplicate) { job.dup = st.duplicate; set(job, 'dup'); if (opts && opts.onImported) api('item/' + st.duplicate).then(function (s) { opts.onImported([s]); }).catch(function () {}); next(); return; }
        job.stage = st; set(job, 'describing');
        return api('describe', { method: 'POST', body: { token: st.token, hint: job.hint || '' } }).then(function (d) {
          job.stage = d;
          if (C.reviewFirst) { set(job, 'review'); running--; pump(); return; }
          commit(job); // keeps this job's slot until commit finishes
        });
      }).catch(function (e) { fail(job, e); });
    }
    function pump() { while (running < 2) { var j = jobs.filter(function (x) { return x.status === 'queued'; })[0]; if (!j) return; start(j); } }
    return {
      el: el,
      addRemote: function (it, hint) { var j = { status: 'queued', remote: it, title: it.title, thumb: it.thumb, hint: hint }; jobs.push(j); row(j); pump(); },
      addUpload: function (file, attest, hint) {
        var fd = new FormData(); fd.append('file', file); fd.append('attest', JSON.stringify(attest));
        var j = { status: 'queued', form: fd, title: file.name, thumb: URL.createObjectURL(file), hint: hint }; jobs.push(j); row(j); pump();
      }
    };
  }

  /* ---------------------------------------------------------------- upload + attestation form */
  function attestForm(opts) {
    opts = opts || {};
    var basis = select('basis', [['', '— How does ' + C.orgShort + ' have the right to use this? —'], ['own', C.orgShort + '\'s own photo (staff, volunteer, or photographer hired by ' + C.orgShort + ')'], ['licensed', 'The owner gave ' + C.orgShort + ' permission'], ['pd', 'Public domain / CC0 (found online)']].concat(C.ccBy ? [['cc_by', 'CC BY — free with credit (found online)']] : []), '');
    var creator = h('input', { type: 'text', placeholder: 'Photographer / creator — required' });
    var holder = h('input', { type: 'text', placeholder: 'Copyright holder, if different (e.g. agency, family)' });
    var srcUrl = h('input', { type: 'url', placeholder: 'https://… the page where the license is stated' });
    var ver = select('license_version', [['4.0', 'CC BY 4.0'], ['3.0', 'CC BY 3.0'], ['2.5', 'CC BY 2.5'], ['2.0', 'CC BY 2.0']], '4.0');
    var perm = h('textarea', { rows: 2, placeholder: 'Who granted permission, when and how (e.g. "Email from J. Smith, 3/4/2026, permission for all DPF uses")' });
    var rels = h('select', { multiple: true, size: 3 });
    var people = select('people', [['', '— People in the image —']].concat(PEOPLE), '');
    var agree = h('input', { type: 'checkbox' });
    var fBasis = field('Right to use', basis), fCreator = field('Creator', creator), fHolder = field('Copyright holder', holder), fSrc = field('Source URL', srcUrl), fVer = field('License version', ver), fPerm = field('Permission', perm), fRel = field('Link signed releases', rels, 'Cmd/Ctrl-click to pick several.');
    api('releases').then(function (d) { d.releases.forEach(function (r) { if (r.status === 'signed' || r.type === 'event') rels.appendChild(h('option', { value: r.id, text: r.summary })); }); }).catch(function () {});
    function sync() {
      var b = basis.value;
      fHolder.hidden = !(b === 'own' || b === 'licensed');
      fSrc.hidden = !(b === 'pd' || b === 'cc_by');
      fVer.hidden = b !== 'cc_by';
      fPerm.hidden = b !== 'licensed';
    }
    basis.addEventListener('change', sync); sync();
    var el = h('div', { class: 'bl-img-attest' }, fBasis, fCreator, fHolder, fSrc, fVer, fPerm, fRel, field('People', people, 'If people are recognizable, link their signed release, or choose "Editorial / newsworthy" for news-context use.'),
      h('label', { class: 'bl-img-chk' }, agree, h('span', { text: C.attestText })));
    return {
      el: el,
      value: function () {
        var ids = Array.prototype.slice.call(rels.selectedOptions).map(function (o) { return +o.value; });
        return { basis: basis.value, creator: creator.value.trim(), holder: holder.value.trim(), source_url: srcUrl.value.trim(), license_version: ver.value, permission_note: perm.value.trim(), release_ids: ids, people: people.value || (ids.length ? 'released' : ''), agreed: agree.checked };
      },
      check: function () {
        var v = this.value();
        if (!v.basis) return 'Choose how ' + C.orgShort + ' has the right to use the image.';
        if (!v.creator) return 'Enter the photographer / creator.';
        if ((v.basis === 'pd' || v.basis === 'cc_by') && !/^https?:\/\//.test(v.source_url)) return 'Enter the source URL where the license is stated.';
        if (v.basis === 'licensed' && !v.permission_note && !v.release_ids.length) return 'Describe the permission, or link a signed contributor license.';
        if (!v.agreed) return 'Tick the responsibility statement.';
        return '';
      }
    };
  }

  function drawUpload(body, opts) {
    var files = [];
    var input = h('input', { type: 'file', accept: 'image/*', multiple: true, hidden: true, onchange: function () { files = Array.prototype.slice.call(input.files); list(); } });
    var drop = h('div', { class: 'bl-img-drop', onclick: function () { input.click(); } }, h('strong', { text: 'Drop images here or click to choose' }), h('small', { text: 'JPG, PNG, WebP, GIF, HEIC · up to ' + bytes(C.maxUpload) + ' each · converted to WebP automatically' }));
    ['dragover', 'dragenter'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); }); });
    drop.addEventListener('drop', function (e) { files = Array.prototype.slice.call(e.dataTransfer.files).filter(function (f) { return /^image\//.test(f.type); }); list(); });
    var fl = h('div', { class: 'bl-img-filelist' });
    function list() { clear(fl); files.forEach(function (f) { fl.appendChild(h('span', { class: 'bl-img-chip', text: f.name + ' · ' + bytes(f.size) })); }); }
    var af = attestForm();
    var hint = h('input', { type: 'text', placeholder: 'Optional context for Claude (who/where/when) — improves alt text' });
    var queue = createQueue(opts);
    var go = btn('Upload & import', function () {
      if (!files.length) { toast('Choose at least one image.', true); return; }
      var err = af.check(); if (err) { toast(err, true); return; }
      var v = af.value();
      files.forEach(function (f) { if (f.size > C.maxUpload) { toast(f.name + ' is larger than the server upload limit.', true); return; } queue.addUpload(f, v, hint.value); });
      files = []; list();
    }, 'button-primary');
    body.appendChild(h('div', { class: 'bl-img-upload' },
      h('div', { class: 'bl-img-note', html: 'Uploading an image you found or received? <strong>It is your responsibility</strong> to be sure ' + C.org + ' may use it. Your name, account, IP address and the time are recorded permanently in the image\'s provenance so you can be contacted with questions.' }),
      drop, input, fl, af.el, field('Context for Claude', hint), go, queue.el));
  }

  /* ================================================================ LIBRARY + ITEM */
  function viewLibrary(root) {
    var st = { s: '', filter: 'all', page: 1 };
    var top = h('div', { class: 'bl-img-libbar' });
    var grid = h('div', { class: 'bl-img-grid bl-img-grid--lib' });
    var foot = h('div', { class: 'bl-img-foot' });
    var drawer = h('div', { class: 'bl-img-drawer', hidden: true });
    var q = h('input', { type: 'search', placeholder: 'Search title, alt text, creator, credit, keywords…' });
    var f = select('filter', [['all', 'All images'], ['issues', 'Has issues'], ['unverified', 'No provenance'], ['people', 'People need a release'], ['unused', 'Not used anywhere'], ['notwebp', 'Not WebP'], ['noalt', 'Missing alt text']], 'all');
    top.appendChild(h('form', { onsubmit: function (e) { e.preventDefault(); st.s = q.value; st.filter = f.value; st.page = 1; load(); } }, q, f, h('button', { class: 'button', type: 'submit' }, 'Search')));
    root.appendChild(top); root.appendChild(grid); root.appendChild(foot); root.appendChild(drawer);
    function load() {
      clear(foot).appendChild(h('span', { class: 'bl-img-muted', text: 'Loading…' }));
      api('library' + qs({ s: st.s, filter: st.filter, page: st.page, per: 48 })).then(function (d) {
        clear(grid); clear(foot);
        d.rows.forEach(function (r) {
          grid.appendChild(h('figure', { class: 'bl-img-card', onclick: function () { openItem(r.id); } },
            h('div', { class: 'bl-img-card__img', style: { backgroundImage: 'url("' + r.thumb + '")' } }, r.issues.length ? h('span', { class: 'bl-img-issue', text: r.issues.length }) : null),
            h('figcaption', null, h('div', { class: 'bl-img-card__t', text: r.title || '(untitled)' }), h('div', { class: 'bl-img-card__m' }, badge(r.tier, r.tier_label)), h('div', { class: 'bl-img-card__s', text: (r.used ? 'Used ' + r.used + '×' : 'Not used') + ' · ' + r.mime }))));
        });
        var pages = Math.ceil(d.total / d.per);
        foot.appendChild(h('span', { class: 'bl-img-muted', text: d.total + ' image(s)' + (d.counts.index_built ? '' : ' · usage counts need Tools → Build usage index') }));
        if (pages > 1) {
          if (st.page > 1) foot.appendChild(btn('‹ Prev', function () { st.page--; load(); }));
          foot.appendChild(h('span', { text: ' Page ' + st.page + ' of ' + pages + ' ' }));
          if (st.page < pages) foot.appendChild(btn('Next ›', function () { st.page++; load(); }));
        }
      }).catch(function (e) { clear(foot).appendChild(h('div', { class: 'bl-img-err', text: e.message })); });
    }
    function openItem(id) {
      drawer.hidden = false; clear(drawer).appendChild(h('p', { class: 'bl-img-muted', text: 'Loading…' }));
      history.replaceState(null, '', location.pathname + '?page=bl-images-library&item=' + id);
      renderItem(drawer, id, function () { drawer.hidden = true; history.replaceState(null, '', location.pathname + '?page=bl-images-library'); load(); });
    }
    load();
    if (C.itemParam) openItem(C.itemParam);
  }

  function renderItem(box, id, onClose) {
    api('item/' + id).then(function (it) {
      clear(box);
      var closeB = h('button', { type: 'button', class: 'bl-img-drawer__x', 'aria-label': 'Close', onclick: onClose }, '×');
      // Focal point preview
      var marker = h('span', { class: 'bl-img-focal__m', style: { left: it.focal.x + '%', top: it.focal.y + '%' } });
      var img = h('img', { src: it.thumb, alt: it.alt });
      var focal = h('div', { class: 'bl-img-focal', title: 'Click the subject to set the focal point', onclick: function (e) {
        var r = img.getBoundingClientRect();
        var x = Math.round((e.clientX - r.left) / r.width * 1000) / 10, y = Math.round((e.clientY - r.top) / r.height * 1000) / 10;
        marker.style.left = x + '%'; marker.style.top = y + '%';
        api('item/' + id + '/focal', { method: 'POST', body: { x: x, y: y } }).then(function () { toast('Focal point saved — crops now center on it.'); }).catch(function (er) { toast(er.message, true); });
      } }, img, marker);

      // Metadata
      var t = h('input', { type: 'text', value: it.title });
      var a = h('textarea', { rows: 2 }, it.alt);
      var d = h('textarea', { rows: 4 }, it.description);
      var k = h('input', { type: 'text', value: it.keywords });
      var p = select('people', [['', 'Not reviewed']].concat(PEOPLE), it.people);
      var save = btn('Save metadata', function () {
        busy(save, true);
        api('item/' + id, { method: 'POST', body: { title: t.value, alt: a.value, description: d.value, keywords: k.value, people: p.value } }).then(function () { busy(save, false); toast('Saved (and recorded in the ledger).'); }).catch(function (e) { busy(save, false); toast(e.message, true); });
      }, 'button-primary');
      var aiB = btn('Rewrite alt with Claude', function () {
        busy(aiB, true, 'Claude is looking…');
        api('item/' + id + '/ai-alt', { method: 'POST' }).then(function (s) { a.value = s.alt; busy(aiB, false); toast('New alt text saved.'); }).catch(function (e) { busy(aiB, false); toast(e.message, true); });
      });
      var meta = h('div', { class: 'bl-img-form' }, field('Title', t), field('Alt text', a, 'What matters in the image, ≤125 characters. Screen readers read this.'), field('Description', d), field('Keywords', k), field('People in image', p), h('div', { class: 'bl-img-row' }, save, aiB),
        h('div', { class: 'bl-img-kv' }, h('b', { text: 'Caption / credit line (automatic)' }), h('span', { text: it.caption || '—' })));

      // Provenance
      var v = it.verify;
      var prov = it.has_provenance ? h('div', { class: 'bl-img-kv-list' },
        kv('Tier', badge(it.tier, it.tier_label)),
        kv('License', it.license_url ? h('a', { href: it.license_url, target: '_blank', rel: 'noopener', text: it.license }) : it.license),
        kv('Creator', it.creator || '—'),
        kv('Source', it.source_url ? h('a', { href: it.source_url, target: '_blank', rel: 'noopener', text: it.source_name || it.source_url }) : it.source_name),
        kv('How obtained', it.origin),
        kv('Obtained', (it.imported_at || '').replace('T', ' ').slice(0, 19) + ' UTC by ' + (it.imported_by || '—')),
        kv('Full attribution', it.attribution),
        kv('Original SHA-256', h('code', { text: it.source_sha256 || '—' })),
        kv('Served file', h('span', null, h('code', { text: it.file_sha256 || '—' }), ' ', v.match ? h('span', { class: 'bl-img-ok', text: '✓ matches the ledger' }) : h('span', { class: 'bl-img-flag', text: v.recorded ? 'File changed since recorded' : 'Not recorded' }), v.xmp ? h('span', { class: 'bl-img-ok', text: ' · rights embedded in file' }) : null)),
        kv('Wayback Machine', it.wayback ? h('a', { href: it.wayback, target: '_blank', rel: 'noopener', text: 'Independent capture of the source page ↗' }) : (it.wayback_status || 'n/a')),
        kv('Ledger', '#' + it.ledger_id),
        (it.restrictions || []).length ? kv('Source restrictions', h('span', { class: 'bl-img-flag', text: it.restrictions.join(', ') })) : null,
        h('div', { class: 'bl-img-row' }, h('a', { class: 'button button-primary', href: it.evidence }, 'Download evidence package (.zip)'), it.master_dl ? h('a', { class: 'button', href: it.master_dl }, 'Download full-resolution original (for print)') : null)
      ) : attestPanel(id, function () { renderItem(box, id, onClose); });

      // Releases
      var relBox = h('div');
      function drawRel(list) {
        clear(relBox);
        if (!list.length) relBox.appendChild(h('p', { class: 'bl-img-muted', text: 'No releases linked.' }));
        list.forEach(function (r) { relBox.appendChild(h('div', { class: 'bl-img-relrow', text: r.summary })); });
        var sel = h('select', { multiple: true, size: 4 });
        api('releases').then(function (dd) { dd.releases.forEach(function (r) { var o = h('option', { value: r.id, text: r.summary }); if (it.releases.indexOf(r.id) > -1) o.selected = true; sel.appendChild(o); }); });
        var b = btn('Save linked releases', function () {
          var ids = Array.prototype.slice.call(sel.selectedOptions).map(function (o) { return +o.value; });
          api('item/' + id + '/releases', { method: 'POST', body: { ids: ids } }).then(function () { toast('Releases updated.'); renderItem(box, id, onClose); }).catch(function (e) { toast(e.message, true); });
        });
        relBox.appendChild(field('Link releases', sel, 'Cmd/Ctrl-click to pick several.'));
        relBox.appendChild(h('div', { class: 'bl-img-row' }, b, C.isAdmin ? h('a', { class: 'button', href: C.adminUrl + 'admin.php?page=bl-images-releases&for=' + id }, 'Request a release for people in this image') : null));
      }
      drawRel(it.release_list);

      // Where used
      var useBox = h('div');
      function drawUse(list) {
        clear(useBox);
        if (!list.length) useBox.appendChild(h('p', { class: 'bl-img-muted', text: 'Not found on any page, post, template or setting.' }));
        list.forEach(function (u) {
          useBox.appendChild(h('div', { class: 'bl-img-use' }, h('strong', { text: u.title }), ' ', h('span', { class: 'bl-img-muted', text: (u.kind || u.type) + (u.status && u.status !== 'publish' ? ' (' + u.status + ')' : '') + ' · ' + u.how.join(', ') }), ' ',
            u.view ? h('a', { href: u.view, target: '_blank' }, 'View') : null, ' ', u.elementor ? h('a', { href: u.elementor, target: '_blank' }, 'Edit in Elementor') : (u.edit ? h('a', { href: u.edit, target: '_blank' }, 'Edit') : null)));
        });
        useBox.appendChild(btn('Rescan now', function () { api('item/' + id + '/usage?scan=1').then(drawUse); }));
      }
      drawUse(it.usage);

      // Replace
      var repBox = h('div');
      (function () {
        var file = h('input', { type: 'file', accept: 'image/*' });
        var mode = select('mode', [['same_url', 'Keep the same URL (pages update instantly; some browsers may show the old image until their cache clears)'], ['new_url', 'New URL — cache-proof; every reference is rewritten automatically']], 'same_url');
        var reason = h('input', { type: 'text', placeholder: 'Why (e.g. "better crop from photographer")' });
        var af = attestForm();
        var go = btn('Replace image', function () {
          if (!file.files[0]) { toast('Choose the new image.', true); return; }
          var err = af.check(); if (err) { toast(err, true); return; }
          busy(go, true, 'Replacing…');
          var fd = new FormData(); fd.append('file', file.files[0]); fd.append('attest', JSON.stringify(af.value()));
          api('stage-upload', { method: 'POST', form: fd }).then(function (st) {
            if (st.duplicate) throw new Error('That exact file is already image #' + st.duplicate + '.');
            return api('item/' + id + '/replace', { method: 'POST', body: { token: st.token, mode: mode.value, reason: reason.value } });
          }).then(function (r) { busy(go, false); toast('Replaced. ' + r.references_changed + ' reference(s) updated.'); renderItem(box, id, onClose); }).catch(function (e) { busy(go, false); toast(e.message, true); });
        }, 'button-primary');
        repBox.appendChild(h('p', { class: 'bl-img-muted', text: 'Keeps this image\'s ID, alt text, title, caption, releases and focal point — and every page that uses it. The old file is archived in the evidence vault.' }));
        repBox.appendChild(field('New image', file)); repBox.appendChild(field('URL', mode)); repBox.appendChild(field('Reason', reason)); repBox.appendChild(af.el); repBox.appendChild(go);
      })();

      // History
      var hist = h('div', { class: 'bl-img-hist' });
      (it.ledger || []).slice().reverse().forEach(function (l) {
        hist.appendChild(h('div', { class: 'bl-img-histrow' }, h('code', { text: '#' + l.id }), ' ', h('strong', { text: l.event.replace(/_/g, ' ') }), ' ', h('span', { class: 'bl-img-muted', text: l.created_utc.replace('T', ' ').slice(0, 19) + ' UTC · ' + (l.user_name || l.user_login || 'system') })));
      });

      var issues = it.audit.issues.length ? h('div', { class: 'bl-img-issues' }, it.audit.issues.map(function (x) { return h('div', { class: 'bl-img-flag', text: '⚠ ' + x }); })) : null;

      box.appendChild(h('div', { class: 'bl-img-item' }, closeB,
        h('div', { class: 'bl-img-item__head' }, h('h2', { text: it.title || '(untitled)' }), h('div', { class: 'bl-img-row' }, badge(it.tier, it.tier_label), h('span', { class: 'bl-img-muted', text: ' #' + it.id + ' · ' + it.width + '×' + it.height + ' · ' + it.mime.replace('image/', '') }), h('a', { href: it.edit, target: '_blank' }, 'WordPress edit screen'))),
        issues,
        h('div', { class: 'bl-img-item__cols' },
          h('div', null, focal, h('p', { class: 'bl-img-muted', text: 'Click the subject to set the focal point. Cropped slots (sliders, cards) will center on it.' })),
          h('div', null, section('Metadata', meta))),
        section('Provenance & license', prov),
        section('Releases (people in this image)', relBox),
        section('Where it\'s used', useBox),
        section('Replace this image', repBox, false),
        section('History (ledger)', hist, false)));
    }).catch(function (e) { clear(box).appendChild(h('div', { class: 'bl-img-err', text: e.message })); });
  }
  function kv(k, v) { return h('div', { class: 'bl-img-kv' }, h('b', { text: k }), typeof v === 'string' ? h('span', { text: v }) : v); }

  function attestPanel(id, done) {
    var af = attestForm();
    var cap = h('input', { type: 'checkbox', checked: true });
    var go = btn('Record provenance', function () {
      var err = af.check(); if (err) { toast(err, true); return; }
      busy(go, true);
      api('item/' + id + '/attest', { method: 'POST', body: { attest: af.value(), write_caption: cap.checked } }).then(function () { toast('Provenance recorded.'); done(); }).catch(function (e) { busy(go, false); toast(e.message, true); });
    }, 'button-primary');
    return h('div', null, h('div', { class: 'bl-img-note bl-img-note--warn', text: 'This image has no provenance record — it was in the library before (BL) Images. Tell us where it came from to make it defensible; if you don\'t know, consider replacing it.' }), af.el, h('label', { class: 'bl-img-chk' }, cap, h('span', { text: 'Write the credit line into the Caption' })), go);
  }

  /* ================================================================ RELEASES */
  function viewReleases(root) {
    var params = new URLSearchParams(location.search);
    var forItem = +params.get('for') || 0;
    var list = h('div', { class: 'bl-img-rellist' });
    var detail = h('div', { class: 'bl-img-reldetail' });
    var head = h('div', { class: 'bl-img-row' }, btn('New release', function () { newForm(); }, 'button-primary'), btn('Edit release templates', function () { templates(); }), h('span', { class: 'bl-img-muted', text: 'Model/likeness releases, photo contribution licenses, and event photography notices.' }));
    root.appendChild(head); root.appendChild(h('div', { class: 'bl-img-relcols' }, list, detail));
    function load(select) {
      api('releases').then(function (d) {
        clear(list);
        if (!d.releases.length) list.appendChild(h('p', { class: 'bl-img-muted', text: 'No releases yet.' }));
        d.releases.forEach(function (r) {
          list.appendChild(h('button', { type: 'button', class: 'bl-img-relitem', onclick: function () { show(r.id); } },
            h('span', { class: 'bl-img-st bl-img-st--' + r.status, text: r.status }), h('strong', { text: r.type === 'event' ? r.event_name : r.signer_name }), h('small', { text: r.type_label + (r.signed_at ? ' · ' + r.signed_at.slice(0, 10) : '') })));
        });
        if (select) show(select);
      });
    }
    function newForm() {
      clear(detail);
      var type = select('type', [['likeness', 'Photo & Likeness Release (person pictured signs)'], ['contributor', 'Photo Contribution License (photographer gives us photos)'], ['event', 'Event Photography Notice (Awards Dinner, rallies…)']], 'likeness');
      var method = select('method', [['esign', 'E-sign — email them a secure link'], ['paper', 'Paper — I have (or will scan) a signed form']], 'esign');
      var name = h('input', { type: 'text' }), email = h('input', { type: 'email' }), phone = h('input', { type: 'tel' });
      var minor = h('input', { type: 'checkbox' }), minorName = h('input', { type: 'text', placeholder: 'Minor\'s full name' });
      var credit = h('input', { type: 'text', placeholder: 'e.g. Jane Smith Photography' });
      var scope = h('textarea', { rows: 2, placeholder: 'e.g. "Photos taken at the 2026 Awards Dinner, May 27, 2026"' });
      var ev = h('input', { type: 'text', placeholder: 'e.g. 2026 Awards Dinner' }), evDate = h('input', { type: 'date' }), venue = h('input', { type: 'text' });
      var methods = ['Registration / ticket terms', 'Signage at entrance', 'Announcement from stage', 'Printed program'].map(function (m) { var c = h('input', { type: 'checkbox', value: m }); return { c: c, el: h('label', { class: 'bl-img-chk' }, c, h('span', { text: m })) }; });
      var optouts = h('textarea', { rows: 2, placeholder: 'Names / descriptions of attendees who opted out' });
      var fPerson = h('div', null, field('Name', name), field('Email', email, 'The signing link goes here; a signed copy is emailed back.'), field('Phone (optional)', phone), h('label', { class: 'bl-img-chk' }, minor, h('span', { text: 'The person pictured is a minor (a parent/guardian will sign)' })), field('Minor\'s name', minorName));
      var fContrib = field('Credit as', credit);
      var fEvent = h('div', null, field('Event', ev), field('Date', evDate), field('Venue', venue), h('div', { class: 'bl-img-field' }, h('span', { class: 'bl-img-field__l', text: 'How attendees were notified' }), methods.map(function (m) { return m.el; })), field('Opt-outs', optouts));
      var fMethod = field('Signing method', method);
      function sync() { var t = type.value; fPerson.hidden = t === 'event'; fContrib.hidden = t !== 'contributor'; fEvent.hidden = t !== 'event'; fMethod.hidden = t === 'event'; }
      type.addEventListener('change', sync); sync();
      var go = btn('Create', function () {
        busy(go, true);
        api('releases', { method: 'POST', body: { type: type.value, method: method.value, signer_name: name.value, signer_email: email.value, signer_phone: phone.value, is_minor: minor.checked, minor_name: minorName.value, credit_as: credit.value, scope: scope.value, event_name: ev.value, event_date: evDate.value, event_venue: venue.value, notice_methods: methods.filter(function (m) { return m.c.checked; }).map(function (m) { return m.c.value; }), opt_outs: optouts.value, attachments: forItem ? [forItem] : [] } })
          .then(function (r) { toast('Created.'); forItem = 0; load(r.id); }).catch(function (e) { busy(go, false); toast(e.message, true); });
      }, 'button-primary');
      detail.appendChild(h('div', { class: 'bl-img-form' }, h('h2', { text: 'New release' }), forItem ? h('div', { class: 'bl-img-note', text: 'Will be linked to image #' + forItem + '.' }) : null, field('Type', type), fMethod, fPerson, fContrib, fEvent, field('What it covers', scope), go));
    }
    function show(id) {
      clear(detail).appendChild(h('p', { class: 'bl-img-muted', text: 'Loading…' }));
      api('releases/' + id).then(function (r) {
        clear(detail);
        var rows = h('div', { class: 'bl-img-kv-list' },
          kv('Status', h('span', { class: 'bl-img-st bl-img-st--' + r.status, text: r.status })),
          kv('Type', r.type_label + (r.method ? ' · ' + ({ esign: 'e-signature', paper: 'paper', record: 'record' }[r.method] || r.method) : '')),
          r.type !== 'event' ? kv('Signer', r.signer_name + (r.signer_email ? ' <' + r.signer_email + '>' : '')) : kv('Event', r.event_name + (r.event_date ? ' · ' + r.event_date : '') + (r.event_venue ? ' · ' + r.event_venue : '')),
          +r.is_minor ? kv('Minor', r.minor_name + (r.guardian_relationship ? ' — signed by ' + r.guardian_relationship : '')) : null,
          r.signed_at ? kv('Signed', r.signed_at.replace('T', ' ').slice(0, 19) + ' UTC' + (r.signed_ip ? ' · IP ' + r.signed_ip : '')) : null,
          r.document_sha256 ? kv('Record SHA-256', h('code', { text: r.document_sha256 })) : null,
          r.limitations ? kv('Limitations', r.limitations) : null,
          r.type === 'event' && r.notice_methods && r.notice_methods.length ? kv('Notice given by', r.notice_methods.join(', ')) : null,
          r.opt_outs ? kv('Opt-outs', r.opt_outs) : null,
          r.revoked_at ? kv('Revoked', r.revoked_at.slice(0, 10) + ' — ' + r.revoked_reason) : null);
        var actions = h('div', { class: 'bl-img-row' });
        if (r.link_full) {
          actions.appendChild(btn('Copy signing link', function () { copy(r.link_full); }));
          if (r.signer_email) actions.appendChild(btn('Email link to ' + r.signer_email, function (e) { var b = e.target; busy(b, true); api('releases/' + id + '/send', { method: 'POST' }).then(function () { busy(b, false); toast('Sent.'); }).catch(function (er) { busy(b, false); toast(er.message, true); }); }));
        }
        if (r.status === 'expired') actions.appendChild(btn('Renew link', function () { api('releases/' + id, { method: 'POST', body: { renew: 1 } }).then(function () { show(id); }); }));
        if (r.status !== 'revoked') actions.appendChild(btn('Revoke (stop new uses)', function () { var why = prompt('Reason (e.g. "Signer asked us to stop using their photo, 9/25/2026")'); if (why) api('releases/' + id + '/revoke', { method: 'POST', body: { reason: why } }).then(function () { show(id); load(); }); }));

        var scanFile = h('input', { type: 'file', accept: 'application/pdf,image/*' });
        var scanKind = select('kind', r.type === 'event' ? [['signage-photo', 'Photo of signage'], ['registration-terms', 'Registration/ticket terms screenshot'], ['other', 'Other evidence']] : [['signed-form', 'Signed paper form (marks as signed)'], ['other', 'Other supporting document']], r.type === 'event' ? 'signage-photo' : 'signed-form');
        var scanDate = h('input', { type: 'date' });
        var scanB = btn('Attach file', function () {
          if (!scanFile.files[0]) { toast('Choose a file.', true); return; }
          var fd = new FormData(); fd.append('file', scanFile.files[0]); fd.append('kind', scanKind.value); fd.append('signed_date', scanDate.value);
          busy(scanB, true); api('releases/' + id + '/scan', { method: 'POST', form: fd }).then(function () { toast('Attached.'); show(id); load(); }).catch(function (e) { busy(scanB, false); toast(e.message, true); });
        });
        var files = h('div', null, (r.files || []).map(function (f) { return h('div', null, h('a', { href: f.url, target: '_blank', text: f.name })); }));

        var ups = null;
        if (r.type === 'contributor') {
          ups = h('div');
          if (!r.uploads_public.length) ups.appendChild(h('p', { class: 'bl-img-muted', text: 'No photos uploaded yet.' }));
          r.uploads_public.forEach(function (u) {
            var b = u.imported ? h('a', { href: C.adminUrl + 'admin.php?page=bl-images-library&item=' + u.imported, text: 'In library #' + u.imported }) : btn('Import to Media Library', function () { busy(b, true, 'Importing…'); api('releases/' + id + '/import', { method: 'POST', body: { sha: u.sha256 } }).then(function () { toast('Imported.'); show(id); }).catch(function (e) { busy(b, false); toast(e.message, true); }); }, r.status === 'signed' ? 'button-primary' : '');
            if (r.status !== 'signed' && !u.imported) b.disabled = true;
            ups.appendChild(h('div', { class: 'bl-img-use' }, h('strong', { text: u.name }), ' ', h('span', { class: 'bl-img-muted', text: bytes(u.bytes) + ' · ' + u.sha256.slice(0, 12) + '…' }), ' ', b));
          });
        }
        var imgs = h('div', { class: 'bl-img-thumbs' }, (r.images || []).map(function (im) { return h('a', { href: C.adminUrl + 'admin.php?page=bl-images-library&item=' + im.id, title: im.title }, h('span', { class: 'bl-img-thumb', style: { backgroundImage: 'url("' + im.thumb + '")' } })); }));
        var linkId = h('input', { type: 'number', placeholder: 'Image ID' });
        var linkB = btn('Link image', function () { var v = +linkId.value; if (!v) return; api('releases/' + id, { method: 'POST', body: { attachments: r.attachments.concat([v]) } }).then(function () { show(id); }).catch(function (e) { toast(e.message, true); }); });
        var pick = btn('Pick from Media Library…', function () {
          if (!window.wp || !wp.media) return;
          var fr = wp.media({ title: 'Images this release covers', multiple: true, library: { type: 'image' } });
          fr.on('select', function () { var ids = fr.state().get('selection').map(function (a) { return a.id; }); api('releases/' + id, { method: 'POST', body: { attachments: r.attachments.concat(ids) } }).then(function () { show(id); }); });
          fr.open();
        });
        var notes = h('textarea', { rows: 2 }, r.notes || '');
        var notesB = btn('Save notes', function () { api('releases/' + id, { method: 'POST', body: { notes: notes.value } }).then(function () { toast('Saved.'); }); });

        detail.appendChild(h('div', { class: 'bl-img-form' }, h('h2', { text: r.summary }), rows, actions,
          section('Images covered', h('div', null, imgs, h('div', { class: 'bl-img-row' }, pick, linkId, linkB))),
          ups ? section('Contributed photos', ups) : null,
          section(r.type === 'event' ? 'Evidence (signage, registration terms)' : 'Signed documents & scans', h('div', null, files, h('div', { class: 'bl-img-row' }, scanFile, scanKind, r.type !== 'event' ? scanDate : null, scanB))),
          section('Notes', h('div', null, notes, notesB), false),
          section('History (ledger)', h('div', null, (r.ledger || []).map(function (l) { return h('div', { class: 'bl-img-histrow' }, h('code', { text: '#' + l.id }), ' ', h('strong', { text: l.event.replace(/_/g, ' ') }), ' ', h('span', { class: 'bl-img-muted', text: l.created_utc.slice(0, 19).replace('T', ' ') + ' · ' + (l.user_name || l.user_login) })); })), false)));
      }).catch(function (e) { clear(detail).appendChild(h('div', { class: 'bl-img-err', text: e.message })); });
    }
    function templates() {
      clear(detail).appendChild(h('p', { class: 'bl-img-muted', text: 'Loading…' }));
      api('templates').then(function (t) {
        clear(detail);
        var wrap = h('div', { class: 'bl-img-form' }, h('h2', { text: 'Release templates' }), h('div', { class: 'bl-img-note bl-img-note--warn', text: 'These are solid plain-English starting points modeled on common nonprofit practice. Have DPF\'s counsel review them once. Every edit creates a new numbered version; signed releases keep the exact text their signer saw.' }),
          h('p', { class: 'bl-img-muted', text: 'Placeholders: {org} {short} {contact} {scope} {limitations} {credit_as} {event}' }));
        Object.keys(t).forEach(function (k) {
          var ta = h('textarea', { rows: 14, class: 'bl-img-mono' }, t[k].text);
          var b = btn('Save as new version', function () { api('templates', { method: 'POST', body: { type: k, text: ta.value } }).then(function (r) { toast('Saved as version ' + r.version + '.'); }).catch(function (e) { toast(e.message, true); }); });
          wrap.appendChild(section((C.types[k] || k) + ' — v' + t[k].version, h('div', null, ta, b), false));
        });
        detail.appendChild(wrap);
      });
    }
    load();
    if (forItem) newForm();
  }

  /* ================================================================ AUDIT */
  function viewAudit(root) {
    var st = { filter: 'issues', page: 1, s: '' };
    var sum = h('div', { class: 'bl-img-stats' });
    var tools = h('div', { class: 'bl-img-row' });
    var tbl = h('div');
    var f = select('filter', [['issues', 'Has issues'], ['all', 'All images'], ['unverified', 'No provenance'], ['people', 'People need a release'], ['unused', 'Not used anywhere'], ['notwebp', 'Not WebP'], ['noalt', 'Missing alt text']], 'issues');
    f.addEventListener('change', function () { st.filter = f.value; st.page = 1; load(); });
    root.appendChild(sum); root.appendChild(h('div', { class: 'bl-img-row' }, f, h('a', { class: 'button', href: C.csv }, 'Export full audit (CSV)'))); root.appendChild(tools); root.appendChild(tbl);
    var log = h('div', { class: 'bl-img-log' });
    tools.appendChild(runner('Adopt (BL) Slide Editor Wikimedia imports', 'tools/adopt', function (d) { return d.results.map(function (r) { return '#' + r.id + ' ' + r.status + (r.why ? ' — ' + r.why : ''); }); }, log));
    tools.appendChild(runner('Write missing alt text with Claude', 'tools/ai-alt', function (d) { return d.results.map(function (r) { return '#' + r.id + ' ' + r.status + (r.alt ? ': ' + r.alt : '') + (r.why ? ' — ' + r.why : ''); }); }, log));
    root.appendChild(log);
    function load() {
      clear(tbl).appendChild(h('p', { class: 'bl-img-muted', text: 'Loading…' }));
      api('library' + qs({ filter: st.filter, page: st.page, per: 100 })).then(function (d) {
        clear(sum);
        var c = d.counts;
        [['Images', c.all], ['With provenance', c.verified], ['Without provenance', c.all - c.verified], ['People need a release', c.people], ['Slide Editor imports to adopt', c.adoptable]].forEach(function (x) { sum.appendChild(h('div', { class: 'bl-img-stat' }, h('b', { text: x[1] }), h('span', { text: x[0] }))); });
        clear(tbl);
        var t = h('table', { class: 'widefat striped bl-img-table' }, h('thead', null, h('tr', null, ['', 'Image', 'Status', 'License', 'People', 'Used', 'Issues'].map(function (x) { return h('th', { text: x }); }))));
        var tb = h('tbody');
        d.rows.forEach(function (r) {
          tb.appendChild(h('tr', null,
            h('td', null, h('span', { class: 'bl-img-thumb', style: { backgroundImage: 'url("' + r.thumb + '")' } })),
            h('td', null, h('a', { href: C.adminUrl + 'admin.php?page=bl-images-library&item=' + r.id, text: r.title || '#' + r.id }), h('div', { class: 'bl-img-muted', text: '#' + r.id + ' · ' + r.date + ' · ' + r.mime })),
            h('td', null, badge(r.tier, r.tier_label)),
            h('td', { text: r.license || '—' }),
            h('td', { text: r.people || '—' }),
            h('td', { text: r.used }),
            h('td', null, r.issues.map(function (x) { return h('div', { class: 'bl-img-flag', text: x }); }), r.warnings.map(function (x) { return h('div', { class: 'bl-img-muted', text: x }); }))));
        });
        t.appendChild(tb); tbl.appendChild(t);
        var pages = Math.ceil(d.total / d.per);
        tbl.appendChild(h('div', { class: 'bl-img-foot' }, h('span', { class: 'bl-img-muted', text: d.total + ' image(s)' }), st.page > 1 ? btn('‹ Prev', function () { st.page--; load(); }) : null, pages > 1 ? ' Page ' + st.page + ' of ' + pages + ' ' : null, st.page < pages ? btn('Next ›', function () { st.page++; load(); }) : null));
      }).catch(function (e) { clear(tbl).appendChild(h('div', { class: 'bl-img-err', text: e.message })); });
    }
    load();
  }

  /** A button that calls a batch endpoint repeatedly until remaining = 0, logging each pass. */
  function runner(label, path, lines, log, opts) {
    opts = opts || {};
    var stop = false;
    var b = btn(label, function () {
      if (b.dataset.run) { stop = true; return; }
      if (opts.confirm && !confirm(opts.confirm)) return;
      stop = false; b.dataset.run = 1; b.textContent = 'Stop';
      (function pass(offset) {
        api(path, { method: 'POST', body: opts.offset ? { offset: offset } : {} }).then(function (d) {
          (lines(d) || []).forEach(function (l) { log.insertBefore(h('div', { text: l }), log.firstChild); });
          var more = opts.offset ? !d.done : d.remaining > 0 && (d.results ? d.results.length : d.hashed) > 0;
          if (more && !stop) pass(opts.offset ? d.next : 0);
          else { delete b.dataset.run; b.textContent = label; toast(stop ? 'Stopped.' : label + ' — done.'); if (opts.done) opts.done(d); }
        }).catch(function (e) { delete b.dataset.run; b.textContent = label; toast(e.message, true); });
      })(0);
    });
    return b;
  }

  /* ================================================================ TOOLS */
  function viewTools(root) {
    var log = h('div', { class: 'bl-img-log' });

    // Usage index
    var idx = h('div', null, h('p', { class: 'bl-img-muted', text: 'Scans every page, post, Elementor template and site setting for images. Powers "Where it\'s used", the Unused filter, and safe reference rewriting. Kept current automatically after the first build.' }),
      runner('Build / rebuild usage index', 'tools/index', function (d) { return ['Indexed ' + Math.min(d.next, d.total) + ' of ' + d.total + ' items' + (d.done ? ' — complete.' : '…')]; }, log, { offset: true }));

    // Duplicates
    var dupBox = h('div');
    var dupRes = h('div');
    dupBox.appendChild(h('p', { class: 'bl-img-muted', text: 'Step 1 fingerprints every image (exact bytes + visual similarity). Step 2 lists groups. For each group pick the keeper, then Re-point (all references → keeper; undoable), then Remove the now-unused duplicates (archived to the vault first).' }));
    dupBox.appendChild(h('div', { class: 'bl-img-row' },
      runner('1. Fingerprint images', 'tools/hash', function (d) { return ['Fingerprinted ' + d.hashed + ' · ' + d.remaining + ' left']; }, log),
      btn('2. Find duplicates', function (e) { var b = e.target; busy(b, true, 'Comparing…'); api('tools/duplicates').then(function (d) { busy(b, false); drawGroups(d.groups); }).catch(function (er) { busy(b, false); toast(er.message, true); }); })));
    dupBox.appendChild(dupRes);
    function drawGroups(groups) {
      clear(dupRes);
      if (!groups.length) { dupRes.appendChild(h('p', { text: 'No duplicates found. 🎉' })); return; }
      dupRes.appendChild(h('p', { class: 'bl-img-muted', text: groups.length + ' group(s). Exact = identical files; Similar = same picture at a different size/format — confirm by eye.' }));
      groups.forEach(function (g, gi) {
        var keeper = g.keeper;
        var cards = h('div', { class: 'bl-img-dupcards' });
        g.members.forEach(function (m) {
          var r = h('input', { type: 'radio', name: 'k' + gi, value: m.id, checked: m.id === keeper, onchange: function () { keeper = m.id; } });
          cards.appendChild(h('label', { class: 'bl-img-dup' }, h('span', { class: 'bl-img-thumb bl-img-thumb--lg', style: { backgroundImage: 'url("' + m.thumb + '")' } }),
            h('span', null, r, ' Keep #' + m.id), h('small', { text: m.title }), h('small', { text: m.width + '×' + m.height + ' · ' + bytes(m.bytes) + ' · ' + m.mime.replace('image/', '') }), h('small', { text: 'Used ' + m.used + '× ' + (m.provenance ? '· provenance ✓' : '') })));
        });
        var status = h('div', { class: 'bl-img-muted' });
        var b1 = btn('Re-point references to keeper', function () {
          var dups = g.members.map(function (m) { return m.id; }).filter(function (x) { return x !== keeper; });
          busy(b1, true); api('tools/consolidate', { method: 'POST', body: { keeper: keeper, dups: dups } }).then(function (r) {
            busy(b1, false); status.textContent = r.changed + ' reference(s) moved to #' + keeper + (r.undo ? ' · undo id ' + r.undo : '') + '. Now remove the duplicates.'; b2.disabled = false; b2.dataset.dups = JSON.stringify(dups);
          }).catch(function (e) { busy(b1, false); toast(e.message, true); });
        }, 'button-primary');
        var b2 = btn('Remove duplicates', function () {
          if (!confirm('Delete the duplicate image(s) from the Media Library? Their files and records are archived in the evidence vault first.')) return;
          busy(b2, true); api('tools/remove-duplicates', { method: 'POST', body: { dups: JSON.parse(b2.dataset.dups) } }).then(function (r) {
            busy(b2, false); status.textContent = 'Removed: ' + (r.removed.join(', ') || 'none') + (r.skipped.length ? ' · Skipped: ' + r.skipped.map(function (s) { return '#' + s.id + ' (' + s.why + ')'; }).join(', ') : ''); b2.disabled = true;
          }).catch(function (e) { busy(b2, false); toast(e.message, true); });
        });
        b2.disabled = true;
        dupRes.appendChild(h('div', { class: 'bl-img-dupgroup' }, h('strong', { text: (g.kind === 'exact' ? 'Exact duplicates' : 'Similar images') }), cards, h('div', { class: 'bl-img-row' }, b1, b2), status));
      });
    }

    // Convert
    var conv = h('div');
    api('tools/convert').then(function (d) {
      conv.appendChild(h('p', { class: 'bl-img-muted', text: d.remaining + ' JPG/PNG/GIF image(s) can be converted. Each gets a WebP master + WebP sizes; every page using it is updated; the old files stay on disk so outside links keep working. ' + (d.webp ? '' : 'WARNING: this server cannot write WebP.') }));
      conv.appendChild(runner('Convert library to WebP', 'tools/convert', function (r) { return r.results.map(function (x) { return '#' + x.id + ' ' + x.status + (x.saved ? ' · saved ' + bytes(x.saved) : '') + (x.refs ? ' · ' + x.refs + ' ref(s) updated' : '') + (x.why ? ' — ' + x.why : ''); }).concat([r.remaining + ' left']); }, log, { confirm: 'Convert all JPG/PNG/GIF images to WebP and update every page that uses them? (Build the usage index first. Each batch is undoable from the Undo list.)' }));
    });

    // Ledger
    var led = h('div');
    var ledOut = h('div', { class: 'bl-img-muted' });
    led.appendChild(h('p', { class: 'bl-img-muted', text: 'Every import, upload, edit, release and replacement is written to a hash-chained ledger. Once a day its latest hash is timestamped on the Bitcoin blockchain via OpenTimestamps (free), proving the records existed unaltered on that date.' }));
    led.appendChild(h('div', { class: 'bl-img-row' },
      btn('Verify the whole ledger', function (e) { var b = e.target; busy(b, true, 'Verifying…'); api('tools/ledger-verify').then(function (d) { busy(b, false); ledOut.textContent = (d.ok ? '✓ ' : '✗ ') + d.message; ledOut.className = d.ok ? 'bl-img-ok' : 'bl-img-flag'; }); }),
      btn('Timestamp now (OpenTimestamps)', function (e) { var b = e.target; busy(b, true); api('tools/ots', { method: 'POST' }).then(function (d) { busy(b, false); toast(d.message, !d.ok); }); }),
      btn('Show recent entries', function () { api('tools/ledger').then(function (d) { clear(ledList); d.rows.forEach(function (l) { ledList.appendChild(h('div', { class: 'bl-img-histrow' }, h('code', { text: '#' + l.id }), ' ', h('strong', { text: l.event.replace(/_/g, ' ') }), ' ', l.attachment_id > 0 ? h('a', { href: C.adminUrl + 'admin.php?page=bl-images-library&item=' + l.attachment_id, text: 'image #' + l.attachment_id }) : (l.release_id > 0 ? 'release #' + l.release_id : ''), ' ', h('span', { class: 'bl-img-muted', text: l.created_utc.slice(0, 19).replace('T', ' ') + ' · ' + (l.user_name || l.user_login) })) ); }); }); })));
    var ledList = h('div', { class: 'bl-img-hist' });
    led.appendChild(ledOut); led.appendChild(ledList);

    // Undo
    var und = h('div');
    function drawUndo() {
      api('tools/undo').then(function (list) {
        clear(und);
        if (!list.length) { und.appendChild(h('p', { class: 'bl-img-muted', text: 'Nothing to undo.' })); return; }
        list.slice(0, 30).forEach(function (u) {
          und.appendChild(h('div', { class: 'bl-img-use' }, h('strong', { text: u.reason }), ' ', h('span', { class: 'bl-img-muted', text: u.at.slice(0, 19).replace('T', ' ') + ' · ' + u.rows + ' change(s) · ' + u.by }), ' ',
            btn('Undo', function () { if (confirm('Restore every reference changed by "' + u.reason + '"?')) api('tools/undo', { method: 'POST', body: { id: u.id } }).then(function (r) { toast(r.restored + ' restored.'); drawUndo(); }).catch(function (e) { toast(e.message, true); }); })));
        });
      });
    }
    drawUndo();

    root.appendChild(h('div', { class: 'bl-img-tools' },
      section('Usage index', idx), section('Duplicates — find & consolidate', dupBox), section('Convert existing library to WebP', conv), section('Provenance ledger', led), section('Undo reference changes', und, false), section('Activity log', log)));
  }

  /* ================================================================ SETTINGS */
  function viewSettings(root) {
    api('settings').then(function (s) {
      var F = {};
      function inp(k, type, attrs) { F[k] = h('input', Object.assign({ type: type || 'text', value: s[k] == null ? '' : s[k] }, attrs || {})); return F[k]; }
      function chk(k, label) { F[k] = h('input', { type: 'checkbox', checked: !!+s[k] }); return h('label', { class: 'bl-img-chk' }, F[k], h('span', { text: label })); }
      var save = btn('Save settings', function () {
        var out = {};
        Object.keys(F).forEach(function (k) { out[k] = F[k].type === 'checkbox' ? (F[k].checked ? 1 : 0) : F[k].value; });
        busy(save, true); api('settings', { method: 'POST', body: out }).then(function () { busy(save, false); toast('Saved.'); }).catch(function (e) { busy(save, false); toast(e.message, true); });
      }, 'button-primary');
      root.appendChild(h('div', { class: 'bl-img-form bl-img-settings' },
        section('Organization', h('div', null, field('Name', inp('org_name')), field('Short name', inp('org_short')), field('Contact email (releases, questions)', inp('contact_email', 'email')))),
        section('Sources & licenses', h('div', null, chk('src_openverse', 'Openverse (Smithsonian, The Met, NASA, Europeana, Cleveland, Brooklyn…)'), chk('src_wikimedia', 'Wikimedia Commons'), chk('allow_cc_by', 'Allow "Safe with credit" (CC BY) — credit is written automatically'), chk('ov_include_flickr', 'Include Flickr via Openverse (NOT recommended: licenses are self-declared by uploaders)'))),
        section('Image quality', h('div', null, field('Longest edge (px)', inp('max_edge', 'number', { min: 1200, max: 4096 })), field('WebP quality — photos', inp('q_photo', 'number', { min: 60, max: 95 })), field('WebP quality — graphics/PNG', inp('q_graphic', 'number', { min: 70, max: 100 })), chk('keep_master', 'Keep full-resolution original in the vault (for print + proof)'), field('Largest original to download (MB)', inp('master_max_mb', 'number')), h('p', { class: 'bl-img-muted', text: 'Server: ' + (s._webp ? 'WebP supported via ' + s._editor : 'WebP NOT supported') + ' · upload limit ' + s._max_upload }))),
        section('Claude (alt text)', h('div', null, chk('ai_alt', 'Have Claude write alt text, title and description, and flag people'), chk('review_first', 'Let me review Claude\'s metadata before each import'), field('Worker URL (blank = use (BL) Slide Editor\'s)', inp('worker_url', 'url')), field('Worker token', inp('worker_token', 'password')), h('p', { class: 'bl-img-muted', text: 'In use: ' + (s._worker_effective || 'none configured') }))),
        section('Provenance', h('div', null, chk('wayback', 'Ask the Wayback Machine to capture each source page (independent witness)'), chk('ots', 'Timestamp the ledger daily with OpenTimestamps (Bitcoin-anchored, free)'), field('Signing links expire after (days)', inp('release_expiry', 'number')))),
        section('Front end', h('div', null, chk('credit_tooltip', 'Show the credit line when visitors hover an image'), h('p', { class: 'bl-img-muted', html: 'Credits page: create a page with the shortcode <code>[bl_image_credits]</code> (add <code>used="yes"</code> to list only images in use).' }))),
        save));
    }).catch(function (e) { root.appendChild(h('div', { class: 'bl-img-err', text: e.message })); });
  }

  /* ================================================================ mount / public API */
  function mount(el, o) {
    o = o || {};
    clear(el);
    var v = o.view || el.getAttribute('data-view') || 'find';
    if (v === 'find') return viewFind(el, o);
    if (v === 'library') return viewLibrary(el);
    if (v === 'releases') return viewReleases(el);
    if (v === 'audit') return viewAudit(el);
    if (v === 'tools') return viewTools(el);
    if (v === 'settings') return viewSettings(el);
  }

  /** Standalone picker for other plugins: BLImages.open({ onSelect: fn(attachments[]) }) */
  function open(o) {
    o = o || {};
    var ov = h('div', { class: 'bl-img-modal' });
    var inner = h('div', { class: 'bl-img-modal__in' });
    var x = h('button', { type: 'button', class: 'bl-img-modal__x', 'aria-label': 'Close', onclick: function () { ov.remove(); } }, '×');
    var body = h('div', { class: 'bl-img-modal__body' });
    inner.appendChild(x); inner.appendChild(body); ov.appendChild(inner); document.body.appendChild(ov);
    mount(body, { view: 'find', embedded: true, onImported: function (atts) { if (o.onSelect) o.onSelect(atts); if (!o.multiple) setTimeout(function () { ov.remove(); }, 600); }, onPickExisting: function (id) { api('item/' + id).then(function (s) { if (o.onSelect) o.onSelect([s]); ov.remove(); }); } });
  }

  window.BLImages = { mount: mount, open: open, api: api };

  document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('bl-img-app');
    if (el) mount(el);
    var hb = document.querySelector('.bl-img-help-btn'), hm = document.getElementById('bl-img-help');
    if (hb && hm) {
      hb.addEventListener('click', function () { hm.hidden = false; });
      hm.addEventListener('click', function (e) { if (e.target === hm || e.target.classList.contains('bl-img-modal__x')) hm.hidden = true; });
    }
  });
})();
