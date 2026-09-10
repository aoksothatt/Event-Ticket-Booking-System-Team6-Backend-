<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Ticket expiration: run frequently so no ticket remains "active" past its
// event's end. Every 15 minutes keeps the door-check honest and bounded.
Schedule::command('tickets:expire')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();
