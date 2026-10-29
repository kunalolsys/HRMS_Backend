<?php

namespace App\Exports;

use App\Models\Location;
use App\Exports\Concerns\FiltersTrashedExport;
use DateTimeInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LocationExport
{
    use FiltersTrashedExport;

    public function download(Request $request): StreamedResponse
    {
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        $status = strtolower(trim((string) $request->query('status', '')));
        $showTrashed = $this->shouldExportTrashed($request);
        return response()->streamDownload(function () use ($search, $status, $showTrashed): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['location_code', 'location_name', 'group_type', 'state', 'cost_center_id', 'est_registration', 'renewal_date', 'biometric_device_id', 'weekly_off_day', 'is_active']);
            Location::with('costCenter')
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        foreach (['location_code', 'location_name', 'group_type', 'state', 'weekly_off_day'] as $column) {
                            $query->orWhereRaw("LOWER({$column}) LIKE ?", ["%{$search}%"]);
                        }
                    });
                })
                ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($query) use ($status): void {
                    $query->where('is_active', in_array($status, ['active', '1', 'true'], true));
                })
                ->where('is_delete', $showTrashed)
                ->chunkById(500, function ($locations) use ($output): void {
                    foreach ($locations as $location) {
                        fputcsv($output, [
                            $location->location_code,
                            $location->location_name,
                            $location->group_type,
                            $location->state,
                            $location->cost_center_id,
                            $location->est_registration,
                            $this->formatRenewalDate($location->renewal_date),
                            $location->biometric_device_id,
                            $location->weekly_off_day,
                            $location->is_active ? 'true' : 'false',
                        ]);
                    }
                });
            fclose($output);
        }, 'locations.csv', ['Content-Type' => 'text/csv']);
    }

    private function formatRenewalDate(mixed $renewalDate): ?string
    {
        if ($renewalDate === null || $renewalDate === '') {
            return null;
        }

        if ($renewalDate instanceof DateTimeInterface) {
            return $renewalDate->format('d-m-Y');
        }

        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $renewalDate);

        return $parsedDate === false
            ? (string) $renewalDate
            : $parsedDate->format('d-m-Y');
    }
}
