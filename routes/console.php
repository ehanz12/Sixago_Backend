<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Eskalasi antrean approval yang melewati SLA (butuh cron: schedule:run tiap menit)
Schedule::command('app:sla-escalate')->hourly();

// Retensi: kedua tabel ini hanya bisa tumbuh monoton; tanpa prune, ukuran
// keduanya cuma naik. Dijadwalkan pagi buta agar tidak contends dengan jam
// sibuk approval.
// Butuh cron: schedule:run tiap menit + schedule:work untuk development.
Schedule::command('audit:prune', ['--days' => 90])->dailyAt('03:15');
Schedule::command('notifications:prune', ['--days' => 90])->dailyAt('03:20');
