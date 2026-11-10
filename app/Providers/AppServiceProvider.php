<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Support\SystemBranding::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\View::composer('*', function ($view): void {
            $view->with('systemName', \App\Support\SystemBranding::name())
                ->with('cmsName', \App\Support\SystemBranding::cmsName())
                  ->with('systemFavicon', \App\Support\SystemBranding::favicon())
                  ->with('systemDefaultFavicon', \App\Support\SystemBranding::defaultFavicon());
        });
        $this->app['queue']->addConnector('database', function () {
            return new class($this->app['db']) extends \Illuminate\Queue\Connectors\DatabaseConnector {
                public function connect(array $config)
                {
                    return new \App\Services\Automation\RetryingDatabaseQueue(
                        $this->connections->connection($config['connection'] ?? null),
                        $config['table'], $config['queue'],
                        $config['retry_after'] ?? 60, $config['after_commit'] ?? null,
                    );
                }
            };
        });
    }
}
