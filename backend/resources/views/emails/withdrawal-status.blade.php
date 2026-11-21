@extends('emails.layout')
@section('body')
@if($approved)
<h2 style="margin:0 0 6px 0;font-size:22px;color:#067647;">Withdrawal approved ✔</h2>
<p style="margin:0 0 16px 0;color:#24324f;">Hi {{ $name }}, your withdrawal <b>{{ $ref }}</b> has been approved and sent.</p>
@else
<h2 style="margin:0 0 6px 0;font-size:22px;color:#b42318;">Withdrawal rejected</h2>
<p style="margin:0 0 16px 0;color:#24324f;">Hi {{ $name }}, your withdrawal <b>{{ $ref }}</b> could not be processed. The full amount has been <b>refunded to your income wallet</b>.</p>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;border-radius:12px;margin:0 0 18px 0;">
  <tr><td style="padding:14px 18px;font-size:14px;line-height:2;">
    <b>Reference:</b> {{ $ref }}<br>
    <b>Amount:</b> {{ $amount }}<br>
    @if($approved)<b>Paid to you:</b> <span style="color:#067647;"><b>{{ $net }}</b></span><br>@endif
    <b>Method:</b> {{ $method }}<br>
    <b>To:</b> <span style="font-family:Consolas,monospace;">{{ $account }}</span><br>
    @if($approved && ! empty($txid))<b>Payout transaction:</b> <span style="font-family:Consolas,monospace;word-break:break-all;">{{ $txid }}</span><br>@endif
    @if(! $approved && ! empty($note))<b>Reason:</b> {{ $note }}<br>@endif
    <b>Processed:</b> {{ $when }}
  </td></tr>
</table>
@if(! $approved)<p style="margin:0 0 16px 0;font-size:13px;color:#66748f;">Please check your payout address and try again, or open a support ticket if you need help.</p>@endif
@endsection
