/* ==========================================================================
   data.js — API client for the New Era Trading Laravel backend
   --------------------------------------------------------------------------
   · On load it fetches a snapshot:  GET /api/bootstrap (signed in) or GET /api/public (visitor)
   · All READ helpers work synchronously on that snapshot (same shape the pages always used)
   · All WRITE helpers call the API and return a Promise → { ok, error, ... };
     after a successful write the snapshot is refreshed before the Promise resolves.
   · Pages wait for `Store.ready` before rendering.
   Backend URL: window.NET_API (set it before this script). Otherwise: the same site's /api when the page is served over http(s),
   or http://127.0.0.1:8000/api when opened from a file or from a local server on another port.
   ========================================================================== */
(function (global) {
  'use strict';

  function detectApi() {
    if (global.NET_API) return String(global.NET_API).replace(/\/+$/, '');
    var l = global.location || {};
    if (l.protocol !== 'http:' && l.protocol !== 'https:') return 'http://127.0.0.1:8000/api';           // opened as a file
    var local = l.hostname === 'localhost' || l.hostname === '127.0.0.1';
    if (local && l.port !== '8000' && l.port !== '') return 'http://127.0.0.1:8000/api';                  // e.g. XAMPP / Live Server
    return l.origin + '/api';                                                                              // production: API lives on the same domain
  }
  var API = detectApi();
  var TOKEN_KEY = 'net_token';
  var WD = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
  var INCOME_TYPES = ['roi', 'direct', 'retopup', 'level'];
  var INCOME_LABELS = { roi: 'ROI Income', direct: 'Direct Referral Income', retopup: 'Retopup Income', level: 'Level Income' };

  function emptyDb() {
    return { users: [], tx: [], orders: [], withdrawals: [], deposits: [], wtx: [], transfers: [], deductions: [], tickets: [], announcements: [], settings: {}, extras: {} };
  }
  var db = emptyDb(), me = null, mode = 'public', lastError = '', demo = false, demoLogins = null;

  function round2(n) { return Math.round(n * 100) / 100; }

  /* ---------------- HTTP ---------------- */
  function getToken() { try { return localStorage.getItem(TOKEN_KEY); } catch (e) { return null; } }
  function setToken(t) { try { if (t) localStorage.setItem(TOKEN_KEY, t); else localStorage.removeItem(TOKEN_KEY); } catch (e) { /* ignore */ } }
  function firstError(j) {
    if (!j) return '';
    if (j.error) return j.error;
    if (j.errors) { var k = Object.keys(j.errors)[0]; if (k) return j.errors[k][0]; }
    return j.message || '';
  }
  function req(method, path, body, form) {
    var headers = { 'Accept': 'application/json' };
    if (body) headers['Content-Type'] = 'application/json';
    var t = getToken();
    if (t) headers.Authorization = 'Bearer ' + t;
    return fetch(API + path, { method: method, headers: headers, body: form ? form : (body ? JSON.stringify(body) : undefined) })
      .then(function (r) {
        return r.text().then(function (txt) {
          var j = {};
          try { j = txt ? JSON.parse(txt) : {}; } catch (e) { j = {}; }
          if (!r.ok) return { ok: false, status: r.status, error: firstError(j) || ('Request failed (' + r.status + ')') };
          j.ok = true;
          return j;
        });
      })
      .catch(function () { return { ok: false, status: 0, error: 'Cannot reach the server. Make sure the backend is running (php artisan serve).' }; });
  }

  /* ---------------- snapshot ---------------- */
  function apply(r) {
    if (!r.ok) { lastError = r.error; return r; }
    lastError = '';
    db = emptyDb();
    Object.keys(r.db || {}).forEach(function (k) { db[k] = r.db[k]; });
    db.settings = r.settings || {};
    db.extras = r.extras || {};
    me = r.me || null;
    mode = r.mode || 'public';
    demo = !!r.demo;
    demoLogins = r.demoLogins || null;   // only a demo server sends these (taken from its .env)
    return r;
  }
  function boot() {
    var p = getToken() ? req('GET', '/bootstrap') : req('GET', '/public');
    return p.then(function (r) {
      if (!r.ok && (r.status === 401 || r.status === 403) && getToken()) { setToken(null); return req('GET', '/public').then(apply); }
      return apply(r);
    });
  }
  // writes: call the API, then refresh the snapshot so the next render shows fresh data
  function write(method, path, body) {
    return req(method, path, body).then(function (r) {
      if (!r.ok) return r;
      return boot().then(function () { return r; });
    });
  }
  var ready = boot();

  /* ---------------- lookups ---------------- */
  function byId(id) { return db.users.find(function (u) { return u.id === id; }) || null; }
  function byCode(code) {
    code = String(code || '').trim().toUpperCase();
    return db.users.find(function (u) { return u.code === code; }) || null;
  }
  function isActive(u) { return !!(u && u.role === 'member' && u.invested > 0 && !u.blocked); }
  function members() { return db.users.filter(function (u) { return u.role === 'member'; }); }
  function mine(list, userId) { return list.filter(function (x) { return x.userId === userId; }); }
  function desc(a, b) { return b.date - a.date; }
  function directs(id) { return db.users.filter(function (u) { return u.sponsorId === id; }); }
  function currentUser() { return me ? byId(me.id) : null; }

  /* ---------------- trading calendar (company timezone) ---------------- */
  var fmtCache = {};
  function tzParts(ts) {
    var tz = db.settings.timezone || 'Asia/Kolkata';
    var f = fmtCache[tz] || (fmtCache[tz] = new Intl.DateTimeFormat('en-US', { timeZone: tz, weekday: 'short', hour: 'numeric', minute: 'numeric', hourCycle: 'h23' }));
    var p = {};
    f.formatToParts(new Date(ts)).forEach(function (x) { p[x.type] = x.value; });
    return { day: WD[p.weekday], h: parseInt(p.hour, 10) % 24, m: parseInt(p.minute, 10) };
  }
  function isTradingDay(ts) { return (db.settings.tradingDays || []).indexOf(tzParts(ts).day) > -1; }
  function hourLabel(h) { return (h % 12 || 12) + ':00 ' + (h < 12 ? 'AM' : 'PM'); }
  function marketStatus(ts) {
    var S = db.settings, p = tzParts(ts || Date.now()), mins = p.h * 60 + p.m;
    var trading = (S.tradingDays || []).indexOf(p.day) > -1;
    var open = trading && mins >= S.withdrawStart * 60 && mins < S.withdrawEnd * 60;
    var reason = open ? 'Withdrawal window is open until ' + hourLabel(S.withdrawEnd) + ' ' + S.tzLabel + '.'
      : !trading ? 'Weekend — trading is off and withdrawals are unavailable. Window reopens Monday ' + hourLabel(S.withdrawStart) + ' ' + S.tzLabel + '.'
      : mins < S.withdrawStart * 60 ? 'Withdrawal window opens today at ' + hourLabel(S.withdrawStart) + ' ' + S.tzLabel + '.'
      : 'Today’s withdrawal window closed at ' + hourLabel(S.withdrawEnd) + ' ' + S.tzLabel + '. Next window: next trading day ' + hourLabel(S.withdrawStart) + '.';
    return { trading: trading, open: open, reason: reason, window: hourLabel(S.withdrawStart) + ' – ' + hourLabel(S.withdrawEnd) + ' ' + S.tzLabel };
  }

  /* ---------------- earning cap ---------------- */
  function investedTotal(userId) { var u = byId(userId); return u ? round2(u.invested || 0) : 0; }
  function incomeTotal(userId) {
    return round2(db.tx.reduce(function (s, t) { return t.userId === userId && INCOME_TYPES.indexOf(t.type) > -1 ? s + t.amount : s; }, 0));
  }
  function activeDirects(userId) { return directs(userId).filter(isActive).length; }
  // level-1 business = what the member's ACTIVE direct referrals have invested
  function directInvested(userId) { return round2(directs(userId).filter(isActive).reduce(function (s, u) { return s + (u.invested || 0); }, 0)); }
  // "working" (5X cap): level-1 business >= capBusinessMultiple (5) x own investment  [capMode 'business', the guide's rule]
  //                     or >= capDirectsRequired active directs                        [capMode 'directs']
  function isWorking(userId) {
    var S = db.settings;
    if (S.capMode === 'directs') return activeDirects(userId) >= (S.capDirectsRequired || 12);
    var inv = investedTotal(userId);
    return inv > 0 && directInvested(userId) >= round2(inv * (S.capBusinessMultiple || 5));
  }
  function capInfo(userId) {
    var S = db.settings, working = isWorking(userId), mult = working ? S.capWorking : S.capNonWorking;
    var inv = investedTotal(userId), used = incomeTotal(userId), cap = mult > 0 ? round2(mult * inv) : 0;
    return { cap: cap, used: used, left: cap ? Math.max(0, round2(cap - used)) : 0, pct: cap ? Math.min(100, round2(used / cap * 100)) : 0, mult: mult, working: working, unlimited: !(mult > 0),
      mode: S.capMode === 'directs' ? 'directs' : 'business',
      directs: activeDirects(userId), needed: S.capDirectsRequired || 12,                                   // 'needed' = directs that unlock all 12 levels
      directBusiness: directInvested(userId), businessNeeded: round2(inv * (S.capBusinessMultiple || 5)) };
  }

  /* ---------------- tree / team (on the member's downline snapshot) ---------------- */
  function countLeg(id, leg) {
    var u = byId(id);
    var child = u && u[leg + 'Id'] ? byId(u[leg + 'Id']) : null;
    if (!child) return 0;
    return 1 + countLeg(child.id, 'left') + countLeg(child.id, 'right');
  }
  function walk(id, fn) {
    var layer = directs(id), depth = 0;
    while (layer.length) {
      var next = [];
      layer.forEach(function (u) { fn(u, depth); next = next.concat(directs(u.id)); });
      layer = next; depth++;
    }
  }
  function generations(id, max) {
    var out = [];
    walk(id, function (u, d) { if (d < (max || 12)) (out[d] = out[d] || []).push(u); });
    return out;
  }
  function teamSize(id) { var n = 0; walk(id, function () { n++; }); return n; }
  function activeTeam(id) { var n = 0; walk(id, function (u) { if (isActive(u)) n++; }); return n; }
  function teamBusiness(id) { var s = 0; walk(id, function (u) { s += u.invested || 0; }); return round2(s); }
  function levelStats(id, from, to, max) {
    return generations(id, max || 12).map(function (layer, i) {
      var list = layer.filter(function (u) { return (!from || u.joined >= from) && (!to || u.joined <= to); });
      return {
        level: i + 1, pct: (db.settings.levelPct || [])[i] || 0, total: list.length,
        unlocked: db.settings.levelUnlockPerLevel === false || activeDirects(id) >= i + 1,
        paid: list.filter(function (u) { return u.invested > 0; }).length,
        business: round2(list.reduce(function (s, u) { return s + (u.invested || 0); }, 0)), users: list
      };
    });
  }
  function directBusiness(id, sinceTs) {
    var ids = directs(id).map(function (u) { return u.id; });
    return round2(db.orders.reduce(function (s, o) { return ids.indexOf(o.userId) > -1 && (!sinceTs || o.date >= sinceTs) ? s + o.amount : s; }, 0));
  }
  function positions() { return db.extras.positions || { of: 0, team: { pos: 0, value: 0 }, business: { pos: 0, value: 0 } }; }

  /* ---------------- ledgers / reports ---------------- */
  function txOf(userId) { return mine(db.tx, userId).sort(desc); }
  function incomeOf(userId, type) { return txOf(userId).filter(function (t) { return t.type === type; }); }
  function statementOf(userId) {
    var bal = 0;
    return mine(db.tx, userId).sort(function (a, b) { return a.date - b.date || a.id - b.id; }).map(function (t) {
      bal = round2(bal + t.amount);
      return { id: t.id, date: t.date, type: t.type, note: t.note, amount: t.amount, balance: bal };
    }).reverse();
  }
  function totalsByType(userId) {
    var t = { total: 0, withdrawn: 0 };
    INCOME_TYPES.forEach(function (k) { t[k] = 0; });
    db.tx.forEach(function (x) {
      if (x.userId !== userId) return;
      if (INCOME_TYPES.indexOf(x.type) > -1) { t[x.type] += x.amount; t.total += x.amount; }
      if (x.type === 'withdrawal') t.withdrawn += -x.amount;
    });
    Object.keys(t).forEach(function (k) { t[k] = round2(t[k]); });
    return t;
  }
  function seriesByDay(list, pick, days) {
    var out = [], start = new Date(); start.setHours(0, 0, 0, 0);
    for (var i = days - 1; i >= 0; i--) {
      var from = start.getTime() - i * 86400000, to = from + 86400000, v = 0;
      list.forEach(function (x) { if (x.date >= from && x.date < to) v += pick(x); });
      out.push({ label: new Date(from).toLocaleDateString(undefined, { day: 'numeric', month: 'short' }), value: round2(v) });
    }
    return out;
  }
  function earningsByDay(userId, days) {
    return seriesByDay(db.tx.filter(function (t) { return t.userId === userId && INCOME_TYPES.indexOf(t.type) > -1; }), function (t) { return t.amount; }, days || 7);
  }
  function signupsByDay(days) { return seriesByDay(members().map(function (m) { return { date: m.joined }; }), function () { return 1; }, days || 7); }
  var ofUser = function (key) { return function (userId) { return mine(db[key], userId).sort(desc); }; };
  function transfersOf(userId) { return db.transfers.filter(function (x) { return x.fromId === userId || x.toId === userId; }).sort(desc); }

  /* ---------------- writes (Promises) ---------------- */
  function login(email, password) {
    return req('POST', '/login', { email: email, password: password }).then(function (r) {
      if (!r.ok) return r;
      if (r.otp_required) return { ok: false, otpRequired: true, sentTo: r.sentTo, error: '' };   // admins: a code was e-mailed
      setToken(r.token);
      return boot().then(function () { return { ok: true, user: r.user }; });
    });
  }
  function loginVerify(email, password, otp) {
    return req('POST', '/login/verify', { email: email, password: password, otp: otp }).then(function (r) {
      if (!r.ok) return r;
      setToken(r.token);
      return boot().then(function () { return { ok: true, user: r.user }; });
    });
  }
  function register(d) {
    return req('POST', '/register', {
      name: d.name, email: d.email, password: d.password, phone: d.phone || null, pin: d.pin || null, sponsorCode: d.sponsorCode,
      leg: d.leg || 'left', parentId: d.parentId ? Number(d.parentId) : null, amount: d.amount ? Number(d.amount) : null
    }).then(function (r) {
      if (!r.ok) return r;
      setToken(r.token);
      return boot().then(function () { return { ok: true, user: r.user }; });
    });
  }
  function logout() {
    var t = getToken();
    setToken(null);
    me = null;
    if (t) fetch(API + '/logout', { method: 'POST', headers: { Accept: 'application/json', Authorization: 'Bearer ' + t } }).catch(function () { /* ignore */ });
  }

  global.Store = {
    get db() { return db; },
    get mode() { return mode; },
    get error() { return lastError; },
    API: API, ready: ready, refresh: boot,
    isDemo: function () { return demo; },
    depositQrUrl: function () { var v = db.settings && db.settings.depositQr; return v ? API + '/public/deposit-qr?v=' + encodeURIComponent(v) : null; },
    demoLogins: function () { return demoLogins; },
    INCOME_TYPES: INCOME_TYPES, INCOME_LABELS: INCOME_LABELS,
    settings: function () { return db.settings; },
    sync: function () { /* ROI is credited on the server */ },
    marketStatus: marketStatus, isTradingDay: isTradingDay, hourLabel: hourLabel,
    byId: byId, byCode: byCode, isActive: isActive, isWorking: isWorking, activeDirects: activeDirects, members: members, currentUser: currentUser,
    hasPin: function () { return !!(me && me.hasPin); },
    directs: directs, generations: generations, teamSize: teamSize, activeTeam: activeTeam, countLeg: countLeg,
    levelStats: levelStats, directBusiness: directBusiness, teamBusiness: teamBusiness, positions: positions,
    investedTotal: investedTotal, incomeTotal: incomeTotal, capInfo: capInfo, directInvested: directInvested,
    txOf: txOf, incomeOf: incomeOf, statementOf: statementOf, totalsByType: totalsByType,
    earningsByDay: earningsByDay, signupsByDay: signupsByDay,
    ordersOf: ofUser('orders'), depositsOf: ofUser('deposits'), withdrawalsOf: ofUser('withdrawals'),
    walletTxOf: ofUser('wtx'), deductionsOf: ofUser('deductions'), ticketsOf: ofUser('tickets'), transfersOf: transfersOf,
    latestMembers: function (n) { return (db.extras.latestMembers || []).slice(0, n || 5); },
    topBuilders: function (n) { return (db.extras.topBuilders || []).slice(0, n || 5); },
    recentUpgrades: function (n) { return (db.extras.recentUpgrades || []).slice(0, n || 5); },
    publicStats: function () { return db.extras.stats || { members: 0, active: 0, orders: 0, paid: 0 }; },
    leaders: function () { return db.extras.leaders || []; },
    publicSummary: function () { return req('GET', '/public'); },

    // auth
    login: login, loginVerify: loginVerify, register: register, logout: logout,
    lookupSponsor: function (code) { return req('GET', '/public/sponsor/' + encodeURIComponent(String(code || '').trim())); },
    // member
    purchasePackage: function (userId, amount, source, pin, mode) { return write('POST', '/member/purchase', { amount: Number(amount), source: source, pin: pin, mode: mode || 'simple' }); },
    compound: function () { return db.compound || { mode: 'compound', balance: 0, lots: [], schedule: [] }; },
    setCompoundMode: function (mode, pin) { return write('POST', '/member/compound/mode', { mode: mode, pin: pin }); },
    requestDeposit: function (userId, amount, txnId) { return write('POST', '/member/deposits', { amount: Number(amount), txnId: txnId }); },
    transferToPurchase: function (userId, amount, pin) { return write('POST', '/member/transfer-self', { amount: Number(amount), pin: pin }); },
    // P2P is a 2-step flow: preview + e-mailed OTP first, then the transfer with the code
    lookupMember: function (code) { return req('GET', '/member/lookup/' + encodeURIComponent(String(code || '').trim())); },
    requestTransferOtp: function (toCode, amount, pin, source) { return req('POST', '/member/transfer/otp', { toCode: toCode, amount: Number(amount), source: source || 'purchase', pin: pin }); },
    transferToMember: function (fromId, toCode, amount, pin, otp, source) { return write('POST', '/member/transfer', { toCode: toCode, amount: Number(amount), source: source || 'purchase', pin: pin, otp: otp }); },
    requestWithdrawalOtp: function (amount, method, account, pin) { return req('POST', '/member/withdrawals/otp', { amount: Number(amount), method: method, account: account, pin: pin }); },
    requestWithdrawal: function (userId, amount, method, account, pin, otp) { return write('POST', '/member/withdrawals', { amount: Number(amount), method: method, account: account, pin: pin, otp: otp }); },
    updateProfile: function (id, p) { return write('PUT', '/member/profile', { name: p.name, phone: p.phone, country: p.country, city: p.city, usdtAddress: p.usdtAddress }); },
    // password change is a 2-step flow: an OTP is e-mailed first, then it is sent together with the old + new password
    forgotPassword: function (email) { return req('POST', '/password/forgot', { email: email }); },
    resetPassword: function (email, otp, password) { return req('POST', '/password/reset', { email: email, otp: otp, password: password }); },
    requestEmailOtp: function () { return req('POST', '/account/email/otp'); },
    verifyEmail: function (otp) { return write('POST', '/account/email/verify', { otp: otp }); },
    submitKyc: function (formData) { return req('POST', '/member/kyc', null, formData).then(function (r) { return r.ok ? boot().then(function () { return r; }) : r; }); },
    requestPasswordOtp: function () { return req('POST', '/account/password/otp'); },
    changePassword: function (id, oldPw, newPw, otp) { return write('PUT', '/account/password', { old: oldPw, new: newPw, otp: otp }); },
    setPin: function (id, newPin, oldPin) { return write('PUT', '/member/pin', { pin: String(newPin), old: oldPin ? String(oldPin) : null }); },
    openTicket: function (userId, subject, message) { return write('POST', '/member/tickets', { subject: subject, message: message }); },
    replyTicket: function (ticketId, by, text) { return write('POST', (by === 'admin' ? '/admin' : '/member') + '/tickets/' + ticketId + '/reply', { text: text }); },
    // admin
    closeTicket: function (id) { return write('POST', '/admin/tickets/' + id + '/close'); },
    processDeposit: function (id, status) { return write('POST', '/admin/deposits/' + id, { status: status }); },
    processWithdrawal: function (id, status, payoutTxid, note) { return write('POST', '/admin/withdrawals/' + id, { status: status, payoutTxid: payoutTxid || null, note: note || null }); },
    recheckDeposit: function (id) { return write('POST', '/admin/deposits/' + id + '/recheck'); },
    kycList: function () { return req('GET', '/admin/kyc'); },
    kycReview: function (id, status, note) { return req('POST', '/admin/kyc/' + id, { status: status, note: note || null }); },
    // KYC documents need the bearer token, so they are fetched as a blob and shown through an object URL
    kycFileUrl: function (id, which) {
      return fetch(API + '/admin/kyc/' + id + '/file/' + which, { headers: { Authorization: 'Bearer ' + getToken() } })
        .then(function (r) { if (!r.ok) throw new Error('not found'); return r.blob(); })
        .then(function (b) { return URL.createObjectURL(b); });
    },
    auditLog: function (params) {
      var q = Object.keys(params || {}).filter(function (k) { return params[k]; }).map(function (k) { return k + '=' + encodeURIComponent(params[k]); }).join('&');
      return req('GET', '/admin/audit' + (q ? '?' + q : ''));
    },
    setBlocked: function (id, blocked) { return write('POST', '/admin/members/' + id + '/block', { blocked: !!blocked }); },
    updateSettings: function (patch) { return write('PUT', '/admin/settings', patch); },
    addAnnouncement: function (text) { return write('POST', '/admin/announcements', { text: text }); },
    // custom deposit QR image (replaces the QR the site draws from the address)
    uploadDepositQr: function (file) { var f = new FormData(); f.append('qr', file); return req('POST', '/admin/deposit-qr', null, f).then(function (r) { return r.ok ? boot().then(function () { return r; }) : r; }); },
    removeDepositQr: function () { return write('DELETE', '/admin/deposit-qr'); },
    // member profile for the admin, and the suspicious-activity alerts
    memberOverview: function (id) { return req('GET', '/admin/members/' + id); },
    riskAlerts: function (params) {
      var q = Object.keys(params || {}).filter(function (k) { return params[k]; }).map(function (k) { return k + '=' + encodeURIComponent(params[k]); }).join('&');
      return req('GET', '/admin/alerts' + (q ? '?' + q : ''));
    },
    riskUpdate: function (id, status, note) { return req('POST', '/admin/alerts/' + id, { status: status, note: note || null }); },
    riskScan: function () { return req('POST', '/admin/alerts/scan'); },
    revokeSessions: function (id) { return req('POST', '/admin/members/' + id + '/revoke'); },
    updateMemberContact: function (id, patch) { return write('PUT', '/admin/members/' + id + '/contact', patch); },
    resetMemberPassword: function (id, password) { return req('POST', '/admin/members/' + id + '/password', { password: password || null }); },
    resetMemberPin: function (id, pin) { return req('POST', '/admin/members/' + id + '/pin', { pin: pin || null }); },
    updateMemberSponsor: function (id, sponsorCode) { return write('PUT', '/admin/members/' + id + '/sponsor', { sponsorCode: sponsorCode }); },
    reset: function () { return req('POST', '/admin/reset').then(function (r) { if (r.ok) logout(); return r; }); }
  };
})(window);
