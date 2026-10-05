@extends('admin.layout')

@section('content')
<div class="grid">
    <div class="stat"><div class="n">{{ $counts['verified'] ?? 0 }}</div><div class="l">Verified</div></div>
    <div class="stat"><div class="n">{{ $counts['review_required'] ?? 0 }}</div><div class="l">Awaiting review</div></div>
    <div class="stat"><div class="n">{{ $counts['failed'] ?? 0 }}</div><div class="l">Failed</div></div>
    <div class="stat"><div class="n">{{ ($counts['pending'] ?? 0) + ($counts['processing'] ?? 0) + ($counts['awaiting_documents'] ?? 0) }}</div><div class="l">In flight</div></div>
    <div class="stat"><div class="n">{{ $failedWebhooks }}</div><div class="l">Dead-letter webhooks</div></div>
</div>

<div class="card">
    <h2 style="font-size:16px;margin-bottom:12px">Recent verifications</h2>
    <table>
        <thead><tr><th>ID</th><th>Consumer</th><th>Subject ref</th><th>Policy</th><th>Status</th><th>Confidence</th><th>Created</th></tr></thead>
        <tbody>
        @forelse($recent as $v)
            <tr>
                <td class="mono"><a href="{{ route('admin.verifications.show', $v) }}">{{ $v->public_id }}</a></td>
                <td>{{ $v->consumer->name }}</td>
                <td class="mono">{{ $v->external_reference }}</td>
                <td>{{ $v->policy_code }}</td>
                <td><span class="badge b-{{ $v->status }}">{{ $v->status }}</span></td>
                <td>{{ $v->confidence !== null ? number_format($v->confidence, 2) : '—' }}</td>
                <td class="muted">{{ $v->created_at->diffForHumans() }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">No verifications yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
