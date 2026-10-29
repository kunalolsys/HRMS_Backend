<?php

namespace App\Exports;

use App\Models\NoticePeriod;
use App\Exports\Concerns\FiltersTrashedExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NoticePeriodExport
{
    use FiltersTrashedExport;

    /**
     * Download notice periods as a CSV file.
     */
    public function download(Request $request): StreamedResponse
    {
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        $status = strtolower(trim((string) ($request->query('status', ''))));
        $designationId = $request->query('designation_id');
        $showTrashed = $this->shouldExportTrashed($request);

        $query = NoticePeriod::with(['designation'])
            ->where('is_delete', $showTrashed)
            ->when(!$showTrashed, function ($q): void {
                $q->where('is_active', true);
            })
            ->when($designationId, function ($q) use ($designationId) {
                $q->where('designation_id', $designationId);
            })
            ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($q) use ($status) {
                $isActive = in_array($status, ['active', '1', 'true'], true);
                $q->where('is_active', $isActive);
            })
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereRaw('LOWER(description) LIKE ?', ["%{$search}%"])
                        ->orWhereHas('designation', function ($dQuery) use ($search) {
                            $dQuery->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                                   ->orWhereRaw('LOWER(code) LIKE ?', ["%{$search}%"]);
                        });
                });
            })
            ->orderBy('id', 'DESC');

        $filename = 'notice_periods_' . date('Y_m_d_His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputs($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'ID',
                'Designation Code',
                'Designation Name',
                'Notice Period Days (Confirmed)',
                'Probation Notice Period Days',
                'Description',
                'Status',
                'Deleted At',
                'Created At',
            ]);

            $query->chunk(200, function ($records) use ($handle) {
                foreach ($records as $row) {
                    $statusText = $row->is_delete ? 'Deleted' : ($row->is_active ? 'true' : 'false');
                    $deletedAt = $row->deleted_at
                        ? ($row->deleted_at instanceof \DateTimeInterface ? $row->deleted_at->format('Y-m-d H:i:s') : (string) $row->deleted_at)
                        : '';
                    $createdAt = $row->created_at?->format('Y-m-d H:i:s') ?? '';

                    fputcsv($handle, [
                        $row->id,
                        $row->designation?->code ?? '',
                        $row->designation?->name ?? '',
                        $row->notice_period_days,
                        $row->probation_notice_period_days ?? '',
                        $row->description ?? '',
                        $statusText,
                        $deletedAt,
                        $createdAt,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}