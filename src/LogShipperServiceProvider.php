<?php

namespace Admon\LogShipper;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class LogShipperServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Token MUST come from the consuming project's .env — never hardcode here.
        // This package lives in vendor/ across many projects; a hardcoded token would
        // mean every dev machine, CI cache, and package mirror has the live secret.
        $this->app['config']->set('log-shipper', [
            'url'   => env('LOG_SHIPPER_URL', 'https://logs.rivion.ai'),
            'token' => env('LOG_SHIPPER_TOKEN'),
        ]);
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\ShipLogs::class,
                Commands\PullLogs::class,
            ]);

            // Auto-register schedule — no Kernel.php changes needed
            $this->app->booted(function () {
                $schedule = $this->app->make(Schedule::class);
                $schedule->command('logs:ship --no-cleanup')
                    ->everySixHours()
                    ->withoutOverlapping()
                    ->runInBackground();

                // Cleanup old local logs once daily at 1 AM
                $schedule->command('logs:ship --days-to-keep=7')
                    ->dailyAt('01:00')
                    ->withoutOverlapping()
                    ->runInBackground();
            });
        }
    }

    /**
     * Build the project identifier from folder name + database name.
     * e.g. "aist--super_tutor", "ivs-rag--ivs_rag_db"
     */
    public static function projectId(): string
    {
        $folder = basename(base_path());

        try {
            $dbName = config('database.connections.' . config('database.default') . '.database');
        } catch (\Throwable $e) {
            $dbName = null;
        }

        if ($dbName && $dbName !== ':memory:') {
            return $folder . '--' . $dbName;
        }

        return $folder;
    }
}
