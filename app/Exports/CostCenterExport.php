<?php

namespace App\Exports;

use App\Models\CostCenter;
use App\Exports\Concerns\FiltersTrashedExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CostCenterExport
{
    use FiltersTrashedExport;

    public function download(Request $request): StreamedResponse
    {
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        $status = strtolower(trim((string) $request->query('status', '')));
        $showTrashed = $this->shouldExportTrashed($request);

        return response()->streamDownload(function () use ($search, $status, $showTrashed): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['code', 'name', 'is_active']);

            CostCenter::query()
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        $query->whereRaw('LOWER(code) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(name) LIKE ?', ["%{$search}%"]);
                    });
                })
                ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($query) use ($status): void {
                    $query->where('is_active', in_array($status, ['active', '1', 'true'], true));
                })
                ->where('is_delete', $showTrashed)
                ->chunkById(500, function ($costCenters) use ($output): void {
                    foreach ($costCenters as $costCenter) {
                        fputcsv($output, [
                            $costCenter->code,
                            $costCenter->name,
                            $costCenter->is_active ? 'true' : 'false',
                        ]);
                    }
                });

            fclose($output);
        }, 'cost-centers.csv', ['Content-Type' => 'text/csv']);
    }
}