<?php

namespace BWProject\BWProxy\Providers;

use BWProject\BWProxy\Http\Controllers\NodeDesiredStateController;
use BWProject\BWProxy\Http\Middleware\AuthenticateNodeAgent;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;

class BWProxyRoutesProvider extends RouteServiceProvider
{
    public function boot(): void
    {
        $this->routes(function (): void {
            Route::prefix('api/bwproxy/v1')
                ->middleware(['api', AuthenticateNodeAgent::class, 'throttle:60,1'])
                ->group(function (): void {
                    Route::get('/nodes/{nodeId}/desired', NodeDesiredStateController::class)
                        ->where('nodeId', '[0-9]+')
                        ->name('bwproxy.nodes.desired');
                });
        });
    }
}
