<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'SchoolRun Verify')</title>
    <style>
        * { box-sizing: border-box; margin: 0; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: #f4f5f7; color: #17202a; }
        header { background: #111827; color: #fff; padding: 14px 24px; display: flex; gap: 24px; align-items: center; }
        header a { color: #cbd5e1; text-decoration: none; font-size: 14px; }
        header a:hover { color: #fff; }
        header .brand { font-weight: 700; color: #fff; }
        main { max-width: 1200px; margin: 24px auto; padding: 0 24px; }
        .card { background: #fff; border-radius: 8px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eef0f3; }
        th { color: #6b7280; font-weight: 600; font-size: 12px; text-transform: uppercase; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .b-verified { background: #dcfce7; color: #166534; }
        .b-failed, .b-rejected, .b-error { background: #fee2e2; color: #991b1b; }
        .b-review_required { background: #fef9c3; color: #854d0e; }
        .b-processing, .b-pending, .b-awaiting_documents { background: #dbeafe; color: #1e40af; }
        .b-partially_verified { background: #e0e7ff; color: #3730a3; }
        .b-cancelled, .b-expired { background: #e5e7eb; color: #374151; }
        .btn { display: inline-block; padding: 8px 16px; border: 0; border-radius: 6px; cursor: pointer; font-size: 14px; text-decoration: none; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-danger { background: #dc2626; color: #fff; }
        .btn-ghost { background: #e5e7eb; color: #111827; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 16px; }
        .stat { background: #fff; border-radius: 8px; padding: 16px; }
        .stat .n { font-size: 26px; font-weight: 700; }
        .stat .l { font-size: 12px; color: #6b7280; text-transform: uppercase; }
        .timeline li { margin-bottom: 6px; font-size: 13px; }
        .muted { color: #6b7280; font-size: 13px; }
        .mono { font-family: ui-monospace, monospace; font-size: 12px; }
        form.inline { display: inline; }
        textarea { width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; }
        input[type=text], input[type=email], input[type=password] { padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
        .flash { background: #dcfce7; padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; color: #166534; }
    </style>
</head>
<body>
    <header>
        <span class="brand">SchoolRun Verify</span>
        @auth
            <a href="{{ route('admin.dashboard') }}">Dashboard</a>
            <a href="{{ route('admin.verifications.index') }}">Verifications</a>
            <a href="{{ route('admin.reviews.index') }}">Review Queue</a>
            <form class="inline" method="POST" action="{{ route('admin.logout') }}" style="margin-left:auto">
                @csrf
                <button class="btn btn-ghost" style="font-size:12px">Sign out</button>
            </form>
        @endauth
    </header>
    <main>
        @if (session('status'))
            <div class="flash">{{ session('status') }}</div>
        @endif
        @yield('content')
    </main>
</body>
</html>
