<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DocumentController as AdminDocumentController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Admin\VerificationController as AdminVerificationController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('admin.dashboard'));

Route::middleware('guest')->group(function () {
    Route::get('/login', fn () => view('admin.login'))->name('login');
    Route::post('/login', function (\Illuminate\Http\Request $request) {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);
        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            return redirect()->intended(route('admin.dashboard'));
        }
        return back()->withErrors(['email' => 'Invalid credentials.'])->onlyInput('email');
    });
});

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/verifications', [AdminVerificationController::class, 'index'])->name('verifications.index');
    Route::get('/verifications/{verification}', [AdminVerificationController::class, 'show'])->name('verifications.show');
    Route::get('/reviews', [ReviewController::class, 'index'])->name('reviews.index');
    Route::post('/verifications/{verification}/review', [ReviewController::class, 'store'])->name('reviews.store');
    Route::get('/documents/{document}/view', [AdminDocumentController::class, 'view'])->name('documents.view');
    Route::post('/logout', function (\Illuminate\Http\Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    })->name('logout');
});
