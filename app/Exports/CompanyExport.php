<?php

namespace App\Exports;

use App\Models\Company;
use App\Exports\Concerns\FiltersTrashedExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyExport
{
    use FiltersTrashedExport;

    private const HEADERS = [
        'company_code', 'company_name', 'company_type', 'pan_number', 'tan_number',
        'gstin_number', 'pf_number', 'esi_number', 'lwf_number', 'registered_address', 'is_active',
    ];

    public function download(Request $request): StreamedResponse
    {
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        $status = strtolower(trim((string) $request->query('status', '')));
        $showTrashed = $this->shouldExportTrashed($request);

        return response()->streamDownload(function () use ($search, $status, $showTrashed): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, self::HEADERS);

            Company::withTrashed()
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        foreach (['company_code', 'company_name', 'company_type', 'pan_number', 'tan_number', 'gstin_number'] as $column) {
                            $query->orWhereRaw("LOWER({$column}) LIKE ?", ["%{$search}%"]);
                        }
                    });
                })
                ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($query) use ($status): void {
                    $query->where('is_active', in_array($status, ['active', '1', 'true'], true));
                })
                ->where('is_delete', $showTrashed)
                ->chunkById(500, function ($companies) use ($output): void {
                    foreach ($companies as $company) {
                        fputcsv($output, [
                            $company->company_code,
                            $company->company_name,
                            $company->company_type,
                            $company->pan_number,
                            $company->tan_number,
                            $company->gstin_number,
                            $company->pf_number,
                            $company->esi_number,
                            $company->lwf_number,
                            $company->registered_address,
                            $company->is_active ? 'true' : 'false',
                        ]);
                    }
                });

            fclose($output);
        }, 'companies.csv', ['Content-Type' => 'text/csv']);
    }
}