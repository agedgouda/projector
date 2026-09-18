<?php

namespace App\Services;

use App\Models\Document;

/**
 * DocumentImportFinalizer::finalize()'s result — which document the user should actually be
 * shown, and what to tell them while it's still generating.
 */
final readonly class ImportFinalizeResult
{
    public function __construct(
        public Document $target,
        public string $message,
    ) {}
}
