<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Verification;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    public function index(Request $request)
    {
        $query = Verification::with(['subject', 'consumer'])->latest();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($ref = $request->query('reference')) {
            $query->where('external_reference', 'like', '%' . $ref . '%');
        }
        if ($id = $request->query('id')) {
            $query->where('public_id', $id);
        }

        return view('admin.verifications.index', [
            'verifications' => $query->paginate(25)->withQueryString(),
        ]);
    }

    public function show(Verification $verification)
    {
        $verification->load([
            'subject', 'consumer', 'documents.extractions', 'fieldResults',
            'events', 'webhookDeliveries', 'reviews.reviewer', 'providerAttempts',
        ]);

        return view('admin.verifications.show', [
            'verification' => $verification,
        ]);
    }
}
