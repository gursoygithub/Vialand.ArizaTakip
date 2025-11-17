<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReservationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'surname' => 'required|string|max:255',
            'email' => 'required|email|max:50',
            'phone' => ['required', 'regex:/^0\d{9}$/'],
            'visit_date' => 'required|date',
            'guests' => 'nullable|array',
            'guests.*.name' => 'required_with:guests|string|max:255',
            'guests.*.surname' => 'required_with:guests|string|max:255',
            'guests.*.tc_no' => 'required_with:guests|numeric|digits:11',
        ];
    }

    /**
     * Get custom attribute names for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'Ad',
            'surname' => 'Soyad',
            'email' => 'Email',
            'phone' => 'Telefon',
            'visit_date' => 'Ziyaret Tarihi',
            'guests.*.name' => 'Misafir Adı',
            'guests.*.surname' => 'Misafir Soyadı',
            'guests.*.tc_no' => 'Misafir TC No',
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Ad alanı zorunludur.',
            'surname.required' => 'Soyad alanı zorunludur.',
            'email.required' => 'Email alanı zorunludur.',
            'email.email' => 'Geçerli bir email adresi girin.',
            'phone.required' => 'Telefon alanı zorunludur.',
            'phone.regex' => 'Telefon numarası geçerli bir formatta olmalıdır. (Örnek: 05321234567)',
            'visit_date.required' => 'Ziyaret tarihi alanı zorunludur.',
            'guests.*.name.required_with' => 'Misafir adı zorunludur.',
            'guests.*.surname.required_with' => 'Misafir soyadı zorunludur.',
            'guests.*.tc_no.required_with' => 'Misafir TC No zorunludur.',
            'guests.*.tc_no.numeric' => 'Misafir TC No sadece sayılardan oluşmalıdır.',
            'guests.*.tc_no.digits' => 'Misafir TC No 11 haneli olmalıdır.',
        ];
    }
}
