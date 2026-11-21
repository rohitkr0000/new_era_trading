@extends('emails.layout')
@section('body')
@if($sent)
<h2 style="margin:0 0 6px 0;font-size:22px;">P2P transfer completed</h2>
<p style="margin:0 0 16px 0;color:#24324f;">Hi {{ $name }}, you sent <b>{{ $amount }}</b> to <b>{{ $other }}</b>.</p>
@else
<h2 style="margin:0 0 6px 0;font-size:22px;color:#067647;">You received a P2P transfer</h2>
<p style="margin:0 0 16px 0;color:#24324f;">Hi {{ $name }}, <b>{{ $other }}</b> sent you <b>{{ $net }}</b>. It is in your purchase wallet.</p>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;border-radius:12px;margin:0 0 18px 0;">
  <tr><td style="padding:14px 18px;font-size:14px;line-height:2;">
    <b>Amount sent:</b> {{ $amount }}<br>
    <b>Company charge:</b> {{ $fee }}<br>
    <b>Received:</b> {{ $net }}<br>
    <b>When:</b> {{ $when }}
  </td></tr>
</table>
@if($sent)<p style="margin:0 0 16px 0;font-size:13px;color:#b42318;"><b>Was this not you?</b> Contact {{ $support }} immediately and change your password.</p>@endif
@endsection
