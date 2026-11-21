/* ==========================================================================
   member/kit.js — UI kit for the member hub
   icons · formatting · badges · cards · data tables · modals · PIN prompt · SVG charts · CSV
   Everything hangs off the global `M` namespace.
   ========================================================================== */
(function (global) {
  'use strict';
  var M = global.M = global.M || {};

  /* ---------- icons (24x24, stroke = currentColor) ---------- */
  var P = {
    home: '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
    network: '<circle cx="12" cy="5" r="2.5"/><circle cx="5" cy="19" r="2.5"/><circle cx="19" cy="19" r="2.5"/><path d="M12 7.5v4M5 16.5v-5h14v5"/>',
    users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.5a3.5 3.5 0 010 7M18 14c2.2.6 3.5 2.8 3.5 6"/>',
    layers: '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
    wallet: '<rect x="3" y="6" width="18" height="14" rx="3"/><path d="M3 10h18"/><circle cx="16.5" cy="15" r="1.2"/>',
    card: '<rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20M6 15h4"/>',
    package: '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9"/>',
    file: '<path d="M6 3h8l5 5v13H6z"/><path d="M14 3v5h5M9 13h7M9 17h7"/>',
    lock: '<rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 018 0v3"/>',
    list: '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
    swap: '<path d="M4 8h14l-3-3M20 16H6l3 3"/>',
    coins: '<circle cx="12" cy="12" r="9"/><path d="M14.6 9.2A2.8 2.8 0 0012 8c-1.4 0-2.5.8-2.5 2s1 1.6 2.5 2 2.5.8 2.5 2-1.1 2-2.5 2a2.8 2.8 0 01-2.6-1.2M12 6.5V8m0 8v1.5"/>',
    bank: '<path d="M3 9l9-5 9 5"/><path d="M5 10v7M9.5 10v7M14.5 10v7M19 10v7M3 20h18"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/>',
    key: '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 7l3 3"/>',
    shield: '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
    download: '<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>',
    upload: '<path d="M12 15V3M7 8l5-5 5 5M5 21h14"/>',
    support: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.5"/><path d="M5.6 5.6l3.9 3.9M14.5 14.5l3.9 3.9M18.4 5.6l-3.9 3.9M9.5 14.5l-3.9 3.9"/>',
    chat: '<path d="M4 5h16v11H9l-5 4z"/><path d="M8 9h8M8 12h5"/>',
    history: '<path d="M3 12a9 9 0 103-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
    logout: '<path d="M9 4H5v16h4M16 8l4 4-4 4M20 12H9"/>',
    bell: '<path d="M6 17v-6a6 6 0 0112 0v6l2 2H4z"/><path d="M10 21h4"/>',
    copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 00-2-2H6a2 2 0 00-2 2v8a2 2 0 002 2h2"/>',
    chevron: '<path d="M6 9l6 6 6-6"/>',
    right: '<path d="M9 6l6 6-6 6"/>',
    left: '<path d="M15 6l-6 6 6 6"/>',
    menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
    x: '<path d="M6 6l12 12M18 6L6 18"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    trend: '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
    gift: '<rect x="3" y="8" width="18" height="13" rx="2"/><path d="M12 8v13M3 13h18M12 8c-2-4-6-3-5 0M12 8c2-4 6-3 5 0"/>',
    trophy: '<path d="M8 4h8v5a4 4 0 01-8 0z"/><path d="M8 6H4v1a4 4 0 004 4M16 6h4v1a4 4 0 01-4 4M12 13v4M8 21h8M10 17h4"/>',
    calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
    eye: '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    megaphone: '<path d="M3 11v3a1 1 0 001 1h3l8 4V6L7 10H4a1 1 0 00-1 1z"/><path d="M19 9a4 4 0 010 6"/>',
    check: '<path d="M5 12l5 5 9-10"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    spark: '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5L18 18M6 18l2.5-2.5M15.5 8.5L18 6"/>',
    percent: '<path d="M19 5L5 19"/><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/>',
    star: '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
    gauge: '<path d="M4 18a9 9 0 1116 0"/><path d="M12 13l4-4"/>',
    up: '<path d="M12 19V5M6 11l6-6 6 6"/>',
    down: '<path d="M12 5v14M6 13l6 6 6-6"/>',
    share: '<circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="M8.2 10.9l7.6-3.8M8.2 13.1l7.6 3.8"/>'
  };
  M.icon = function (name, cls) {
    return '<svg class="ic ' + (cls || '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (P[name] || P.spark) + '</svg>';
  };

  /* ---------- formatting ---------- */
  M.esc = UI.esc;
  M.money = function (n) { return UI.money(n); };
  M.date = function (ts) { return ts ? UI.date(ts) : '—'; };
  M.dt = function (ts) { return ts ? new Date(ts).toLocaleString(undefined, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'; };
  M.amt = function (n) { return '<span class="amt ' + (n < 0 ? 'neg' : 'pos') + '">' + (n > 0 ? '+' : '') + M.money(n) + '</span>'; };
  M.short = function (s, n) { s = String(s || ''); n = n || 14; return s.length > n ? s.slice(0, n - 4) + '…' + s.slice(-4) : s; };
  M.toast = function (msg, kind) { UI.toast(msg, kind); };
  M.copy = function (t) { UI.copy(t); };

  /* ---------- badges / small blocks ---------- */
  var TONE = { approved: 'ok', credited: 'ok', active: 'ok', completed: 'ok', received: 'ok', running: 'info', open: 'info', sent: 'warn', self: 'muted',
    pending: 'warn', deducted: 'warn', rejected: 'bad', reversed: 'muted', closed: 'muted', blocked: 'bad', inactive: 'warn' };
  M.badge = function (text, tone) { return '<span class="bdg bdg-' + (tone || TONE[String(text).toLowerCase()] || 'muted') + '">' + M.esc(text) + '</span>'; };
  M.empty = function (title, text, action) {
    return '<div class="m-empty">' + M.icon('spark', 'xl') + '<b>' + M.esc(title || 'Nothing here yet') + '</b>' + (text ? '<p>' + M.esc(text) + '</p>' : '') + (action || '') + '</div>';
  };
  M.card = function (title, body, opt) {
    opt = opt || {};
    return '<section class="m-card ' + (opt.cls || '') + '">' +
      (title ? '<header class="m-card-h"><div><h3>' + (opt.icon ? M.icon(opt.icon) : '') + M.esc(title) + '</h3>' + (opt.sub ? '<p>' + opt.sub + '</p>' : '') + '</div>' + (opt.actions || '') + '</header>' : '') +
      '<div class="m-card-b">' + body + '</div></section>';
  };
  M.field = function (label, control, hint) {
    return '<label class="m-field"><span>' + label + '</span>' + control + (hint ? '<small>' + hint + '</small>' : '') + '</label>';
  };
  M.alert = function (el, msg, kind) {
    if (!el) return;
    el.className = 'm-alert ' + (kind || 'bad');
    el.textContent = msg;
    el.hidden = !msg;
  };
  M.stat = function (o) {   // {icon, label, value, sub, tone, href}
    var tag = o.href ? 'a' : 'div';
    return '<' + tag + ' class="m-kpi tone-' + (o.tone || 'blue') + '"' + (o.href ? ' href="' + o.href + '"' : '') + '>' +
      '<span class="m-kpi-ic">' + M.icon(o.icon || 'coins') + '</span>' +
      '<span class="m-kpi-l">' + M.esc(o.label) + '</span><b class="m-kpi-v">' + o.value + '</b>' +
      (o.sub ? '<small>' + o.sub + '</small>' : '') + (o.href ? '<i class="m-kpi-go">' + M.icon('right') + '</i>' : '') + '</' + tag + '>';
  };

  /* ---------- CSV ---------- */
  M.csv = function (name, header, rows) {
    var q = function (v) { v = String(v == null ? '' : v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
    var text = [header].concat(rows).map(function (r) { return r.map(q).join(','); }).join('\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([text], { type: 'text/csv' }));
    a.download = name + '.csv';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
  };

  /* ---------- data table: search · date range · page size · paging · totals · CSV ---------- */
  // opts: { columns:[{key,label,align,render(row),csv(row)}], rows, dateKey, search, sumKey, sumLabel, exportName, empty, per }
  M.table = function (el, opts) {
    var st = { q: '', from: '', to: '', per: opts.per || 10, page: 1 };
    var cols = opts.columns;
    var hasDate = !!opts.dateKey, hasSearch = opts.search !== false;
    el.innerHTML =
      '<div class="m-tbar">' +
        (hasSearch ? '<div class="m-search">' + M.icon('search') + '<input type="search" placeholder="Search…" data-k="q"></div>' : '') +
        (hasDate ? '<div class="m-range"><input type="date" data-k="from" aria-label="From date"><span>→</span><input type="date" data-k="to" aria-label="To date"></div>' : '') +
        '<div class="m-tbar-r"><label class="m-per">Rows <select data-k="per">' + [10, 25, 50, 100].map(function (n) { return '<option' + (n === st.per ? ' selected' : '') + '>' + n + '</option>'; }).join('') + '</select></label>' +
        (opts.exportName ? '<button class="mbtn line sm" data-act="csv">' + M.icon('download') + 'CSV</button>' : '') + '</div>' +
      '</div><div class="m-tbody"></div>';
    var body = el.querySelector('.m-tbody');

    function text(r) { return cols.map(function (c) { return c.csv ? c.csv(r) : (r[c.key] == null ? '' : r[c.key]); }).join(' ').toLowerCase(); }
    function filtered() {
      var from = st.from ? new Date(st.from + 'T00:00:00').getTime() : null, to = st.to ? new Date(st.to + 'T23:59:59').getTime() : null;
      return opts.rows.filter(function (r) {
        if (st.q && text(r).indexOf(st.q.toLowerCase()) < 0) return false;
        if (hasDate && from && r[opts.dateKey] < from) return false;
        if (hasDate && to && r[opts.dateKey] > to) return false;
        return true;
      });
    }
    function draw() {
      var list = filtered(), pages = Math.max(1, Math.ceil(list.length / st.per));
      if (st.page > pages) st.page = pages;
      var start = (st.page - 1) * st.per, slice = list.slice(start, start + st.per);
      if (!list.length) { body.innerHTML = M.empty(opts.emptyTitle || 'No records found', opts.empty || (opts.rows.length ? 'Try a different search or date range.' : '')); return; }
      var sum = opts.sumKey ? list.reduce(function (s, r) { return s + (Number(r[opts.sumKey]) || 0); }, 0) : null;
      var nums = [], lo = Math.max(1, st.page - 2), hi = Math.min(pages, lo + 4);
      for (var i = lo; i <= hi; i++) nums.push('<button class="' + (i === st.page ? 'on' : '') + '" data-page="' + i + '">' + i + '</button>');
      body.innerHTML =
        '<div class="m-twrap"><table class="m-table"><thead><tr>' + cols.map(function (c) { return '<th class="' + (c.align === 'r' ? 'r' : '') + '">' + c.label + '</th>'; }).join('') + '</tr></thead><tbody>' +
        slice.map(function (r) { return '<tr>' + cols.map(function (c) { return '<td data-l="' + M.esc(c.label) + '" class="' + (c.align === 'r' ? 'r' : '') + '">' + (c.render ? c.render(r) : M.esc(r[c.key])) + '</td>'; }).join('') + '</tr>'; }).join('') +
        '</tbody></table></div>' +
        '<div class="m-tfoot"><span>Showing <b>' + (start + 1) + '–' + (start + slice.length) + '</b> of <b>' + list.length + '</b></span>' +
        (sum !== null ? '<span class="m-sum">' + M.esc(opts.sumLabel || 'Total') + ': <b>' + M.money(sum) + '</b></span>' : '') +
        '<span class="m-pager"><button data-page="' + (st.page - 1) + '"' + (st.page === 1 ? ' disabled' : '') + ' aria-label="Previous">' + M.icon('left') + '</button>' + nums.join('') +
        '<button data-page="' + (st.page + 1) + '"' + (st.page === pages ? ' disabled' : '') + ' aria-label="Next">' + M.icon('right') + '</button></span></div>';
    }
    el.addEventListener('input', function (e) {
      var k = e.target.dataset.k; if (!k) return;
      st[k] = k === 'per' ? parseInt(e.target.value, 10) : e.target.value; st.page = 1; draw();
    });
    el.addEventListener('click', function (e) {
      var b = e.target.closest('[data-page]');
      if (b && !b.disabled) { st.page = parseInt(b.dataset.page, 10); draw(); return; }
      if (e.target.closest('[data-act="csv"]')) {
        M.csv(opts.exportName, cols.map(function (c) { return c.label.replace(/<[^>]+>/g, ''); }),
          filtered().map(function (r) { return cols.map(function (c) { return c.csv ? c.csv(r) : r[c.key]; }); }));
      }
    });
    draw();
    return { redraw: draw };
  };

  /* ---------- modal ---------- */
  M.modal = function (o) {   // {title, body, footer, wide, onMount(el, close)}
    var wrap = document.createElement('div');
    wrap.className = 'm-modal-wrap';
    wrap.innerHTML = '<div class="m-modal' + (o.wide ? ' wide' : '') + '" role="dialog" aria-modal="true"><header><h3>' + M.esc(o.title || '') + '</h3><button class="m-x" aria-label="Close">' + M.icon('x') + '</button></header>' +
      '<div class="m-modal-b">' + (o.body || '') + '</div>' + (o.footer ? '<footer>' + o.footer + '</footer>' : '') + '</div>';
    document.body.appendChild(wrap);
    requestAnimationFrame(function () { wrap.classList.add('show'); });
    function close() { wrap.classList.remove('show'); setTimeout(function () { wrap.remove(); }, 250); document.removeEventListener('keydown', esc); }
    function esc(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', esc);
    wrap.addEventListener('click', function (e) { if (e.target === wrap || e.target.closest('.m-x') || e.target.closest('[data-close]')) close(); });
    if (o.onMount) o.onMount(wrap.querySelector('.m-modal'), close);
    return { el: wrap, close: close };
  };
  M.confirm = function (title, html, okText) {
    return new Promise(function (resolve) {
      var done = false;
      M.modal({
        title: title, body: html,
        footer: '<button class="mbtn line" data-close>Cancel</button><button class="mbtn gold" data-ok>' + M.esc(okText || 'Confirm') + '</button>',
        onMount: function (el, close) {
          el.querySelector('[data-ok]').onclick = function () { done = true; close(); resolve(true); };
          el.parentNode.addEventListener('click', function (e) { if (!done && (e.target === el.parentNode || e.target.closest('[data-close]') || e.target.closest('.m-x'))) resolve(false); });
        }
      });
    });
  };
  // asks for the transaction PIN; resolves with the PIN (the server verifies it) or null if cancelled
  M.askPin = function (userId) {
    return new Promise(function (resolve) {
      if (!Store.hasPin(userId)) {
        M.modal({
          title: 'Set your transaction PIN first',
          body: '<p class="m-muted">For your security, withdrawals, transfers and purchases need a 4–6 digit transaction PIN.</p>',
          footer: '<button class="mbtn line" data-close>Later</button><a class="mbtn gold" href="#/account/pin" data-close>Set PIN now</a>'
        });
        return resolve(null);
      }
      var ok = false;
      M.modal({
        title: 'Enter transaction PIN',
        body: '<p class="m-muted">Confirm this action with your 4–6 digit PIN.</p><div class="m-alert bad" hidden></div>' +
          '<input class="m-pin" type="password" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="••••••">',
        footer: '<button class="mbtn line" data-close>Cancel</button><button class="mbtn gold" data-ok>' + M.icon('shield') + 'Confirm</button>',
        onMount: function (el, close) {
          var inp = el.querySelector('.m-pin'), al = el.querySelector('.m-alert');
          setTimeout(function () { inp.focus(); }, 60);
          var go = function () {
            if (/^\d{4,6}$/.test(inp.value)) { ok = true; var pin = inp.value; close(); resolve(pin); }
            else { M.alert(al, 'Enter your 4–6 digit PIN.'); inp.focus(); }
          };
          el.querySelector('[data-ok]').onclick = go;
          inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') go(); });
          el.parentNode.addEventListener('click', function (e) { if (!ok && (e.target === el.parentNode || e.target.closest('[data-close]') || e.target.closest('.m-x'))) resolve(false); });
        }
      });
    });
  };

  // E-mailed code dialog: o = { sentTo, summary (html), resend: function () -> Promise<{ok, error, sentTo}> } -> resolves the 6-digit code, or null when cancelled
  M.askOtp = function (o) {
    return new Promise(function (resolve) {
      var ok = false;
      M.modal({
        title: 'Enter the e-mailed code',
        body: '<p class="m-muted" style="margin-top:0">' + (o.summary || '') + '</p>' +
          '<div class="m-alert ok" data-sent>A 6-digit code was sent to <b>' + M.esc(o.sentTo || 'your e-mail') + '</b> from New Era Trading. Check your inbox (and spam folder).</div>' +
          '<div class="m-alert bad" hidden data-err></div>' +
          '<input class="m-pin sm" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="••••••">' +
          '<p class="m-muted" style="margin:.7rem 0 0;font-size:.82rem">The code works once, only for this request, and expires in 10 minutes. <a href="#" data-resend>Send a new code</a></p>',
        footer: '<button class="mbtn line" data-close>Cancel</button><button class="mbtn gold" data-ok>' + M.icon('shield') + 'Confirm</button>',
        onMount: function (el, close) {
          var inp = el.querySelector('.m-pin'), err = el.querySelector('[data-err]');
          setTimeout(function () { inp.focus(); }, 60);
          var go = function () {
            if (/^\d{6}$/.test(inp.value)) { ok = true; var code = inp.value; close(); resolve(code); }
            else { M.alert(err, 'Enter the 6-digit code from the e-mail.'); inp.focus(); }
          };
          el.querySelector('[data-ok]').onclick = go;
          inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') go(); });
          el.querySelector('[data-resend]').onclick = function (e) {
            e.preventDefault();
            if (!o.resend) return;
            o.resend().then(function (r) { M.alert(err, r.ok ? 'A new code was sent. Only the newest code works.' : r.error, r.ok ? 'ok' : 'bad'); });
          };
          el.parentNode.addEventListener('click', function (e) { if (!ok && (e.target === el.parentNode || e.target.closest('[data-close]') || e.target.closest('.m-x'))) resolve(null); });
        }
      });
    });
  };

  /* ---------- SVG charts ---------- */
  var gid = 0;
  M.area = function (el, pts, o) {
    o = o || {};
    var W = 640, H = 230, L = 46, R = 10, T = 14, B = 28, id = 'ag' + (++gid);
    var max = Math.max.apply(null, pts.map(function (p) { return p.value; }).concat([1]));
    var step = Math.pow(10, Math.floor(Math.log10(max))), nice = Math.ceil(max / step) * step;
    var x = function (i) { return L + i * (W - L - R) / Math.max(1, pts.length - 1); };
    var y = function (v) { return T + (1 - v / nice) * (H - T - B); };
    var line = pts.map(function (p, i) { return (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(p.value).toFixed(1); }).join(' ');
    var grid = '', i;
    for (i = 0; i <= 4; i++) {
      var gv = nice * i / 4, gy = y(gv);
      grid += '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + gy + '" y2="' + gy + '" class="g"/><text x="' + (L - 8) + '" y="' + (gy + 4) + '" text-anchor="end">' + (o.prefix || '') + Math.round(gv) + '</text>';
    }
    var every = Math.ceil(pts.length / 7), labels = '', dots = '';
    pts.forEach(function (p, k) {
      if (k % every === 0 || k === pts.length - 1) labels += '<text x="' + x(k) + '" y="' + (H - 8) + '" text-anchor="middle">' + M.esc(p.label) + '</text>';
      dots += '<circle cx="' + x(k) + '" cy="' + y(p.value) + '" r="4"><title>' + M.esc(p.label) + ': ' + (o.prefix || '') + p.value.toFixed(2) + '</title></circle>';
    });
    el.innerHTML = '<svg class="m-area" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" role="img" aria-label="' + M.esc(o.label || 'Chart') + '">' +
      '<defs><linearGradient id="' + id + '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f5b800" stop-opacity=".45"/><stop offset="1" stop-color="#f5b800" stop-opacity="0"/></linearGradient></defs>' +
      grid + '<path d="' + line + ' L' + x(pts.length - 1) + ' ' + (H - B) + ' L' + L + ' ' + (H - B) + 'Z" fill="url(#' + id + ')"/>' +
      '<path d="' + line + '" class="ln"/>' + dots + labels + '</svg>';
  };
  M.donut = function (el, parts, center) {
    var total = parts.reduce(function (s, p) { return s + p.value; }, 0), r = 62, C = 2 * Math.PI * r, off = 0;
    var rings = total ? parts.filter(function (p) { return p.value > 0; }).map(function (p) {
      var len = p.value / total * C, s = '<circle r="' + r + '" cx="80" cy="80" stroke="' + p.color + '" stroke-dasharray="' + len.toFixed(2) + ' ' + (C - len).toFixed(2) + '" stroke-dashoffset="' + (-off).toFixed(2) + '"><title>' + M.esc(p.label) + ': ' + M.money(p.value) + '</title></circle>';
      off += len; return s;
    }).join('') : '<circle r="' + r + '" cx="80" cy="80" stroke="rgba(255,255,255,.1)"/>';
    el.innerHTML = '<svg class="m-donut" viewBox="0 0 160 160" role="img" aria-label="Income mix"><g transform="rotate(-90 80 80)">' + rings + '</g>' +
      '<text x="80" y="76" text-anchor="middle" class="t1">' + M.esc(center ? center.label : 'Total') + '</text><text x="80" y="98" text-anchor="middle" class="t2">' + M.esc(center ? center.value : '') + '</text></svg>';
  };
  M.ring = function (pct, label) {   // small progress ring (gauge)
    var r = 52, C = 2 * Math.PI * r, len = Math.max(0, Math.min(100, pct)) / 100 * C;
    return '<svg class="m-ring" viewBox="0 0 130 130"><circle r="' + r + '" cx="65" cy="65" class="bg"/><circle r="' + r + '" cx="65" cy="65" class="fg" stroke-dasharray="' + len.toFixed(1) + ' ' + C.toFixed(1) + '" transform="rotate(-90 65 65)"/>' +
      '<text x="65" y="64" text-anchor="middle" class="t1">' + Math.round(pct) + '%</text><text x="65" y="84" text-anchor="middle" class="t2">' + M.esc(label || '') + '</text></svg>';
  };
})(window);
