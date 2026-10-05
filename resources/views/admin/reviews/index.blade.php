@extends('admin.layout')

@section('content')
<div class="card">
    <h2 style="font-size:16px;margin-bottom:12px">Review queue</h2>
    <table>
        <thead><tr><th>ID</th><th>Consumer</th><th>Subject ref</th><th>Policy</th><th>Confidence</th><th>Reasons</th><th>Created</th></tr></thead>
        <tbody>
        @forelse($verifications as $v)
            <tr>
                <td class="mono"><a href="{{ route('admin.verifications.show', $v) }}">{{ $v->public_id }}</a></td>
                <td>{{ $v->consumer->name }}</td>
                <td class="mono">{{ $v->external_reference }}</td>
                <td>{{ $v->policy_code }}</td>
                <td>{{ $v->confidence !== null ? number_format($v->confidence, 2) : '—' }}</td>
                <td class="muted">{{ implode(', ', $v->reason_codes ?? []) }}</td>
                <td class="muted">{{ $v->created_at->diffForHumans() }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">Queue is empty.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div style="margin-top:14px">{{ $verifications->links() }}</div>
</div>
@endsection
