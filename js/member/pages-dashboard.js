/* member/pages-dashboard.js — bento-grid overview */
(function () {
  'use strict';
  var IL = Store.INCOME_LABELS;
  var COLORS = { roi: '#12b76a', direct: '#1e6cff', retopup: '#f5b800', level: '#38bdf8' };
  var ICONS = { roi: 'trend', direct: 'users', retopup: 'swap', level: 'layers' };

  M.page('/dashboard', {
    title: 'Dashboard',
    render: function (el, ctx) {
      var me = ctx.me, S = ctx.S;
      var p = me.invested > 0, ms = Store.marketStatus();
      var t = Store.totalsByType(me.id), pos = Store.positions(me.id), cap = Store.capInfo(me.id);
      var orders = Store.ordersOf(me.id);
      var compounding = orders.filter(function (o) { return o.option === 'Compounding'; }).reduce(function (s, o) { return s + o.amount; }, 0);
      var credited = Store.walletTxOf(me.id).filter(function (w) { return w.amount > 0; }).reduce(function (s, w) { return s + w.amount; }, 0);
      var link = UI.referralLink(me.code, ctx.base);
      var today = new Date(); today.setHours(0, 0, 0, 0);
      var hour = new Date().getHours(), greet = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
      var ann = Store.db.announcements.slice().sort(function (a, b) { return b.date - a.date; });

      // reminder: withdrawals need a verified e-mail and approved KYC
      var todo = [];
      if (S.emailVerifyRequired && !me.emailVerified) todo.push('verify your e-mail');
      if (S.kycRequired && me.kyc !== 'approved') todo.push(me.kyc === 'pending' ? 'wait for your KYC review' : 'complete your KYC');
      var todoBar = todo.length ? '<div class="m-alert" style="display:block;margin-bottom:1rem"><b>Before you can withdraw:</b> ' + todo.join(' and ') + '. <a href="#/account/kyc"><b>Open KYC &amp; Verification →</b></a></div>' : '';

      el.innerHTML = todoBar +
        (ann.length ? '<div class="m-ticker">' + M.icon('megaphone') + '<div class="m-ticker-track"><div>' + ann.concat(ann).map(function (a) { return '<span>' + M.esc(a.text) + '</span>'; }).join('') + '</div></div></div>' : '') +
        '<div class="bento">' +

        // ---- hero
        '<section class="m-hero b-8">' +
          '<img class="m-hero-wm" src="' + ctx.base + 'images/logo.png" alt="">' +
          '<div class="m-hero-top"><div><span class="m-eyebrow">' + greet + '</span><h2>' + M.esc(me.name) + '</h2>' +
            '<div class="m-chips"><span class="m-chip2">' + M.icon('user') + M.esc(me.code) + '</span>' +
            '<span class="m-chip2 gold">' + M.icon('package') + (p ? M.money(me.invested) + ' invested' : 'No package yet') + '</span>' +
            '<span class="m-chip2">' + M.icon('calendar') + 'Joined ' + M.date(me.joined) + '</span></div></div>' +
            '<div class="m-pos"><div class="m-pos-row">' +
                '<div class="m-pos-i"><b>#' + pos.team.pos + '</b><span>Team size</span><small>' + pos.team.value + ' members</small></div>' +
                '<div class="m-pos-i"><b>#' + pos.business.pos + '</b><span>Team business</span><small>' + M.money(pos.business.value) + '</small></div>' +
              '</div></div></div>' +
          '<div class="m-ref"><label>Your referral link</label><div class="m-ref-row"><input readonly value="' + M.esc(link) + '" id="dRef">' +
            '<button class="mbtn gold" id="dCopy">' + M.icon('copy') + 'Copy</button>' +
            '<a class="mbtn glass" target="_blank" rel="noopener" href="https://wa.me/?text=' + encodeURIComponent('Join my team on New Era Trading: ' + link) + '">' + M.icon('share') + 'WhatsApp</a>' +
            '<a class="mbtn glass" target="_blank" rel="noopener" href="https://t.me/share/url?url=' + encodeURIComponent(link) + '">' + M.icon('share') + 'Telegram</a></div></div>' +
          '<div class="m-quick">' +
            [['/deposit/new', 'card', 'Deposit'], ['/wallet/purchase', 'package', 'Buy Package'], ['/withdraw/request', 'bank', 'Withdraw'], ['/wallet/transfer', 'swap', 'Transfer'], ['/network/team', 'network', 'My team']]
              .map(function (q) { return '<a href="#' + q[0] + '">' + M.icon(q[1]) + '<span>' + q[2] + '</span></a>'; }).join('') +
          '</div>' +
        '</section>' +

        // ---- wallets
        '<section class="m-wallets b-4">' +
          wallet('Income wallet', me.wallet, 'Total income ' + M.money(t.total), 'coins', '#/withdraw/request', 'Withdraw', 'w-gold') +
          wallet('Purchase wallet', me.purchaseWallet || 0, 'Total credited ' + M.money(credited), 'wallet', '#/deposit/new', 'Deposit', 'w-navy') +
          wallet('Invested', Store.investedTotal(me.id), 'Compounding ' + M.money(compounding), 'package', '#/wallet/purchase', 'Buy package', 'w-light') +
        '</section>' +

        // ---- charts
        M.card('Earnings — last 14 days', '<div class="m-chart" id="dArea"></div>', { cls: 'b-8', icon: 'trend', sub: 'All income types combined', actions: '<a class="mbtn line sm" href="#/income/statement">Statement</a>' }) +
        M.card('Income mix', '<div class="m-mix"><div id="dDonut"></div><ul class="m-legend" id="dLegend"></ul></div>', { cls: 'b-4', icon: 'percent' }) +

        // ---- trading week (Mon–Fri on, Sat–Sun off) + withdrawal window
        weekStrip(S, ms) +

        // ---- income tiles
        '<section class="m-kpis b-12">' +
          Store.INCOME_TYPES.map(function (k) { return M.stat({ icon: ICONS[k], label: IL[k], value: M.money(t[k]), href: '#/income/' + k, tone: k }); }).join('') +
          M.stat({ icon: 'coins', label: 'Total income', value: M.money(t.total), href: '#/income/statement', tone: 'binary' }) +
          M.stat({ icon: 'bank', label: 'Total withdrawal', value: M.money(t.withdrawn), href: '#/withdraw/history', tone: 'navy' }) +
          M.stat({ icon: 'users', label: 'Total direct team', value: Store.directs(me.id).length, sub: Store.directs(me.id).filter(Store.isActive).length + ' active', href: '#/network/team', tone: 'sky' }) +
          M.stat({ icon: 'percent', label: 'Daily ROI (trading days)', value: M.money(me.invested * S.roiPct / 100), sub: S.roiPct + '% of ' + M.money(me.invested), href: '#/income/roi', tone: 'roi' }) +
        '</section>' +

        // ---- cap / team / running orders
        M.card('Earning limit · ' + (cap.working ? 'Working ' + S.capWorking + 'X' : 'Non-working ' + S.capNonWorking + 'X'), '<div class="m-cap">' + M.ring(cap.pct, cap.mult ? cap.mult + 'X cap' : 'No cap') +
          '<ul class="m-kv"><li><span>Cap</span><b>' + (cap.cap ? M.money(cap.cap) : '—') + '</b></li><li><span>Earned</span><b>' + M.money(cap.used) + '</b></li><li><span>Remaining</span><b class="pos">' + (cap.cap ? M.money(cap.left) : '—') + '</b></li></ul></div>' +
          '<p class="m-note">' + (cap.mode === 'directs'
            ? (cap.working ? 'You have ' + cap.directs + ' active direct referrals, so you can earn up to ' + S.capWorking + 'X your invested amount.'
              : 'Without ' + cap.needed + ' active direct referrals you can withdraw up to ' + S.capNonWorking + 'X of what you invested. You have ' + cap.directs + ' of ' + cap.needed + ' — refer ' + (cap.needed - cap.directs) + ' more to unlock ' + S.capWorking + 'X.')
            : (cap.working ? 'Your level-1 business is ' + M.money(cap.directBusiness) + ' — at least ' + (S.capBusinessMultiple || 5) + 'X your investment — so you can earn up to ' + S.capWorking + 'X.'
              : 'You can earn up to ' + S.capNonWorking + 'X of what you invested. Your level-1 business is ' + M.money(cap.directBusiness) + '; reach ' + M.money(cap.businessNeeded) + ' (' + (S.capBusinessMultiple || 5) + 'X your investment) to unlock ' + S.capWorking + 'X.')) + ' Retopping up raises both your cap and the business target.</p>', { cls: 'b-4', icon: 'gauge' }) +
        M.card('Team & business', '<ul class="m-kv lg">' +
          '<li><span>' + M.icon('users') + 'Total direct team</span><b>' + Store.directs(me.id).length + '</b></li>' +
          '<li><span>' + M.icon('network') + 'Total team (active)</span><b>' + Store.teamSize(me.id) + ' <small>(' + Store.activeTeam(me.id) + ')</small></b></li>' +
          '<li><span>' + M.icon('clock') + 'Today direct business</span><b>' + M.money(Store.directBusiness(me.id, today.getTime())) + '</b></li>' +
          '<li><span>' + M.icon('package') + 'Total direct business</span><b>' + M.money(Store.directBusiness(me.id)) + '</b></li>' +
          '<li><span>' + M.icon('layers') + 'Levels unlocked</span><b>' + Math.min(12, cap.directs) + ' / ' + S.levelPct.length + ' <small>(' + cap.directs + ' active directs)</small></b></li></ul>', { cls: 'b-4', icon: 'network', actions: '<a class="mbtn line sm" href="#/network/levels">Levels</a>' }) +
        M.card('My packages', orders.length ? '<ul class="m-orders">' + orders.slice(0, 4).map(function (o) {
            var ret = o.paidDays * o.pct, pct = Math.min(100, Math.round(ret / Math.max(1, cap.mult * 100) * 100));
            return '<li><div class="row1"><b>' + M.esc(o.ref) + ' · ' + M.money(o.amount) + '</b>' + M.badge(o.status === 'running' ? (cap.cap && !cap.left ? 'Cap reached' : 'Running') : o.status === 'closed' ? 'Closed' : 'Active', o.status === 'running' && (!cap.cap || cap.left) ? 'info' : 'muted') + '</div>' +
              '<div class="row2"><span>' + M.esc(o.kind) + ' · ' + M.esc(o.option) + ' · ' + M.date(o.date) + '</span><span>' + o.paidDays + ' trading days · ' + ret + '% ROI</span></div>' +
              '<div class="m-bar sm"><i style="width:' + pct + '%"></i></div></li>';
          }).join('') + '</ul>' : M.empty('No package yet', 'Join from ' + S.currency + S.minJoin + ' to start earning ' + S.roiPct + '% per trading day.', '<a class="mbtn gold sm" href="#/wallet/purchase">Buy Package</a>'),
          { cls: 'b-4', icon: 'package', actions: '<a class="mbtn line sm" href="#/wallet/purchases">History</a>' }) +

        // ---- announcements / news
        M.card('Latest announcements', ann.length ? '<ul class="m-feed">' + ann.slice(0, 4).map(function (a) {
            return '<li><span class="mi">' + M.icon('megaphone') + '</span><div><p>' + M.esc(a.text) + '</p><small>' + M.dt(a.date) + '</small></div></li>';
          }).join('') + '</ul>' : M.empty('No announcements'), { cls: 'b-6', icon: 'megaphone' }) +
        M.card('News & achievers', '<div class="m-tabs" id="dTabs"><button class="on" data-t="new">New joiners</button><button data-t="top">Top builders</button><button data-t="upg">Recent activations</button></div><ul class="m-people" id="dPeople"></ul>', { cls: 'b-6', icon: 'trophy' }) +

        // ---- recent transactions
        M.card('Recent transactions', '<div id="dRecent"></div>', { cls: 'b-12', icon: 'list', actions: '<a class="mbtn line sm" href="#/income/statement">View all</a>' }) +
        '</div>';

      // wire up
      document.getElementById('dCopy').onclick = function () { M.copy(link); };
      M.area(document.getElementById('dArea'), Store.earningsByDay(me.id, 14), { prefix: S.currency, label: 'Earnings last 14 days' });
      var parts = Store.INCOME_TYPES.map(function (k) { return { label: IL[k], value: t[k], color: COLORS[k] }; });
      M.donut(document.getElementById('dDonut'), parts, { label: 'Total', value: M.money(t.total) });
      document.getElementById('dLegend').innerHTML = parts.filter(function (x) { return x.value > 0; }).map(function (x) {
        return '<li><i style="background:' + x.color + '"></i><span>' + M.esc(x.label) + '</span><b>' + (t.total ? Math.round(x.value / t.total * 100) : 0) + '%</b></li>';
      }).join('') || '<li class="m-muted">No income yet</li>';

      var people = {
        new: Store.latestMembers(5).map(function (u) { return [u, 'Joined ' + M.date(u.joined), u.invested > 0 ? 'Active' : 'New']; }),
        top: Store.topBuilders(5).map(function (x, i) { return [x.user, x.team + ' in team · ' + x.directs + ' directs', '#' + (i + 1)]; }),
        upg: Store.recentUpgrades(5).map(function (o) { var u = o.user || Store.byId(o.userId) || { name: 'Member' }; return [u, o.kind + ' package · ' + M.date(o.date), o.kind]; })
      };
      function drawPeople(k) {
        document.getElementById('dPeople').innerHTML = people[k].map(function (r) {
          return '<li><span class="m-av">' + M.esc(UI.initials(r[0].name)) + '</span><div><b>' + M.esc(r[0].name) + '</b><small>' + M.esc(r[1]) + '</small></div>' + M.badge(r[2], 'info') + '</li>';
        }).join('');
      }
      document.getElementById('dTabs').onclick = function (e) {
        var b = e.target.closest('button'); if (!b) return;
        this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
        drawPeople(b.dataset.t);
      };
      drawPeople('new');

      M.table(document.getElementById('dRecent'), {
        rows: Store.statementOf(me.id).slice(0, 8), search: false, per: 10,
        columns: [
          { key: 'date', label: 'Date', render: function (r) { return M.dt(r.date); } },
          { key: 'note', label: 'Description' },
          { key: 'type', label: 'Type', render: function (r) { return M.badge(IL[r.type] || r.type.charAt(0).toUpperCase() + r.type.slice(1), r.amount < 0 ? 'warn' : 'ok'); } },
          { key: 'amount', label: 'Amount', align: 'r', render: function (r) { return M.amt(r.amount); } },
          { key: 'balance', label: 'Balance', align: 'r', render: function (r) { return M.money(r.balance); } }
        ]
      });
    }
  });

  // Mon–Sun status strip — trading days from settings, today highlighted, live withdrawal window state
  function weekStrip(S, ms) {
    var names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], order = [1, 2, 3, 4, 5, 6, 0];
    var today = new Date(new Date().toLocaleString('en-US', { timeZone: S.timezone })).getDay();
    return '<section class="m-week b-12"><div class="m-week-days">' + order.map(function (d) {
      var on = S.tradingDays.indexOf(d) > -1;
      return '<div class="m-day ' + (on ? 'on' : 'off') + (d === today ? ' today' : '') + '"><b>' + names[d] + '</b>' + M.icon(on ? 'check' : 'x') + '<small>' + (on ? 'Trading' : 'Off') + '</small></div>';
    }).join('') + '</div><div class="m-week-info"><span class="m-dotlive ' + (ms.open ? 'open' : 'shut') + '"></span><div><b>Withdrawal window ' + (ms.open ? 'open' : 'closed') + '</b><small>' + M.esc(ms.reason) + '</small></div>' +
      '<div class="m-week-facts"><span>' + M.icon('clock') + M.esc(ms.window) + '</span><span>' + M.icon('check') + 'Receipt within ' + S.receiptHours + ' hrs</span><span>' + M.icon('coins') + 'From ' + S.currency + S.minWithdraw + ', multiples of ' + S.currency + S.withdrawMultiple + '</span></div></div></section>';
  }

  function wallet(label, value, sub, icon, href, action, cls) {
    return '<div class="m-wallet ' + cls + '"><span class="m-wallet-ic">' + M.icon(icon) + '</span><div><small>' + label + '</small><b>' + M.money(value) + '</b><span>' + sub + '</span></div><a class="mbtn sm" href="' + href + '">' + action + '</a></div>';
  }
})();
