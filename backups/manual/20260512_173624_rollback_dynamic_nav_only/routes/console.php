<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

Schedule::command('iam:sync-root-ou-entry-types')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
