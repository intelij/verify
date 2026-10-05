@extends('admin.layout')

@section('content')
<div class="card">
    <form method="GET" style="display:flex;gap:10px;margin-bottom:14px">
        <input type="text" name="id" placeholder="Verification ID" value="{{ request('id') }}">
        <input type="text" name="reference" placeholder="External reference" value="{{ request('reference') }}">
        <select name="status" style="padding:8px;border:1px solid #d1d5db;border-radius:6px">
            <option value="">All statuses</option>
            @foreach(['pending','awaiting_documents','processing','review_required','verified','partially_verified','failed','rejected','expired','error','cancelled'] as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>
            @endforeach
        </select>
        <button class="btn btn-primary">Filter</button>
    </form>
    <table>
        <thead><tr><th>ID</th><th>Consumer</th><th>Subject ref</th><th>Policy</th><th>Status</th><th>Confidence</th><th>Created</th></tr></thead>
        <tbody>
        @forelse($verifications as $v)
            <tr>
                <td class="mono"><a href="{{ route('admin.verifications.show', $v) }}">{{ $v->public_id }}</a></td>
                <td>{{ $v->consumer->name }}</td>
                <td class="mono">{{ $v->external_reference }}</td>
                <td>{{ $v->policy_code }}</td>
                <td><span class="badge b-{{ $v->status }}">{{ $v->status }}</span></td>
                <td>{{ $v->confidence !== null ? number_format($v->confidence, 2) : '—' }}</td>
                <td class="muted">{{ $v->created_at->format('Y-m-d H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">No matching verifications.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div style="margin-top:14px">{{ $verifications->links() }}</div>
</div>
@endsection
