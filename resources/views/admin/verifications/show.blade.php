@extends('admin.layout')

@section('content')
@php $v = $verification; @endphp

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:start">
        <div>
            <h1 style="font-size:18px" class="mono">{{ $v->public_id }}</h1>
            <p class="muted">{{ $v->consumer->name }} · {{ $v->policy_code }} · ref <span class="mono">{{ $v->external_reference }}</span></p>
        </div>
        <span class="badge b-{{ $v->status }}" style="font-size:14px">{{ $v->status }}</span>
    </div>
    @if($v->reason_codes)
        <p style="margin-top:10px"><strong>Reasons:</strong> {{ implode(', ', $v->reason_codes) }}</p>
    @endif
    <p class="muted" style="margin-top:6px">
        Confidence: {{ $v->confidence !== null ? number_format($v->confidence, 3) : '—' }} ·
        Created {{ $v->created_at->format('Y-m-d H:i:s') }} ·
        Completed {{ $v->completed_at?->format('Y-m-d H:i:s') ?? '—' }} ·
        Valid until {{ $v->verified_until?->format('Y-m-d') ?? '—' }}
    </p>
</div>

<div class="card">
    <h2 style="font-size:15px;margin-bottom:10px">Subject</h2>
    <table>
        <tr><th>Name</th><td>{{ $v->subject->first_name }} {{ $v->subject->middle_names }} {{ $v->subject->surname }}</td></tr>
        <tr><th>External ID</th><td class="mono">{{ $v->subject->external_id }}</td></tr>
        <tr><th>DOB</th><td>{{ $v->subject->date_of_birth?->toDateString() ?? '—' }}</td></tr>
        <tr><th>Country</th><td>{{ $v->subject->country ?? '—' }}</td></tr>
        <tr><th>National ID</th><td class="mono">{{ \App\Support\PiiMasker::mask($v->subject->national_id_number) ?? '—' }}</td></tr>
        <tr><th>Licence</th><td class="mono">{{ \App\Support\PiiMasker::mask($v->subject->driver_license_number) ?? '—' }}</td></tr>
    </table>
</div>

<div class="card">
    <h2 style="font-size:15px;margin-bottom:10px">Field results</h2>
    <table>
        <thead><tr><th>Field</th><th>Expected</th><th>Match</th><th>Confidence</th><th>Reason</th></tr></thead>
        <tbody>
        @forelse($v->fieldResults as $r)
            <tr>
                <td>{{ $r->field }}</td>
                <td class="mono">{{ $r->expected_masked ?? '—' }}</td>
                <td>{{ $r->match === null ? '—' : ($r->match ? 'MATCH' : 'MISMATCH') }}</td>
                <td>{{ $r->confidence !== null ? number_format($r->confidence, 2) : '—' }}</td>
                <td class="muted">{{ $r->reason_code }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">No field results yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="card">
    <h2 style="font-size:15px;margin-bottom:10px">Documents</h2>
    <table>
        <thead><tr><th>ID</th><th>Declared</th><th>Detected</th><th>MIME</th><th>Quality</th><th>Extractions</th><th></th></tr></thead>
        <tbody>
        @forelse($v->documents as $d)
            <tr>
                <td class="mono">{{ $d->public_id }}</td>
                <td>{{ $d->declared_type ?? '—' }}</td>
                <td>{{ $d->detected_type ?? '—' }}{{ $d->detected_country ? ' (' . $d->detected_country . ')' : '' }}</td>
                <td class="muted">{{ $d->mime_type }}</td>
                <td>{{ $d->quality_score !== null ? number_format($d->quality_score, 2) : '—' }}
                    @if($d->quality && !empty($d->quality['problems']))
                        <br><span class="muted">{{ implode('; ', $d->quality['problems']) }}</span>
                    @endif
                </td>
                <td>
                    @foreach($d->extractions as $e)
                        <div class="muted">{{ $e->provider }} · conf {{ number_format($e->extraction_confidence ?? 0, 2) }}</div>
                    @endforeach
                </td>
                <td><a class="btn btn-ghost" href="{{ route('admin.documents.view', $d) }}" target="_blank">View</a></td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">No documents attached.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if($v->status === 'review_required')
<div class="card">
    <h2 style="font-size:15px;margin-bottom:10px">Manual review</h2>
    <form method="POST" action="{{ route('admin.reviews.store', $v) }}">
        @csrf
        <p style="margin-bottom:10px"><textarea name="notes" rows="2" placeholder="Reviewer notes (optional)"></textarea></p>
        <button class="btn btn-primary" name="decision" value="approve">Approve → Verified</button>
        <button class="btn btn-danger" name="decision" value="reject">Reject</button>
    </form>
</div>
@endif

@if($v->reviews->isNotEmpty())
<div class="card">
    <h2 style="font-size:15px;margin-bottom:10px">Reviews</h2>
    @foreach($v->reviews as $r)
        <p class="muted">{{ $r->created_at->format('Y-m-d H:i') }} — <strong>{{ $r->decision }}</strong> by {{ $r->reviewer->name ?? 'admin' }} {{ $r->notes ? '— ' . $r->notes : '' }}</p>
    @endforeach
</div>
@endif

<div class="card">
    <h2 style="font-size:15px;margin-bottom:10px">Timeline</h2>
    <ul class="timeline">
        @foreach($v->events as $e)
            <li><span class="mono muted">{{ $e->created_at->format('H:i:s') }}</span> — <strong>{{ $e->event }}</strong>
                @if($e->actor_type !== 'system') <span class="muted">({{ $e->actor_type }})</span> @endif
                @if($e->data) <span class="muted mono">{{ json_encode(\App\Support\PiiMasker::maskArray($e->data)) }}</span> @endif
            </li>
        @endforeach
    </ul>
</div>

<div class="card">
    <h2 style="font-size:15px;margin-bottom:10px">Webhooks</h2>
    <table>
        <thead><tr><th>Event</th><th>Status</th><th>Attempts</th><th>Last code</th><th>Next attempt</th></tr></thead>
        <tbody>
        @forelse($v->webhookDeliveries as $w)
            <tr>
                <td>{{ $w->event }}</td>
                <td>{{ $w->status }}</td>
                <td>{{ $w->attempts }}/{{ $w->max_attempts }}</td>
                <td>{{ $w->last_response_code ?? '—' }}</td>
                <td class="muted">{{ $w->next_attempt_at?->format('H:i:s') ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">No webhook deliveries.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="card">
    <h2 style="font-size:15px;margin-bottom:10px">Provider attempts</h2>
    <table>
        <thead><tr><th>Provider</th><th>Op</th><th>Status</th><th>Duration</th><th>Error</th><th>When</th></tr></thead>
        <tbody>
        @forelse($v->providerAttempts as $p)
            <tr>
                <td>{{ $p->provider }}</td>
                <td>{{ $p->operation }}</td>
                <td>{{ $p->status }}</td>
                <td>{{ $p->duration_ms ? $p->duration_ms . 'ms' : '—' }}</td>
                <td class="muted">{{ $p->error_code ?? '—' }}</td>
                <td class="muted">{{ $p->created_at->format('H:i:s') }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">None recorded.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
