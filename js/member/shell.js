/* ==========================================================================
   member/shell.js — menu, hash router and app layout for the member hub
   Pages register themselves with  M.page('/path', { title, render(el, ctx) })
   ========================================================================== */
(function (global) {
  'use strict';
  var M = global.M;
  var IL = Store.INCOME_LABELS;

  /* ---------- navigation (single source of truth for top nav, drawer, breadcrumbs) ---------- */
  M.MENU = [
    { id: 'dashboard', label: 'Dashboard', icon: 'home', route: '/dashboard' },
    { id: 'network', label: 'Network', icon: 'network', items: [
      ['/network/team', 'Team Report', 'users', 'Your direct team and their growth'],
      ['/network/levels', 'Team Level Report', 'layers', 'Members and business by level']
    ] },
    { id: 'wallet', label: 'Wallet', icon: 'wallet', items: [
      ['/deposit/new', 'Deposit USDT', 'card', 'Fund your purchase wallet (BEP20)'],
      ['/deposit/history', 'Deposit History', 'history', 'Every deposit and its status'],
      ['/wallet/purchase', 'Purchase Wallet', 'package', 'Join from $5 or add a new package'],
      ['/wallet/purchases', 'Purchased History', 'file', 'Packages, ROI days and status'],
      ['/wallet/compounding', 'Compounding', 'trend', 'Compounding balance and daily schedule'],
      ['/wallet/statement', 'Wallet Statement', 'list', 'Purchase wallet ledger'],
      ['/wallet/transfer', 'Wallet Transfer', 'swap', 'Send funds to another member'],
      ['/tools/calculator', 'Calculator', 'gauge', 'Simple interest and compounding']
    ] },
    { id: 'income', label: 'Income', icon: 'coins', items: [
      ['/income/roi', IL.roi, 'trend', '5% per trading day on your packages'],
      ['/income/direct', IL.direct, 'users', '5% of your directs’ joining'],
      ['/income/retopup', IL.retopup, 'swap', '5% when your directs retop up'],
      ['/income/level', IL.level, 'layers', '12 levels on your team’s investments'],
      ['/income/statement', 'All Income Statement', 'file', 'Every credit and debit with balance']
    ] },
    { id: 'withdraw', label: 'Withdraw', icon: 'bank', items: [
      ['/withdraw/request', 'Income Withdrawal', 'bank', 'Request a payout'],
      ['/withdraw/history', 'Withdrawal History', 'history', 'Payouts and their status'],
      ['/withdraw/self', 'Transfer in My Wallet', 'swap', 'Income wallet → purchase wallet'],
      ['/withdraw/transfers', 'Transfer History', 'list', 'All wallet transfers'],
      ['/withdraw/deductions', 'Deduction History', 'percent', 'Service charges and fees']
    ] },
    { id: 'account', label: 'Account', icon: 'user', items: [
      ['/account/profile', 'Update Profile', 'user', 'Personal and payout details'],
      ['/account/kyc', 'KYC & Verification', 'shield', 'Verify e-mail and identity to withdraw'],
      ['/account/password', 'Change Password', 'key', 'Keep your login secure'],
      ['/account/pin', 'Change Txn PIN', 'shield', 'PIN for payouts and transfers'],
      ['/account/downloads', 'Downloads', 'download', 'Brand kit and your reports']
    ] },
    { id: 'support', label: 'Support', icon: 'support', items: [
      ['/support/new', 'Open New Ticket', 'chat', 'Ask our team for help'],
      ['/support/history', 'Ticket History', 'history', 'Your conversations']
    ] }
  ];
  function findRoute(path) {
    for (var i = 0; i < M.MENU.length; i++) {
      var g = M.MENU[i];
      if (g.route === path) return { group: g, item: [g.route, g.label, g.icon, ''] };
      var it = (g.items || []).find(function (x) { return x[0] === path; });
      if (it) return { group: g, item: it };
    }
    return null;
  }

  /* ---------- page registry + router ---------- */
  var pages = {};
  M.page = function (path, def) { pages[path] = def; };
  M.go = function (path) { location.hash = '#' + path; };

  var me = null, base = '../';
  function render() {
    var path = (location.hash || '').replace(/^#/, '') || '/dashboard';
    if (!pages[path]) path = '/dashboard';
    Store.sync();
    me = Store.byId(me.id);
    if (!me || me.blocked) { Store.logout(); location.replace(base + 'login.html'); return; }

    var info = findRoute(path) || { group: M.MENU[0], item: ['/dashboard', 'Dashboard', 'home', ''] };
    var def = pages[path];
    document.title = (def.title || info.item[1]) + ' — New Era Trading';
    document.getElementById('mCrumb').innerHTML = '<a href="#/dashboard">' + M.icon('home') + '</a>' +
      (info.group.id !== 'dashboard' ? '<span>' + M.esc(info.group.label) + '</span>' : '') + '<span class="cur">' + M.esc(def.title || info.item[1]) + '</span>';
    document.getElementById('mTitle').textContent = def.title || info.item[1];
    document.getElementById('mSub').textContent = def.sub || info.item[3] || '';
    document.getElementById('mHeadIc').innerHTML = M.icon(info.item[2] || 'home');

    // active states
    document.querySelectorAll('[data-group]').forEach(function (n) { n.classList.toggle('on', n.dataset.group === info.group.id); });
    document.querySelectorAll('[data-route]').forEach(function (n) { n.classList.toggle('on', n.dataset.route === path); });
    closeMenus();
    drawWalletChip();

    var el = document.getElementById('mMain');
    el.className = 'm-main page-' + path.replace(/\//g, '-').slice(1);
    el.innerHTML = '';
    var ctx = { me: me, S: Store.settings(), path: path, go: M.go, refresh: render, base: base };
    def.render(el, ctx);
    el.classList.remove('enter'); void el.offsetWidth; el.classList.add('enter');
    window.scrollTo({ top: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
  }
  M.refresh = function () { render(); };

  /* ---------- layout ---------- */
  function megaHtml(g) {
    return '<div class="m-mega' + (g.wide ? ' wide' : '') + '"><header class="m-mega-h">' + M.icon(g.icon) + '<b>' + g.label + '</b><small>' + g.items.length + ' pages</small></header><div class="m-mega-grid">' + g.items.map(function (it) {
      return '<a href="#' + it[0] + '" data-route="' + it[0] + '"><span class="mi">' + M.icon(it[2]) + '</span><span><b>' + M.esc(it[1]) + '</b><small>' + M.esc(it[3]) + '</small></span></a>';
    }).join('') + '</div></div>';
  }
  // floating bottom dock: one icon per group; groups with pages open a panel that pops upward
  function dockHtml() {
    return M.MENU.map(function (g) {
      var label = '<span class="m-dk-l">' + g.label + '</span>';
      if (g.route) return '<div class="m-dk-i" data-group="' + g.id + '"><a class="m-dk-b" href="#' + g.route + '" aria-label="' + g.label + '">' + M.icon(g.icon) + '</a>' + label + '</div>';
      return '<div class="m-dk-i has" data-group="' + g.id + '"><button class="m-dk-b" type="button" aria-haspopup="true" aria-label="' + g.label + '">' + M.icon(g.icon) + '</button>' + label + megaHtml(g) + '</div>';
    }).join('');
  }
  function drawWalletChip() {
    var c = document.getElementById('mWalletChip');
    if (c) c.innerHTML = '<small>Income wallet</small><b>' + M.money(me.wallet) + '</b>';
  }
  function closeMenus(except) {
    document.querySelectorAll('.m-dk-i.open, .m-pop.open').forEach(function (n) { if (n !== except) n.classList.remove('open'); });
    var dock = document.querySelector('.m-dock');
    if (dock) dock.classList.toggle('has-open', !!document.querySelector('.m-dk-i.open'));
  }

  function notifications() {
    var items = Store.db.announcements.slice().sort(function (a, b) { return b.date - a.date; }).slice(0, 3).map(function (a) {
      return { icon: 'megaphone', text: a.text, date: a.date, href: '#/dashboard' };
    }).concat(Store.txOf(me.id).filter(function (t) { return Store.INCOME_TYPES.indexOf(t.type) > -1; }).slice(0, 5).map(function (t) {
      return { icon: 'coins', text: Store.INCOME_LABELS[t.type] + ' ' + M.money(t.amount) + ' credited', date: t.date, href: '#/income/' + t.type };
    })).sort(function (a, b) { return b.date - a.date; });
    var seen = 0;
    try { seen = Number(localStorage.getItem('net_seen_' + me.id)) || 0; } catch (e) { /* ignore */ }
    var unread = items.filter(function (i) { return i.date > seen; }).length;
    document.getElementById('mBellDot').hidden = !unread;
    document.getElementById('mNotif').innerHTML = '<header><b>Notifications</b><small>' + unread + ' new</small></header>' + (items.length ? items.map(function (i) {
      return '<a href="' + i.href + '" class="' + (i.date > seen ? 'new' : '') + '"><span class="mi">' + M.icon(i.icon) + '</span><span><b>' + M.esc(i.text) + '</b><small>' + M.dt(i.date) + '</small></span></a>';
    }).join('') : '<p class="m-muted" style="padding:1rem">You are all caught up.</p>');
  }

  /* ---------- e-mail must be verified before the dashboard unlocks (report requirement #9) ---------- */
  function renderEmailGate() {
    var app = document.getElementById('app');
    app.innerHTML =
      '<header class="m-top"><div class="m-wrap m-top-in">' +
        '<a class="m-brand" href="' + base + 'index.html"><img src="' + base + 'images/logo.png" alt="New Era Trading"><span>New Era Trading<small>Member Hub</small></span></a>' +
        '<div class="m-top-r"><button class="mbtn line sm" id="gateLogout">' + M.icon('logout') + 'Logout</button></div>' +
      '</div></header>' +
      '<main class="m-wrap" style="max-width:480px;margin:3rem auto">' +
        M.card('Verify your e-mail to continue', '<div class="m-alert" hidden id="gateErr"></div>' +
          '<p class="m-muted">We e-mailed a 6-digit code to <b>' + M.esc(me.email) + '</b>. Enter it below to unlock your dashboard.</p>' +
          '<form id="gateForm" class="m-form">' +
            M.field('Code', '<input name="otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required placeholder="123456">') +
            '<button class="mbtn gold block" type="submit">' + M.icon('check') + 'Verify e-mail</button>' +
          '</form>' +
          '<button class="mbtn line block" id="gateResend" type="button" style="margin-top:.6rem">Resend code</button>' +
          '<p class="m-muted" style="font-size:.82rem;margin-top:1rem">Typed the wrong e-mail by mistake? Contact support — an admin can correct it for you.</p>', { icon: 'shield' }) +
      '</main>';
    document.getElementById('gateLogout').onclick = function () { Store.logout(); location.href = base + 'login.html'; };
    document.getElementById('gateForm').addEventListener('submit', function (e) {
      e.preventDefault();
      var err = document.getElementById('gateErr'); M.alert(err, '');
      var btn = this.querySelector('button[type=submit]'); btn.disabled = true;
      Store.verifyEmail(this.otp.value.trim()).then(function (r) {
        btn.disabled = false;
        if (!r.ok) return M.alert(err, r.error, 'bad');
        M.toast('E-mail verified');
        location.reload();
      });
    });
    document.getElementById('gateResend').onclick = function () {
      var b = this; b.disabled = true;
      Store.requestEmailOtp().then(function (r) {
        b.disabled = false;
        M.toast(r.ok ? 'A new code was sent' : r.error, r.ok ? 'ok' : 'err');
      });
    };
  }

  M.mount = function () {
    var u = Store.currentUser();
    if (!u || u.role !== 'member') { if (u) Store.logout(); location.replace(base + 'login.html'); return; }
    me = u;
    if (! me.emailVerified) { renderEmailGate(); return; }
    var app = document.getElementById('app');
    app.innerHTML =
      '<header class="m-top"><div class="m-wrap m-top-in">' +
        '<a class="m-brand" href="#/dashboard"><img src="' + base + 'images/logo.png" alt="New Era Trading"><span>New Era Trading<small>Member Hub</small></span></a>' +
        '<div class="m-top-r">' +
          '<div class="m-chip" id="mWalletChip"></div>' +
          '<div class="m-pop" id="mBellWrap"><button class="m-icbtn" id="mBell" aria-label="Notifications">' + M.icon('bell') + '<i id="mBellDot" hidden></i></button><div class="m-drop m-notif" id="mNotif"></div></div>' +
          '<div class="m-pop" id="mUserWrap"><button class="m-user" id="mUser" aria-label="Account menu"><span class="m-av">' + M.esc(UI.initials(me.name)) + '</span><span class="m-user-t"><b>' + M.esc(me.name) + '</b><small>' + M.esc(me.code) + '</small></span>' + M.icon('chevron', 'chev') + '</button>' +
            '<div class="m-drop m-umenu"><div class="m-umenu-h"><span class="m-av lg">' + M.esc(UI.initials(me.name)) + '</span><div><b>' + M.esc(me.name) + '</b><small>' + M.esc(me.email) + '</small></div></div>' +
            '<a href="#/account/profile">' + M.icon('user') + 'Update profile</a><a href="#/account/password">' + M.icon('key') + 'Change password</a><a href="#/account/pin">' + M.icon('shield') + 'Transaction PIN</a>' +
            '<a href="' + base + 'index.html">' + M.icon('home') + 'Website</a>' +
            '<button id="mLogout" class="danger">' + M.icon('logout') + 'Logout</button></div></div>' +
        '</div>' +
      '</div></header>' +
      '<section class="m-head"><div class="m-wrap m-head-in"><span class="m-head-ic" id="mHeadIc"></span><div><nav class="m-crumb" id="mCrumb"></nav><h1 id="mTitle"></h1><p id="mSub"></p></div></div></section>' +
      '<main class="m-wrap"><div class="m-main" id="mMain"></div></main>' +
      '<footer class="m-foot m-wrap"><span>© ' + new Date().getFullYear() + ' New Era Trading · neweratrading.net</span><span>Demo data stored in your browser. Earnings are not guaranteed.</span></footer>' +
      '<div class="m-dock-scrim" id="mDockScrim"></div>' +
      '<nav class="m-dock" aria-label="Main menu"><div class="m-dock-in">' + dockHtml() + '</div></nav>';

    // dock: click a group to pop its pages upward; click again / outside / Esc to close
    var dock = document.querySelector('.m-dock');
    document.querySelectorAll('.m-dk-i.has').forEach(function (n) {
      n.querySelector('.m-dk-b').addEventListener('click', function (e) {
        e.stopPropagation();
        var o = !n.classList.contains('open');
        closeMenus();
        if (o) { n.classList.add('open'); dock.classList.add('has-open'); }
      });
    });
    document.getElementById('mDockScrim').onclick = function () { closeMenus(); };
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenus(); });
    // macOS-style magnification on pointer devices
    var items = [].slice.call(document.querySelectorAll('.m-dk-i'));
    if (matchMedia('(hover: hover) and (pointer: fine)').matches && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
      var inner = dock.querySelector('.m-dock-in');
      inner.addEventListener('mousemove', function (e) {
        items.forEach(function (it) {
          var r = it.getBoundingClientRect(), d = Math.abs(e.clientX - (r.left + r.width / 2));
          it.style.setProperty('--s', (1 + Math.max(0, 1 - d / 150) * 0.38).toFixed(3));
        });
      });
      inner.addEventListener('mouseleave', function () { items.forEach(function (it) { it.style.setProperty('--s', 1); }); });
    }
    ['mBellWrap', 'mUserWrap'].forEach(function (id) {
      var w = document.getElementById(id);
      w.querySelector('button').addEventListener('click', function (e) {
        e.stopPropagation(); var o = !w.classList.contains('open'); closeMenus(); if (o) w.classList.add('open');
        if (id === 'mBellWrap' && o) { try { localStorage.setItem('net_seen_' + me.id, Date.now()); } catch (er) { /* ignore */ } setTimeout(notifications, 1500); }
      });
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('.m-dk-i, .m-pop')) closeMenus(); });
    document.getElementById('mLogout').onclick = function () { Store.logout(); location.href = base + 'login.html'; };

    notifications();
    window.addEventListener('hashchange', render);
    render();
  };
})(window);
