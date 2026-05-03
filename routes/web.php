<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ReservationController;
use Illuminate\Support\Facades\Route;


//Route::get('/reservation-form', function () {
//    return view('forms/reservation_forms');
//});
//
Route::post("/reservation-form/store", [ReservationController::class, 'store'])
    ->name('reservation-form.store');

Route::get('/reservation-form/create', [ReservationController::class, 'create'])
    ->name('reservation-form.create');

Route::prefix('auth')
    ->controller(AuthController::class)
    ->middleware('guest')
    ->group(function () {
        Route::get('/', 'showLoginForm')->name('login');
        Route::post('/', 'login')->name('login.submit');
    });

/**
 * Lightweight unread-count endpoint kept for any client that wants to
 * poll the bell badge count. The realtime sound + desktop notification
 * pipeline now runs through FCM (resources/views/filament/fcm-init.blade.php)
 * instead of polling, but Filament's own bell still updates via its
 * databaseNotificationsPolling tick.
 */
Route::get('/api/notifications/unread-count', function () {
    if (!auth()->check()) {
        return response()->json(['count' => 0]);
    }
    return response()->json([
        'count' => auth()->user()->unreadNotifications()->count(),
    ]);
})->middleware(['web', 'auth'])->name('api.notifications.unread-count');

/**
 * FCM token registration. Frontend posts the token returned by
 * getToken() in fcm-init.blade.php; we upsert it into fcm_tokens
 * (idempotent on re-registration, also reactivates a previously
 * deactivated token if the same browser reconnects).
 */
Route::post('/fcm/token', function (\Illuminate\Http\Request $request) {
    if (!auth()->check()) {
        return response()->json(['ok' => false, 'reason' => 'unauthenticated'], 401);
    }

    $token = (string) $request->input('token');
    if ($token === '') {
        return response()->json(['ok' => false, 'reason' => 'token required'], 422);
    }

    \App\Models\FcmToken::upsertForUser(auth()->id(), $token);

    return response()->json(['ok' => true]);
})->middleware(['web', 'auth'])->name('fcm.token.register');

//Route::controller(AuthController::class)
//    ->middleware('guest')
//    ->group(function () {
//        Route::get('/', 'showLoginForm')->name('login');
//        Route::post('/', 'login')->name('login.submit');
//    });

