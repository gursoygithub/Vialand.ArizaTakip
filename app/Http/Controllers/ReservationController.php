<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReservationRequest;
use App\Models\Reservation;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
//    public function index()
//    {
//        $reservationForms = Reservation::all();
//        return view('reservation_forms.index', compact('reservationForms'));
//    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('forms/reservation');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ReservationRequest $request)
    {
        $validatedData = $request->validated();

        if ($validatedData){
            dd(request()->all());
        } else {
            dd("Form verileri doğrulanamadı.");
        }
        dd("bir şeyler oldu");


        Reservation::create($validatedData);
        return redirect()->route('reservation-form.create')->with('success', 'Form başarıyla kaydedildi.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Reservation $reservationForm)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Reservation $reservationForm)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Reservation $reservationForm)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Reservation $reservationForm)
    {
        //
    }
}
