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

