/* member/pages-wallet.js — Deposit · Purchase / Retopup · Purchased history · Wallet statement · Wallet transfer */
(function () {
  'use strict';

  function balances(me) {
    return '<div class="m-balbar">' +
      '<div><span class="mi">' + M.icon('wallet') + '</span><small>Purchase wallet</small><b>' + M.money(me.purchaseWallet || 0) + '</b></div>' +
      '<div><span class="mi gold">' + M.icon('coins') + '</span><small>Income wallet</small><b>' + M.money(me.wallet) + '</b></div>' +
      '<div><span class="mi">' + M.icon('package') + '</span><small>Total invested</small><b>' + M.money(me.invested || 0) + '</b></div></div>';
  }

  /* ---------- Deposit USDT ---------- */
  M.page('/deposit/new', {
    title: 'Deposit USDT',
    render: function (el, ctx) {
      var S = ctx.S, me = ctx.me;
      el.innerHTML = balances(me) + '<div class="m-split">' +
        M.card('1 · Send USDT to this address', '<div class="m-pay">' +
          '<figure class="m-qr"><div class="m-qr-frame" id="dpQr"></div><figcaption>' + M.icon('eye') + 'Scan to pay</figcaption></figure>' +
          '<div class="m-addr"><small>Network</small><b class="m-net">' + M.esc(S.depositNetwork) + '</b>' +
          '<small>Deposit address</small><div class="m-ref-row"><input readonly value="' + M.esc(S.depositAddress) + '" id="dpAddr"></div>' +
          '<div class="m-pay-btns"><button class="mbtn navy sm" id="dpCopy">' + M.icon('copy') + 'Copy address</button><button class="mbtn line sm" id="dpDl">' + M.icon('download') + 'Download QR</button></div></div>' +
          '</div>' +
          '<ol class="m-steps"><li>Send <b>only USDT on ' + M.esc(S.depositNetwork) + '</b> to the address above.</li><li>Copy the transaction hash from your wallet or BscScan.</li>' +
          '<li>Submit the form. Your purchase wallet is credited once the deposit is verified.</li></ol>' +
          (/DEMO/.test(S.depositAddress) ? '<p class="m-note warn">' + M.icon('shield') + 'Demo address — the admin sets the real one in Plan Settings. Never send funds to a demo address.</p>' : ''), { icon: 'card' }) +
        M.card('2 · Submit your deposit', '<div class="m-alert" hidden id="dpErr"></div><form id="dpForm" class="m-form">' +
          M.field('Amount (USDT)', '<input name="amount" type="number" min="' + S.minDeposit + '" step="0.01" required placeholder="Minimum ' + S.minDeposit + '">') +
          M.field('Transaction hash / ID', '<input name="txn" required placeholder="0x…" autocomplete="off">', 'Each hash can be submitted only once.') +
          '<button class="mbtn gold block" type="submit">' + M.icon('check') + 'Submit deposit</button></form>', { icon: 'file' }) +
        '</div>';
      document.getElementById('dpCopy').onclick = function () { M.copy(S.depositAddress); };
      // QR of the company deposit address (with the logo in the centre — ECC level H keeps it scannable)
      var custom = Store.depositQrUrl();   // an admin-uploaded QR image wins over the generated one
      if (custom) {
        var im = new Image(); im.alt = 'Deposit QR'; im.src = custom; im.style.cssText = 'width:100%;height:100%;object-fit:contain;display:block';
        im.onerror = function () { document.getElementById('dpQr').innerHTML = QR.svg(S.depositAddress, { ecl: 'H', logo: ctx.base + 'images/logo-128.png', fg: '#061230', quiet: 2 }); };
        document.getElementById('dpQr').appendChild(im);
      } else document.getElementById('dpQr').innerHTML = QR.svg(S.depositAddress, { ecl: 'H', logo: ctx.base + 'images/logo-128.png', fg: '#061230', quiet: 2 });
      document.getElementById('dpDl').onclick = function () {
        if (custom) {   // download the uploaded image itself
          fetch(custom).then(function (r) { return r.blob(); }).then(function (b) {
            var a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = 'new-era-trading-deposit-qr.' + (b.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
            document.body.appendChild(a); a.click(); a.remove();
          });
          return;
        }
        // PNG download drawn straight from the QR matrix (no external images, so it works offline too)
        var mat = QR.matrix(S.depositAddress, 'H'), n = mat.length, q = 4, sc = 12, dim = (n + q * 2) * sc;
        var cv = document.createElement('canvas'); cv.width = cv.height = dim;
        var g = cv.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, dim, dim); g.fillStyle = '#061230';
        for (var y = 0; y < n; y++) for (var x = 0; x < n; x++) if (mat[y][x]) g.fillRect((x + q) * sc, (y + q) * sc, sc, sc);
        var a = document.createElement('a'); a.href = cv.toDataURL('image/png'); a.download = 'new-era-trading-deposit-qr.png';
        document.body.appendChild(a); a.click(); a.remove();
      };
      document.getElementById('dpForm').onsubmit = function (e) {
        e.preventDefault();
        var btn = this.querySelector('button[type=submit]'); btn.disabled = true;
        Store.requestDeposit(me.id, this.amount.value, this.txn.value).then(function (r) {
          btn.disabled = false;
          if (!r.ok) return M.alert(document.getElementById('dpErr'), r.error);
          M.toast('Deposit submitted — pending verification');
          M.go('/deposit/history');
        });
      };
    }
  });

  M.page('/deposit/history', {
    title: 'Deposit History',
    render: function (el, ctx) {
      var rows = Store.depositsOf(ctx.me.id);
      el.innerHTML = M.card('USDT deposits', '<div id="dhT"></div>', { icon: 'history', actions: '<a class="mbtn gold sm" href="#/deposit/new">' + M.icon('plus') + 'New deposit</a>' });
      M.table(document.getElementById('dhT'), {
        rows: rows, dateKey: 'date', sumKey: 'amount', sumLabel: 'Total deposited', exportName: 'deposits', empty: 'Your deposits will appear here.',
        columns: [
          { key: 'date', label: 'Payment date', render: function (r) { return M.dt(r.date); }, csv: function (r) { return M.dt(r.date); } },
          { key: 'amount', label: 'Amount', align: 'r', render: function (r) { return M.money(r.amount); } },
          { key: 'network', label: 'Network' },
          { key: 'txnId', label: 'Txn ID', render: function (r) { return '<code title="' + M.esc(r.txnId) + '">' + M.esc(M.short(r.txnId, 18)) + '</code>'; } },
          { key: 'processedAt', label: 'Verified on', render: function (r) { return M.date(r.processedAt); }, csv: function (r) { return M.date(r.processedAt); } },
          { key: 'status', label: 'Status', render: function (r) { return M.badge(r.status); } }
        ]
      });
    }
  });

  /* ---------- Purchase package ---------- */
  /* ---------- Purchase / Retopup: amount-based package, minimum joining $5 ---------- */
  M.page('/wallet/purchase', {
    title: 'Purchase Wallet',
    render: function (el, ctx) {
      var me = ctx.me, S = ctx.S, retopup = me.invested > 0, sp = me.sponsorId ? Store.byId(me.sponsorId) : null;
      var quick = [5, 10, 25, 50, 100, 250, 500];
      el.innerHTML = balances(me) + '<div class="m-split wide-l">' +
        M.card('Buy a package', '<div class="m-alert" hidden id="buyErr"></div><form id="buyForm" class="m-form">' +
          '<div class="m-seg"><label><input type="radio" name="src" value="purchase" checked><span>' + M.icon('wallet') + '<b>Purchase wallet</b><small>' + M.money(me.purchaseWallet || 0) + ' · Investment</small></span></label>' +
          '<label><input type="radio" name="src" value="income"><span>' + M.icon('coins') + '<b>Income wallet</b><small>' + M.money(me.wallet) + ' · Compounding</small></span></label></div>' +
          '<label class="m-lbl" style="display:block;font-weight:700;margin:.2rem 0 .4rem">Interest type</label>' +
          '<div class="m-seg" style="grid-template-columns:1fr"><label><input type="radio" name="payout" value="compound" checked><span>' + M.icon('trend') + '<b>Compounding</b><small>' + S.roiPct + '% added daily, interest on interest. Pick anytime whether to keep compounding or get it paid to your income wallet from <a href="#/wallet/compounding">Compounding</a>.</small></span></label></div>' +
          M.field('Package amount (USD)', '<input name="amount" type="number" min="' + S.minJoin + '" step="' + S.joinMultiple + '" required placeholder="' + S.currency + S.minJoin + ', ' + S.currency + (S.minJoin * 2) + ', ' + S.currency + (S.minJoin * 3) + '…">', 'Minimum ' + S.currency + S.minJoin + ', then in multiples of ' + S.currency + S.joinMultiple + ' (5, 10, 15, 20, 25…).') +
          '<div class="m-amts">' + quick.map(function (q) { return '<button type="button" class="mbtn line sm" data-amt="' + q + '">' + S.currency + q + '</button>'; }).join('') + '</div>' +
          '<ul class="m-kv sum" id="buySum"></ul>' +
          '<button class="mbtn gold block" type="submit">' + M.icon('package') + 'Buy Package</button></form>', { icon: 'package' }) +
        M.card('How your package earns', '<ul class="m-rules">' +
          '<li>' + M.icon('trend') + '<div><b>ROI ' + S.roiPct + '% per trading day</b><span>Credited Monday to Friday for ' + (S.roiDays || 25) + ' trading days, then the package cycle expires. No ROI on Saturday and Sunday. <a href="#/tools/calculator">Try the calculator</a>.</span></div></li>' +
          '<li>' + M.icon('users') + '<div><b>Your sponsor earns ' + (retopup ? S.retopupPct + '% retopup income' : S.directPct + '% direct referral income') + '</b><span>' + (sp ? M.esc(sp.name) + ' (' + M.esc(sp.code) + ')' : 'Company') + ' receives it on the amount you pay.</span></div></li>' +
          '<li>' + M.icon('layers') + '<div><b>12-level income</b><span>Your upline earns ' + (S.levelPct[0] || 0) + '% (level 1), ' + (S.levelPct[1] || 0) + '% (level 2) and ' + (S.levelPct[2] || 0) + '% on each of levels 3–' + S.levelPct.length + ' of every amount you invest.</span></div></li>' +
          '<li>' + M.icon('gauge') + '<div><b>Earning cap ' + S.capNonWorking + 'X / ' + S.capWorking + 'X</b><span>Up to ' + S.capNonWorking + 'X of invested; ' + S.capWorking + 'X once your level-1 business reaches ' + (S.capBusinessMultiple || 5) + 'X your own investment.</span></div></li></ul>', { icon: 'spark' }) +
        '</div>';

      var f = document.getElementById('buyForm'), sum = document.getElementById('buySum');
      function calc() {
        var a = Number(f.amount.value) || 0, cap = Store.isWorking(me.id) ? S.capWorking : S.capNonWorking;
        var x = window.Calc && a > 0 ? Calc.scenarios(a, S) : null;
        sum.innerHTML = '<li><span>Type</span><b>' + (retopup ? 'Retopup' : 'Joining') + ' · Compounding</b></li>' +
          '<li><span>ROI per trading day (' + S.roiPct + '%)</span><b class="pos">+' + M.money(a * S.roiPct / 100) + ' <small>(added to balance)</small></b></li>' +
          (x ? '<li><span>After ' + x.cycle + ' days (compounded)</span><b class="pos">' + M.money(x.ciFinal) + '</b></li><li><span>After the cycle expires</span><b>' + M.money(x.ciAfterExpiry) + '</b></li>' : '') +
          '<li><span>New total invested</span><b>' + M.money((me.invested || 0) + a) + '</b></li>' +
          '<li class="tot"><span>Earning cap (' + cap + 'X)</span><b>' + M.money(((me.invested || 0) + a) * cap) + '</b></li>';
      }
      f.amount.oninput = calc; calc();
      el.querySelectorAll('[data-amt]').forEach(function (b) { b.onclick = function () { f.amount.value = b.dataset.amt; calc(); }; });
      f.onsubmit = function (e) {
        e.preventDefault();
        var amount = Number(f.amount.value), src = f.src.value, al = document.getElementById('buyErr');
        if (!amount || amount < S.minJoin) return M.alert(al, 'Minimum amount is ' + S.currency + S.minJoin + '.');
        if (Math.round(amount * 100) % Math.round(S.joinMultiple * 100) !== 0) return M.alert(al, 'Amount must be in multiples of ' + S.currency + S.joinMultiple + ' (5, 10, 15, 20…).');
        M.confirm('Confirm ' + (retopup ? 'retopup' : 'joining'), '<ul class="m-kv"><li><span>Amount</span><b>' + M.money(amount) + '</b></li>' +
          '<li><span>Pay from</span><b>' + (src === 'income' ? 'Income wallet (compounding)' : 'Purchase wallet') + '</b></li>' +
          '<li><span>ROI per trading day</span><b class="pos">+' + M.money(amount * S.roiPct / 100) + '</b></li></ul>', 'Continue').then(function (ok) {
          return ok ? M.askPin(me.id) : null;
        }).then(function (pin) {
          if (!pin) return null;
          return Store.purchasePackage(me.id, amount, src, pin, 'compound').then(function (r) {
            if (!r.ok) return M.alert(al, r.error);
            M.toast('Package purchased: ' + M.money(amount));
            M.go('/wallet/purchases');
          });
        });
      };
    }
  });

  /* ---------------- compounding balance (company spreadsheet) ---------------- */
  M.page('/wallet/compounding', {
    title: 'Compounding',
    render: function (el, ctx) {
      var S = ctx.S, C = Store.compound(), mode = C.mode === 'cash' ? 'cash' : 'compound', has = C.lots.length > 0 || C.schedule.length > 0;
      var cycle = S.roiDays || 25, packages = C.packages || [];
      var STAT = { running: ['Running', 'info'], matured: ['Ready to decide', 'ok'], closed: ['Withdrawn', 'muted'] };
      el.innerHTML = '<div class="m-split wide-l">' +
        M.card('Compounding balance',
          '<div class="m-big-bal"><small>Live balance earning ' + S.roiPct + '% per trading day</small><b>' + M.money(C.balance) + '</b></div>' +
          (has ? '<div class="m-alert ok" style="display:block">Today\'s income on this balance: <b>+' + M.money(C.balance * S.roiPct / 100) + '</b> — mode: <b>' + (mode === 'compound' ? 'keep compounding' : 'transfer to income wallet') + '</b> — ' +
              (mode === 'compound' ? 'it gets added to the balance, so tomorrow\'s income is calculated on the bigger total.' : 'it is paid to your income wallet, so the balance (and tomorrow\'s income) stays the same.') + '</div>' +
            '<div class="m-alert" hidden id="cmErr"></div>' +
            '<div class="m-amts" style="margin-top:.6rem"><button class="mbtn ' + (mode === 'compound' ? 'gold' : 'line') + '" data-mode="compound">' + M.icon('trend') + 'Keep compounding</button>' +
            '<button class="mbtn ' + (mode === 'cash' ? 'gold' : 'line') + '" data-mode="cash">' + M.icon('coins') + 'Transfer to income wallet</button></div>'
            : '<p class="m-muted">You have no compounding balance yet. When you buy a package, choose <b>Compounding</b> as the interest type.</p>' +
              '<a class="mbtn gold" href="#/wallet/purchase">' + M.icon('package') + 'Buy a compounding package</a>') +
          '<p class="m-muted" style="font-size:.82rem;margin-bottom:0">Each package earns ' + cycle + ' trading days, then it matures — at that point you choose to withdraw it or start a new ' + cycle +
            '-day cycle on it. You can also withdraw a package early, at whatever it has grown to so far (further growth for that cycle is then forfeited). No interest on Saturday and Sunday.</p>', { icon: 'trend' }) +
        M.card('Your compounding packages', packages.length ? '<ul class="m-kv" id="cmPkgs">' + packages.map(function (p) {
          var st = STAT[p.status] || ['—', 'muted'];
          var actions = '';
          if (p.status === 'running') actions = '<button class="mbtn line sm" data-wd="' + p.orderId + '">' + M.icon('coins') + 'Withdraw now</button>';
          else if (p.status === 'matured') actions = '<button class="mbtn gold sm" data-wd="' + p.orderId + '">' + M.icon('coins') + 'Withdraw</button>' +
            '<button class="mbtn line sm" data-rc="' + p.orderId + '">' + M.icon('trend') + 'Re-compound</button>';
          return '<li style="flex-wrap:wrap"><span><code>' + M.esc(p.ref) + '</code> <small class="m-muted">bought ' + M.date(p.date) + ' · ' + p.paidDays + '/' + p.days + ' days · ' + M.badge(st[0], st[1]) + '</small></span>' +
            '<b>' + M.money(p.value) + '</b>' + (actions ? '<div style="flex:0 0 100%;margin-top:.4rem;display:flex;gap:.4rem">' + actions + '</div>' : '') + '</li>';
        }).join('') + '</ul>' : '<p class="m-muted">Buy a compounding package to see it listed here.</p>', { icon: 'package' }) +
        M.card('Live amounts', C.lots.length ? '<ul class="m-kv">' + C.lots.map(function (l) {
          return '<li><span>' + (l.principal ? 'Purchased amount' : 'Interest of ' + M.esc(l.since)) + ' <small class="m-muted">· ' + l.daysLeft + ' day' + (l.daysLeft === 1 ? '' : 's') + ' left</small></span><b>' + M.money(l.amount) + '</b></li>';
        }).join('') + '</ul>' : '<p class="m-muted">Nothing is earning right now.</p>', { icon: 'layers' }) +
        '</div>' +
        M.card('Daily schedule', '<div id="cmT"></div>', { icon: 'list', sub: 'Opening + interest − matured = closing, newest first' });

      el.querySelectorAll('[data-mode]').forEach(function (b) {
        b.onclick = function () {
          if ((b.dataset.mode === 'cash') === (mode === 'cash')) return;
          var al = document.getElementById('cmErr');
          M.askPin(ctx.me.id).then(function (pin) {
            if (!pin) return null;
            return Store.setCompoundMode(b.dataset.mode, pin).then(function (r) {
              if (!r.ok) return M.alert(al, r.error);
              M.toast(b.dataset.mode === 'cash' ? 'The daily interest will now be paid to your income wallet' : 'The daily interest will now be added to your balance');
              ctx.refresh();
            });
          });
        };
      });
      el.querySelectorAll('[data-wd]').forEach(function (b) {
        b.onclick = function () {
          var orderId = b.dataset.wd, pkg = packages.filter(function (p) { return String(p.orderId) === orderId; })[0];
          var early = pkg && pkg.status === 'running';
          M.confirm('Withdraw this package', '<p>You will receive <b>' + M.money(pkg ? pkg.value : 0) + '</b> in your income wallet' +
            (early ? ' right now — this package stops compounding, so it will not earn the rest of its 25-day cycle.' : '.') + '</p>', 'Withdraw').then(function (ok) {
            return ok ? M.askPin(ctx.me.id) : null;
          }).then(function (pin) {
            if (!pin) return null;
            return Store.withdrawCompoundOrder(orderId, pin).then(function (r) {
              if (!r.ok) return M.toast(r.error, 'err');
              M.toast('Withdrawn ' + M.money(r.value) + ' to your income wallet');
              ctx.refresh();
            });
          });
        };
      });
      el.querySelectorAll('[data-rc]').forEach(function (b) {
        b.onclick = function () {
          var orderId = b.dataset.rc, pkg = packages.filter(function (p) { return String(p.orderId) === orderId; })[0];
          M.confirm('Re-compound this package', '<p>' + M.money(pkg ? pkg.value : 0) + ' will start a brand-new ' + cycle + '-day compounding cycle as the new principal.</p>', 'Re-compound').then(function (ok) {
            return ok ? M.askPin(ctx.me.id) : null;
          }).then(function (pin) {
            if (!pin) return null;
            return Store.recompoundOrder(orderId, pin).then(function (r) {
              if (!r.ok) return M.toast(r.error, 'err');
              M.toast('Re-compounding ' + M.money(r.principal) + ' for ' + cycle + ' more days');
              ctx.refresh();
            });
          });
        };
      });
      M.table(document.getElementById('cmT'), {
        rows: C.schedule, search: false, per: 15, empty: 'The schedule appears after the first trading day.',
        columns: [
          { key: 'day', label: 'Day', render: function (r) { return M.esc(r.day); } },
          { key: 'opening', label: 'Opening', align: 'r', render: function (r) { return M.money(r.opening); } },
          { key: 'interest', label: 'Interest', align: 'r', render: function (r) { return '<b class="pos">+' + M.money(r.interest) + '</b> <small class="m-muted">' + (r.mode === 'cash' ? 'paid' : 'added') + '</small>'; } },
          { key: 'expiring', label: 'Matured', align: 'r', render: function (r) { return r.expiring > 0 ? '<span>' + M.money(r.expiring) + '</span>' : '—'; } },
          { key: 'closing', label: 'Closing', align: 'r', render: function (r) { return M.money(r.closing); } }
        ]
      });
    }
  });

  M.page('/wallet/purchases', {
    title: 'Purchased History',
    render: function (el, ctx) {
      var cap = Store.capInfo(ctx.me.id);
      var rows = Store.ordersOf(ctx.me.id);
      el.innerHTML = M.card('Packages', '<div id="phT"></div>', { icon: 'file', actions: '<a class="mbtn gold sm" href="#/wallet/purchase">' + M.icon('plus') + 'Retopup</a>' });
      M.table(document.getElementById('phT'), {
        rows: rows, dateKey: 'date', sumKey: 'amount', sumLabel: 'Total invested', exportName: 'purchased-history', empty: 'Join from ' + ctx.S.currency + ctx.S.minJoin + ' to get started.',
        columns: [
          { key: 'ref', label: 'Ref no', render: function (r) { return '<code>' + M.esc(r.ref) + '</code>'; } },
          { key: 'date', label: 'Purchase date', render: function (r) { return M.date(r.date); }, csv: function (r) { return M.date(r.date); } },
          { key: 'kind', label: 'Type', render: function (r) { return M.badge(r.kind, r.kind === 'Joining' ? 'ok' : 'info'); } },
          { key: 'amount', label: 'Package amount', align: 'r', render: function (r) { return M.money(r.amount); } },
          { key: 'option', label: 'Inv option', render: function (r) { return M.badge(r.option, r.option === 'Compounding' ? 'info' : 'muted'); } },
          { key: 'pct', label: 'ROI / trading day', align: 'r', render: function (r) { return r.pct + '%'; } },
          { key: 'paidDays', label: 'Trading days paid', align: 'r' },
          { key: 'status', label: 'Status', render: function (r) {
            if (r.status !== 'running') return M.badge(r.status === 'closed' ? 'Closed' : 'Active');
            return cap.cap && !cap.left ? M.badge('Cap reached', 'muted') : M.badge('Running', 'info');
          }, csv: function (r) { return r.status; } }
        ]
      });
    }
  });

  M.page('/wallet/statement', {
    title: 'Wallet Statement',
    render: function (el, ctx) {
      var rows = Store.walletTxOf(ctx.me.id);
      var LBL = { deposit: 'Deposit', purchase: 'Purchase', 'transfer-in': 'Transfer in', 'transfer-out': 'Transfer out' };
      el.innerHTML = balances(ctx.me) + M.card('Purchase wallet ledger', '<div id="wsT"></div>', { icon: 'list' });
      M.table(document.getElementById('wsT'), {
        rows: rows, dateKey: 'date', exportName: 'wallet-statement', empty: 'Deposits, purchases and transfers appear here.',
        columns: [
          { key: 'date', label: 'Date', render: function (r) { return M.dt(r.date); }, csv: function (r) { return M.dt(r.date); } },
          { key: 'note', label: 'Description' },
          { key: 'type', label: 'Type', render: function (r) { return M.badge(LBL[r.type] || r.type, r.amount < 0 ? 'warn' : 'ok'); } },
          { key: 'amount', label: 'Amount', align: 'r', render: function (r) { return M.amt(r.amount); } },
          { key: 'balance', label: 'Balance', align: 'r', render: function (r) { return M.money(r.balance); } }
        ]
      });
    }
  });

  /* ---------- Wallet transfer (purchase wallet -> another member) ---------- */
  M.page('/wallet/transfer', {
    title: 'Wallet Transfer',
    render: function (el, ctx) {
      var me = ctx.me;
      el.innerHTML = balances(me) + '<div class="m-split">' +
        M.card('P2P · send to a member', '<div class="m-alert" hidden id="wtErr"></div><form id="wtForm" class="m-form">' +
          M.field('Member ID', '<input name="to" required placeholder="e.g. NET1004" autocomplete="off">', '<span id="wtWho">Type the member ID — their name appears here so you can confirm it.</span>') +
          '<label class="m-lbl" style="display:block;font-weight:700;margin:.2rem 0 .4rem">Pay from</label>' +
          '<div class="m-seg"><label><input type="radio" name="src" value="purchase" checked><span>' + M.icon('wallet') + '<b>Purchase wallet</b><small>' + M.money(me.purchaseWallet || 0) + '</small></span></label>' +
          '<label><input type="radio" name="src" value="income"><span>' + M.icon('coins') + '<b>Income wallet</b><small>' + M.money(me.wallet) + '</small></span></label></div>' +
          M.field('Amount', '<input name="amount" type="number" min="' + (ctx.S.minTransfer || 1) + '" step="0.01" required>', '<span id="wtAvail">Available in your purchase wallet: ' + M.money(me.purchaseWallet || 0) + '</span>') +
          '<ul class="m-kv sum" id="wtSum"></ul>' +
          '<button class="mbtn gold block" type="submit">' + M.icon('swap') + 'Continue</button></form>' +
          '<p class="m-muted" style="font-size:.82rem;margin-bottom:0">P2P works every day, including Saturday and Sunday. The company keeps ' + (ctx.S.p2pFeePct || 0) + '% of the amount sent. Every transfer needs your PIN and a code we e-mail you.</p>', { icon: 'swap' }) +
        M.card('Recent member transfers', '<div id="wtT"></div>', { icon: 'history' }) + '</div>';
      var f = document.getElementById('wtForm'), who = document.getElementById('wtWho'), sum = document.getElementById('wtSum'), avail = document.getElementById('wtAvail');
      var lookT, feePct = ctx.S.p2pFeePct || 0;
      function calc() {
        var src = f.src.value, bal = src === 'income' ? me.wallet : (me.purchaseWallet || 0);
        avail.textContent = 'Available in your ' + (src === 'income' ? 'income' : 'purchase') + ' wallet: ' + M.money(bal);
        var a = Number(f.amount.value) || 0, fee = Math.round(a * feePct) / 100;
        sum.innerHTML = a ? '<li><span>You send (' + (src === 'income' ? 'income wallet' : 'purchase wallet') + ')</span><b>' + M.money(a) + '</b></li><li><span>Company charge (' + feePct + '%)</span><b class="neg">−' + M.money(fee) + '</b></li><li class="tot"><span>They receive</span><b>' + M.money(a - fee) + '</b></li>' : '';
      }
      f.amount.oninput = calc;
      el.querySelectorAll('input[name=src]').forEach(function (r) { r.onchange = calc; });
      calc();
      f.to.oninput = function () {          // live recipient check: the name is shown so you can confirm who you are paying
        var code = this.value.trim();
        clearTimeout(lookT);
        if (!code) { who.innerHTML = 'Type the member ID — their name appears here so you can confirm it.'; return; }
        if (code.toUpperCase() === me.code) { who.innerHTML = '<b class="neg">That is your own ID</b>'; return; }
        lookT = setTimeout(function () {
          Store.lookupMember(code).then(function (r) {
            if (f.to.value.trim() !== code) return;
            who.innerHTML = r.ok ? '<b class="pos">' + M.icon('check') + 'Send to: ' + M.esc(r.name) + ' (' + M.esc(r.code) + ')</b>' : '<b class="neg">Member not found</b>';
          });
        }, 300);
      };
      f.onsubmit = function (e) {
        e.preventDefault();
        var to = f.to.value.trim(), amount = f.amount.value, source = f.src.value, al = document.getElementById('wtErr');
        M.alert(al, '');
        M.askPin(me.id).then(function (pin) {
          if (!pin) return null;
          return Store.requestTransferOtp(to, amount, pin, source).then(function (o) {          // step 1: checks + e-mailed code
            if (!o.ok) return M.alert(al, o.error);
            return M.askOtp({
              sentTo: o.sentTo,
              summary: 'Send <b>' + M.money(o.amount) + '</b> from your <b>' + (source === 'income' ? 'income' : 'purchase') + ' wallet</b> to <b>' + M.esc(o.to.name) + '</b> (' + M.esc(o.to.code) + '). Company charge ' + M.money(o.fee) + ' — they receive <b>' + M.money(o.net) + '</b>.',
              resend: function () { return Store.requestTransferOtp(to, amount, pin, source); }
            }).then(function (code) {
              if (!code) return null;
              return Store.transferToMember(me.id, to, amount, pin, code, source).then(function (r) {   // step 2: the code must match
                if (!r.ok) return M.alert(al, r.error);
                M.toast('Sent ' + M.money(amount) + ' to ' + r.to.name);
                ctx.refresh();
              });
            });
          });
        });
      };
      M.table(document.getElementById('wtT'), {
        rows: Store.transfersOf(me.id).filter(function (x) { return x.kind === 'member'; }), search: false, per: 10, empty: 'No member transfers yet.',
        columns: [
          { key: 'date', label: 'Date', render: function (r) { return M.date(r.date); } },
          { key: 'who', label: 'Member', render: function (r) { var out = r.fromId === me.id; return (out ? 'To ' : 'From ') + M.esc(out ? r.toName : r.fromName) + ' <small class="m-muted">' + M.esc(out ? r.toCode : r.fromCode) + '</small>'; } },
          { key: 'amount', label: 'Amount', align: 'r', render: function (r) { return M.amt(r.fromId === me.id ? -r.amount : r.amount); } }
        ]
      });
    }
  });
})();
