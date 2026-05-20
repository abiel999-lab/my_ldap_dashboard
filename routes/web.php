<?php

use App\Http\Controllers\Auth\KeycloakAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::get('/auth/redirect', [KeycloakAuthController::class, 'redirect'])
    ->name('auth.keycloak.redirect');

Route::get('/auth/callback', [KeycloakAuthController::class, 'callback'])
    ->name('auth.keycloak.callback');

Route::match(['get', 'post'], '/auth/logout', [KeycloakAuthController::class, 'logout'])
    ->name('auth.keycloak.logout');

Route::get('/signed-out', [KeycloakAuthController::class, 'signedOut'])
    ->name('auth.keycloak.signed-out');

Route::get('/forbidden', [KeycloakAuthController::class, 'forbidden'])
    ->name('auth.forbidden');

Route::get('/login-keycloak', function () {
    return redirect('/auth/redirect?fresh=1');
})->name('auth.keycloak.login-keycloak');

Route::get('/login', function () {
    return redirect('/auth/redirect?fresh=1');
})->name('login');

use App\Http\Controllers\Operations\ImportTemplateDownloadController;

Route::get('/admin/operations/import-template-maker/{template}/download', ImportTemplateDownloadController::class)
    ->name('operations.import-templates.download');
