<?php

namespace App\Enums;

enum VerificationStatus: string
{
    case Pending = 'pending';
    case AwaitingDocuments = 'awaiting_documents';
    case Processing = 'processing';
    case ReviewRequired = 'review_required';
    case Verified = 'verified';
    case PartiallyVerified = 'partially_verified';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Error = 'error';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Verified, self::PartiallyVerified, self::Failed,
            self::Rejected, self::Expired, self::Error, self::Cancelled,
        ], true);
    }

    public static function fromString(string $status): self
    {
        return self::from($status);
    }
}
