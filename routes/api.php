<?php

use App\Http\Controllers\Api\V1\DirectoryReadController;
use App\Http\Middleware\Api\ReadOnlyApiKeyMiddleware;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', [DirectoryReadController::class, 'health'])
        ->name('api.v1.health');

    Route::middleware(ReadOnlyApiKeyMiddleware::class)->group(function (): void {
        Route::get('/users', [DirectoryReadController::class, 'users'])
            ->name('api.v1.users.index');

        Route::get('/users/{uid}', [DirectoryReadController::class, 'user'])
            ->where('uid', '.*')
            ->name('api.v1.users.show');

        Route::get('/directory-objects', [DirectoryReadController::class, 'directoryObjects'])
            ->name('api.v1.directory_objects.index');

        Route::get('/directory-objects/{id}', [DirectoryReadController::class, 'directoryObject'])
            ->whereNumber('id')
            ->name('api.v1.directory_objects.show');

        Route::get('/schema', [DirectoryReadController::class, 'schema'])
            ->name('api.v1.schema.index');

        Route::get('/schema/{id}', [DirectoryReadController::class, 'schemaEntry'])
            ->whereNumber('id')
            ->name('api.v1.schema.show');
    });
});
