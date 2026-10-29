<?php

namespace App\Exports;

use App\Models\Division;
use App\Exports\Concerns\FiltersTrashedExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DivisionExport
{
    use FiltersTrashedExport;

    public function download(Request $request): StreamedResponse
    {
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        $status = strtolower(trim((string) $request->query('status', '')));
        $showTrashed = $this->shouldExportTrashed($request);

        return response()->streamDownload(function () use ($search, $status, $showTrashed): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['name', 'is_active']);
            Division::query()
                ->when($search !== '', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]))
                ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($query) use ($status): void {
                    $query->where('is_active', in_array($status, ['active', '1', 'true'], true));
                })
                ->where('is_delete', $showTrashed)
                ->chunkById(500, function ($divisions) use ($output): void {
                    foreach ($divisions as $division) {
                        fputcsv($output, [$division->name, $division->is_active ? 'true' : 'false']);
                    }
                });
            fclose($output);
        }, 'divisions.csv', ['Content-Type' => 'text/csv']);
    }
}