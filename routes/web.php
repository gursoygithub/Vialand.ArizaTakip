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

// Örnek test route
//Route::get('/test-upload', function () {
//    $task = App\Models\Task::first(); // test için var olan task
//    $task->addMediaFromUrl('https://trs3.cloudspark.com.tr/arizatakip/kk3.jpg')
//        ->toMediaCollection('task_attachments', 's3');
//    return 'Upload tamam!';
//});

Route::prefix('auth')
    ->controller(AuthController::class)
    ->middleware('guest')
    ->group(function () {
        Route::get('/', 'showLoginForm')->name('login');
        Route::post('/', 'login')->name('login.submit');
    });

/**
 * Lightweight unread-count endpoint for the desktop-notification poller in
 * resources/views/filament/notifications-js.blade.php. Filament's Livewire
 * components don't expose unreadNotificationsCount via window.Livewire.find
 * in this build, so the JS reads the count directly from this route every
 * 5s. Auth-gated; returns 0 for guests.
 */
Route::get('/api/notifications/unread-count', function () {
    if (!auth()->check()) {
        return response()->json(['count' => 0]);
    }
    return response()->json([
        'count' => auth()->user()->unreadNotifications()->count(),
    ]);
})->middleware(['web', 'auth'])->name('api.notifications.unread-count');

//Route::controller(AuthController::class)
//    ->middleware('guest')
//    ->group(function () {
//        Route::get('/', 'showLoginForm')->name('login');
//        Route::post('/', 'login')->name('login.submit');
//    });

