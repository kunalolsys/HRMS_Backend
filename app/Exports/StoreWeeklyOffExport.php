<?php

namespace App\Exports;

use App\Models\StoreWeeklyOff;
use App\Exports\Concerns\FiltersTrashedExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StoreWeeklyOffExport
{
    use FiltersTrashedExport;

    public function download(Request $request): StreamedResponse
    {
        $search     = mb_strtolower(trim((string) $request->query('search', '')));
        $status     = strtolower(trim((string) $request->query('status', '')));
        $locationId = $request->query('location_id');
        $showTrashed = $this->shouldExportTrashed($request);

        $query = StoreWeeklyOff::withTrashed()->with('location')
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereRaw('LOWER(weekly_off_day) LIKE ?', ["%{$search}%"])
                        ->orWhereHas('location', function ($locQuery) use ($search) {
                            $locQuery->whereRaw('LOWER(location_name) LIKE ?', ["%{$search}%"])
                                     ->orWhereRaw('LOWER(location_code) LIKE ?', ["%{$search}%"]);
                        });
                });
            })
            ->when(!empty($locationId), function ($q) use ($locationId) {
                $q->where('location_id', $locationId);
            })
            ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($q) use ($status) {
                $q->where('is_active', in_array($status, ['active', '1', 'true'], true));
            })
            ->where('is_delete', $showTrashed)
            ->orderByDesc('id');

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="store-weekly-offs-' . now()->format('Y-m-d_His') . '.csv"',
        ];

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['location_code', 'location_name', 'weekly_off_day', 'is_active']);

            $query->chunk(200, function ($records) use ($handle) {
                foreach ($records as $record) {
                    fputcsv($handle, [
                        $record->location->location_code ?? '',
                        $record->location->location_name ?? '',
                        $record->weekly_off_day ?? '',
                        $record->is_active ? 'true' : 'false',
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }
}