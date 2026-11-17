<?php

namespace Tests\Feature;

use App\Http\Requests\AuthRequest;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Login brute-force protection on AuthController (/auth).
 *
 * AuthService is mocked so no LDAP server is needed: the tests only care
 * about how many attempts reach the service and what the user is told.
 */
class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function mockFailingLogin(int $expectedCalls): void
    {
        $this->mock(AuthService::class, function (MockInterface $mock) use ($expectedCalls): void {
            $mock->shouldReceive('login')
                ->times($expectedCalls)
                ->andReturn(['success' => false, 'message' => __('ui.invalid_credentials')]);
        });
    }

    private function attemptLogin(string $username, string $ip = '10.0.0.1'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->from(route('login'))
            ->post(route('login.submit'), [
                'username' => $username,
                'password' => 'wrong-password',
            ]);
    }

    private function throttledMessage(): string
    {
        return __('ui.login_throttled', ['minutes' => 1]);
    }

    public function test_failed_attempts_below_the_limit_are_passed_to_the_auth_service(): void
    {
        $this->mockFailingLogin(AuthRequest::MAX_ATTEMPTS_PER_USERNAME_AND_IP);

        for ($i = 0; $i < AuthRequest::MAX_ATTEMPTS_PER_USERNAME_AND_IP; $i++) {
            $this->attemptLogin('ali.veli')
                ->assertRedirect(route('login'))
                ->assertSessionHasErrors(['username' => __('ui.invalid_credentials')]);
        }
    }

    public function test_attempt_after_the_username_and_ip_limit_is_blocked_before_ldap(): void
    {
        Event::fake([Lockout::class]);
        $this->mockFailingLogin(AuthRequest::MAX_ATTEMPTS_PER_USERNAME_AND_IP);

        for ($i = 0; $i < AuthRequest::MAX_ATTEMPTS_PER_USERNAME_AND_IP; $i++) {
            $this->attemptLogin('ali.veli');
        }

        $this->attemptLogin('ali.veli')
            ->assertSessionHasErrors(['username' => $this->throttledMessage()]);

        Event::assertDispatched(Lockout::class);
    }

    public function test_username_is_matched_case_insensitively(): void
    {
        $this->mockFailingLogin(AuthRequest::MAX_ATTEMPTS_PER_USERNAME_AND_IP);

        for ($i = 0; $i < AuthRequest::MAX_ATTEMPTS_PER_USERNAME_AND_IP; $i++) {
            $this->attemptLogin('ali.veli');
        }

        $this->attemptLogin('ALI.VELI')
            ->assertSessionHasErrors(['username' => $this->throttledMessage()]);
    }

    public function test_other_usernames_from_the_same_ip_are_not_blocked(): void
    {
        $this->mockFailingLogin(AuthRequest::MAX_ATTEMPTS_PER_USERNAME_AND_IP + 1);

        for ($i = 0; $i < AuthRequest::MAX_ATTEMPTS_PER_USERNAME_AND_IP; $i++) {
            $this->attemptLogin('ali.veli');
        }

        $this->attemptLogin('ayse.yilmaz')
            ->assertSessionHasErrors(['username' => __('ui.invalid_credentials')]);
    }

    public function test_username_is_blocked_across_ips_after_the_username_wide_limit(): void
    {
        $this->mockFailingLogin(AuthRequest::MAX_ATTEMPTS_PER_USERNAME);

        for ($i = 0; $i < AuthRequest::MAX_ATTEMPTS_PER_USERNAME; $i++) {
            $this->attemptLogin('ali.veli', '10.0.1.'.$i);
        }

        $this->attemptLogin('ali.veli', '10.0.2.1')
            ->assertSessionHasErrors([
                'username' => __('ui.login_throttled', [
                    'minutes' => (int) ceil(AuthRequest::USERNAME_DECAY_SECONDS / 60),
                ]),
            ]);
    }

    public function test_validation_errors_do_not_count_as_failed_attempts(): void
    {
        $this->mockFailingLogin(1);

        for ($i = 0; $i < AuthRequest::MAX_ATTEMPTS_PER_USERNAME_AND_IP + 2; $i++) {
            $this->from(route('login'))
                ->post(route('login.submit'), ['username' => 'ali veli!', 'password' => 'x'])
                ->assertSessionHasErrors(['username' => __('ui.username_regex')]);
        }

        $this->attemptLogin('ali.veli')
            ->assertSessionHasErrors(['username' => __('ui.invalid_credentials')]);
    }

    public function test_successful_login_clears_previous_failures(): void
    {
        $user = User::factory()->create();
        $calls = 0;
        $limit = AuthRequest::MAX_ATTEMPTS_PER_USERNAME_AND_IP;

        $this->mock(AuthService::class, function (MockInterface $mock) use (&$calls, $limit, $user): void {
            $mock->shouldReceive('login')->andReturnUsing(function () use (&$calls, $limit, $user): array {
                $calls++;

                if ($calls === $limit) {
                    Auth::login($user);

                    return ['success' => true, 'message' => __('ui.login_success'), 'user' => $user];
                }

                return ['success' => false, 'message' => __('ui.invalid_credentials')];
            });
        });

        for ($i = 0; $i < $limit - 1; $i++) {
            $this->attemptLogin('ali.veli');
        }

        $this->attemptLogin('ali.veli')->assertRedirect();
        Auth::logout();

        $this->attemptLogin('ali.veli')
            ->assertSessionHasErrors(['username' => __('ui.invalid_credentials')]);
        $this->assertSame($limit + 1, $calls);
    }
}
