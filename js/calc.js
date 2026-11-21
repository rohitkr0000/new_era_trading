/* ==========================================================================
   calc.js — Simple-interest and compounding calculator (New Era Trading)
   --------------------------------------------------------------------------
   Same maths as the company's spreadsheet ("Calculater", "Compounding Calculator" and the three
   "after 25 / 50 days" tabs), written once and used by:
     · calculator.html            (public)
     · user/dashboard.html        (member hub  → Tools → Calculator)
     · admin/calculator.html      (company view: system deposit and liability)
     · node tests                 (tests/calc.test.js checks every number against the sheet)

   THE MODEL ("lots")
     Every amount that earns is a lot: the deposit itself, and — in compounding — each day's interest.
     A lot earns the daily rate on its full value for `cycle` days (25), then it expires.
       · deposit lot   : earns on days 1 … cycle
       · interest lot  : created at the end of day d, earns on days d+1 … d+cycle
     Each day, the interest is  rate × (all lots that are still live at the start of the day).
       · "cash"     mode: the interest is paid out (withdrawn) — nothing is added to the base
       · "compound" mode: the interest becomes a new lot — the base grows
     Switching mode between days gives every tab of the sheet:
       simple interest = cash all cycle · compounding = compound all cycle ·
       "withdraw after 25 days" = compound 25 then cash 25 · "keep compounding" = compound 50 ·
       "withdraw after 50 days" = compound 50 then cash 25.
   ========================================================================== */
(function (global) {
  'use strict';

  var r2 = function (n) { return Math.round((n + 1e-9) * 100) / 100; };

  /**
   * @param {number} deposit   starting amount
   * @param {number} rate      daily rate as a fraction (0.05)
   * @param {number} cycle     days a lot keeps earning (25)
   * @param {Array<[number,string]>} plan  [[untilDay, 'compound'|'cash'], …] — the mode up to and including that day
   * @returns {{rows:Array, cash:number, base:number, gross:number}}
   *   rows[i] = { day, mode, opening, interest, expiring, closing }
   *   cash  = total interest paid out as cash      base = live balance after the last day
   *   gross = balance at the close of the last day BEFORE that day's expiry
   */
  function run(deposit, rate, cycle, plan) {
    var lots = [{ amt: deposit, last: cycle }];           // `last` = the final day this lot earns on
    var rows = [], cash = 0, total = plan[plan.length - 1][0], gross = deposit;
    for (var day = 1; day <= total; day++) {
      var mode = 'cash';
      for (var p = 0; p < plan.length; p++) { if (day <= plan[p][0]) { mode = plan[p][1]; break; } }
      var opening = 0; lots.forEach(function (l) { opening += l.amt; });
      var interest = opening * rate;
      if (mode === 'compound') lots.push({ amt: interest, last: day + cycle }); else cash += interest;
      var beforeExpiry = 0; lots.forEach(function (l) { beforeExpiry += l.amt; });
      var expiring = 0;
      lots = lots.filter(function (l) { if (l.last === day) { expiring += l.amt; return false; } return true; });
      var closing = 0; lots.forEach(function (l) { closing += l.amt; });
      gross = beforeExpiry;
      rows.push({ day: day, mode: mode, opening: opening, interest: interest, expiring: expiring, closing: closing });
    }
    return { rows: rows, cash: cash, base: rows.length ? rows[rows.length - 1].closing : deposit, gross: gross };
  }

  /** All scenarios for one deposit. `S` = plan settings (roiPct, roiDays, withdrawFeePct, directPct, levelPct …). */
  function scenarios(deposit, S) {
    S = S || {};
    var rate = (S.roiPct != null ? S.roiPct : 5) / 100;
    var cycle = S.roiDays || 25;
    var fee = (S.withdrawFeePct != null ? S.withdrawFeePct : 10) / 100;
    var net = function (x) { return x * (1 - fee); };

    var si = run(deposit, rate, cycle, [[cycle, 'cash']]);
    var ci = run(deposit, rate, cycle, [[cycle, 'compound']]);
    var w1 = run(deposit, rate, cycle, [[cycle, 'compound'], [cycle * 2, 'cash']]);
    var c2 = run(deposit, rate, cycle, [[cycle * 2, 'compound']]);
    var w2 = run(deposit, rate, cycle, [[cycle * 2, 'compound'], [cycle * 3, 'cash']]);

    var refPct = S.directPct != null ? S.directPct : 5;
    var levelPct = (S.levelPct || []).reduce(function (a, b) { return a + b; }, 0);
    var out = {
      rate: rate, cycle: cycle, fee: fee, deposit: deposit,
      /* the "Calculater" tab */
      roiPerDay: deposit * rate,
      siInterest: deposit * rate * cycle,                     // SI_25 Days   (sheet F3 = ROI × 25)
      siFinal: deposit + deposit * rate * cycle,              // deposit + interest (sheet E6 = 225)
      siNet: net(deposit * rate * cycle),                     // net withdrawal after the charge (sheet G3)
      ciFinal: ci.gross,                                      // CI_25 Days   (sheet I3 = deposit × 1.05^25)
      ciInterest: ci.gross - deposit,
      ciAfterExpiry: ci.base,                                 // balance after the 25-day cycle expires (sheet J3)
      /* company view */
      referral: deposit * refPct / 100,
      level: deposit * levelPct / 100,
      refPct: refPct, levelPct: levelPct,
      /* schedules for each tab */
      si: si, ci: ci,
      after25Withdraw: w1, after25Compound: c2, after50Withdraw: w2,
      net: net
    };
    out.systemDeposit = deposit - out.referral - out.level;                     // sheet E3
    out.liability = out.siNet + out.level + out.roiPerDay - deposit;            // sheet H3 "Different & Liability with SI"
    return out;
  }

  var api = { run: run, scenarios: scenarios, round2: r2 };

  /* ======================================================================
     UI — Calc.mount(element, { settings, admin })
     ====================================================================== */
  var CSS = [
    '.calc{--cg:#e8b923;--cb:rgba(127,127,127,.28);--cs:rgba(127,127,127,.10);color:inherit;font-family:inherit}',
    '.calc *{box-sizing:border-box}',
    '.calc-top{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.8rem;margin-bottom:1rem}',
    '.calc-f{display:flex;flex-direction:column;gap:.3rem}',
    '.calc-f label{font-size:.78rem;opacity:.75;font-weight:600;letter-spacing:.03em}',
    '.calc-f input{width:100%;padding:.65rem .8rem;border-radius:10px;border:1px solid var(--cb);background:var(--cs);color:inherit;font:inherit;font-size:1.05rem}',
    '.calc-f small{font-size:.72rem;opacity:.65}',
    '.calc-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:.8rem;margin:1.1rem 0}',
    '.calc-c{border:1px solid var(--cb);border-radius:14px;padding:.9rem 1rem;background:var(--cs)}',
    '.calc-c small{display:block;font-size:.74rem;opacity:.72;margin-bottom:.25rem}',
    '.calc-c b{font-size:1.45rem;font-weight:800}',
    '.calc-c em{display:block;font-style:normal;font-size:.72rem;opacity:.65;margin-top:.2rem}',
    '.calc-c.g{border-color:var(--cg)}.calc-c.g b{color:var(--cg)}',
    '.calc-tabs{display:flex;flex-wrap:wrap;gap:.45rem;margin:1.2rem 0 .8rem}',
    '.calc-tabs button{padding:.5rem .85rem;border-radius:999px;border:1px solid var(--cb);background:transparent;color:inherit;font:inherit;font-size:.85rem;cursor:pointer}',
    '.calc-tabs button.on{background:var(--cg);border-color:var(--cg);color:#1b1500;font-weight:700}',
    '.calc-note{border-left:3px solid var(--cg);padding:.6rem .9rem;border-radius:6px;background:var(--cs);font-size:.88rem;margin:.6rem 0 1rem}',
    '.calc-wrap{overflow:auto;max-height:460px;border:1px solid var(--cb);border-radius:12px}',
    '.calc table{width:100%;border-collapse:collapse;font-size:.88rem;font-variant-numeric:tabular-nums}',
    '.calc th,.calc td{padding:.5rem .8rem;text-align:right;white-space:nowrap}',
    '.calc th:first-child,.calc td:first-child{text-align:left}',
    '.calc thead th{position:sticky;top:0;background:#101c3a;color:#f4d160;font-size:.74rem;letter-spacing:.05em;text-transform:uppercase}',
    '.calc tbody tr:nth-child(even){background:var(--cs)}',
    '.calc td.pos{color:#18b46b}.calc td.neg{color:#e5484d}',
    '.calc tfoot td{font-weight:800;border-top:2px solid var(--cg)}',
    '.calc-chart{width:100%;height:auto;margin:.4rem 0 0}',
    '.calc-legend{display:flex;gap:1.2rem;flex-wrap:wrap;font-size:.8rem;margin:.3rem 0 1rem}',
    '.calc-legend i{display:inline-block;width:.8rem;height:.8rem;border-radius:3px;margin-right:.35rem;vertical-align:-1px}',
    '.calc-warn{font-size:.8rem;opacity:.75;margin-top:1rem;line-height:1.5}'
  ].join('');

  function money(n, cur) { return (cur || '$') + (Math.round((n + 1e-9) * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

  function chart(x, cur) {
    var W = 640, H = 230, pl = 52, pb = 26, pt = 10, pr = 10, n = x.cycle;
    var si = [], ci = [], max = 0;
    for (var d = 1; d <= n; d++) {
      si.push(x.deposit + x.roiPerDay * d);
      ci.push(x.deposit * Math.pow(1 + x.rate, d));
    }
    max = Math.max(si[n - 1], ci[n - 1]) * 1.05;
    var X = function (d) { return pl + (W - pl - pr) * (d - 1) / Math.max(1, n - 1); };
    var Y = function (v) { return pt + (H - pt - pb) * (1 - v / max); };
    var path = function (a) { return a.map(function (v, i) { return (i ? 'L' : 'M') + X(i + 1).toFixed(1) + ' ' + Y(v).toFixed(1); }).join(' '); };
    var grid = '';
    for (var g = 0; g <= 4; g++) {
      var gv = max * g / 4;
      grid += '<line x1="' + pl + '" x2="' + (W - pr) + '" y1="' + Y(gv).toFixed(1) + '" y2="' + Y(gv).toFixed(1) + '" stroke="currentColor" opacity=".15"/>' +
        '<text x="' + (pl - 6) + '" y="' + (Y(gv) + 4).toFixed(1) + '" text-anchor="end" font-size="11" fill="currentColor" opacity=".7">' + cur + Math.round(gv) + '</text>';
    }
    var ticks = '';
    [1, Math.round(n / 4), Math.round(n / 2), Math.round(n * 3 / 4), n].forEach(function (d) {
      ticks += '<text x="' + X(d).toFixed(1) + '" y="' + (H - 7) + '" text-anchor="middle" font-size="11" fill="currentColor" opacity=".7">Day ' + d + '</text>';
    });
    return '<svg class="calc-chart" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Simple interest and compounding growth">' + grid + ticks +
      '<path d="' + path(si) + '" fill="none" stroke="#35c5b0" stroke-width="2.5"/><path d="' + path(ci) + '" fill="none" stroke="#e8b923" stroke-width="2.5"/></svg>';
  }

  function table(rows, cur) {
    var sumInt = 0; rows.forEach(function (r) { sumInt += r.interest; });
    return '<div class="calc-wrap"><table><thead><tr><th>Day</th><th>Opening</th><th>Daily interest</th><th>Expiring</th><th>Closing</th></tr></thead><tbody>' +
      rows.map(function (r) {
        return '<tr><td>' + r.day + '</td><td>' + money(r.opening, cur) + '</td><td class="pos">+' + money(r.interest, cur) + '</td><td class="' + (r.expiring ? 'neg' : '') + '">' +
          (r.expiring ? '−' + money(r.expiring, cur) : '—') + '</td><td>' + money(r.closing, cur) + '</td></tr>';
      }).join('') + '</tbody><tfoot><tr><td>Total</td><td></td><td class="pos">+' + money(sumInt, cur) + '</td><td></td><td></td></tr></tfoot></table></div>';
  }

  function mount(el, opts) {
    opts = opts || {};
    var S = Object.assign({ currency: '$', roiPct: 5, roiDays: 25, withdrawFeePct: 10, directPct: 5, minJoin: 5, joinMultiple: 5, capNonWorking: 2, capWorking: 5,
      levelPct: [2, 1, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2] }, opts.settings || {});
    var admin = !!opts.admin, cur = S.currency || '$';
    if (!document.getElementById('calc-css')) { var st = document.createElement('style'); st.id = 'calc-css'; st.textContent = CSS; document.head.appendChild(st); }
    var tab = 'si', deposit = 100;
    var lvTotal = Math.round(S.levelPct.reduce(function (a, b) { return a + b; }, 0) * 100) / 100;

    var inputs = '<div class="calc-f"><label for="cDep">Deposit (' + cur + ')</label><input id="cDep" type="number" min="' + S.minJoin + '" step="' + S.joinMultiple + '" value="100">' +
      '<small>From ' + cur + S.minJoin + ', in multiples of ' + cur + S.joinMultiple + '</small></div>';
    if (admin) {
      inputs += '<div class="calc-f"><label for="cRate">Daily ROI (%)</label><input id="cRate" type="number" min="0" step="0.1" value="' + S.roiPct + '"></div>' +
        '<div class="calc-f"><label for="cCyc">Cycle (days)</label><input id="cCyc" type="number" min="1" step="1" value="' + (S.roiDays || 25) + '"></div>' +
        '<div class="calc-f"><label for="cFee">Withdrawal charge (%)</label><input id="cFee" type="number" min="0" step="0.5" value="' + S.withdrawFeePct + '"></div>' +
        '<div class="calc-f"><label for="cRef">Referral (%)</label><input id="cRef" type="number" min="0" step="0.5" value="' + S.directPct + '"></div>' +
        '<div class="calc-f"><label for="cLvl">All 12 levels together (%)</label><input id="cLvl" type="number" min="0" step="0.1" value="' + lvTotal + '"></div>';
    } else {
      inputs += '<div class="calc-f"><label>Daily ROI</label><input value="' + S.roiPct + '% per trading day" readonly></div>' +
        '<div class="calc-f"><label>Cycle</label><input value="' + (S.roiDays || 25) + ' trading days" readonly></div>' +
        '<div class="calc-f"><label>Withdrawal charge</label><input value="' + S.withdrawFeePct + '%" readonly></div>';
    }

    el.innerHTML = '<div class="calc">' +
      '<div class="calc-top">' + inputs + '</div>' +
      '<div id="cCards"></div>' +
      '<div class="calc-legend"><span><i style="background:#35c5b0"></i>Simple interest (deposit + interest)</span><span><i style="background:#e8b923"></i>Compounding (deposit + compounded interest)</span></div>' +
      '<div id="cChart"></div>' +
      '<div class="calc-tabs" id="cTabs"></div><div id="cOut"></div>' +
      '<p class="calc-warn">This is an illustration of the plan’s arithmetic, not a promise of returns. Actual payouts also depend on the earning cap (' + S.capNonWorking + 'X / ' + S.capWorking +
        'X of what you invested), the withdrawal rules (Mon–Fri, 10 AM–2 PM) and the withdrawal charge. Trading involves risk.</p>' +
    '</div>';

    var $ = function (id) { return el.querySelector('#' + id); };

    function read() {
      var v = parseFloat($('cDep').value); deposit = isNaN(v) || v <= 0 ? S.minJoin : v;
      var o = Object.assign({}, S);
      if (admin) {
        o.roiPct = parseFloat($('cRate').value) || 0; o.roiDays = parseInt($('cCyc').value, 10) || 25; o.withdrawFeePct = parseFloat($('cFee').value) || 0;
        o.directPct = parseFloat($('cRef').value) || 0; o.levelPct = [parseFloat($('cLvl').value) || 0];
      }
      return o;
    }

    function draw() {
      var o = read(), x = scenarios(deposit, o), n = x.cycle, m = function (v) { return money(v, cur); };
      var card = function (label, val, sub, g) { return '<div class="calc-c' + (g ? ' g' : '') + '"><small>' + label + '</small><b>' + val + '</b>' + (sub ? '<em>' + sub + '</em>' : '') + '</div>'; };
      var c = card('ROI per trading day', m(x.roiPerDay), o.roiPct + '% of the deposit') +
        card('Simple interest · ' + n + ' days', m(x.siInterest), 'deposit + interest = ' + m(x.siFinal), true) +
        card('You receive after the ' + o.withdrawFeePct + '% charge', m(x.siNet), 'on the ' + m(x.siInterest) + ' interest') +
        card('Compounding · ' + n + ' days', m(x.ciFinal), 'interest earned ' + m(x.ciInterest), true) +
        card('After the ' + n + '-day cycle expires', m(x.ciAfterExpiry), 'the deposit has expired; the compounded interest keeps earning');
      if (admin) {
        c += card('Paid to the upline', m(x.referral + x.level), 'referral ' + m(x.referral) + ' + levels ' + m(x.level)) +
          card('System deposit', m(x.systemDeposit), 'deposit − referral − level income') +
          card('Difference & liability (simple interest)', m(x.liability), 'net withdrawal + level + 1 day ROI − deposit');
      }
      $('cCards').innerHTML = '<div class="calc-cards">' + c + '</div>';
      $('cChart').innerHTML = chart(x, cur);

      var tabs = [['si', 'Simple interest'], ['ci', 'Compounding · ' + n + ' days'], ['w1', 'After ' + n + ' days · withdraw'],
        ['c2', 'After ' + n + ' days · keep compounding'], ['w2', 'After ' + n * 2 + ' days · withdraw']];
      $('cTabs').innerHTML = tabs.map(function (t) { return '<button type="button" data-t="' + t[0] + '" class="' + (t[0] === tab ? 'on' : '') + '">' + t[1] + '</button>'; }).join('');
      $('cTabs').querySelectorAll('button').forEach(function (b) { b.onclick = function () { tab = b.dataset.t; draw(); }; });

      var note, rows, cash;
      if (tab === 'si') {
        rows = x.si.rows;
        note = 'Every day the deposit earns ' + o.roiPct + '% = <b>' + m(x.roiPerDay) + '</b>, paid out as cash, for ' + n + ' trading days: <b>' + m(x.siInterest) +
          '</b>. After the ' + o.withdrawFeePct + '% charge you receive <b>' + m(x.siNet) + '</b>. Then the cycle expires.';
      } else if (tab === 'ci') {
        rows = x.ci.rows;
        note = 'Each day’s interest is added to the base and earns too (opening × (1 + ' + o.roiPct + '%)). After ' + n + ' days: <b>' + m(x.ciFinal) + '</b> = deposit × (1 + ' + o.roiPct +
          '%)<sup>' + n + '</sup>. When the cycle ends the deposit expires and <b>' + m(x.ciAfterExpiry) + '</b> of compounded interest remains.';
      } else if (tab === 'w1') {
        rows = x.after25Withdraw.rows.slice(n); cash = rows.reduce(function (a, r) { return a + r.interest; }, 0);
        note = 'After day ' + n + ' the base is ' + m(x.ciAfterExpiry) + '. From now on the daily ' + o.roiPct + '% is <b>withdrawn</b> as cash (not added to the base), and each day one compounded lot reaches the end of its own ' +
          n + '-day life and expires. Cash withdrawn over the next ' + n + ' days: <b>' + m(cash) + '</b> (' + m(x.net(cash)) + ' after the charge).';
      } else if (tab === 'c2') {
        rows = x.after25Compound.rows.slice(n);
        note = 'The same base, but the daily interest keeps <b>compounding</b> while the oldest lot expires each day (closing = opening + interest − expiring). After ' + n * 2 +
          ' days the base is <b>' + m(rows[rows.length - 1].closing) + '</b>.';
      } else {
        rows = x.after50Withdraw.rows.slice(n * 2); cash = rows.reduce(function (a, r) { return a + r.interest; }, 0);
        note = 'Compounding for ' + n * 2 + ' days gives a base of ' + m(rows[0].opening) + '; then the daily interest is <b>withdrawn</b> for ' + n + ' more days: <b>' + m(cash) + '</b> in cash (' + m(x.net(cash)) + ' after the charge).';
      }
      $('cOut').innerHTML = '<div class="calc-note">' + note + '</div>' + table(rows, cur);
    }

    el.querySelectorAll('input').forEach(function (i) { if (!i.readOnly) i.addEventListener('input', draw); });
    draw();
  }
  api.mount = mount;

  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  global.Calc = Object.assign(global.Calc || {}, api);
})(typeof window !== 'undefined' ? window : globalThis);
