<?php

namespace App\Exports;

use App\Models\MinimumWageMaster;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MinimumWageMasterExport
{
    /**
     * Download minimum wage records as a CSV stream.
     */
    public function download(Request $request): StreamedResponse
    {
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        $status = strtolower(trim((string) $request->query('status', $request->query('is_active', ''))));
        $state = trim((string) $request->query('state_code', ''));
        $trashed = strtolower(trim((string) $request->query('trashed', '')));
        $currentUser = $request->user();
        $canViewTrashed = $currentUser
            && method_exists($currentUser, 'hasAnyRole')
            && $currentUser->hasAnyRole(['Super Admin', 'Admin']);
        $showTrashed = $canViewTrashed
            && (in_array($trashed, ['only', 'true', '1'], true) || $status === 'trashed');
        $hasStatusFilter = in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true);

        $query = MinimumWageMaster::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->whereRaw('LOWER(state_code) LIKE ?', ["%{$search}%"])
                      ->orWhereRaw('LOWER(skill_category) LIKE ?', ["%{$search}%"]);
                });
            })
            ->when($state !== '', fn ($query) => $query->where('state_code', $state))
            ->where('is_delete', $showTrashed)
            ->when($hasStatusFilter, function ($query) use ($status): void {
                $query->where('is_active', in_array($status, ['active', '1', 'true'], true));
            })
            ->orderByDesc('id');

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="minimum_wages_' . date('Y-m-d_H-i-s') . '.csv"',
        ];

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputs($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'state_code',
                'skill_category',
                'basic_minimum_wage',
                'vda_amount',
                'total_minimum_wage',
                'effective_from',
                'effective_to',
                'is_active',
            ]);

            $query->chunk(500, function ($rows) use ($handle) {
                foreach ($rows as $row) {
                    fputcsv($handle, [
                        $row->state_code,
                        $row->skill_category,
                        $row->basic_minimum_wage,
                        $row->vda_amount ?? '0.00',
                        $row->total_minimum_wage,
                        $row->effective_from?->format('Y-m-d') ?? $row->effective_from,
                        $row->effective_to?->format('Y-m-d') ?? $row->effective_to,
                        $row->is_active ? 'true' : 'false',
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }
}