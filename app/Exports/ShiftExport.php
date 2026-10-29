<?php

namespace App\Exports;

use App\Models\Shift;
use App\Exports\Concerns\FiltersTrashedExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShiftExport
{
    use FiltersTrashedExport;

    private const HEADERS = [
        'shift_code', 'shift_name', 'shift_start_time', 'shift_end_time', 'grace_time',
        'full_day_min_hours', 'short_leave_min_hours', 'half_day_min_hours', 'no_salary_max_hours',
        'effective_from', 'effective_to', 'location_names', 'designation_names', 'is_active'
    ];

    public function download(Request $request): StreamedResponse
    {
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        $status = strtolower(trim((string) $request->query('status', '')));
        $showTrashed = $this->shouldExportTrashed($request);

        return response()->streamDownload(function () use ($search, $status, $showTrashed): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, self::HEADERS);

            Shift::withTrashed()->with(['shiftLocationDesignations.location', 'shiftLocationDesignations.designation'])
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        $query->whereRaw('LOWER(shift_code) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(shift_name) LIKE ?', ["%{$search}%"]);
                    });
                })
                ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($query) use ($status): void {
                    $query->where('is_active', in_array($status, ['active', '1', 'true'], true));
                })
                ->where('is_delete', $showTrashed)
                ->chunkById(500, function ($shifts) use ($output): void {
                    foreach ($shifts as $shift) {
                        $locationNames = $shift->shiftLocationDesignations
                            ->pluck('location.location_name')
                            ->filter()
                            ->unique()
                            ->implode(', ');

                        $designationNames = $shift->shiftLocationDesignations
                            ->pluck('designation.name')
                            ->filter()
                            ->unique()
                            ->implode(', ');

                        fputcsv($output, [
                            $shift->shift_code,
                            $shift->shift_name,
                            $shift->shift_start_time,
                            $shift->shift_end_time,
                            // Minutes to HH:MM Conversion
                            $shift->grace_time, 
                            $this->minutesToTime($shift->full_day_min_hours),     // e.g. 480 -> "08:00"
                            $this->minutesToTime($shift->short_leave_min_hours),   // e.g. 120 -> "02:00"
                            $this->minutesToTime($shift->half_day_min_hours),      // e.g. 240 -> "04:00"
                            $this->minutesToTime($shift->no_salary_max_hours),                           
                            $shift->effective_from,
                            $shift->effective_to,
                            $locationNames,
                            $designationNames,
                            $shift->is_active ? 'true' : 'false',
                        ]);
                    }
                });

            fclose($output);
        }, 'shifts.csv', ['Content-Type' => 'text/csv']);
    }
    /**
     * Convert Total Minutes to HH:MM
     */
    private function minutesToTime(?int $totalMinutes): string
    {
        if (is_null($totalMinutes) || $totalMinutes <= 0) {
            return '00:00';
        }

        $hours = floor($totalMinutes / 60);
        $minutes = $totalMinutes % 60;

        return sprintf('%02d:%02d', $hours, $minutes);
    }
}