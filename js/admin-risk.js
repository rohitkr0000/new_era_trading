/* ==========================================================================
   admin-risk.js — admin only
     · AdminRisk.openMember(id)      full picture of one member (team, levels, income, money, activity, alerts) with actions
     · AdminRisk.watch()             bell + nav badge + POPUP when new suspicious activity / data errors appear
     · AdminRisk.renderAlerts(...)   the list used by the Alerts page
   Everything printed here comes through UI.esc (names, notes and audit details are user-controlled text).
   ========================================================================== */
(function (global) {
  'use strict';

  var esc = function (s) { return UI.esc(s); };
  var money = function (n) { return UI.money(n); };
  var dt = function (ms) { return ms ? new Date(ms).toLocaleString(undefined, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'; };
  var SEEN_KEY = 'net_risk_seen';
  var openModals = 0;
  var timer = null, lastCounts = null;

  function sevBadge(s) { return UI.badge(s === 'high' ? 'High' : s === 'medium' ? 'Medium' : 'Low', s === 'high' ? 'red' : s === 'medium' ? 'amber' : 'blue'); }
  function stBadge(s) { return UI.badge(s, s === 'open' ? 'red' : s === 'resolved' ? 'green' : 'gray'); }
  function getSeen() { try { return Number(localStorage.getItem(SEEN_KEY)) || 0; } catch (e) { return 0; } }
  function setSeen(n) { try { localStorage.setItem(SEEN_KEY, String(n)); } catch (e) { /* ignore */ } }

  /* ---------- tiny modal ---------- */
  function modal(html, cls, onClose) {
    var back = document.createElement('div');
    back.className = 'modal-back';
    back.innerHTML = '<div class="modal-box ' + (cls || '') + '" role="dialog" aria-modal="true">' + html + '</div>';
    document.body.appendChild(back);
    openModals++;
    var closed = false;
    function close() {
      if (closed) return; closed = true;
      back.remove(); openModals--; document.removeEventListener('keydown', onKey);
      if (onClose) onClose();
    }
    function onKey(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', onKey);
    back.addEventListener('mousedown', function (e) { if (e.target === back) close(); });
    back.querySelectorAll('[data-close]').forEach(function (b) { b.onclick = close; });
    return { el: back, box: back.firstChild, close: close };
  }

  /* ---------- alert actions (resolve / dismiss / reopen) with an optional note ---------- */
  function actAlert(id, status, done) {
    var note = null;
    if (status !== 'open') { note = window.prompt(status === 'resolved' ? 'Resolved — what did you do? (optional note)' : 'Dismiss — why is this not a problem? (optional note)', ''); if (note === null) return; }
    Store.riskUpdate(id, status, note).then(function (r) {
      if (!r.ok) return UI.toast(r.error, 'err');
      UI.toast(status === 'open' ? 'Alert reopened' : 'Alert ' + status);
      if (done) done();
      poll();
    });
  }

  function alertCard(a, opts) {
    opts = opts || {};
    var open = a.status === 'open';
    var who = a.userId ? '<a href="#" data-member="' + a.userId + '"><b>' + esc(a.userName || 'Member') + '</b> · ' + esc(a.userCode || '') + '</a>' + (a.blocked ? ' ' + UI.badge('Blocked', 'red') : '') : '';
    return '<div class="alert-card sev-' + esc(a.severity) + (open ? '' : ' done') + '" data-aid="' + a.id + '">' +
      '<h4>' + sevBadge(a.severity) + esc(a.title) + ' ' + (open ? '' : stBadge(a.status)) + '</h4>' +
      (opts.hideWho ? '' : '<div class="small">' + who + '</div>') +
      '<p>' + esc(a.detail) + '</p>' +
      '<div class="small muted">' + dt(a.date) + (a.note ? ' · Note: ' + esc(a.note) : '') + '</div>' +
      '<div class="acts" style="margin-top:.5rem">' +
        (open ? '<button class="btn btn-sm btn-success" data-act="resolved">Resolve</button><button class="btn btn-sm btn-ghost" data-act="dismissed">Not a problem</button>' : '<button class="btn btn-sm btn-ghost" data-act="open">Reopen</button>') +
        (opts.hideWho || !a.userId ? '' : '<button class="btn btn-sm btn-outline" data-member="' + a.userId + '">View member</button>') +
        (opts.hideWho || !a.userId || a.blocked || !open ? '' : '<button class="btn btn-sm btn-danger" data-block="' + a.userId + '">Block member</button>') +
      '</div></div>';
  }

  function wireCards(root, afterChange) {
    root.querySelectorAll('[data-member]').forEach(function (b) { b.onclick = function (e) { e.preventDefault(); openMember(Number(b.dataset.member)); }; });
    root.querySelectorAll('[data-act]').forEach(function (b) {
      b.onclick = function () { var id = Number(b.closest('[data-aid]').dataset.aid); actAlert(id, b.dataset.act, afterChange); };
    });
    root.querySelectorAll('[data-block]').forEach(function (b) {
      b.onclick = function () {
        var uid = Number(b.dataset.block);
        if (!window.confirm('Block this member? They are signed out at once and cannot log in until you unblock them.')) return;
        Store.setBlocked(uid, true).then(function (r) { UI.toast(r.ok ? 'Member blocked' : r.error, r.ok ? 'ok' : 'err'); if (r.ok && afterChange) afterChange(); });
      };
    });
  }

  /* ---------- one member: the whole picture ---------- */
  function openMember(id, tab) {
    var m = modal('<div class="modal-head"><h2>Loading member…</h2><div class="grow"><button class="btn btn-sm btn-ghost" data-close>Close</button></div></div><div class="modal-body"><div class="empty">Loading…</div></div>');
    Store.memberOverview(id).then(function (d) {
      if (!d.ok) { m.box.querySelector('.modal-body').innerHTML = '<div class="banner-bad">' + esc(d.error) + '</div>'; return; }
      renderMember(m, d, tab || 'summary');
    });
  }

  function kv(rows) { return '<div class="kv">' + rows.map(function (r) { return '<div><span>' + r[0] + '</span><b>' + r[1] + '</b></div>'; }).join('') + '</div>'; }
  function tiles(rows) { return '<div class="tiles">' + rows.map(function (r) { return '<div class="tile"><small>' + r[0] + '</small><b>' + r[1] + '</b></div>'; }).join('') + '</div>'; }
  function yn(v, yes, no) { return v ? UI.badge(yes || 'Yes', 'green') : UI.badge(no || 'No', 'amber'); }

  /** TEST TOOL: a days-input + button per package, to instantly credit ROI days instead of waiting for real time. */
  function ffCell(o) {
    if (o.status !== 'running') return '<span class="muted small">—</span>';
    var isComp = o.option === 'Compounding';
    var remaining = o.days ? Math.max(1, o.days - o.paidDays) : 25;
    return '<div class="row" style="gap:.3rem;align-items:center;flex-wrap:nowrap">' +
      '<input type="number" min="1" max="400" value="' + remaining + '" class="ffDays" data-order="' + o.id + '" style="width:60px">' +
      '<button class="btn btn-sm btn-outline ffBtn" data-order="' + o.id + '" data-comp="' + (isComp ? '1' : '0') + '">' + (isComp ? 'Advance acct' : 'Complete') + '</button>' +
    '</div>';
  }

  function renderMember(m, d, tab) {
    var u = d.member, t = d.team, c = d.cap, mo = d.money;
    var openAlerts = d.alerts.filter(function (a) { return a.status === 'open'; });
    var issues = d.checks.length;

    var head = '<div class="modal-head"><h2>' + esc(u.name) + ' <small class="muted">· ' + esc(u.code) + '</small></h2>' +
      (u.blocked ? UI.badge('Blocked', 'red') : u.invested > 0 ? UI.badge('Active', 'green') : UI.badge('Inactive', 'amber')) +
      (openAlerts.length ? UI.badge(openAlerts.length + ' open alert' + (openAlerts.length > 1 ? 's' : ''), 'red') : '') +
      '<div class="grow"><button class="btn btn-sm ' + (u.blocked ? 'btn-success' : 'btn-danger') + '" id="mBlock">' + (u.blocked ? 'Unblock member' : 'Block member') + '</button>' +
      '<button class="btn btn-sm btn-ghost" id="mRevoke">Sign out everywhere (' + u.sessions + ')</button>' +
      '<button class="btn btn-sm btn-ghost" data-close>Close</button></div></div>';

    var banner = issues || openAlerts.length
      ? '<div class="banner-bad"><b>⚠ Needs your attention.</b> ' + (issues ? issues + ' problem' + (issues > 1 ? 's' : '') + ' found right now. ' : '') + 'See the “Alerts” tab to handle them.</div>'
      : '<div class="banner-ok">✓ No data errors or suspicious activity found for this member right now.</div>';

    var tabs = [['summary', 'Summary'], ['team', 'Team &amp; levels'], ['income', 'Income'], ['money', 'Money'], ['activity', 'Activity'], ['alerts', 'Alerts' + (openAlerts.length || issues ? ' ⚠' : '')]];
    var tabBar = '<div class="tabs">' + tabs.map(function (x) { return '<button class="tab' + (x[0] === tab ? ' on' : '') + '" data-tab="' + x[0] + '">' + x[1] + '</button>'; }).join('') + '</div>';

    var pages = {};

    pages.summary =
      tiles([['Invested', money(u.invested)], ['Income wallet', money(u.wallet)], ['Purchase wallet', money(u.purchaseWallet)], ['Total earned', money(d.income.total)],
        ['Team members', t.total], ['Direct referrals', t.directs]]) +
      '<div class="subhead">Profile</div>' +
      kv([['E-mail', esc(u.email) + ' ' + yn(u.emailVerified, 'verified', 'not verified')], ['Phone', esc(u.phone || '—')], ['Country / city', esc([u.country, u.city].filter(Boolean).join(' / ') || '—')],
        ['KYC', UI.badge(u.kyc, u.kyc === 'approved' ? 'green' : u.kyc === 'rejected' ? 'red' : 'amber') + ' <a href="kyc.html" class="small">Review documents →</a>'], ['Transaction PIN', yn(u.hasPin, 'set', 'not set')], ['Payout wallet', esc(u.usdtAddress || '—')],
        ['Sponsor', u.sponsor ? '<a href="#" data-member="' + u.sponsor.id + '">' + esc(u.sponsor.name) + ' · ' + esc(u.sponsor.code) + '</a>' : '—'],
        ['Joined', dt(u.joined)], ['Last sign-in', dt(u.lastLogin)], ['Last sign-in IP', esc(u.lastLoginIp || '—')], ['Active sessions', u.sessions]]) +
      '<div class="subhead">Earning cap</div>' + capBlock(c, d.income.total) +
      '<div class="subhead">Admin: account &amp; security</div>' + credBlock(u);

    pages.team =
      tiles([['Direct referrals', t.directs], ['Active directs', t.activeDirects], ['Whole team (all levels)', t.total], ['Active in team', t.active], ['Team business', money(t.business)], ['Level-1 business', money(c.directBusiness)]]) +
      '<p class="hint" style="margin-bottom:.8rem">Cap rule: with level-1 business of at least ' + money(c.businessNeeded) + ' (5× own investment) this member is <b>working</b> and earns up to ' + (c.working ? c.mult + 'X' : '5X') + '; otherwise up to 2X. ' +
        'Now: <b>' + (c.working ? 'Working (' + c.mult + 'X)' : 'Non-working (' + c.mult + 'X)') + '</b>. Level k income needs at least k active directs.</p>' +
      '<div class="subhead">Members and level income, level by level</div>' +
      UI.table(['Level', 'Pays', 'Members', 'Active', 'Business', 'Level income earned', 'Unlocked'],
        t.levels.map(function (l) {
          return ['L' + l.level, l.pct + '%', l.members, l.active, money(l.business), money(l.income), l.unlocked ? UI.badge('Yes', 'green') : UI.badge('Needs ' + l.level + ' active directs', 'gray')];
        })) +
      '<p class="hint">Members deeper than level 12 are counted in “Whole team” but earn no level income.</p>';

    pages.income =
      tiles([['ROI income', money(d.income.roi)], ['Direct income', money(d.income.direct)], ['Retopup income', money(d.income.retopup)], ['Level income', money(d.income.level)], ['Total income', money(d.income.total)]]) +
      capBlock(c, d.income.total) +
      '<div class="subhead">Latest income-wallet entries</div>' +
      UI.table(['Date', 'Type', 'Amount', 'Note'], d.ledger.map(function (l) { return [dt(l.date), UI.typeBadge(l.type), '<b style="color:' + (l.amount < 0 ? '#b91c1c' : '#166534') + '">' + money(l.amount) + '</b>', esc(l.note)]; }), 'No entries.');

    pages.money =
      tiles([['Deposits approved', money(mo.depositsApproved)], ['Deposits pending', money(mo.depositsPending)], ['Withdrawn (approved)', money(mo.withdrawnPaid)], ['Withdrawals pending', money(mo.withdrawalsPending)],
        ['P2P sent', money(mo.p2pSent) + ' <small>(' + mo.p2pSentCount + ')</small>'], ['P2P received', money(mo.p2pReceived) + ' <small>(' + mo.p2pReceivedCount + ')</small>']]) +
      '<div class="subhead">Packages</div>' +
      '<p class="hint">“Test: fast-forward” instantly credits ROI days right now, skipping the real wait — for testing only.</p>' +
      UI.table(['Ref', 'Type', 'Option', 'Amount', 'ROI days', 'Status', 'Date', 'Test: fast-forward'], d.orders.map(function (o) { return [esc(o.ref), esc(o.kind), esc(o.option), money(o.amount), o.paidDays + (o.days ? ' / ' + o.days : ''), esc(o.status), dt(o.date), ffCell(o)]; }), 'No packages.') +
      '<div class="subhead">Deposits</div>' +
      UI.table(['Date', 'Amount', 'Status', 'Blockchain check', 'Transaction'], d.deposits.map(function (x) { return [dt(x.date), money(x.amount), esc(x.status), (x.verified ? UI.badge(x.verified, x.verified === 'verified' ? 'green' : x.verified === 'mismatch' ? 'red' : 'amber') : '—') + (x.note ? '<br><small class="muted">' + esc(x.note) + '</small>' : ''), '<small>' + esc(x.txn) + '</small>']; }), 'No deposits.') +
      '<div class="subhead">Withdrawals</div>' +
      UI.table(['Date', 'Ref', 'Amount', 'Fee', 'Net', 'Status', 'To'], d.withdrawals.map(function (w) { return [dt(w.date), esc(w.ref), money(w.amount), money(w.fee), money(w.net), esc(w.status), '<small>' + esc(w.account) + '</small>']; }), 'No withdrawals.') +
      '<div class="subhead">Transfers</div>' +
      UI.table(['Date', 'Kind', 'Direction', 'Other member', 'Amount', 'Fee'], d.transfers.map(function (x) { return [dt(x.date), esc(x.kind === 'self' ? 'Own wallet' : 'P2P'), x.kind === 'self' ? '—' : (x.dir === 'out' ? 'Sent' : 'Received'), esc(x.other || '—'), money(x.amount), money(x.fee)]; }), 'No transfers.');

    pages.activity =
      '<div class="subhead">Recent activity (sign-ins, money requests, security events)</div>' +
      UI.table(['Date', 'Action', 'IP', 'Details'], d.audit.map(function (a) {
        var bad = /failed|wrong|block|reject/.test(a.action);
        return [dt(a.date), (bad ? UI.badge(a.action, 'red') : '<code>' + esc(a.action) + '</code>'), esc(a.ip || '—'), '<small>' + esc(a.meta ? JSON.stringify(a.meta) : '') + '</small>'];
      }), 'No activity recorded.');

    var live = d.checks.map(function (f) {
      return '<div class="alert-card sev-' + esc(f.severity) + '"><h4>' + sevBadge(f.severity) + esc(f.title) + ' ' + UI.badge('detected now', 'red') + '</h4><p>' + esc(f.detail) + '</p></div>';
    }).join('');
    var hist = d.alerts.map(function (a) { return alertCard({ id: a.id, userId: u.id, severity: a.severity, title: a.title, detail: a.detail, status: a.status, note: a.note, date: a.date }, { hideWho: true }); }).join('');
    pages.alerts =
      '<div class="subhead">Found by the live check right now</div>' + (live || '<div class="banner-ok">Nothing wrong found by the live check.</div>') +
      '<div class="subhead">Alert history for this member</div>' + (hist || '<div class="empty">No alerts have been raised for this member.</div>') +
      '<p class="hint">Alerts are raised automatically every 10 minutes. “Resolve” = you dealt with it · “Not a problem” = it is fine, do not raise this same case again.</p>';

    m.box.innerHTML = head + '<div class="modal-body">' + banner + tabBar + '<div id="mPage">' + pages[tab] + '</div></div>';
    m.box.querySelectorAll('[data-close]').forEach(function (b) { b.onclick = m.close; });

    function show(name) {
      tab = name;
      m.box.querySelectorAll('.tab').forEach(function (b) { b.classList.toggle('on', b.dataset.tab === name); });
      var page = m.box.querySelector('#mPage');
      page.innerHTML = pages[name];
      wire(page);
    }
    function reload() { Store.memberOverview(u.id).then(function (n) { if (n.ok) renderMember(m, n, tab); }); }
    function wire(root) {
      wireCards(root, reload);
      root.querySelectorAll('[data-member]').forEach(function (b) { b.onclick = function (e) { e.preventDefault(); m.close(); openMember(Number(b.dataset.member)); }; });
      wireCredBlock(root, u, reload);
      root.querySelectorAll('.ffBtn').forEach(function (b) {
        b.onclick = function () {
          var orderId = Number(b.dataset.order);
          var isComp = b.dataset.comp === '1';
          var input = root.querySelector('.ffDays[data-order="' + orderId + '"]');
          var days = Math.max(1, Math.min(400, Number(input.value) || 1));
          if (!window.confirm((isComp ? 'Advance ' + u.name + '’s whole compounding balance by ' : 'Instantly complete ') + days + ' trading day(s) for this package? TEST tool — it credits real ROI right now, skipping the wait.')) return;
          b.disabled = true;
          var call = isComp ? Store.fastForwardCompound(u.id, days) : Store.fastForwardOrder(orderId, days);
          call.then(function (r) {
            b.disabled = false;
            if (!r.ok) return UI.toast(r.error, 'err');
            UI.toast(isComp ? 'Advanced ' + r.processedDays + ' day(s), balance ' + money(r.balance) : 'Credited through day ' + r.paidDays + (r.days ? ' / ' + r.days : ''), 'ok');
            reload();
          });
        };
      });
    }
    m.box.querySelectorAll('.tab').forEach(function (b) { b.onclick = function () { show(b.dataset.tab); }; });
    wire(m.box.querySelector('#mPage'));

    m.box.querySelector('#mBlock').onclick = function () {
      var nb = !u.blocked;
      if (nb && !window.confirm('Block ' + u.name + '? They are signed out at once and cannot log in until you unblock them.')) return;
      Store.setBlocked(u.id, nb).then(function (r) { UI.toast(r.ok ? u.name + (nb ? ' blocked' : ' unblocked') : r.error, r.ok ? 'ok' : 'err'); if (r.ok) { reload(); if (global.__membersRedraw) global.__membersRedraw(); } });
    };
    m.box.querySelector('#mRevoke').onclick = function () {
      if (!window.confirm('Sign ' + u.name + ' out of every device? They can log in again with their password.')) return;
      Store.revokeSessions(u.id).then(function (r) { UI.toast(r.ok ? 'Signed out of ' + r.revoked + ' session(s)' : r.error, r.ok ? 'ok' : 'err'); if (r.ok) reload(); });
    };
  }

  /* ---------- Summary tab: admin can change a member's profile, password, PIN and sponsor ---------- */
  function credBlock(u) {
    return '<div class="alert-card" style="border-left-color:var(--primary)">' +
      '<form id="acctForm" class="row" style="gap:.6rem;flex-wrap:wrap;align-items:end">' +
        '<div><label class="small muted">E-mail</label><br><input id="acctEmail" type="email" value="' + esc(u.email) + '" style="width:220px"></div>' +
        '<div><label class="small muted">Phone</label><br><input id="acctPhone" type="text" inputmode="numeric" maxlength="10" value="' + esc(u.phone || '') + '" style="width:140px" oninput="this.value=this.value.replace(/\\D/g,\'\').slice(0,10)"></div>' +
        '<div><label class="small muted">Country</label><br><input id="acctCountry" type="text" value="' + esc(u.country || '') + '" style="width:110px"></div>' +
        '<div><label class="small muted">City</label><br><input id="acctCity" type="text" value="' + esc(u.city || '') + '" style="width:110px"></div>' +
        '<div><label class="small muted">Payout wallet (USDT)</label><br><input id="acctWallet" type="text" value="' + esc(u.usdtAddress || '') + '" style="width:220px;font-family:monospace"></div>' +
        '<button class="btn btn-sm btn-primary" type="submit">Update profile</button>' +
      '</form>' +
      '<p class="hint">Changing the e-mail marks it unverified again — the member must verify it before withdrawing.</p>' +
      '<hr style="border:none;border-top:1px dashed var(--line);margin:.7rem 0">' +
      '<form id="pwForm" class="row" style="gap:.6rem;flex-wrap:wrap;align-items:end">' +
        '<div><label class="small muted">New password (leave blank to auto-generate)</label><br><input id="pwNew" type="text" placeholder="8+ chars, letters + numbers" style="min-width:240px" autocomplete="off"></div>' +
        '<button class="btn btn-sm btn-danger" type="submit">Reset password</button>' +
      '</form>' +
      '<p class="hint">Resetting signs the member out of every device; they sign in again with the new password.</p>' +
      '<hr style="border:none;border-top:1px dashed var(--line);margin:.7rem 0">' +
      '<form id="pinForm" class="row" style="gap:.6rem;flex-wrap:wrap;align-items:end">' +
        '<div><label class="small muted">New transaction PIN (leave blank to auto-generate)</label><br><input id="pinNew" type="text" placeholder="4–6 digits" style="width:160px" autocomplete="off"></div>' +
        '<button class="btn btn-sm btn-danger" type="submit">Reset PIN</button>' +
      '</form>' +
      '<p class="hint">The PIN guards withdrawals and transfers. Resetting it also clears any wrong-PIN lockout.</p>' +
      '<hr style="border:none;border-top:1px dashed var(--line);margin:.7rem 0">' +
      '<form id="sponsorForm" class="row" style="gap:.6rem;flex-wrap:wrap;align-items:end">' +
        '<div><label class="small muted">Change sponsor (current: ' + (u.sponsor ? esc(u.sponsor.code) : 'none') + ')</label><br><input id="sponsorCode" type="text" placeholder="e.g. NET1001" style="width:160px;text-transform:uppercase"></div>' +
        '<button class="btn btn-sm btn-outline" type="submit">Change sponsor</button>' +
      '</form>' +
      '<p class="hint">Only future investments follow the new sponsor — commissions already paid under the old one are not rewritten, and this does not move the member in the Binary Tree.</p>' +
    '</div>';
  }

  function showGeneratedSecret(title, label, value) {
    var m = modal('<div class="modal-head"><h2>' + esc(title) + '</h2><div class="grow"><button class="btn btn-sm btn-ghost" data-close>Close</button></div></div>' +
      '<div class="modal-body"><p class="hint">Shown once — copy ' + esc(label) + ' now and share it with the member yourself. It will not be shown again.</p>' +
      '<div class="row" style="gap:.5rem"><input readonly value="' + esc(value) + '" id="genSecretBox" style="font-family:monospace;min-width:220px;flex:1">' +
      '<button class="btn btn-sm btn-outline" id="genSecretCopy">Copy</button></div></div>', 'sm');
    m.box.querySelector('#genSecretCopy').onclick = function () { UI.copy(value); };
  }

  function wireCredBlock(root, u, reload) {
    if (!root) return;
    var acctForm = root.querySelector('#acctForm');
    if (acctForm) acctForm.onsubmit = function (e) {
      e.preventDefault();
      var email = root.querySelector('#acctEmail').value.trim();
      var phone = root.querySelector('#acctPhone').value.trim();
      var country = root.querySelector('#acctCountry').value.trim();
      var city = root.querySelector('#acctCity').value.trim();
      var wallet = root.querySelector('#acctWallet').value.trim();
      var patch = {};
      if (email && email.toLowerCase() !== (u.email || '').toLowerCase()) patch.email = email;
      if (phone && phone !== (u.phone || '')) patch.phone = phone;
      if (country !== (u.country || '')) patch.country = country;
      if (city !== (u.city || '')) patch.city = city;
      if (wallet !== (u.usdtAddress || '')) patch.usdtAddress = wallet;
      if (! Object.keys(patch).length) return UI.toast('Change something first', 'err');
      var btn = acctForm.querySelector('button'); btn.disabled = true;
      Store.updateMemberContact(u.id, patch).then(function (r) {
        btn.disabled = false;
        if (!r.ok) return UI.toast(r.error, 'err');
        UI.toast('Profile updated'); reload();
      });
    };
    var pwForm = root.querySelector('#pwForm');
    if (pwForm) pwForm.onsubmit = function (e) {
      e.preventDefault();
      var pw = root.querySelector('#pwNew').value;
      if (!window.confirm('Reset ' + u.name + '’s password? They will be signed out of every device.')) return;
      var btn = pwForm.querySelector('button'); btn.disabled = true;
      Store.resetMemberPassword(u.id, pw || null).then(function (r) {
        btn.disabled = false;
        if (!r.ok) return UI.toast(r.error, 'err');
        root.querySelector('#pwNew').value = '';
        if (r.password) showGeneratedSecret('New password for ' + u.name, 'it', r.password);
        else UI.toast('Password updated. Signed out of ' + r.sessionsRevoked + ' session(s).');
        reload();
      });
    };
    var pinForm = root.querySelector('#pinForm');
    if (pinForm) pinForm.onsubmit = function (e) {
      e.preventDefault();
      var pin = root.querySelector('#pinNew').value.trim();
      if (pin && !/^\d{4,6}$/.test(pin)) return UI.toast('PIN must be 4 to 6 digits', 'err');
      if (!window.confirm('Reset ' + u.name + '’s transaction PIN?')) return;
      var btn = pinForm.querySelector('button'); btn.disabled = true;
      Store.resetMemberPin(u.id, pin || null).then(function (r) {
        btn.disabled = false;
        if (!r.ok) return UI.toast(r.error, 'err');
        root.querySelector('#pinNew').value = '';
        if (r.pin) showGeneratedSecret('New PIN for ' + u.name, 'it', r.pin);
        else UI.toast('PIN updated.');
        reload();
      });
    };
    var sponsorForm = root.querySelector('#sponsorForm');
    if (sponsorForm) sponsorForm.onsubmit = function (e) {
      e.preventDefault();
      var code = root.querySelector('#sponsorCode').value.trim();
      if (!code) return UI.toast('Enter the new sponsor’s code', 'err');
      if (!window.confirm('Change ' + u.name + '’s sponsor to ' + code.toUpperCase() + '? Only future investments will follow the new sponsor — past commissions are not rewritten.')) return;
      var btn = sponsorForm.querySelector('button'); btn.disabled = true;
      Store.updateMemberSponsor(u.id, code).then(function (r) {
        btn.disabled = false;
        if (!r.ok) return UI.toast(r.error, 'err');
        root.querySelector('#sponsorCode').value = '';
        UI.toast('Sponsor changed to ' + r.sponsor.code);
        reload();
      });
    };
  }

  function capBlock(c, total) {
    if (c.unlimited) return '<p class="hint">No earning cap is set.</p>';
    var over = Number(total) > Number(c.cap) + 0.009;
    return '<div><b>' + money(c.used) + '</b> earned of <b>' + money(c.cap) + '</b> cap (' + c.mult + 'X' + (c.working ? ', working' : ', non-working') + ') — ' + money(c.left) + ' left' +
      (over ? ' ' + UI.badge('OVER THE CAP', 'red') : '') + '</div>' +
      '<div class="meter' + (over ? ' warn' : '') + '"><i style="width:' + Math.min(100, c.pct) + '%"></i></div>';
  }

  /* ---------- alerts list (Alerts page) ---------- */
  function renderAlerts(root, params, after) {
    root.innerHTML = '<div class="empty">Loading…</div>';
    return Store.riskAlerts(params).then(function (r) {
      if (!r.ok) { root.innerHTML = '<div class="banner-bad">' + esc(r.error) + '</div>'; return r; }
      root.innerHTML = r.items.length ? r.items.map(function (a) { return alertCard(a); }).join('') : '<div class="empty">Nothing here. ✓</div>';
      wireCards(root, function () { renderAlerts(root, params, after); });
      if (after) after(r);
      return r;
    });
  }

  /* ---------- watcher: bell, nav badge, popup ---------- */
  function paintBadges(counts) {
    var bell = document.getElementById('riskBell');
    if (bell) {
      var dot = bell.querySelector('.dot');
      dot.hidden = !counts.open; dot.textContent = counts.open > 99 ? '99+' : counts.open;
      bell.classList.toggle('hot', counts.high > 0);
      bell.title = counts.open ? counts.open + ' open alert(s): ' + counts.high + ' high, ' + counts.medium + ' medium, ' + counts.low + ' low' : 'No open alerts';
    }
    var link = document.querySelector('.nav a[href="alerts.html"]');
    if (link) {
      var nb = link.querySelector('.nav-badge');
      if (counts.open) { if (!nb) { nb = document.createElement('b'); nb.className = 'nav-badge'; link.appendChild(nb); } nb.textContent = counts.open; }
      else if (nb) nb.remove();
    }
  }

  function popup(items, counts) {
    var shown = items.slice(0, 5), maxId = Math.max.apply(null, items.map(function (a) { return a.id; }));
    var m = modal('<div class="modal-head"><h2 class="pop-title">🚨 Security alert — ' + items.length + ' new</h2><div class="grow"><button class="btn btn-sm btn-ghost" data-close>Remind me later</button></div></div>' +
      '<div class="modal-body"><p class="small muted">The system found ' + (items.length > 1 ? 'these problems' : 'this problem') + ' on member accounts. Open ' + (items.length > 1 ? 'each' : 'it') + ' to see everything and decide: resolve, ignore, block or sign the member out.</p>' +
      '<div id="popList">' + shown.map(function (a) { return alertCard(a); }).join('') + '</div>' +
      (items.length > shown.length ? '<p class="hint">+ ' + (items.length - shown.length) + ' more on the Alerts page.</p>' : '') +
      '<div class="row" style="margin-top:.8rem"><a class="btn btn-primary" href="' + (document.body.dataset.page === 'alerts' ? '#' : 'alerts.html') + '">Open Alerts page (' + counts.open + ' open)</a></div></div>', 'sm', function () { setSeen(maxId); });
    wireCards(m.box, function () { m.close(); });
    m.box.querySelectorAll('[data-member]').forEach(function (b) { b.addEventListener('click', function () { m.close(); }); });
  }

  function poll() {
    return Store.riskAlerts({ status: 'open', limit: 60 }).then(function (r) {
      if (!r.ok) { if (r.status === 401 && timer) { clearInterval(timer); timer = null; } return; }
      lastCounts = r.counts;
      paintBadges(r.counts);
      var seen = getSeen();
      var fresh = r.items.filter(function (a) { return a.id > seen && (a.severity === 'high' || a.severity === 'medium'); });
      if (!fresh.length) { var onlyLow = r.items.filter(function (a) { return a.id > seen; }); if (onlyLow.length) setSeen(Math.max.apply(null, onlyLow.map(function (a) { return a.id; }))); return; }
      if (openModals === 0 && document.body.dataset.page !== 'alerts') popup(fresh, r.counts);
    });
  }

  var started = false;
  function watch() {
    if (started) return; started = true;
    var right = document.querySelector('.topbar-right');
    if (right && !document.getElementById('riskBell')) {
      var b = document.createElement('button');
      b.className = 'icon-btn bell'; b.id = 'riskBell'; b.type = 'button'; b.setAttribute('aria-label', 'Security alerts');
      b.innerHTML = '🔔<span class="dot" hidden></span>';
      b.onclick = function () { location.href = 'alerts.html'; };
      right.insertBefore(b, right.firstChild);
    }
    poll();
    timer = setInterval(poll, 45000);
  }

  global.AdminRisk = { openMember: openMember, watch: watch, renderAlerts: renderAlerts, refresh: poll, counts: function () { return lastCounts; } };
})(window);
