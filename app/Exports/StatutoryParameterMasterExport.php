<?php

namespace App\Exports;

use App\Models\StatutoryParameterMaster;
use App\Exports\Concerns\FiltersTrashedExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StatutoryParameterMasterExport
{
    use FiltersTrashedExport;

    /**
     * Download statutory parameter records as a CSV.
     */
    public function download(Request $request): StreamedResponse
    {
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        $status = strtolower(trim((string) ($request->query('status') ?? $request->query('is_active', ''))));
        $showTrashed = $this->shouldExportTrashed($request);

        $query = StatutoryParameterMaster::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->whereRaw('LOWER(rule_code) LIKE ?', ["%{$search}%"])
                      ->orWhereRaw('LOWER(description) LIKE ?', ["%{$search}%"]);
                });
            })
            ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($query) use ($status): void {
                $query->where('is_active', in_array($status, ['active', '1', 'true'], true));
            })
            ->where('is_delete', $showTrashed)
            ->orderByDesc('id');

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="statutory_parameters_' . date('Y-m-d_H-i-s') . '.csv"',
        ];

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputs($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'rule_code',
                'description',
                'pf_employee_rate',
                'pf_employer_rate',
                'pf_wage_ceiling',
                'pf_eps_rate',
                'pf_eps_max_cap',
                'pf_start_month',
                'esi_employee_rate',
                'esi_employer_rate',
                'esi_gross_ceiling',
                'esi_start_month',
                'total_deduction_cap_pct',
                'effective_from',
                'effective_to',
                'is_active',
            ]);

            $query->chunk(500, function ($rows) use ($handle) {
                foreach ($rows as $row) {
                    fputcsv($handle, [
                        $row->rule_code,
                        $row->description,
                        $row->pf_employee_rate,
                        $row->pf_employer_rate,
                        $row->pf_wage_ceiling,
                        $row->pf_eps_rate,
                        $row->pf_eps_max_cap,
                        $row->pf_start_month,
                        $row->esi_employee_rate,
                        $row->esi_employer_rate,
                        $row->esi_gross_ceiling,
                        $row->esi_start_month,
                        $row->total_deduction_cap_pct,
                        $row->effective_from?->format('Y-m-d') ?? $row->effective_from,
                        $row->effective_to?->format('Y-m-d') ?? ($row->effective_to ?: '2099-12-31'),
                        $row->is_active ? 'true' : 'false',
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }
}