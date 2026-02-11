<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('outbox:send-mail --limit=20')
    ->everyMinute()
    ->withoutOverlapping();
    
Schedule::command('outbox:send-sms --limit=20')
    ->everyMinute()
    ->withoutOverlapping();