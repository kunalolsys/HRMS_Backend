<?php

namespace App\Exports\Concerns;

use Illuminate\Http\Request;

trait FiltersTrashedExport
{
    protected function shouldExportTrashed(Request $request): bool
    {
        $trashed = strtolower(trim((string) $request->query('trashed', '')));
        $status = strtolower(trim((string) $request->query('status', '')));
        $user = $request->user();
        $canViewTrashed = $user
            && method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole(['Super Admin', 'Admin']);

        return $canViewTrashed
            && (in_array($trashed, ['only', 'true', '1'], true) || $status === 'trashed');
    }
}
