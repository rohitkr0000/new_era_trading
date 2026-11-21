/* home.js — behaviour + animation for index.html */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var $$ = function (s, r) { return [].slice.call((r || document).querySelectorAll(s)); };
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var fine = window.matchMedia && matchMedia('(hover: hover) and (pointer: fine)').matches;
  var esc = UI.esc;
  // plan defaults (from the New Era Trading guide) — used if the API is unreachable
  var DEFAULTS = { currency: '$', roiPct: 5, directPct: 5, retopupPct: 5, levelPct: [2, 1, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2],
    capNonWorking: 2, capWorking: 5, capDirectsRequired: 12, capBusinessMultiple: 5, roiDays: 25, withdrawFeePct: 10, joinMultiple: 5, minJoin: 5, minWithdraw: 5, withdrawMultiple: 5, withdrawStart: 10, withdrawEnd: 14, receiptHours: 24,
    tradingDays: [1, 2, 3, 4, 5], tzLabel: 'IST' };

  $('year').textContent = new Date().getFullYear();

  /* ---------------- preloader ---------------- */
  var pre = $('pre');
  function hidePre() { pre.classList.add('off'); }
  window.addEventListener('load', function () { setTimeout(hidePre, 450); });
  setTimeout(hidePre, 2500);

  // wait for the session + the public summary (stats, leaders, live plan settings) from the API
  Promise.all([Store.ready, Store.publicSummary()]).then(function (res) { start(res[1] || {}); });

  function start(pub) {
  var S = Object.assign({}, DEFAULTS, (pub.ok && pub.settings) || {});
  var stats = (pub.extras && pub.extras.stats) || { members: 0, active: 0, orders: 0, paid: 0 };

  /* ---------------- live data -> DOM ---------------- */

  function marquee(el, items, outline) {
    var half = items.map(function (t, i) { return '<span class="' + outline(i) + '">' + esc(t) + '</span><span class="' + outline(i + 1) + '">✦</span>'; }).join('');
    el.innerHTML = half + half;
  }
  marquee($('mq1'), ['Trade Smart', 'Grow Together', S.roiPct + '% ROI', S.directPct + '% Referral', S.retopupPct + '% Retopup', S.levelPct.length + ' Levels'], function (i) { return i % 2 ? 'o' : ''; });
  marquee($('mq2'), ['Trade Smart', 'Grow Together', 'New Era Trading', 'Mon – Fri'], function (i) { return i % 2 ? '' : 'f'; });

  var pills = [['📈', S.roiPct + '% ROI'], ['🤝', S.directPct + '% Direct Referral'], ['🔁', S.retopupPct + '% Retopup'], ['🏆', S.levelPct.length + '-Level Income'],
    ['⚖️', S.capNonWorking + 'X / ' + S.capWorking + 'X Cap'], ['🚀', '$' + S.minJoin + ' Joining'], ['💸', '$' + S.minWithdraw + ' Withdrawals'], ['📅', 'Mon – Fri Trading'],
    ['⏰', Store.hourLabel(S.withdrawStart) + ' – ' + Store.hourLabel(S.withdrawEnd)], ['✅', S.receiptHours + '-hr Receipt'], ['👛', 'Instant Wallet'], ['💬', 'Support Tickets']];
  $('pills').innerHTML = pills.map(function (p, i) { return '<span class="pill" style="--k:' + i + '">' + p[0] + ' ' + p[1] + '</span>'; }).join('');

  var leaders = (pub.extras && pub.extras.leaders) || [];
  $('lgrid').innerHTML = leaders.map(function (m, i) {
    return '<div data-r style="--d:' + i * 120 + '"><div class="tilt gcard leader"><div class="av">' + esc(UI.initials(m.name)) + '</div>' +
      '<h3>' + esc(m.name) + '</h3><div class="meta">' + esc(m.code) + ' · ' + m.team + ' in team</div>' +
      '<span class="pk" style="background:rgba(255,201,51,.18);color:#ffc933">' + '#' + (i + 1) + ' leader' + '</span>' +
      '<a class="more" href="register.html?sponsor=' + encodeURIComponent(m.code) + '">Join this team →</a></div></div>';
  }).join('');

  $('cMembers').dataset.count = stats.members;
  $('cActive').dataset.count = stats.active;
  $('cOrders').dataset.count = stats.orders;
  $('cPaid').dataset.count = Math.round(stats.paid);

  /* ---- plan sections (all values come from the plan settings) ---- */
  $('cpRoi').textContent = S.roiPct + '%'; $('cpDirect').textContent = S.directPct + '%'; $('cpRetopup').textContent = S.retopupPct + '%';
  var cur = S.currency;
  $('facts').innerHTML = [
    [S.roiPct + '%', 'ROI per trading day'], [S.directPct + '%', 'Direct referrals'], [S.retopupPct + '%', 'Retopup income'],
    [cur + S.minJoin, 'Minimum joining'], [S.levelPct.length, 'Income levels'], [S.capWorking + 'X', 'Working multiplier']
  ].map(function (f, i) { return '<div class="fact" data-r style="--d:' + i * 70 + '"><b>' + esc(String(f[0])) + '</b><span>' + esc(f[1]) + '</span></div>'; }).join('');

  var lv = S.levelPct, maxPct = Math.max.apply(null, lv.concat([1]));
  var bandText = function () {   // "Level 1 earns 2%, level 2 earns 1%, levels 3–12 earn 0.2% each"
    var out = [], i = 0;
    while (i < lv.length) { var j = i; while (j + 1 < lv.length && lv[j + 1] === lv[i]) j++; out.push(j > i ? 'levels ' + (i + 1) + '–' + (j + 1) + ' earn ' + lv[i] + '% each' : 'level ' + (i + 1) + ' earns ' + lv[i] + '%'); i = j + 1; }
    return out.join(', ');
  };
  $('lvLead').textContent = 'Paid on every investment made by your team: ' + bandText() + '. Level k unlocks with k active direct referrals.';
  $('lvPyr').innerHTML = lv.map(function (p, i) {
    return '<div class="lv-step ' + (i < 4 ? 'g' : 't') + '" style="--w:' + (34 + i * 5.5) + '%"><b>L' + (i + 1) + '</b><span>' + p + '%</span></div>';
  }).join('');
  $('lvList').innerHTML = '<header><b>Level-wise income</b><span>' + lv.length + ' levels</span></header>' + lv.map(function (p, i) {
    return '<div class="lv-row ' + (i < 4 ? 'g' : 't') + '"><i>' + (i + 1) + '</i><span>Level ' + (i + 1) + '</span><div class="meter"><i data-bar="' + Math.round(p / maxPct * 100) + '"></i></div><b>' + p + '%</b></div>';
  }).join('');
  var bands = [], k = 0;
  while (k < lv.length) { var e = k; while (e + 1 < lv.length && lv[e + 1] === lv[k]) e++; bands.push([e > k ? 'Levels ' + (k + 1) + '–' + (e + 1) : 'Level ' + (k + 1), lv[k] + '%' + (e > k ? ' each' : ''), k < 4]); k = e + 1; }
  $('lvBands').innerHTML = bands.map(function (b, i) { return '<div class="lv-band ' + (b[2] ? 'g' : 't') + '" data-r="zoom" style="--d:' + i * 80 + '"><small>' + b[0] + '</small><b>' + b[1] + '</b></div>'; }).join('');

  var barsHtml = function (n, of) { var h = ''; for (var x = 0; x < of; x++) h += '<i class="' + (x < n ? 'on' : '') + '"></i>'; return h; };
  var top = Math.max(S.capWorking, S.capNonWorking, 1);
  $('mxN').textContent = $('mxN2').textContent = S.capNonWorking + 'X';
  if ($('mxDirects')) $('mxDirects').textContent = S.capDirectsRequired || 12;
  document.querySelectorAll('.mxMult').forEach(function (e) { e.textContent = S.capBusinessMultiple || 5; });
  $('mxW').textContent = $('mxW2').textContent = S.capWorking + 'X';
  $('barsN').innerHTML = barsHtml(Math.round(S.capNonWorking), Math.ceil(top));
  $('barsW').innerHTML = barsHtml(Math.round(S.capWorking), Math.ceil(top));

  $('ruJoin').textContent = cur + S.minJoin; $('ruWd').textContent = cur + S.minWithdraw;
  var step = S.withdrawMultiple || S.minWithdraw || 5;
  $('ruJoin2').textContent = cur + S.minJoin;
  $('ruWdNote').textContent = 'Then in multiples of ' + cur + step + ' — ' + cur + step + ', ' + cur + step * 2 + ', ' + cur + step * 3 + '…';
  $('ladTitle').textContent = 'Withdraw in multiples of ' + cur + step;
  // amount ladder: 8 rising steps + a dashed "and more" step (bars grow when the section scrolls into view)
  $('ruCoins').innerHTML = [1, 2, 3, 4, 5, 6, 7, 8].map(function (n) {
    return '<div class="lb ' + (n % 2 ? 'g' : 't') + '" style="--h:' + (18 + n * 10) + '%;--i:' + n + '"><span>' + cur + step * n + '</span><i></i><small>Step ' + n + '</small></div>';
  }).join('') + '<div class="lb more" style="--h:100%;--i:9"><span>…</span><i></i><small>and more</small></div>';
  $('ruOpen').textContent = Store.hourLabel(S.withdrawStart); $('ruClose').textContent = Store.hourLabel(S.withdrawEnd); $('ruRecv').textContent = S.receiptHours + ' hours';
  var dnames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  $('wk').innerHTML = [1, 2, 3, 4, 5, 6, 0].map(function (d, i) {
    var on = S.tradingDays.indexOf(d) > -1;
    return '<div class="wk-day ' + (on ? 'on' : 'off') + '" data-r style="--d:' + i * 60 + '"><b>' + dnames[d] + '</b><span>' + (on ? '✓' : '✕') + '</span><small>Trading</small><em>' + (on ? 'ON' : 'OFF') + '</em></div>';
  }).join('') + '<p class="wk-note"><b>Monday – Friday:</b> trading active, withdrawals ' + Store.hourLabel(S.withdrawStart) + ' – ' + Store.hourLabel(S.withdrawEnd) + ' ' + esc(S.tzLabel) + '. <b>Saturday – Sunday:</b> trading off, withdrawals unavailable.</p>';

  var bars = '';
  [38, 55, 44, 70, 62, 85, 74, 95, 80].forEach(function (h, i) { bars += '<i style="--h:' + h + '%;--k:' + i + '"></i>'; });
  $('mockBars').innerHTML = bars;

  var cu = Store.currentUser();
  if (cu) $('navAuth').innerHTML = '<a class="hbtn pri sm mag" href="' + (cu.role === 'admin' ? 'admin' : 'user') + '/dashboard.html">Dashboard →</a>';

  /* ---------------- split titles ---------------- */
  function split(el) {
    var i = 0;
    (function walk(node) {
      [].slice.call(node.childNodes).forEach(function (ch) {
        if (ch.nodeType === 3) {
          var frag = document.createDocumentFragment();
          ch.textContent.split(/(\s+)/).forEach(function (t) {
            if (!t) return;
            if (/^\s+$/.test(t)) { frag.appendChild(document.createTextNode(' ')); return; }
            var w = document.createElement('span'); w.className = 'w';
            var s = document.createElement('span'); s.style.setProperty('--i', i++); s.textContent = t;
            w.appendChild(s); frag.appendChild(w);
          });
          ch.parentNode.replaceChild(frag, ch);
        } else if (ch.nodeType === 1) walk(ch);
      });
    })(el);
  }
  $$('.split').forEach(split);

  /* ---------------- reveal / counters / meters ---------------- */
  function countUp(el) {
    var target = parseFloat(el.dataset.count) || 0, pre = el.dataset.prefix || '', suf = el.dataset.suffix || '';
    var fmt = function (v) { return pre + Math.round(v).toLocaleString() + suf; };
    if (reduce) { el.textContent = fmt(target); return; }
    var t0 = performance.now(), dur = 1800;
    (function tick(now) {
      var p = Math.min(1, (now - t0) / dur), e = 1 - Math.pow(1 - p, 3);
      el.textContent = fmt(target * e);
      if (p < 1) requestAnimationFrame(tick);
    })(t0);
  }
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) {
      if (!en.isIntersecting) return;
      var el = en.target;
      el.classList.add('in');
      if (el.dataset.count !== undefined) countUp(el);
      if (el.dataset.bar !== undefined) el.style.width = el.dataset.bar + '%';
      io.unobserve(el);
    });
  }, { threshold: 0.15, rootMargin: '0px 0px -30px 0px' });
  $$('[data-r], .split, [data-count], [data-bar], .mock-bars').forEach(function (el) { io.observe(el); });

  /* ---------------- header, progress, to-top, active link ---------------- */
  var hd = $('hd'), prog = $('prog'), totop = $('totop');
  var links = $$('#nav a[href^="#"]');
  var secs = links.map(function (a) { return document.querySelector(a.getAttribute('href')); });
  function onScroll() {
    var y = window.scrollY, max = document.documentElement.scrollHeight - innerHeight;
    prog.style.width = (max > 0 ? y / max * 100 : 0) + '%';
    hd.classList.toggle('solid', y > 40);
    totop.classList.toggle('show', y > 700);
    var cur = 0, best = -Infinity;                       // active = the section whose top is closest above the header
    secs.forEach(function (s, i) { if (!s) return; var t = s.getBoundingClientRect().top; if (t <= 160 && t > best) { best = t; cur = i; } });
    links.forEach(function (a, i) { a.classList.toggle('on', i === cur); });
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  totop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
  $('seeHow').addEventListener('click', function () { $('how').scrollIntoView({ behavior: 'smooth', block: 'center' }); });

  var nav = $('nav');
  $('burger').addEventListener('click', function () { nav.classList.toggle('open'); });
  $$('#nav a').forEach(function (a) { a.addEventListener('click', function () { nav.classList.remove('open'); }); });

  /* ---------------- parallax (uses the independent `translate` property) ---------------- */
  var par = $$('[data-parallax]');
  if (!reduce && par.length) {
    var ticking = false;
    var doPar = function () {
      par.forEach(function (el) {
        var r = el.parentNode.getBoundingClientRect();
        var off = (r.top + r.height / 2 - innerHeight / 2) * parseFloat(el.dataset.parallax);
        el.style.translate = '0 ' + off.toFixed(1) + 'px';
      });
      ticking = false;
    };
    window.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(doPar); } }, { passive: true });
    doPar();
  }

  /* ---------------- tilt cards, magnetic buttons, glow cursor ---------------- */
  if (fine && !reduce) {
    document.addEventListener('mousemove', function (e) {
      var t = e.target.closest ? e.target.closest('.tilt') : null;
      if (!t) return;
      var r = t.getBoundingClientRect(), px = (e.clientX - r.left) / r.width, py = (e.clientY - r.top) / r.height;
      t.style.setProperty('--ry', ((px - .5) * 14).toFixed(2) + 'deg');
      t.style.setProperty('--rx', ((.5 - py) * 14).toFixed(2) + 'deg');
      t.style.setProperty('--mx', (px * 100).toFixed(1) + '%');
      t.style.setProperty('--my', (py * 100).toFixed(1) + '%');
    });
    document.addEventListener('mouseout', function (e) {
      var t = e.target.closest ? e.target.closest('.tilt') : null;
      if (t && !t.contains(e.relatedTarget)) { t.style.setProperty('--rx', '0deg'); t.style.setProperty('--ry', '0deg'); }
    });
    document.addEventListener('mousemove', function (e) {
      var m = e.target.closest ? e.target.closest('.mag') : null;
      $$('.mag').forEach(function (b) { if (b !== m) b.style.translate = ''; });
      if (!m) return;
      var r = m.getBoundingClientRect();
      m.style.translate = ((e.clientX - r.left - r.width / 2) * .25).toFixed(1) + 'px ' + ((e.clientY - r.top - r.height / 2) * .35).toFixed(1) + 'px';
    });

    var glow = $('glow'), gx = innerWidth / 2, gy = innerHeight / 2, tx = gx, ty = gy;
    document.addEventListener('mousemove', function (e) { tx = e.clientX; ty = e.clientY; glow.style.opacity = 1; });
    (function follow() { gx += (tx - gx) * .12; gy += (ty - gy) * .12; glow.style.left = gx + 'px'; glow.style.top = gy + 'px'; requestAnimationFrame(follow); })();
  }

  /* ---------------- typed words ---------------- */
  (function typed() {
    var el = $('typed'), words = [S.roiPct + '% ROI every trading day', S.directPct + '% direct referral income', S.retopupPct + '% retopup income', S.levelPct.length + '-level income'], wi = 0, ci = 0, del = false;
    if (reduce) { el.textContent = words[0]; return; }
    (function step() {
      var w = words[wi];
      el.textContent = w.slice(0, ci);
      if (!del && ci === w.length) { del = true; return setTimeout(step, 1500); }
      if (del && ci === 0) { del = false; wi = (wi + 1) % words.length; return setTimeout(step, 350); }
      ci += del ? -1 : 1;
      setTimeout(step, del ? 35 : 75);
    })();
  })();

  /* ---------------- FAQ accordion ---------------- */
  var items = $$('.acc-item');
  function setOpen(it, open) {
    it.classList.toggle('open', open);
    var a = it.querySelector('.acc-a'); a.style.maxHeight = open ? a.scrollHeight + 'px' : '0px';
  }
  items.forEach(function (it) {
    it.querySelector('.acc-q').addEventListener('click', function () {
      var willOpen = !it.classList.contains('open');
      items.forEach(function (o) { setOpen(o, false); });
      if (willOpen) setOpen(it, true);
    });
  });
  items.forEach(function (it) { setOpen(it, it.classList.contains('open')); });
  window.addEventListener('resize', function () { items.forEach(function (it) { if (it.classList.contains('open')) setOpen(it, true); }); });

  /* ---------------- testimonial slider ---------------- */
  (function slider() {
    var slides = $$('.slide'), dotsEl = $('dots'), i = 0, timer;
    slides.forEach(function (_, k) {
      var b = document.createElement('button'); b.setAttribute('aria-label', 'Slide ' + (k + 1));
      b.addEventListener('click', function () { go(k); restart(); });
      dotsEl.appendChild(b);
    });
    var dots = $$('button', dotsEl);
    function go(n) { i = (n + slides.length) % slides.length; slides.forEach(function (s, k) { s.classList.toggle('on', k === i); dots[k].classList.toggle('on', k === i); }); }
    function restart() { clearInterval(timer); if (!reduce) timer = setInterval(function () { go(i + 1); }, 5000); }
    $('slider').addEventListener('mouseenter', function () { clearInterval(timer); });
    $('slider').addEventListener('mouseleave', restart);
    go(0); restart();
  })();

  /* ---------------- visibility helper for canvases ---------------- */
  function watchVisible(el, cb) {
    var vis = true;
    new IntersectionObserver(function (e) { vis = e[0].isIntersecting; cb && cb(vis); }, { threshold: 0 }).observe(el);
    return function () { return vis && !document.hidden; };
  }

  /* ---------------- hero: floating particle network ---------------- */
  (function network() {
    var cv = $('net'), ctx = cv.getContext('2d'), hero = cv.parentNode;
    var w = 0, h = 0, dpr = Math.min(window.devicePixelRatio || 1, 2), pts = [], mouse = { x: -999, y: -999 };
    var visible = watchVisible(hero);
    function size() {
      var r = hero.getBoundingClientRect(); w = r.width; h = r.height;
      cv.width = w * dpr; cv.height = h * dpr; ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      var n = Math.round(Math.min(85, w * h / 15000));
      pts = [];
      for (var i = 0; i < n; i++) pts.push({ x: Math.random() * w, y: Math.random() * h, vx: (Math.random() - .5) * .45, vy: (Math.random() - .5) * .45, r: 1 + Math.random() * 2.2, hot: Math.random() < .18 });
    }
    size();
    window.addEventListener('resize', size);
    hero.addEventListener('mousemove', function (e) { var r = cv.getBoundingClientRect(); mouse.x = e.clientX - r.left; mouse.y = e.clientY - r.top; });
    hero.addEventListener('mouseleave', function () { mouse.x = mouse.y = -999; });
    function frame() {
      requestAnimationFrame(frame);
      if (!visible()) return;
      ctx.clearRect(0, 0, w, h);
      for (var i = 0; i < pts.length; i++) {
        var p = pts[i];
        p.x += p.vx; p.y += p.vy;
        if (p.x < 0 || p.x > w) p.vx *= -1;
        if (p.y < 0 || p.y > h) p.vy *= -1;
        var mdx = p.x - mouse.x, mdy = p.y - mouse.y, md = Math.sqrt(mdx * mdx + mdy * mdy);
        if (md < 150) { p.x += mdx / md * .8; p.y += mdy / md * .8; ctx.strokeStyle = 'rgba(255,255,255,' + (1 - md / 150) * .5 + ')'; ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(mouse.x, mouse.y); ctx.stroke(); }
        for (var j = i + 1; j < pts.length; j++) {
          var q = pts[j], dx = p.x - q.x, dy = p.y - q.y, d = dx * dx + dy * dy;
          if (d < 16900) { ctx.strokeStyle = 'rgba(255,201,51,' + (1 - d / 16900) * .35 + ')'; ctx.lineWidth = 1; ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(q.x, q.y); ctx.stroke(); }
        }
        ctx.fillStyle = p.hot ? '#ffc933' : 'rgba(255,255,255,.65)';
        ctx.shadowColor = p.hot ? '#ffc933' : 'transparent'; ctx.shadowBlur = p.hot ? 12 : 0;
        ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, 6.283); ctx.fill();
      }
      ctx.shadowBlur = 0;
    }
    if (!reduce) frame(); else { size(); }
  })();

  /* ---------------- "why" blob: live candlestick chart ---------------- */
  (function chart() {
    var cv = $('chart'), ctx = cv.getContext('2d'), N = 22, data = [], price = 50, w = 0, h = 0, dpr = Math.min(window.devicePixelRatio || 1, 2);
    var visible = watchVisible(cv);
    function next() {
      var o = price, c = Math.max(18, Math.min(88, o + (Math.random() - .4) * 11));
      price = c;
      return { o: o, c: c, hi: Math.max(o, c) + Math.random() * 5, lo: Math.min(o, c) - Math.random() * 5 };
    }
    for (var i = 0; i < N + 2; i++) data.push(next());
    function size() { var r = cv.getBoundingClientRect(); w = r.width; h = r.height; cv.width = w * dpr; cv.height = h * dpr; ctx.setTransform(dpr, 0, 0, dpr, 0, 0); }
    size(); window.addEventListener('resize', size);
    var y = function (v) { return h * .12 + (1 - v / 100) * h * .76; };
    var t0 = performance.now(), step = 800, last = 0, born = performance.now();
    function draw(now) {
      var p = ((now - t0) % step) / step;
      if (p < last) { data.push(next()); data.shift(); }
      last = p;
      var cw = w / (N - 1), appear = Math.min(1, (now - born) / 1400);
      ctx.clearRect(0, 0, w, h);
      ctx.strokeStyle = 'rgba(255,255,255,.06)'; ctx.lineWidth = 1;
      for (var g = 1; g < 6; g++) { ctx.beginPath(); ctx.moveTo(0, h * g / 6); ctx.lineTo(w, h * g / 6); ctx.stroke(); }
      ctx.globalAlpha = appear;
      // area + line through closes
      var line = new Path2D(), first = true;
      data.forEach(function (d, k) { var x = k * cw - p * cw; if (first) { line.moveTo(x, y(d.c)); first = false; } else line.lineTo(x, y(d.c)); });
      var area = new Path2D(line); area.lineTo((data.length - 1) * cw - p * cw, h); area.lineTo(-p * cw, h); area.closePath();
      var gr = ctx.createLinearGradient(0, 0, 0, h); gr.addColorStop(0, 'rgba(255,201,51,.35)'); gr.addColorStop(1, 'rgba(30,108,255,0)');
      ctx.fillStyle = gr; ctx.fill(area);
      // candles
      data.forEach(function (d, k) {
        var x = k * cw - p * cw, up = d.c >= d.o, col = up ? '#22c55e' : '#ef4444';
        ctx.strokeStyle = col; ctx.fillStyle = col; ctx.lineWidth = 1.5;
        ctx.beginPath(); ctx.moveTo(x, y(d.hi)); ctx.lineTo(x, y(d.lo)); ctx.stroke();
        var top = y(Math.max(d.o, d.c)), hh = Math.max(3, Math.abs(y(d.o) - y(d.c)));
        ctx.fillRect(x - cw * .24, top, cw * .48, hh);
      });
      ctx.shadowColor = '#ffc933'; ctx.shadowBlur = 14; ctx.strokeStyle = '#fff'; ctx.lineWidth = 2.2; ctx.stroke(line); ctx.shadowBlur = 0;
      var lx = (data.length - 1) * cw - p * cw, ly = y(data[data.length - 1].c);
      ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.arc(lx - cw, ly, 4, 0, 6.283); ctx.fill();
      ctx.globalAlpha = 1;
    }
    function frame(now) { requestAnimationFrame(frame); if (visible()) draw(now); }
    if (reduce) draw(performance.now() + 400); else requestAnimationFrame(frame);
  })();
  }  // end start()
})();
