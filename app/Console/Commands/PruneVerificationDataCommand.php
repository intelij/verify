<?php

namespace App\Console\Commands;

use App\Enums\VerificationStatus;
use App\Models\Verification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Retention: deletes original documents after document_retention_days and
 * scrubs stored raw-OCR text after extracted_data_retention_days.
 */
class PruneVerificationDataCommand extends Command
{
    protected $signature = 'verification:prune {--dry-run}';
    protected $description = 'Apply retention policies to stored documents and extracted data';

    public function handle(): int
    {
        $docDays = (int) config('verification.retention.document_days', 90);
        $rawDays = (int) config('verification.retention.extracted_data_days', 180);
        $dry = $this->option('dry-run');
        $cut = now()->subDays($docDays);

        $docsDeleted = 0;
        Verification::where('completed_at', '<', $cut)
            ->whereHas('documents', fn ($q) => $q->where('status', '!=', 'purged'))
            ->with('documents')
            ->chunkById(100, function ($verifications) use ($dry, &$docsDeleted) {
                foreach ($verifications as $v) {
                    foreach ($v->documents as $doc) {
                        if ($doc->status === 'purged') {
                            continue;
                        }
                        if (! $dry) {
                            Storage::disk($doc->storage_disk)->delete($doc->storage_path);
                            $doc->update(['status' => 'purged']);
                        }
                        $docsDeleted++;
                    }
                }
            });

        $rawCut = now()->subDays($rawDays);
        $rawScrubbed = 0;
        \App\Models\DocumentExtraction::whereNotNull('raw_text_encrypted')
            ->where('created_at', '<', $rawCut)
            ->chunkById(100, function ($rows) use ($dry, &$rawScrubbed) {
                foreach ($rows as $row) {
                    if (! $dry) {
                        $row->update(['raw_text_encrypted' => null]);
                    }
                    $rawScrubbed++;
                }
            });

        // Expire verified records whose document expiry has passed.
        $expired = Verification::where('status', VerificationStatus::Verified->value)
            ->where('verified_until', '<', now())
            ->count();
        if (! $dry) {
            Verification::where('status', VerificationStatus::Verified->value)
                ->where('verified_until', '<', now())
                ->each(fn ($v) => $v->transitionTo(VerificationStatus::Expired));
        }

        $this->info(($dry ? '[dry-run] ' : '') . "documents purged: {$docsDeleted}, raw OCR scrubbed: {$rawScrubbed}, verifications expired: {$expired}");

        return self::SUCCESS;
    }
}
