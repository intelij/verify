<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReasonCode;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Models\Verification;
use App\Services\WebhookService;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index()
    {
        return view('admin.reviews.index', [
            'verifications' => Verification::with(['subject', 'consumer'])
                ->where('status', VerificationStatus::ReviewRequired->value)
                ->oldest()->paginate(25),
        ]);
    }

    public function store(Request $request, Verification $verification, WebhookService $webhooks)
    {
        if ($verification->status !== VerificationStatus::ReviewRequired->value) {
            return back()->withErrors(['review' => 'Verification is not awaiting review.']);
        }

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $verification->reviews()->create([
            'reviewer_id' => $request->user()->id,
            'decision' => $data['decision'],
            'notes' => $data['notes'] ?? null,
        ]);

        $status = $data['decision'] === 'approve'
            ? VerificationStatus::Verified : VerificationStatus::Rejected;
        $reason = $data['decision'] === 'approve'
            ? ReasonCode::MANUAL_REVIEW_APPROVED : ReasonCode::MANUAL_REVIEW_REJECTED;

        $verification->transitionTo($status, array_merge($verification->reason_codes ?? [], [$reason]));
        $verification->recordEvent('review.completed', [
            'decision' => $data['decision'],
        ], 'admin', (string) $request->user()->id);

        $webhooks->dispatch($verification, 'verification.completed');

        return redirect()->route('admin.verifications.show', $verification)
            ->with('status', 'Review recorded.');
    }
}
