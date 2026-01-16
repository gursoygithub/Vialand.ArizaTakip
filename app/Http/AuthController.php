<?php

namespace App\Http;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuthRequest;
use App\Services\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function __construct(protected AuthService $authService)
    {
    }

    public function showLoginForm(): View
    {
        return view('auth.login');
    }

    public function login(AuthRequest $request): RedirectResponse
    {
        $result = $this->authService->login($request->validated());

        if (!$result['success']) {
            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => $result['message']]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('filament.pages.auth'));
    }
}
