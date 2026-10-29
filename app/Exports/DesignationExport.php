<?php

namespace App\Exports;

use App\Models\Designation;
use App\Exports\Concerns\FiltersTrashedExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DesignationExport
{
    use FiltersTrashedExport;

    private const HEADERS = [
        'code', 'name', 'grade', 'pay_band_min', 'pay_band_max',
        'notice_period_confirmed', 'notice_period_probation', 'is_active'
    ];

    public function download(Request $request): StreamedResponse
    {
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        $status = strtolower(trim((string) $request->query('status', '')));
        $showTrashed = $this->shouldExportTrashed($request);

        return response()->streamDownload(function () use ($search, $status, $showTrashed): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, self::HEADERS);

            Designation::query()
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        $query->whereRaw('LOWER(code) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(grade) LIKE ?', ["%{$search}%"]);
                    });
                })
                ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($query) use ($status): void {
                    $query->where('is_active', in_array($status, ['active', '1', 'true'], true));
                })
                ->where('is_delete', $showTrashed)
                ->chunkById(500, function ($designations) use ($output): void {
                    foreach ($designations as $designation) {
                        $data = $designation->only(self::HEADERS);
                        $data['is_active'] = $designation->is_active ? 'true' : 'false';
                        fputcsv($output, $data);
                    }
                });

            fclose($output);
        }, 'designations.csv', ['Content-Type' => 'text/csv']);
    }
}