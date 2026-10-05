@extends('admin.layout')

@section('content')
<div class="card" style="max-width:380px;margin:60px auto">
    <h1 style="font-size:18px;margin-bottom:16px">Sign in</h1>
    <form method="POST" action="{{ route('login') }}">
        @csrf
        <p style="margin-bottom:12px"><input type="email" name="email" placeholder="Email" required style="width:100%" value="{{ old('email') }}"></p>
        <p style="margin-bottom:12px"><input type="password" name="password" placeholder="Password" required style="width:100%"></p>
        @error('email')<p style="color:#991b1b;font-size:13px;margin-bottom:8px">{{ $message }}</p>@enderror
        <button class="btn btn-primary" style="width:100%">Sign in</button>
    </form>
</div>
@endsection
