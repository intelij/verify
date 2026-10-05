<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Models\Verification;
use App\Models\WebhookDelivery;

class DashboardController extends Controller
{
    public function index()
    {
        $counts = Verification::selectRaw('status, COUNT(*) as c')
            ->groupBy('status')->pluck('c', 'status');

        return view('admin.dashboard', [
            'counts' => $counts,
            'recent' => Verification::with(['subject', 'consumer'])
                ->latest()->limit(15)->get(),
            'reviewQueue' => Verification::where('status', VerificationStatus::ReviewRequired->value)->count(),
            'failedWebhooks' => WebhookDelivery::where('status', 'dead_letter')->count(),
        ]);
    }
}
