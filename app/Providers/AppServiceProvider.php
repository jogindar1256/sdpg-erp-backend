<?php

namespace App\Providers;

use App\Database\EmulatedPreparesPostgresConnection;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // See EmulatedPreparesPostgresConnection's own doc comment — this
        // fixes the boolean-becomes-literal-1 fallout from
        // PDO::ATTR_EMULATE_PREPARES (config/database.php's pgsql
        // connection), which itself exists to survive Supabase's Supavisor
        // pooler running in transaction mode.
        Connection::resolverFor('pgsql', function ($connection, $database, $prefix, $config) {
            return new EmulatedPreparesPostgresConnection($connection, $database, $prefix, $config);
        });
    }
}
