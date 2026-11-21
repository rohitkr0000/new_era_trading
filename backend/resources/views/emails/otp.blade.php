@extends('emails.layout')
@section('body')
<h2 style="margin:0 0 6px 0;font-size:22px;">Your verification code</h2>
<p style="margin:0 0 16px 0;color:#24324f;">Hi {{ $name }}, use this one-time code to confirm your <b>{{ $purpose }}</b>.</p>
<div style="text-align:center;margin:0 0 18px 0;">
  <div style="display:inline-block;background:#061230;color:#ffd95c;border-radius:14px;padding:16px 34px;font-family:Consolas,monospace;font-size:34px;font-weight:800;letter-spacing:.35em;">{{ $code }}</div>
</div>
<p style="margin:0 0 6px 0;text-align:center;font-size:14px;">This code expires in <b>{{ $minutes }} minutes</b> and can be used once.</p>
<p style="margin:0 0 16px 0;text-align:center;font-size:13px;color:#b42318;"><b>Never share this code with anyone</b> — including people who say they work for New Era Trading.</p>
<p style="margin:0 0 16px 0;font-size:13px;color:#66748f;">If you did not request this code, ignore this e-mail and change your password — someone may know your login.</p>
@endsection
