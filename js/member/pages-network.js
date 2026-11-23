/* member/pages-network.js — Team Report · Team Level Report */
(function () {
  'use strict';

  function memberCell(u) { return '<div class="m-who"><span class="m-av sm">' + M.esc(UI.initials(u.name)) + '</span><div><b>' + M.esc(u.name) + '</b><small>' + M.esc(u.code) + '</small></div></div>'; }
  function statusOf(u) { return u.blocked ? M.badge('Blocked') : u.invested > 0 ? M.badge('Active') : M.badge('Inactive'); }
  function firstPurchase(u) { var o = Store.ordersOf(u.id); return o.length ? o[o.length - 1].date : null; }

  /* ---------- Team Report (direct team / "delegator list") ---------- */
  M.page('/network/team', {
    title: 'Team Report',
    render: function (el, ctx) {
      var list = Store.directs(ctx.me.id).map(function (u, i) {
        return { sl: i + 1, u: u, code: u.code, name: u.name, phone: u.phone || '—', joined: u.joined, purchase: firstPurchase(u),
          total: Store.teamSize(u.id), active: Store.activeTeam(u.id), pkg: u.invested || 0 };
      });
      el.innerHTML =
        '<div class="m-grid4">' +
          M.stat({ icon: 'users', label: 'Direct members', value: list.length, tone: 'blue' }) +
          M.stat({ icon: 'check', label: 'Active directs', value: list.filter(function (r) { return r.u.invested > 0; }).length, tone: 'roi' }) +
          M.stat({ icon: 'network', label: 'Total team', value: Store.teamSize(ctx.me.id), tone: 'sky' }) +
          M.stat({ icon: 'package', label: 'Direct business', value: M.money(Store.directBusiness(ctx.me.id)), tone: 'binary' }) +
        '</div>' + M.card('Direct team', '<div id="tTable"></div>', { icon: 'users', sub: 'Members you personally sponsored' });
      M.table(document.getElementById('tTable'), {
        rows: list, dateKey: 'joined', exportName: 'team-report',
        empty: 'Share your referral link from the dashboard to build your team.',
        columns: [
          { key: 'sl', label: '#' },
          { key: 'code', label: 'Member', render: function (r) { return memberCell(r.u); }, csv: function (r) { return r.code + ' ' + r.name; } },
          { key: 'phone', label: 'Mobile' },
          { key: 'joined', label: 'Joining date', render: function (r) { return M.date(r.joined); }, csv: function (r) { return M.date(r.joined); } },
          { key: 'purchase', label: 'Purchase date', render: function (r) { return M.date(r.purchase); }, csv: function (r) { return M.date(r.purchase); } },
          { key: 'pkg', label: 'Invested', align: 'r', render: function (r) { return M.money(r.pkg); } },
          { key: 'total', label: 'Total team', align: 'r' },
          { key: 'active', label: 'Active team', align: 'r' },
          { key: 'status', label: 'Status', render: function (r) { return statusOf(r.u); }, csv: function (r) { return r.u.invested > 0 ? 'Active' : 'Inactive'; } }
        ]
      });
    }
  });

  /* ---------- Team Level Report ---------- */
  M.page('/network/levels', {
    title: 'Team Level Report',
    render: function (el, ctx) {
      el.innerHTML = M.card('Members by level',
        '<form class="m-filter" id="lForm"><label>From<input type="date" name="from"></label><label>To<input type="date" name="to"></label>' +
        '<button class="mbtn gold" type="submit">' + M.icon('search') + 'Apply</button><button class="mbtn line" type="reset">Reset</button></form><div id="lOut"></div>',
        { icon: 'layers', sub: 'Filter by joining date. Click a level to see its members.' });
      var form = document.getElementById('lForm');
      function draw() {
        var from = form.from.value ? new Date(form.from.value + 'T00:00:00').getTime() : null;
        var to = form.to.value ? new Date(form.to.value + 'T23:59:59').getTime() : null;
        var stats = Store.levelStats(ctx.me.id, from, to, 10);
        var tot = stats.reduce(function (s, l) { return { total: s.total + l.total, paid: s.paid + l.paid, business: s.business + l.business }; }, { total: 0, paid: 0, business: 0 });
        var max = Math.max.apply(null, stats.map(function (l) { return l.total; }).concat([1]));
        document.getElementById('lOut').innerHTML = stats.length ?
          '<div class="m-twrap"><table class="m-table"><thead><tr><th>Level</th><th class="r">Income</th><th>Status</th><th class="r">Total members</th><th class="r">Paid members</th><th>Share</th><th class="r">Total business</th><th></th></tr></thead><tbody>' +
          stats.map(function (l) {
            return '<tr><td data-l="Level"><span class="m-lvl">L' + l.level + '</span></td><td data-l="Income" class="r"><b>' + l.pct + '%</b></td><td data-l="Status">' + (l.unlocked ? M.badge('Unlocked', 'ok') : M.badge('Needs ' + l.level + ' directs', 'warn')) + '</td><td data-l="Total" class="r">' + l.total + '</td><td data-l="Paid" class="r">' + l.paid + '</td>' +
              '<td data-l="Share"><div class="m-bar sm"><i style="width:' + Math.round(l.total / max * 100) + '%"></i></div></td><td data-l="Business" class="r">' + M.money(l.business) + '</td>' +
              '<td class="r"><button class="mbtn line sm" data-lv="' + l.level + '"' + (l.total ? '' : ' disabled') + '>' + M.icon('eye') + 'View</button></td></tr>';
          }).join('') + '</tbody><tfoot><tr><th>Total</th><th></th><th></th><th class="r">' + tot.total + '</th><th class="r">' + tot.paid + '</th><th></th><th class="r">' + M.money(tot.business) + '</th><th></th></tr></tfoot></table></div>'
          : M.empty('No team members yet', 'Members you sponsor (and theirs) appear here level by level.');
        document.querySelectorAll('[data-lv]').forEach(function (b) {
          b.onclick = function () {
            var lv = stats[parseInt(b.dataset.lv, 10) - 1];
            M.modal({
              title: 'Level ' + lv.level + ' members', wide: true, body: '<div id="lvT"></div>',
              onMount: function (m) {
                M.table(m.querySelector('#lvT'), {
                  rows: lv.users.map(function (u) { var sp = Store.byId(u.sponsorId); return { u: u, name: u.name, code: u.code, sponsor: sp ? sp.code : '—', joined: u.joined, inv: Store.investedTotal(u.id) }; }),
                  columns: [
                    { key: 'name', label: 'Member', render: function (r) { return memberCell(r.u); } },
                    { key: 'sponsor', label: 'Sponsor' },
                    { key: 'joined', label: 'Joined', render: function (r) { return M.date(r.joined); } },
                    { key: 'inv', label: 'Business', align: 'r', render: function (r) { return M.money(r.inv); } },
                    { key: 's', label: 'Status', render: function (r) { return statusOf(r.u); } }
                  ]
                });
              }
            });
          };
        });
      }
      form.addEventListener('submit', function (e) { e.preventDefault(); draw(); });
      form.addEventListener('reset', function () { setTimeout(draw, 0); });
      draw();
    }
  });
})();
