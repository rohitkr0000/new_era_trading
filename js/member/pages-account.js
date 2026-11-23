/* member/pages-account.js — Profile · Password · Txn PIN · Downloads · Support tickets */
(function () {
  'use strict';
  var COUNTRIES = ['India', 'United Arab Emirates', 'United Kingdom', 'United States', 'Nepal', 'Bangladesh', 'Sri Lanka', 'Singapore', 'Malaysia', 'Other'];

  M.page('/account/profile', {
    title: 'Update Profile',
    render: function (el, ctx) {
      var me = ctx.me, sp = me.sponsorId ? Store.byId(me.sponsorId) : null;
      el.innerHTML = '<div class="m-split wide-l">' +
        M.card('Edit profile', '<div class="m-alert" hidden id="prErr"></div><form id="prForm" class="m-form cols">' +
          M.field('Username (Member ID)', '<input value="' + M.esc(me.code) + '" readonly>') +
          M.field('Full name', '<input name="name" required value="' + M.esc(me.name) + '">') +
          M.field('Email', '<input value="' + M.esc(me.email) + '" readonly>', 'Contact support to change your email.') +
          M.field('Mobile', '<input name="phone" type="tel" value="' + M.esc(me.phone || '') + '">') +
          M.field('Country', '<select name="country"><option value="">Select country</option>' + COUNTRIES.map(function (c) { return '<option' + (me.country === c ? ' selected' : '') + '>' + c + '</option>'; }).join('') + '</select>') +
          M.field('City', '<input name="city" value="' + M.esc(me.city || '') + '">') +
          '<div class="span2">' + M.field('USDT BEP20 (BSC) address', '<input name="usdt" value="' + M.esc(me.usdtAddress || '') + '" placeholder="0x…">', 'Used to prefill your withdrawals.') + '</div>' +
          '<div class="span2"><button class="mbtn gold" type="submit">' + M.icon('check') + 'Save changes</button></div></form>', { icon: 'user' }) +
        M.card('Account summary', '<div class="m-profile"><span class="m-av xl">' + M.esc(UI.initials(me.name)) + '</span><b>' + M.esc(me.name) + '</b><small>' + M.esc(me.code) + ' · #' + Store.positions(me.id).team.pos + ' by team · #' + Store.positions(me.id).business.pos + ' by business</small></div>' +
          '<ul class="m-kv"><li><span>Status</span>' + M.badge(me.invested > 0 ? 'Active' : 'Inactive') + '</li><li><span>Invested</span><b>' + M.money(me.invested || 0) + '</b></li><li><span>Earning cap</span><b>' + Store.capInfo(me.id).mult + 'X · ' + (Store.isWorking(me.id) ? 'working' : 'non-working') + '</b></li>' +
          '<li><span>Sponsor</span><b>' + (sp ? M.esc(sp.name) + ' · ' + M.esc(sp.code) : 'Company') + '</b></li><li><span>Placement</span><b>' + (me.parentId ? M.esc(Store.byId(me.parentId).name) + ' · ' + me.leg : 'Top of tree') + '</b></li>' +
          '<li><span>Joined</span><b>' + M.date(me.joined) + '</b></li><li><span>Transaction PIN</span>' + (Store.hasPin(me.id) ? M.badge('Set', 'ok') : '<a href="#/account/pin">' + M.badge('Not set', 'warn') + '</a>') + '</li></ul>', { icon: 'shield' }) +
        '</div>';
      document.getElementById('prForm').onsubmit = function (e) {
        e.preventDefault();
        Store.updateProfile(me.id, { name: this.name.value, phone: this.phone.value, country: this.country.value, city: this.city.value, usdtAddress: this.usdt.value }).then(function (r) {
          if (!r.ok) return M.alert(document.getElementById('prErr'), r.error);
          M.toast('Profile updated'); ctx.refresh();
        });
      };
    }
  });

  M.page('/account/password', {
    title: 'Change Password',
    render: function (el, ctx) {
      var mask = String(ctx.me.email || '').replace(/(?<=.).(?=[^@]*@)/g, '*');
      el.innerHTML = '<div class="m-narrow">' + M.card('Change login password',
        '<div class="m-alert" hidden id="pwErr"></div>' +
        // step 1 — ask for the e-mail code
        '<div id="pwStep1"><p class="m-muted" style="margin-top:0">For your security we confirm every password change with a one-time code sent to <b>' + M.esc(mask) + '</b>.</p>' +
        '<button class="mbtn gold block" id="pwSend" type="button">' + M.icon('shield') + 'Send verification code to my e-mail</button></div>' +
        // step 2 — code + passwords
        '<form id="pwForm" class="m-form" hidden>' +
        '<div class="m-alert ok" id="pwSent"></div>' +
        M.field('Verification code (6 digits)', '<input name="otp" class="m-pin sm" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required placeholder="••••••">', 'The code expires in 10 minutes. <a href="#" id="pwResend">Send a new code</a>') +
        M.field('Current password', '<input name="old" type="password" required autocomplete="current-password">') +
        M.field('New password', '<input name="pw" type="password" minlength="8" required autocomplete="new-password">', 'At least 8 characters, with letters and digits.') +
        M.field('Confirm new password', '<input name="pw2" type="password" required autocomplete="new-password">') +
        '<button class="mbtn gold block" type="submit">' + M.icon('key') + 'Update password</button></form>', { icon: 'key' }) + '</div>';

      var al = document.getElementById('pwErr'), form = document.getElementById('pwForm'), step1 = document.getElementById('pwStep1');
      function sendCode(btn) {
        if (btn) btn.disabled = true;
        Store.requestPasswordOtp().then(function (r) {
          if (btn) btn.disabled = false;
          if (!r.ok) return M.alert(al, r.error);
          M.alert(al, '', 'bad');
          step1.hidden = true; form.hidden = false;
          document.getElementById('pwSent').textContent = 'A 6-digit code was sent to ' + (r.sentTo || mask) + ' from New Era Trading. Check your inbox (and spam folder).';
          form.otp.focus();
        });
      }
      document.getElementById('pwSend').onclick = function () { sendCode(this); };
      document.getElementById('pwResend').onclick = function (e) { e.preventDefault(); sendCode(null); };
      form.onsubmit = function (e) {
        e.preventDefault();
        if (this.pw.value !== this.pw2.value) return M.alert(al, 'New passwords do not match.');
        var btn = this.querySelector('button[type=submit]'); btn.disabled = true;
        Store.changePassword(ctx.me.id, this.old.value, this.pw.value, this.otp.value.trim()).then(function (r) {
          btn.disabled = false;
          if (!r.ok) return M.alert(al, r.error);
          form.reset(); form.hidden = true; step1.hidden = false;
          M.alert(al, 'Password updated successfully. A confirmation e-mail was sent and your other devices were signed out.', 'ok');
        });
      };
    }
  });

  /* ---------------- E-mail verification + KYC ---------------- */
  M.page('/account/kyc', {
    title: 'KYC & Verification',
    render: function (el, ctx) {
      var me = ctx.me, S = ctx.S, kyc = me.kyc || 'none';
      var mask = String(me.email || '').replace(/(?<=.).(?=[^@]*@)/g, '*');
      var kycBadge = { none: M.badge('Not submitted', 'warn'), pending: M.badge('Under review', 'info'), approved: M.badge('Approved', 'ok'), rejected: M.badge('Rejected', 'bad') }[kyc] || '';
      var need = [];
      if (S.emailVerifyRequired && !me.emailVerified) need.push('verify your e-mail');
      if (S.kycRequired && kyc !== 'approved') need.push('get your KYC approved');

      el.innerHTML = '<div class="m-narrow">' +
        M.card('Account verification', '<p class="m-note" style="margin-top:0">' + (need.length
          ? 'To withdraw you need to ' + need.join(' and ') + '.' : 'Your account is fully verified — you can withdraw whenever the window is open.') + '</p>' +
          '<ul class="m-kv"><li><span>E-mail (' + M.esc(mask) + ')</span>' + (me.emailVerified ? M.badge('Verified', 'ok') : M.badge('Not verified', 'warn')) + '</li>' +
          '<li><span>KYC identity check</span>' + kycBadge + '</li></ul>', { icon: 'shield' }) +

        (me.emailVerified ? '' : M.card('1 · Verify your e-mail',
          '<div class="m-alert" hidden id="evErr"></div>' +
          '<form id="evForm" class="m-form"><div class="m-alert ok" id="evSent" style="display:block">A 6-digit code was e-mailed to <b>' + M.esc(mask) + '</b> from New Era Trading when you signed up. Check your inbox (and spam folder).</div>' +
          M.field('Verification code (6 digits)', '<input name="otp" class="m-pin sm" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required placeholder="••••••">', 'Expires in 10 minutes. Didn\'t get it? <a href="#" id="evResend">Send me a new code</a>') +
          '<button class="mbtn gold block" type="submit">Verify e-mail</button></form>', { icon: 'key' })) +

        M.card((me.emailVerified ? '' : '2 · ') + 'KYC documents',
          (kyc === 'approved' ? '<div class="m-alert ok">Your KYC is approved. Thank you!</div>'
            : kyc === 'pending' ? '<div class="m-alert ok">Your documents were received and are being reviewed. You will get an e-mail with the decision.</div>'
            : (kyc === 'rejected' ? '<div class="m-alert bad"><b>Rejected:</b> ' + M.esc(me.kycNote || 'please upload clearer documents') + '</div>' : '') +
              '<div class="m-alert" hidden id="kyErr"></div>' +
              '<form id="kyForm" class="m-form" enctype="multipart/form-data">' +
              M.field('Document type', '<select name="docType" required><option value="passport">Passport</option><option value="national_id">National ID card</option>' +
                '<option value="driving_licence">Driving licence</option><option value="aadhaar">Aadhaar</option><option value="pan">PAN card</option></select>') +
              M.field('Document number', '<input name="docNumber" required maxlength="40" autocomplete="off">') +
              M.field('Document — front', '<input name="front" type="file" accept="image/jpeg,image/png,application/pdf" required>', 'JPG, PNG or PDF, up to 4 MB.') +
              M.field('Document — back (if it has one)', '<input name="back" type="file" accept="image/jpeg,image/png,application/pdf">') +
              M.field('Selfie holding the document', '<input name="selfie" type="file" accept="image/jpeg,image/png" required>', 'Your face and the document must both be clearly visible.') +
              '<button class="mbtn gold block" type="submit">' + M.icon('upload') + 'Submit for review</button></form>'),
          { icon: 'file' }) + '</div>';

      // e-mail verification: the code is already on its way (sent at sign-up); a new one can be requested
      var evErr = document.getElementById('evErr'), evForm = document.getElementById('evForm');
      if (evForm) {
        document.getElementById('evResend').onclick = function (e) {
          e.preventDefault();
          Store.requestEmailOtp().then(function (r) {
            if (!r.ok) return M.alert(evErr, r.error);
            M.alert(evErr, 'A new code was sent to ' + (r.sentTo || mask) + '. Only the newest code works.', 'ok');
          });
        };
        evForm.onsubmit = function (e) {
          e.preventDefault();
          var btn = this.querySelector('button[type=submit]'); btn.disabled = true;
          Store.verifyEmail(this.otp.value.trim()).then(function (r) {
            btn.disabled = false;
            if (!r.ok) return M.alert(evErr, r.error);
            M.toast('E-mail verified'); ctx.refresh();
          });
        };
      }

      // KYC upload
      var form = document.getElementById('kyForm');
      if (form) form.onsubmit = function (e) {
        e.preventDefault();
        var al = document.getElementById('kyErr'), big = false;
        ['front', 'back', 'selfie'].forEach(function (n) { var f = form[n].files[0]; if (f && f.size > 4 * 1024 * 1024) big = true; });
        if (big) return M.alert(al, 'Each file must be 4 MB or smaller.');
        var fd = new FormData(form);
        if (!form.back.files[0]) fd.delete('back');
        var btn = form.querySelector('button[type=submit]'); btn.disabled = true;
        Store.submitKyc(fd).then(function (r) {
          btn.disabled = false;
          if (!r.ok) return M.alert(al, r.error);
          M.toast('KYC submitted'); ctx.refresh();
        });
      };
    }
  });

  M.page('/account/pin', {
    title: 'Change Txn PIN', sub: 'Your PIN confirms withdrawals, transfers and package purchases.',
    render: function (el, ctx) {
      var has = Store.hasPin(ctx.me.id);
      el.innerHTML = '<div class="m-narrow">' + M.card(has ? 'Enter transaction PIN' : 'Create transaction PIN', '<div class="m-alert" hidden id="pnErr"></div><form id="pnForm" class="m-form">' +
        (has ? M.field('Current PIN', '<input name="old" class="m-pin sm" type="password" inputmode="numeric" maxlength="6" required>', '<a href="#" id="pnForgot">Forgot PIN?</a>') : '<p class="m-note">' + M.icon('shield') + 'You have not set a PIN yet. Choose 4–6 digits you will remember.</p>') +
        M.field('New PIN', '<input name="pin" class="m-pin sm" type="password" inputmode="numeric" maxlength="6" required>') +
        M.field('Confirm new PIN', '<input name="pin2" class="m-pin sm" type="password" inputmode="numeric" maxlength="6" required>') +
        '<button class="mbtn gold block" type="submit">' + M.icon('shield') + (has ? 'Change PIN' : 'Create PIN') + '</button></form>' +
        ((Store.demoLogins() && Store.demoLogins().member && ctx.me.email === Store.demoLogins().member.email && Store.demoLogins().member.pin) ? '<p class="m-note">Demo account PIN: <b>' + M.esc(Store.demoLogins().member.pin) + '</b></p>' : ''), { icon: 'shield' }) + '</div>';
      var form = document.getElementById('pnForm'), al = document.getElementById('pnErr');
      function newPinOk() {
        if (!/^\d{4,6}$/.test(form.pin.value)) { M.alert(al, 'New PIN must be 4 to 6 digits.'); return false; }
        if (form.pin.value !== form.pin2.value) { M.alert(al, 'New PINs do not match.'); return false; }
        return true;
      }
      form.onsubmit = function (e) {
        e.preventDefault();
        M.alert(al, '');
        if (!newPinOk()) return;
        Store.setPin(ctx.me.id, form.pin.value, form.old ? form.old.value : '').then(function (r) {
          if (!r.ok) return M.alert(al, r.error);
          M.toast(has ? 'PIN changed' : 'PIN created'); ctx.refresh();
        });
      };
      // forgot PIN: set the new PIN above, then prove it is you with an e-mailed code instead of the old PIN
      var forgot = document.getElementById('pnForgot');
      if (forgot) forgot.onclick = function (e) {
        e.preventDefault();
        M.alert(al, '');
        if (!newPinOk()) return;
        var newPin = form.pin.value;
        Store.requestPinForgotOtp().then(function (r) {
          if (!r.ok) return M.alert(al, r.error);
          M.askOtp({
            sentTo: r.sentTo,
            summary: 'We will set your transaction PIN to the new one you entered, using the code e-mailed to you instead of the old PIN.',
            resend: function () { return Store.requestPinForgotOtp(); }
          }).then(function (code) {
            if (!code) return;
            Store.resetPinWithOtp(newPin, code).then(function (r2) {
              if (!r2.ok) return M.alert(al, r2.error);
              M.toast('PIN reset'); ctx.refresh();
            });
          });
        });
      };
    }
  });

  /* ---------- Downloads: real brand files + reports generated in the browser ---------- */
  M.page('/account/downloads', {
    title: 'Downloads',
    render: function (el, ctx) {
      var me = ctx.me, b = ctx.base;
      var files = [
        ['image', 'Logo — PNG 512px', 'Transparent New Era Trading logo for posts and banners.', b + 'images/logo.png', 'logo-new-era-trading.png'],
        ['image', 'Logo — PNG 128px', 'Small logo for profile pictures and chats.', b + 'images/logo-128.png', 'logo-new-era-trading-128.png'],
        ['file', 'Compensation plan', 'Open the plan overview on the website.', b + 'index.html#plan', null]
      ];
      var reports = [
        ['income', 'Income statement', 'All credits and debits with running balance.'],
        ['team', 'Team report', 'Your direct team with joining and package details.'],
        ['payouts', 'Withdrawal history', 'Every payout request and its status.']
      ];
      el.innerHTML = '<div class="m-dl">' + files.map(function (f) {
        return '<article class="m-dl-i"><span class="mi">' + M.icon(f[0] === 'image' ? 'star' : 'file') + '</span><div><b>' + f[1] + '</b><small>' + f[2] + '</small></div>' +
          '<a class="mbtn line sm" href="' + f[3] + '"' + (f[4] ? ' download="' + f[4] + '"' : ' target="_blank"') + '>' + M.icon(f[4] ? 'download' : 'eye') + (f[4] ? 'Download' : 'Open') + '</a></article>';
      }).join('') + reports.map(function (r) {
        return '<article class="m-dl-i"><span class="mi gold">' + M.icon('list') + '</span><div><b>' + r[1] + ' (CSV)</b><small>' + r[2] + '</small></div><button class="mbtn gold sm" data-rep="' + r[0] + '">' + M.icon('download') + 'Generate</button></article>';
      }).join('') + '</div>';
      el.querySelectorAll('[data-rep]').forEach(function (btn) {
        btn.onclick = function () {
          var k = btn.dataset.rep;
          if (k === 'income') M.csv('income-statement', ['Date', 'Details', 'Type', 'Amount', 'Balance'], Store.statementOf(me.id).map(function (r) { return [M.dt(r.date), r.note, r.type, r.amount, r.balance]; }));
          if (k === 'team') M.csv('team-report', ['Member ID', 'Name', 'Mobile', 'Joined', 'Invested', 'Team'], Store.directs(me.id).map(function (u) { return [u.code, u.name, u.phone, M.date(u.joined), u.invested || 0, Store.teamSize(u.id)]; }));
          if (k === 'payouts') M.csv('withdrawals', ['Date', 'Ref', 'Method', 'Amount', 'Fee', 'Net', 'Status'], Store.withdrawalsOf(me.id).map(function (w) { return [M.dt(w.date), w.ref, w.method, w.amount, w.fee, w.net, w.status]; }));
        };
      });
    }
  });

  /* ---------- Support ---------- */
  M.page('/support/new', {
    title: 'Open New Ticket',
    render: function (el, ctx) {
      el.innerHTML = '<div class="m-split wide-l">' +
        M.card('How can we help?', '<div class="m-alert" hidden id="tkErr"></div><form id="tkForm" class="m-form">' +
          M.field('Topic', '<select name="topic"><option>Deposit</option><option>Withdrawal</option><option>Package / upgrade</option><option>Team / genealogy</option><option>Account</option><option>Other</option></select>') +
          M.field('Subject', '<input name="subject" required maxlength="120" placeholder="Short summary of your issue">') +
          M.field('Message', '<textarea name="message" rows="6" required placeholder="Describe the issue. Include transaction IDs if relevant."></textarea>') +
          '<button class="mbtn gold" type="submit">' + M.icon('chat') + 'Create ticket</button></form>', { icon: 'chat' }) +
        M.card('Before you write', '<ul class="m-rules"><li>' + M.icon('clock') + '<div><b>Response time</b><span>Usually within one business day.</span></div></li>' +
          '<li>' + M.icon('file') + '<div><b>Include details</b><span>Transaction hash, amount and date help us resolve faster.</span></div></li>' +
          '<li>' + M.icon('shield') + '<div><b>Stay safe</b><span>We will never ask for your password or PIN.</span></div></li></ul>', { icon: 'support' }) + '</div>';
      document.getElementById('tkForm').onsubmit = function (e) {
        e.preventDefault();
        Store.openTicket(ctx.me.id, '[' + this.topic.value + '] ' + this.subject.value, this.message.value).then(function (r) {
          if (!r.ok) return M.alert(document.getElementById('tkErr'), r.error);
          M.toast('Ticket ' + r.ticket.ref + ' created'); M.go('/support/history');
        });
      };
    }
  });

  M.page('/support/history', {
    title: 'Ticket History',
    render: function (el, ctx) {
      el.innerHTML = M.card('Your tickets', '<div id="thT"></div>', { icon: 'history', actions: '<a class="mbtn gold sm" href="#/support/new">' + M.icon('plus') + 'New ticket</a>' });
      M.table(document.getElementById('thT'), {
        rows: Store.ticketsOf(ctx.me.id), dateKey: 'date', empty: 'Open a ticket if you need help.',
        columns: [
          { key: 'ref', label: 'Ticket', render: function (r) { return '<code>' + M.esc(r.ref) + '</code>'; } },
          { key: 'subject', label: 'Subject' },
          { key: 'date', label: 'Posted', render: function (r) { return M.dt(r.date); } },
          { key: 'last', label: 'Replies', align: 'r', render: function (r) { return r.replies.length; } },
          { key: 'status', label: 'Status', render: function (r) { return M.badge(r.status === 'open' ? 'Open' : 'Closed'); } },
          { key: 'v', label: '', align: 'r', render: function (r) { return '<button class="mbtn line sm" data-tk="' + r.id + '">' + M.icon('eye') + 'View</button>'; } }
        ]
      });
      el.addEventListener('click', function (e) {
        var b = e.target.closest('[data-tk]'); if (!b) return;
        var t = Store.db.tickets.find(function (x) { return String(x.id) === b.dataset.tk; });
        var msg = function (by, text, date) { return '<div class="m-msg ' + by + '"><b>' + (by === 'admin' ? 'Support team' : 'You') + '</b><p>' + M.esc(text) + '</p><small>' + M.dt(date) + '</small></div>'; };
        M.modal({
          title: t.ref + ' · ' + t.subject, wide: true,
          body: '<div class="m-thread">' + msg('user', t.message, t.date) + t.replies.map(function (r) { return msg(r.by, r.text, r.date); }).join('') + '</div>' +
            (t.status === 'open' ? '<div class="m-alert" hidden></div><textarea class="m-reply" rows="3" placeholder="Write a reply…"></textarea>' : '<p class="m-note">This ticket is closed. Open a new ticket if you need more help.</p>'),
          footer: t.status === 'open' ? '<button class="mbtn line" data-close>Close</button><button class="mbtn gold" data-send>' + M.icon('chat') + 'Send reply</button>' : '<button class="mbtn line" data-close>Close</button>',
          onMount: function (m, close) {
            var s = m.querySelector('[data-send]');
            if (s) s.onclick = function () {
              Store.replyTicket(t.id, 'user', m.querySelector('.m-reply').value).then(function (r) {
                if (!r.ok) return M.alert(m.querySelector('.m-alert'), r.error);
                close(); M.toast('Reply sent'); ctx.refresh();
              });
            };
          }
        });
      });
    }
  });
})();
