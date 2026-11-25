<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('outbox:process')
    ->everyMinute()
    ->timezone(config('app.business_timezone', 'Asia/Tehran'))
    ->withoutOverlapping()
    ->onFailure(function (): void {
        Log::critical('Domain event outbox processing reported failures.');
    });
Schedule::command('backup:run')
    ->dailyAt('02:00')
    ->timezone(config('app.business_timezone', 'Asia/Tehran'))
    ->withoutOverlapping()
    ->onFailure(function (): void {
        Log::critical('Encrypted backup failed.');
    });
Schedule::command('backup:status')
    ->dailyAt('03:00')
    ->timezone(config('app.business_timezone', 'Asia/Tehran'))
    ->withoutOverlapping()
    ->onFailure(function (): void {
        Log::critical('Backup monitoring failed: no recent encrypted backup.');
    });
Schedule::command('inventory:refresh-expiry-alerts')
    ->dailyAt('07:00')
    ->timezone(config('app.business_timezone', 'Asia/Tehran'))
    ->withoutOverlapping();
