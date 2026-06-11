<?php

use App\Http\Controllers\Auth\KeycloakAuthController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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


/*
|--------------------------------------------------------------------------
| LDIF Export Batch Download
|--------------------------------------------------------------------------
| Stable download route for LDIF export batches.
*/
Route::get('/admin/operations/ldif-export-batches/{record}/download', function ($record) {
    $row = DB::table('ldif_export_batches')
        ->where('id', $record)
        ->first();

    abort_unless($row, 404, 'LDIF export batch not found.');

    $rawPath = trim((string) ($row->output_path ?? ''));

    abort_if($rawPath === '', 404, 'LDIF output_path is empty.');

    $path = str_replace('\\', '/', $rawPath);
    $path = preg_replace('#^/+#', '', $path);
    $path = preg_replace('#^var/www/html/storage/app/private/#', '', $path);
    $path = preg_replace('#^var/www/html/storage/app/#', '', $path);
    $path = preg_replace('#^storage/app/private/#', '', $path);
    $path = preg_replace('#^storage/app/#', '', $path);
    $path = preg_replace('#^app/private/#', '', $path);
    $path = preg_replace('#^app/#', '', $path);
    $path = preg_replace('#^private/#', '', $path);

    $candidates = [];

    $candidates[] = storage_path('app/private/' . $path);
    $candidates[] = storage_path('app/' . $path);

    if (str_starts_with($rawPath, '/')) {
        $candidates[] = $rawPath;
    }

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && is_file($candidate) && is_readable($candidate)) {
            return response()->download(
                $candidate,
                'ldif-export-batch-' . $record . '.ldif',
                ['Content-Type' => 'application/ldif; charset=UTF-8']
            );
        }
    }

    $searchBase = storage_path('app/private/ldif-exports');
    $found = null;

    if (is_dir($searchBase)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($searchBase, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $filename = $file->getFilename();

            if (
                str_contains($filename, 'ldif-export-' . $record . '-')
                && str_ends_with($filename, '.ldif')
            ) {
                $found = $file->getPathname();
                break;
            }
        }
    }

    if ($found && is_file($found) && is_readable($found)) {
        $relative = str_replace(storage_path('app/private') . '/', '', $found);

        DB::table('ldif_export_batches')
            ->where('id', $record)
            ->update([
                'output_disk' => 'local',
                'output_path' => $relative,
                'updated_at' => now(),
            ]);

        return response()->download(
            $found,
            'ldif-export-batch-' . $record . '.ldif',
            ['Content-Type' => 'application/ldif; charset=UTF-8']
        );
    }

    Log::error('LDIF smart download failed', [
        'record' => $record,
        'raw_path' => $rawPath,
        'normalized_path' => $path,
        'checked_paths' => $candidates,
        'search_base' => $searchBase,
        'search_base_exists' => is_dir($searchBase),
    ]);

    abort(404, 'LDIF file not found by smart resolver.');
})
    ->middleware(['web', 'auth'])
    ->name('operations.ldif-export-batches.download');
