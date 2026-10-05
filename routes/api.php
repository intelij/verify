<?php

use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\VerificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/verifications', [VerificationController::class, 'store']);
    Route::get('/verifications/{verification}', [VerificationController::class, 'show']);
    Route::get('/verifications/{verification}/result', [VerificationController::class, 'result']);
    Route::post('/verifications/{verification}/retry', [VerificationController::class, 'retry']);
    Route::post('/verifications/{verification}/cancel', [VerificationController::class, 'cancel']);
    Route::post('/verifications/{verification}/documents', [DocumentController::class, 'store']);
});
