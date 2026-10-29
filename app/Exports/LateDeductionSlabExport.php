<?php

namespace App\Exports;

use App\Models\LateDeductionSlab;
use App\Exports\Concerns\FiltersTrashedExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LateDeductionSlabExport
{
    use FiltersTrashedExport;

    private const HEADERS = ['lates_count', 'days_deducted'];

    public function download(Request $request): StreamedResponse
    {
        $showTrashed = $this->shouldExportTrashed($request);

        return response()->streamDownload(function () use ($showTrashed): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, self::HEADERS);

            LateDeductionSlab::query()
                ->where('is_delete', $showTrashed)
                ->orderBy('lates_count', 'asc')
                ->chunkById(500, function ($slabs) use ($output): void {
                    foreach ($slabs as $slab) {
                        fputcsv($output, [
                            $slab->lates_count,
                            $slab->days_deducted,
                        ]);
                    }
                });

            fclose($output);
        }, 'late-deduction-slabs.csv', ['Content-Type' => 'text/csv']);
    }
}