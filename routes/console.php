<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every login and token renewal creates a token; drop revoked and expired ones.
Schedule::command('passport:purge')->daily();

// Expired email confirmation codes and other prunable models.
Schedule::command('model:prune')->daily();
