@extends('emails.layout')
@section('body')
<h2 style="margin:0 0 6px 0;font-size:22px;">Welcome, {{ $name }}! 🎉</h2>
<p style="margin:0 0 16px 0;color:#24324f;">Your New Era Trading account has been created successfully. Here are your details:</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;border-radius:12px;margin:0 0 18px 0;">
  <tr><td style="padding:14px 18px;font-size:14px;line-height:2;">
    <b>Member ID:</b> <span style="font-family:Consolas,monospace;font-size:16px;color:#0f3d9e;"><b>{{ $code }}</b></span><br>
    <b>Registered mobile:</b> {{ $phone }}<br>
    @if($sponsor)<b>Your sponsor:</b> {{ $sponsor }}<br>@endif
  </td></tr>
</table>
<p style="margin:0 0 6px 0;"><b>Quick facts</b></p>
<ul style="margin:0 0 20px 18px;padding:0;color:#24324f;">
  <li>Start with just <b>{{ $minJoin }}</b> — joining and retopup amounts are in multiples of $5.</li>
  <li>Withdrawals: Monday–Friday, <b>{{ $window }}</b> (never on Saturday or Sunday).</li>
  <li>Set your <b>transaction PIN</b> in the member hub before your first purchase or withdrawal.</li>
</ul>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px 0;"><tr><td style="background:#f5b800;border-radius:10px;">
  <a href="{{ $loginUrl }}" style="display:inline-block;padding:12px 26px;font-weight:700;color:#1a1200;text-decoration:none;">Open my dashboard →</a>
</td></tr></table>
<p style="margin:0 0 16px 0;font-size:13px;color:#66748f;">Your personal referral link:<br><a href="{{ $link }}" style="color:#1e6cff;word-break:break-all;">{{ $link }}</a></p>
@endsection
