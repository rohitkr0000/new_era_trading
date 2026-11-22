/* ==========================================================================
   app.js — shared UI helpers + panel layout (sidebar / topbar / auth guard)
   ========================================================================== */
(function (global) {
  'use strict';

  var UI = {
    esc: function (s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    },
    money: function (n) {
      var v = Number(n) || 0;
      return (v < 0 ? '-' : '') + Store.settings().currency +
        Math.abs(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    date: function (ts) {
      return new Date(ts).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });
    },
    initials: function (name) {
      return String(name || '?').split(/\s+/).map(function (w) { return w[0]; }).slice(0, 2).join('').toUpperCase();
    },
    badge: function (text, kind) { return '<span class="badge badge-' + (kind || 'gray') + '">' + UI.esc(text) + '</span>'; },
    statusBadge: function (u) {
      if (u.blocked) return UI.badge('Blocked', 'red');
      return u.invested > 0 ? UI.badge('Active', 'green') : UI.badge('Inactive', 'amber');
    },
    // invested amount (packages are amount-based: joining from $5, then retopups)
    pkgBadge: function (u) {
      return u && u.invested > 0 ? UI.badge(UI.money(u.invested), 'blue') : UI.badge('No package', 'gray');
    },
    typeBadge: function (t) {
      var map = { direct: 'green', level: 'blue', binary: 'purple', withdrawal: 'red', refund: 'amber' };
      return UI.badge(t.charAt(0).toUpperCase() + t.slice(1), map[t] || 'gray');
    },
    toast: function (msg, kind) {
      var box = document.getElementById('toasts');
      if (!box) { box = document.createElement('div'); box.id = 'toasts'; document.body.appendChild(box); }
      var el = document.createElement('div');
      el.className = 'toast toast-' + (kind || 'ok');
      el.textContent = msg;
      box.appendChild(el);
      setTimeout(function () { el.classList.add('hide'); setTimeout(function () { el.remove(); }, 300); }, 2800);
    },
    copy: function (text) {
      var done = function () { UI.toast('Copied to clipboard'); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, done);
      else { var t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); } catch (e) { /* ignore */ } t.remove(); done(); }
    },
    barChart: function (el, items) {
      var max = Math.max.apply(null, items.map(function (i) { return i.value; }).concat([1]));
      el.innerHTML = '<div class="bar-chart">' + items.map(function (i) {
        return '<div class="bar-col"><span class="bar-val">' + (i.value ? UI.esc(i.fmt ? i.fmt(i.value) : i.value) : '') + '</span>' +
          '<div class="bar" style="height:' + Math.max(3, (i.value / max) * 100) + '%"></div>' +
          '<span class="bar-lbl">' + UI.esc(i.label) + '</span></div>';
      }).join('') + '</div>';
    },
    table: function (headers, rows, empty) {
      if (!rows.length) return '<div class="empty">' + UI.esc(empty || 'Nothing to show yet.') + '</div>';
      return '<div class="table-wrap"><table class="table"><thead><tr>' +
        headers.map(function (h) { return '<th>' + h + '</th>'; }).join('') + '</tr></thead><tbody>' +
        rows.map(function (r) { return '<tr>' + r.map(function (c) { return '<td>' + c + '</td>'; }).join('') + '</tr>'; }).join('') +
        '</tbody></table></div>';
    },
    // absolute URL of the public register page with a sponsor code (works from file:// too)
    referralLink: function (code, base) {
      return new URL((base || '') + 'register.html?sponsor=' + encodeURIComponent(code), location.href).href;
    }
  };

  var NAV = {
    user: [
      ['dashboard', '📊', 'Dashboard'], ['genealogy', '🌳', 'Genealogy'], ['referrals', '👥', 'My Team'],
      ['income', '💰', 'Income'], ['wallet', '👛', 'Wallet'], ['packages', '📦', 'Packages'], ['profile', '👤', 'Profile']
    ],
    admin: [
      ['dashboard', '📊', 'Dashboard'], ['members', '👥', 'Members'], ['deposits', '💳', 'Deposits'], ['payouts', '💸', 'Payouts'], ['wallet', '💰', 'Company Wallet'], ['kyc', '🪪', 'KYC'], ['tickets', '💬', 'Tickets'], ['alerts', '🚨', 'Alerts'], ['audit', '🧾', 'Audit Log'], ['calculator', '🧮', 'Calculator'], ['settings', '⚙️', 'Plan Settings']
    ]
  };

  var Layout = {
    user: null,
    mount: function () {
      var b = document.body, role = b.dataset.role, page = b.dataset.page, base = b.dataset.base || '';
      var u = Store.currentUser();
      var needed = role === 'user' ? 'member' : role;   // the "user" area is for accounts with role "member"
      if (!u || u.role !== needed) {
        if (u) Store.logout();                           // wrong area for this account: clear session so login.html doesn't bounce back
        location.replace(base + 'login.html');
        return null;
      }
      Layout.user = u;

      var content = document.getElementById('page-content');
      var links = NAV[role].map(function (n) {
        return '<a href="' + n[0] + '.html" class="' + (n[0] === page ? 'active' : '') + '"><span>' + n[1] + '</span>' + n[2] + '</a>';
      }).join('');

      var shell = document.createElement('div');
      shell.className = 'shell';
      shell.innerHTML =
        '<aside class="sidebar" id="sidebar">' +
          '<a class="brand" href="' + base + 'index.html"><img class="logo-img" src="' + base + 'images/logo.png" alt="">' + UI.esc(Store.settings().siteName) + '</a>' +
          '<div class="nav-label">' + (role === 'admin' ? 'Administration' : 'Member area') + '</div>' +
          '<nav class="nav">' + links + '</nav>' +
          '<div class="side-foot"><a href="' + base + 'index.html">← Public site</a></div>' +
        '</aside>' +
        '<div class="scrim" id="scrim"></div>' +
        '<div class="main">' +
          '<header class="topbar">' +
            '<button class="icon-btn menu-btn" id="menuBtn" aria-label="Menu">☰</button>' +
            '<h1>' + UI.esc(b.dataset.title || '') + '</h1>' +
            '<div class="topbar-right">' +
              '<div class="user-chip"><span class="avatar">' + UI.esc(UI.initials(u.name)) + '</span>' +
              '<span class="user-meta"><b>' + UI.esc(u.name) + '</b><small>' + UI.esc(u.code) + '</small></span></div>' +
              '<button class="btn btn-ghost btn-sm" id="logoutBtn">Logout</button>' +
            '</div>' +
          '</header>' +
          '<main class="content" id="page"></main>' +
        '</div>';
      document.body.appendChild(shell);
      document.getElementById('page').appendChild(content);
      content.hidden = false;

      var sb = document.getElementById('sidebar');
      var toggle = function () { sb.classList.toggle('open'); document.getElementById('scrim').classList.toggle('show'); };
      document.getElementById('menuBtn').addEventListener('click', toggle);
      document.getElementById('scrim').addEventListener('click', toggle);
      document.getElementById('logoutBtn').addEventListener('click', function () { Store.logout(); location.href = base + 'login.html'; });
      if (role === 'admin') {   // member profiles + suspicious-activity alerts + popup (loaded on every admin page)
        var rs = document.createElement('script'); rs.src = base + 'js/admin-risk.js';
        rs.onload = function () { if (global.AdminRisk) global.AdminRisk.watch(); };
        document.body.appendChild(rs);
      }
      return u;
    }
  };

  global.UI = UI;
  global.Layout = Layout;
})(window);
