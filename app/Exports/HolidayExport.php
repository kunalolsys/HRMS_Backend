<?php

namespace App\Exports;

use App\Exports\Concerns\FiltersTrashedExport;
use App\Models\Holiday;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HolidayExport
{
    use FiltersTrashedExport;

    /**
     * Download holidays as a CSV file.
     */
    public function download(Request $request): StreamedResponse
    {
        $search      = mb_strtolower(trim((string) $request->query('search', '')));
        $status      = strtolower(trim((string) ($request->query('status') ?? $request->query('is_active', ''))));
        $type        = $request->query('holiday_type');
        $startDate   = $request->query('start_date');
        $endDate     = $request->query('end_date');
        $year        = $request->query('year');
        $month       = $request->query('month');
        $showTrashed = $this->shouldExportTrashed($request);

        $query = Holiday::query()
            ->where('is_delete', $showTrashed)
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(function ($sub) use ($search): void {
                    $sub->whereRaw('LOWER(holiday_title) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(holiday_type) LIKE ?', ["%{$search}%"]);
                });
            })
            ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($q) use ($status): void {
                $q->where('is_active', in_array($status, ['active', '1', 'true'], true));
            })
            ->when(!empty($type), function ($q) use ($type): void {
                $q->where('holiday_type', $type);
            })
            ->when(!empty($startDate) && !empty($endDate), function ($q) use ($startDate, $endDate): void {
                $q->whereBetween('holiday_date', [$startDate, $endDate]);
            })
            ->when($year && $month, function ($q) use ($year, $month): void {
                $q->whereYear('holiday_date', (int) $year)
                  ->whereMonth('holiday_date', (int) $month);
            })
            ->when($year && !$month, function ($q) use ($year): void {
                $q->whereYear('holiday_date', (int) $year);
            })
            ->orderBy('holiday_date', 'asc');

        $filename = 'holidays_export_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, ['holiday_date', 'holiday_title', 'holiday_type', 'is_active']);

            $query->chunk(500, function ($holidays) use ($handle): void {
                foreach ($holidays as $holiday) {
                    $dateStr = $holiday->holiday_date instanceof \DateTimeInterface
                        ? $holiday->holiday_date->format('Y-m-d')
                        : (string) $holiday->holiday_date;

                    fputcsv($handle, [
                        $dateStr,
                        $holiday->holiday_title,
                        $holiday->holiday_type,
                        $holiday->is_active ? 'true' : 'false',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}