<?php

declare(strict_types=1);

use App\Domains\Tenant\Http\Controllers\OrganizationController;
use App\Domains\Tenant\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

return static function (?string $version, callable $toVersionedPath): void {
    Route::prefix($toVersionedPath($version, 'organization'))
        ->middleware(['tenant.context', 'api.auth'])
        ->group(function (): void {
            Route::get('/list', [OrganizationController::class, 'list'])
                ->middleware('api.permission:organization.view,organization.manage');
            Route::get('/all', [OrganizationController::class, 'all'])
                ->middleware('api.permission:organization.view,organization.manage,team.manage,team.view');
            Route::post('', [OrganizationController::class, 'store'])
                ->middleware(['idempotent.request', 'api.permission:organization.manage']);
            Route::put('/{id}', [OrganizationController::class, 'update'])
                ->middleware(['idempotent.request', 'api.permission:organization.manage']);
            Route::delete('/{id}', [OrganizationController::class, 'destroy'])
                ->middleware('api.permission:organization.manage');
        });

    Route::prefix($toVersionedPath($version, 'team'))
        ->middleware(['tenant.context', 'api.auth'])
        ->group(function (): void {
            Route::get('/list', [TeamController::class, 'list'])
                ->middleware('api.permission:team.view,team.manage');
            Route::get('/all', [TeamController::class, 'all'])
                ->middleware('api.permission:team.view,team.manage,user.manage,user.view');
            Route::post('', [TeamController::class, 'store'])
                ->middleware(['idempotent.request', 'api.permission:team.manage']);
            Route::put('/{id}', [TeamController::class, 'update'])
                ->middleware(['idempotent.request', 'api.permission:team.manage']);
            Route::delete('/{id}', [TeamController::class, 'destroy'])
                ->middleware('api.permission:team.manage');
        });
};
