<?php

namespace App\Console;

use App\Console\Commands\SyncZotloSubscriptions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        SyncZotloSubscriptions::class,
    ];

    protected function schedule(Schedule $schedule): void
    {
        $from = now()->subDay()->toDateString();
        $to   = now()->toDateString();

        $schedule->command('sync-zotlo-subscriptions')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command("report:subscriptions --from={$from} --to={$to}")
            ->dailyAt('00:10')
            ->appendOutputTo(storage_path('logs/report.log'));
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
