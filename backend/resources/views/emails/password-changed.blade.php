@extends('emails.layout')
@section('body')
@if(! empty($reset))
<h2 style="margin:0 0 6px 0;font-size:22px;">Your password was reset</h2>
<p style="margin:0 0 16px 0;color:#24324f;">Hi {{ $name }}, the password of your New Era Trading account was reset with an e-mailed code on <b>{{ $when }}</b>. All your devices have been signed out.</p>
@else
<h2 style="margin:0 0 6px 0;font-size:22px;">Your password was changed</h2>
<p style="margin:0 0 16px 0;color:#24324f;">Hi {{ $name }}, the password of your New Era Trading account was changed on <b>{{ $when }}</b>. You have been signed out of your other devices.</p>
@endif
<p style="margin:0 0 16px 0;font-size:13px;color:#b42318;"><b>Was this not you?</b> Contact {{ $support }} immediately so we can secure your account.</p>
@endsection
