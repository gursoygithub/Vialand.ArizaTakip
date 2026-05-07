<?php

use App\Jobs\CheckSlaBreaches;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use function PHPUnit\Framework\callback;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo('/auth/login');
    })
    ->withSchedule(callback: function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        $schedule->command('employee:sync')
            ->everyFiveMinutes()
            ->timezone(timezone: config('app.timezone', 'UTC'))
            ->onSuccess(callback: function (): void {
                info(message: 'Personel senkronizasyon komutu başarıyla tamamlandı.');
            })
            ->onFailure(callback: function ():void {
                info(message: 'Personel senkronizasyon komutu başarısız oldu.');
            });

        $schedule->job(new CheckSlaBreaches())
            ->everyFiveMinutes()
            ->timezone(config('app.timezone', 'UTC'))
            ->name('check-sla-breaches')
            ->withoutOverlapping();

        // Yarının log dosyasını önceden oluştur
        $schedule->call(function () {
            $tomorrow = now()->addDay()->format('Y-m-d');
            $file = storage_path("logs/laravel-{$tomorrow}.log");
            if (!file_exists($file)) {
                touch($file);
                chmod($file, 0777);
            }
        })->dailyAt('23:55');
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
