/* member/pages-money.js — Income reports (ROI · direct · retopup · level + statement) · Withdraw / transfer / deductions */
(function () {
  'use strict';
  var IL = Store.INCOME_LABELS;
  var S0 = Store.settings();
  var NOTE = {
    roi: S0.roiPct + '% of each of your packages, credited every trading day (Monday–Friday). No ROI on weekends.',
    direct: S0.directPct + '% of the joining package of every member you sponsor.',
    retopup: S0.retopupPct + '% of every retopup (new package) bought by members you sponsor.',
    level: 'Paid on every investment made by your team, 12 levels deep: level 1 pays 2%, level 2 pays 1%, levels 3–12 pay 0.2% each. Level k unlocks with k active direct referrals (12 directs = all 12 levels).'
  };

  /* ---------- one page per income type ---------- */
  Store.INCOME_TYPES.forEach(function (type) {
    M.page('/income/' + type, {
      title: IL[type], sub: NOTE[type],
      render: function (el, ctx) {
        var rows = Store.incomeOf(ctx.me.id, type);
        var month = new Date(); month.setDate(1); month.setHours(0, 0, 0, 0);
        var week = Date.now() - 7 * 86400000;
        var sum = function (since) { return rows.reduce(function (s, r) { return r.date >= since ? s + r.amount : s; }, 0); };
        el.innerHTML = '<div class="m-grid4">' +
          M.stat({ icon: 'coins', label: 'Total ' + IL[type].toLowerCase(), value: M.money(sum(0)), tone: type }) +
          M.stat({ icon: 'calendar', label: 'This month', value: M.money(sum(month.getTime())), tone: 'sky' }) +
          M.stat({ icon: 'clock', label: 'Last 7 days', value: M.money(sum(week)), tone: 'roi' }) +
          M.stat({ icon: 'list', label: 'Records', value: rows.length, tone: 'navy' }) +
          '</div>' + M.card(IL[type] + ' history', '<div id="inT"></div>', { icon: 'file', sub: NOTE[type] });
        M.table(document.getElementById('inT'), {
          rows: rows, dateKey: 'date', sumKey: 'amount', sumLabel: 'Total amount', exportName: 'income-' + type,
          emptyTitle: 'No ' + IL[type].toLowerCase() + ' yet', empty: NOTE[type],
          columns: [
            { key: 'date', label: 'Bonus date', render: function (r) { return M.dt(r.date); }, csv: function (r) { return M.dt(r.date); } },
            { key: 'note', label: 'Transaction description' },
            { key: 'from', label: 'From', render: function (r) { var u = r.fromId && Store.byId(r.fromId); return u ? M.esc(u.name) + ' <small class="m-muted">' + M.esc(u.code) + '</small>' : '—'; }, csv: function (r) { var u = r.fromId && Store.byId(r.fromId); return u ? u.code : ''; } },
            { key: 'status', label: 'Status', render: function () { return M.badge('Credited'); }, csv: function () { return 'Credited'; } },
            { key: 'amount', label: 'Amount', align: 'r', render: function (r) { return M.amt(r.amount); } }
          ]
        });
      }
    });
  });

  M.page('/income/statement', {
    title: 'All Income Statement', sub: 'Every credit and debit on your income wallet, with running balance.',
    render: function (el, ctx) {
      var rows = Store.statementOf(ctx.me.id), t = Store.totalsByType(ctx.me.id);
      var debits = rows.filter(function (r) { return r.amount < 0; }).reduce(function (s, r) { return s - r.amount; }, 0);
      el.innerHTML = '<div class="m-grid4">' +
        M.stat({ icon: 'up', label: 'Total credited', value: M.money(t.total), tone: 'roi' }) +
        M.stat({ icon: 'down', label: 'Total debited', value: M.money(debits), tone: 'signup' }) +
        M.stat({ icon: 'wallet', label: 'Current balance', value: M.money(ctx.me.wallet), tone: 'binary' }) +
        M.stat({ icon: 'list', label: 'Transactions', value: rows.length, tone: 'navy' }) +
        '</div>' + M.card('Income wallet statement', '<div id="stT"></div>', { icon: 'file' });
      M.table(document.getElementById('stT'), {
        rows: rows, dateKey: 'date', exportName: 'income-statement',
        columns: [
          { key: 'date', label: 'Date', render: function (r) { return M.dt(r.date); }, csv: function (r) { return M.dt(r.date); } },
          { key: 'note', label: 'Transaction details' },
          { key: 'type', label: 'Type', render: function (r) { return M.badge(IL[r.type] || r.type.charAt(0).toUpperCase() + r.type.slice(1), r.amount < 0 ? 'warn' : 'ok'); } },
          { key: 'cr', label: 'Cr / Dr', render: function (r) { return r.amount < 0 ? '<b class="neg">Dr</b>' : '<b class="pos">Cr</b>'; }, csv: function (r) { return r.amount < 0 ? 'Dr' : 'Cr'; } },
          { key: 'amount', label: 'Amount', align: 'r', render: function (r) { return M.amt(r.amount); } },
          { key: 'balance', label: 'Balance', align: 'r', render: function (r) { return M.money(r.balance); } }
        ]
      });
    }
  });

  /* ---------- Withdraw ---------- */
  M.page('/withdraw/request', {
    title: 'Income Withdrawal',
    render: function (el, ctx) {
      var me = ctx.me, S = ctx.S, ms = Store.marketStatus();
      el.innerHTML = '<div class="m-window ' + (ms.open ? 'open' : 'shut') + '"><span class="m-dotlive ' + (ms.open ? 'open' : 'shut') + '"></span><div><b>Withdrawal window ' + (ms.open ? 'open now' : 'closed') + '</b><small>' + M.esc(ms.reason) + '</small></div><span class="m-window-t">' + M.icon('clock') + M.esc(ms.window) + ' · Mon–Fri</span></div>' +
        '<div class="m-split">' +
        M.card('Request a payout', '<div class="m-big-bal"><small>Available in income wallet</small><b>' + M.money(me.wallet) + '</b></div>' +
          '<div class="m-alert" hidden id="wrErr"></div><form id="wrForm" class="m-form">' +
          M.field('Request type', '<select name="method"><option>USDT BEP20</option><option>Bank transfer</option><option>UPI</option></select>') +
          M.field('Payout address / account', '<input name="account" required value="' + M.esc(me.usdtAddress || '') + '" placeholder="Wallet address or account details">', me.usdtAddress ? 'Prefilled from your profile.' : 'Tip: save your USDT address in Update Profile.') +
          M.field('Withdrawal amount', '<input name="amount" type="number" min="' + S.minWithdraw + '" step="' + (S.withdrawMultiple || 1) + '" required placeholder="' + S.currency + S.minWithdraw + ', ' + S.currency + (S.minWithdraw * 2) + ', ' + S.currency + (S.minWithdraw * 3) + '…">', 'Minimum ' + S.currency + S.minWithdraw + ', in multiples of ' + S.currency + S.withdrawMultiple + '.') +
          '<div class="m-amts">' + [5, 10, 15, 20, 25, 50, 100].map(function (q) { return '<button type="button" class="mbtn line sm" data-amt="' + q + '"' + (q > me.wallet ? ' disabled' : '') + '>' + S.currency + q + '</button>'; }).join('') + '</div>' +
          '<ul class="m-kv sum" id="wrSum"></ul>' +
          '<button class="mbtn gold block" type="submit"' + (ms.open ? '' : ' disabled') + '>' + M.icon('bank') + (ms.open ? 'Continue' : 'Opens ' + S.tzLabel + ' ' + Store.hourLabel(S.withdrawStart) + ' Mon–Fri') + '</button></form>', { icon: 'bank' }) +
        M.card('Withdrawal rules', '<ul class="m-rules">' +
          '<li>' + M.icon('coins') + '<div><b>Amount</b><span>Minimum ' + M.money(S.minWithdraw) + ', in multiples of ' + M.money(S.withdrawMultiple) + ' (5, 10, 15, 20…)</span></div></li>' +
          '<li>' + M.icon('clock') + '<div><b>Window</b><span>' + M.esc(ms.window) + ', Monday to Friday</span></div></li>' +
          '<li>' + M.icon('check') + '<div><b>Receipt target</b><span>Within ' + S.receiptHours + ' hours of your request</span></div></li>' +
          '<li>' + M.icon('calendar') + '<div><b>Weekends</b><span>Saturday and Sunday are non-trading days — withdrawals unavailable</span></div></li>' +
          (S.withdrawFeePct ? '<li>' + M.icon('percent') + '<div><b>Service charge</b><span>' + S.withdrawFeePct + '% of the requested amount</span></div></li>' : '') +
          '<li>' + M.icon('shield') + '<div><b>Security</b><span>Every payout needs your transaction PIN and a one-time code e-mailed to you</span></div></li></ul>', { icon: 'shield' }) +
        '</div>';
      var f = document.getElementById('wrForm'), sum = document.getElementById('wrSum');
      function calc() {
        var a = Number(f.amount.value) || 0, fee = Math.round(a * S.withdrawFeePct) / 100;
        var okMult = !S.withdrawMultiple || Math.round(a * 100) % Math.round(S.withdrawMultiple * 100) === 0;
        sum.innerHTML = '<li><span>Amount</span><b>' + M.money(a) + (a && !okMult ? ' <small class="neg">not a multiple of ' + S.currency + S.withdrawMultiple + '</small>' : '') + '</b></li>' +
          (S.withdrawFeePct ? '<li><span>Service charge (' + S.withdrawFeePct + '%)</span><b class="neg">−' + M.money(fee) + '</b></li>' : '') +
          '<li class="tot"><span>You receive</span><b>' + M.money(a - fee) + '</b></li><li><span>Expected by</span><b>' + M.dt(Date.now() + S.receiptHours * 3600000) + '</b></li>';
      }
      f.amount.oninput = calc; calc();
      el.querySelectorAll('[data-amt]').forEach(function (b) { b.onclick = function () { f.amount.value = b.dataset.amt; calc(); }; });
      f.method.onchange = function () { if (this.value === 'USDT BEP20') f.account.value = me.usdtAddress || ''; else f.account.value = ''; };
      f.onsubmit = function (e) {
        e.preventDefault();
        var d = { m: f.method.value, a: f.amount.value, acc: f.account.value }, al = document.getElementById('wrErr');
        M.alert(al, '');
        M.askPin(me.id).then(function (pin) {
          if (!pin) return null;
          return Store.requestWithdrawalOtp(d.a, d.m, d.acc, pin).then(function (o) {      // step 1: every rule is checked, a code is e-mailed
            if (!o.ok) return M.alert(al, o.error);
            return M.askOtp({
              sentTo: o.sentTo,
              summary: 'Withdraw <b>' + M.money(Number(d.a)) + '</b> to <b>' + M.esc(d.m) + '</b> <code>' + M.esc(M.short(d.acc, 16)) + '</code>.',
              resend: function () { return Store.requestWithdrawalOtp(d.a, d.m, d.acc, pin); }
            }).then(function (code) {
              if (!code) return null;
              return Store.requestWithdrawal(me.id, d.a, d.m, d.acc, pin, code).then(function (r) {   // step 2: only a matching code creates the request
                if (!r.ok) return M.alert(al, r.error);
                M.toast('Withdrawal request submitted');
                M.go('/withdraw/history');
              });
            });
          });
        });
      };
    }
  });

  M.page('/withdraw/history', {
    title: 'Withdrawal History',
    render: function (el, ctx) {
      el.innerHTML = M.card('Payout requests', '<div id="whT"></div>', { icon: 'history', actions: '<a class="mbtn gold sm" href="#/withdraw/request">' + M.icon('plus') + 'New request</a>' });
      M.table(document.getElementById('whT'), {
        rows: Store.withdrawalsOf(ctx.me.id), dateKey: 'date', sumKey: 'amount', sumLabel: 'Total requested', exportName: 'withdrawals', empty: 'Your payout requests will appear here.',
        columns: [
          { key: 'date', label: 'Request date', render: function (r) { return M.dt(r.date); }, csv: function (r) { return M.dt(r.date); } },
          { key: 'ref', label: 'Ref', render: function (r) { return '<code>' + M.esc(r.ref || r.id) + '</code>'; } },
          { key: 'method', label: 'Type' },
          { key: 'account', label: 'Account', render: function (r) { return '<code title="' + M.esc(r.account) + '">' + M.esc(M.short(r.account, 16)) + '</code>'; } },
          { key: 'amount', label: 'Amount', align: 'r', render: function (r) { return M.money(r.amount); } },
          { key: 'fee', label: 'Fee', align: 'r', render: function (r) { return M.money(r.fee); } },
          { key: 'net', label: 'Net payout', align: 'r', render: function (r) { return '<b>' + M.money(r.net) + '</b>'; } },
          { key: 'status', label: 'Status', render: function (r) { return M.badge(r.status); } },
          { key: 'payoutTxid', label: 'Payout hash', render: function (r) { return r.payoutTxid ? '<code title="' + M.esc(r.payoutTxid) + '">' + M.esc(M.short(r.payoutTxid, 14)) + '</code>' : (r.adminNote ? '<small class="m-muted">' + M.esc(r.adminNote) + '</small>' : '—'); } }
        ]
      });
    }
  });

  M.page('/withdraw/self', {
    title: 'Transfer in My Wallet',
    render: function (el, ctx) {
      var me = ctx.me;
      el.innerHTML = '<div class="m-split">' +
        M.card('Income wallet → purchase wallet', '<div class="m-flow"><div><small>Income wallet</small><b>' + M.money(me.wallet) + '</b></div><span>' + M.icon('right') + '</span><div><small>Purchase wallet</small><b>' + M.money(me.purchaseWallet || 0) + '</b></div></div>' +
          '<div class="m-alert" hidden id="wsErr"></div><form id="wsForm" class="m-form">' +
          M.field('Amount', '<input name="amount" type="number" min="1" step="0.01" required>', 'Use it to buy or upgrade packages, or send it to team members.') +
          '<button class="mbtn gold block" type="submit">' + M.icon('swap') + 'Transfer</button></form>', { icon: 'swap' }) +
        M.card('Why transfer?', '<ul class="m-rules"><li>' + M.icon('package') + '<div><b>Upgrade faster</b><span>Fund an upgrade from what you have earned.</span></div></li>' +
          '<li>' + M.icon('users') + '<div><b>Support your team</b><span>Send purchase-wallet funds to a member with Wallet Transfer.</span></div></li>' +
          '<li>' + M.icon('percent') + '<div><b>No fee</b><span>Transfers between your own wallets are free.</span></div></li></ul>', { icon: 'spark' }) + '</div>';
      document.getElementById('wsForm').onsubmit = function (e) {
        e.preventDefault();
        var a = this.amount.value;
        M.askPin(me.id).then(function (pin) {
          if (!pin) return null;
          return Store.transferToPurchase(me.id, a, pin).then(function (r) {
            if (!r.ok) return M.alert(document.getElementById('wsErr'), r.error);
            M.toast('Moved ' + M.money(a) + ' to your purchase wallet');
            M.go('/withdraw/transfers');
          });
        });
      };
    }
  });

  M.page('/withdraw/transfers', {
    title: 'Transfer History',
    render: function (el, ctx) {
      var me = ctx.me;
      var rows = Store.transfersOf(me.id).map(function (x) {
        var kind = x.kind === 'self' ? 'Self' : x.fromId === me.id ? 'Sent' : 'Received';
        var name = kind === 'Sent' ? x.toName : x.fromName, code = kind === 'Sent' ? x.toCode : x.fromCode;
        return { date: x.date, kind: kind, desc: x.kind === 'self' ? 'Income wallet → purchase wallet' : (kind === 'Sent' ? 'To ' : 'From ') + name + ' (' + code + ')', amount: kind === 'Sent' ? -x.amount : x.amount };
      });
      el.innerHTML = M.card('Wallet transfers', '<div id="thT"></div>', { icon: 'list' });
      M.table(document.getElementById('thT'), {
        rows: rows, dateKey: 'date', exportName: 'transfers', empty: 'Self transfers and member transfers appear here.',
        columns: [
          { key: 'date', label: 'Date', render: function (r) { return M.dt(r.date); }, csv: function (r) { return M.dt(r.date); } },
          { key: 'kind', label: 'Type', render: function (r) { return M.badge(r.kind); } },
          { key: 'desc', label: 'Description' },
          { key: 'amount', label: 'Amount', align: 'r', render: function (r) { return M.amt(r.amount); } }
        ]
      });
    }
  });

  M.page('/withdraw/deductions', {
    title: 'Deduction History',
    render: function (el, ctx) {
      el.innerHTML = M.card('Service charges', '<div id="ddT"></div>', { icon: 'percent', sub: 'Fees deducted from payouts. Fees on rejected payouts are reversed.' });
      M.table(document.getElementById('ddT'), {
        rows: Store.deductionsOf(ctx.me.id).filter(function (d) { return d.type !== 'referral_forfeit'; }), dateKey: 'date', sumKey: 'amount', sumLabel: 'Total deducted', exportName: 'deductions', empty: 'No deductions yet.',
        columns: [
          { key: 'date', label: 'Date', render: function (r) { return M.dt(r.date); }, csv: function (r) { return M.dt(r.date); } },
          { key: 'note', label: 'Transaction description' },
          { key: 'status', label: 'Status', render: function (r) { return M.badge(r.status); } },
          { key: 'amount', label: 'Amount', align: 'r', render: function (r) { return M.money(r.amount); } }
        ]
      });
    }
  });
})();
