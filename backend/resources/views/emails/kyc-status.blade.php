@extends('emails.layout')
@section('body')
@if($approved)
<h2 style="margin:0 0 6px 0;font-size:22px;color:#067647;">KYC approved ✔</h2>
<p style="margin:0 0 16px 0;color:#24324f;">Hi {{ $name }}, your identity verification is complete. You can now request withdrawals (Mon–Fri, within the withdrawal window).</p>
@else
<h2 style="margin:0 0 6px 0;font-size:22px;color:#b42318;">KYC needs attention</h2>
<p style="margin:0 0 16px 0;color:#24324f;">Hi {{ $name }}, we could not approve your documents.</p>
@if($note)<p style="margin:0 0 16px 0;background:#f4f6fb;border-radius:12px;padding:14px 18px;font-size:14px;"><b>Reason:</b> {{ $note }}</p>@endif
<p style="margin:0 0 16px 0;font-size:13px;color:#66748f;">Please log in, open <b>Account → KYC &amp; Verification</b> and upload clear, uncropped photos again.</p>
@endif
@endsection
