<?php

namespace App\Exports;

use App\Models\ShortLeaveDeductionSlab;
use App\Exports\Concerns\FiltersTrashedExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShortLeaveDeductionSlabExport
{
    use FiltersTrashedExport;

    private const HEADERS = ['short_leaves_count', 'days_deducted'];

    public function download(Request $request): StreamedResponse
    {
        $showTrashed = $this->shouldExportTrashed($request);

        return response()->streamDownload(function () use ($showTrashed): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, self::HEADERS);

            ShortLeaveDeductionSlab::query()
                ->where('is_delete', $showTrashed)
                ->orderBy('short_leaves_count', 'asc')
                ->chunkById(500, function ($slabs) use ($output): void {
                    foreach ($slabs as $slab) {
                        fputcsv($output, [
                            $slab->short_leaves_count,
                            $slab->days_deducted,
                        ]);
                    }
                });

            fclose($output);
        }, 'short-leave-deduction-slabs.csv', ['Content-Type' => 'text/csv']);
    }
}