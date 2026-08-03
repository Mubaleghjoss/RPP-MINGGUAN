<?php

namespace App\Http\Controllers;

use App\Models\SourceDocument;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SourceDocumentController extends Controller
{
    public function __invoke(SourceDocument $sourceDocument): BinaryFileResponse
    {
        $path = base_path($sourceDocument->path);
        abort_unless(is_file($path), 404);
        return response()->file($path, ['Content-Type' => 'application/pdf']);
    }
}
