<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>New Era Trading</title>
</head>
<body style="margin:0;padding:0;background:#eef2fb;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0b1736;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2fb;padding:24px 12px;">
<tr><td align="center">
  <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 8px 30px rgba(6,18,48,.12);">
    <tr><td style="background:#061230;background-image:linear-gradient(120deg,#040c24,#0b2a6f);padding:22px 28px;border-bottom:3px solid #f5b800;">
      <table role="presentation" cellpadding="0" cellspacing="0"><tr>
        <td style="vertical-align:middle;padding-right:12px;"><img src="{{ $message->embed(public_path('mail-logo.png')) }}" width="46" height="46" alt="NET" style="display:block;border:0;"></td>
        <td style="vertical-align:middle;">
          <div style="font-size:20px;font-weight:800;font-style:italic;color:#ffffff;line-height:1.1;">New Era Trading</div>
          <div style="font-size:10px;letter-spacing:.22em;color:#ffd95c;text-transform:uppercase;margin-top:3px;">neweratrading.net</div>
        </td>
      </tr></table>
    </td></tr>
    <tr><td style="padding:30px 28px 8px 28px;font-size:15px;line-height:1.65;">
      @yield('body')
    </td></tr>
    <tr><td style="padding:8px 28px 26px 28px;">
      <div style="border-top:1px solid #e4e8f2;padding-top:16px;font-size:12px;line-height:1.6;color:#66748f;">
        Need help? Write to <a href="mailto:{{ $support }}" style="color:#1e6cff;">{{ $support }}</a> or open a ticket in your member hub.<br>
        New Era Trading will <b>never</b> ask for your password, OTP or transaction PIN by e-mail, phone or chat.<br>
        This is an automatic message from <b>New Era Trading</b> — please do not reply to this address.
      </div>
    </td></tr>
  </table>
  <div style="max-width:560px;margin-top:14px;font-size:11px;color:#8a97b3;line-height:1.5;">© {{ date('Y') }} New Era Trading · neweratrading.net<br>Earnings depend on your activity and are not guaranteed. Past results do not promise future income.</div>
</td></tr>
</table>
</body>
</html>
