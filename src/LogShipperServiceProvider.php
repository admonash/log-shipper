<?php

namespace Admon\LogShipper;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class LogShipperServiceProvider extends ServiceProvider
{
    public function register()
    {
        // mergeConfigFrom respects already-set keys, so when the consuming project
        // has run `php artisan config:cache` the baked-in token survives. The old
        // `$this->app['config']->set(...)` here clobbered the cache on every boot
        // with `env('LOG_SHIPPER_TOKEN')`, which is null in CLI after a config
        // cache — silently breaking `logs:ship` across all instances.
        $this->mergeConfigFrom(__DIR__ . '/../config/log-shipper.php', 'log-shipper');
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
