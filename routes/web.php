<?php

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
