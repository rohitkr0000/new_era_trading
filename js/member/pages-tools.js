/* member/pages-tools.js — Calculator (simple interest + compounding) */
(function () {
  'use strict';
  var M = window.M;

  M.page('/tools/calculator', {
    title: 'Calculator',
    render: function (el, ctx) {
      el.innerHTML = M.card('Simple interest & compounding', '<div id="calcHost"></div>', { icon: 'gauge', sub: 'Plan numbers come from the live settings' });
      Calc.mount(document.getElementById('calcHost'), { settings: ctx.S });
    }
  });
})();
