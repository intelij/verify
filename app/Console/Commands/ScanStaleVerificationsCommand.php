<?php

namespace App\Console\Commands;

use App\Enums\VerificationStatus;
use App\Jobs\ProcessVerificationJob;
use App\Models\Verification;
use Illuminate\Console\Command;

/**
 * Reconciliation on our side: re-dispatch stuck jobs and cancel uploads
 * that never arrived.
 */
class ScanStaleVerificationsCommand extends Command
{
    protected $signature = 'verification:scan-stale';
    protected $description = 'Re-dispatch stuck processing jobs and expire stale awaiting_documents verifications';

    public function handle(): int
    {
        $staleAfter = (int) config('verification.stale_processing_minutes', 30);

        $redispatched = 0;
        Verification::where('status', VerificationStatus::Pending->value)
            ->where('created_at', '<', now()->subMinutes(5))
            ->each(function ($v) use (&$redispatched) {
                ProcessVerificationJob::dispatch($v->id);
                $v->recordEvent('processing.redispatched');
                $redispatched++;
            });

        $cancelled = Verification::where('status', VerificationStatus::AwaitingDocuments->value)
            ->where('documents_due_at', '<', now())
            ->count();
        Verification::where('status', VerificationStatus::AwaitingDocuments->value)
            ->where('documents_due_at', '<', now())
            ->each(function ($v) {
                $v->transitionTo(VerificationStatus::Expired, ['REUPLOAD_REQUIRED']);
                $v->recordEvent('verification.expired', ['reason' => 'documents_not_received']);
            });

        $this->info("re-dispatched: {$redispatched}, expired awaiting documents: {$cancelled}");

        return self::SUCCESS;
    }
}
