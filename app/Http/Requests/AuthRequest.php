<?php

namespace App\Http\Requests;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthRequest extends FormRequest
{
    /**
     * Failed attempts allowed for one username from one IP address.
     */
    public const MAX_ATTEMPTS_PER_USERNAME_AND_IP = 5;

    /**
     * Lockout duration for the username + IP limiter, in seconds.
     */
    public const USERNAME_AND_IP_DECAY_SECONDS = 60;

    /**
     * Failed attempts allowed for one username across all IP addresses.
     * Kept below a typical Active Directory lockout threshold so a
     * distributed attack is stopped here before AD locks the real account.
     */
    public const MAX_ATTEMPTS_PER_USERNAME = 10;

    /**
     * Lockout duration for the username-wide limiter, in seconds.
     */
    public const USERNAME_DECAY_SECONDS = 900;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => 'required|regex:/^[a-zA-Z0-9_.]+$/',
            'password' => 'required',
        ];
    }

    public function messages(): array
    {
        return [
            'username.regex' => __('ui.username_regex'),
        ];
    }

    /**
     * Reject the attempt when either limiter is exhausted.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $limits = [
            $this->usernameAndIpThrottleKey() => self::MAX_ATTEMPTS_PER_USERNAME_AND_IP,
            $this->usernameThrottleKey() => self::MAX_ATTEMPTS_PER_USERNAME,
        ];

        foreach ($limits as $key => $maxAttempts) {
            if (! RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                continue;
            }

            event(new Lockout($this));

            throw ValidationException::withMessages([
                'username' => __('ui.login_throttled', [
                    'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60),
                ]),
            ]);
        }
    }

    /**
     * Count one failed login attempt against both limiters.
     */
    public function recordFailedAttempt(): void
    {
        RateLimiter::hit($this->usernameAndIpThrottleKey(), self::USERNAME_AND_IP_DECAY_SECONDS);
        RateLimiter::hit($this->usernameThrottleKey(), self::USERNAME_DECAY_SECONDS);
    }

    /**
     * Reset both limiters after a successful login.
     */
    public function clearFailedAttempts(): void
    {
        RateLimiter::clear($this->usernameAndIpThrottleKey());
        RateLimiter::clear($this->usernameThrottleKey());
    }

    public function usernameAndIpThrottleKey(): string
    {
        return 'login:username-ip:'.$this->normalizedUsername().'|'.$this->ip();
    }

    public function usernameThrottleKey(): string
    {
        return 'login:username:'.$this->normalizedUsername();
    }

    private function normalizedUsername(): string
    {
        return Str::transliterate(Str::lower((string) $this->input('username')));
    }
}
