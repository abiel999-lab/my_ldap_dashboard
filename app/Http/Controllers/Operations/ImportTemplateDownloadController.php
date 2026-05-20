<?php

namespace App\Http\Controllers\Operations;

use App\Models\Operations\ImportTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImportTemplateDownloadController
{
    public function __invoke(Request $request, ImportTemplate $template): BinaryFileResponse
    {
        abort_unless($template->output_path, 404);

        $paths = [
            storage_path('app/private/'.$template->output_path),
            storage_path('app/'.$template->output_path),
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                return response()->download($path, $template->output_filename ?: basename($path));
            }
        }

        abort(404);
    }
}
