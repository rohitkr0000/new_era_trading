@extends('emails.layout')
@section('body')
<h2 style="margin:0 0 6px 0;font-size:22px;">Withdrawal request received</h2>
<p style="margin:0 0 16px 0;color:#24324f;">Hi {{ $name }}, we have received your withdrawal request. It will be processed and should reach you within <b>{{ $hours }} hours</b>.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;border-radius:12px;margin:0 0 18px 0;">
  <tr><td style="padding:14px 18px;font-size:14px;line-height:2;">
    <b>Reference:</b> {{ $ref }}<br>
    <b>Amount:</b> {{ $amount }}<br>
    @if((float) ltrim($fee, '$') > 0)<b>Service charge:</b> {{ $fee }}<br>@endif
    <b>You will receive:</b> <span style="color:#067647;"><b>{{ $net }}</b></span><br>
    <b>Method:</b> {{ $method }}<br>
    <b>To:</b> <span style="font-family:Consolas,monospace;">{{ $account }}</span><br>
    <b>Requested:</b> {{ $when }}<br>
    <b>Expected by:</b> {{ $due }}
  </td></tr>
</table>
<p style="margin:0 0 16px 0;font-size:13px;color:#b42318;"><b>Did not make this request?</b> Contact {{ $support }} immediately and change your password and transaction PIN.</p>
@endsection
