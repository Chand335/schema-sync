<?php

namespace Chand335\SchemaSync;

use Chand335\SchemaSync\Commands\SchemaSyncCommand;
use Illuminate\Support\ServiceProvider;

class SchemaSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/schema-sync.php', 'schema-sync');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SchemaSyncCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/schema-sync.php' => config_path('schema-sync.php'),
            ], 'config');
        }
    }
}
