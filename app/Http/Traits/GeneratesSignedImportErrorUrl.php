<?php

namespace App\Http\Traits;

use Illuminate\Support\Facades\URL;

trait GeneratesSignedImportErrorUrl
{
    /**
     * Generate a temporary signed route URL for downloading import error files.
     *
     * @param string $routeName
     * @param string $errorFilePath
     * @param int $minutes
     * @return string
     */
    protected function generateImportErrorsUrl(string $routeName, string $errorFilePath, int $minutes = 30): string
    {
        return URL::temporarySignedRoute(
            $routeName,
            now()->addMinutes($minutes),
            ['filename' => basename($errorFilePath)]
        );
    }
}