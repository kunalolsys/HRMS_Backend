<?php

namespace App\Exports;

use App\Models\SalaryBifurcation;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SalaryBifurcationExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    protected ?Request $request = null;

    public function __construct(?Request $request = null)
    {
        $this->request = $request;
    }

    public function download(?Request $request = null): BinaryFileResponse
    {
        $this->request = $request ?? $this->request;
        $fileName = 'salary_bifurcations_' . now()->format('Y_m_d_His') . '.xlsx';

        return Excel::download($this, $fileName);
    }

    public function collection()
    {
        $status = strtolower(trim((string) ($this->request?->query('status', ''))));
        $trashed = strtolower(trim((string) ($this->request?->query('trashed', ''))));
        $currentUser = $this->request?->user();
        $canViewTrashed = $currentUser
            && method_exists($currentUser, 'hasAnyRole')
            && $currentUser->hasAnyRole(['Super Admin', 'Admin']);
        $showTrashed = $canViewTrashed
            && (in_array($trashed, ['only', 'true', '1'], true) || $status === 'trashed');

        return SalaryBifurcation::query()
            ->with(['createdBy:id,name', 'updatedBy:id,name'])
            ->when($showTrashed, function ($q) {
                $q->onlyTrashed();
            })
            ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($q) use ($status) {
                $isActive = in_array($status, ['active', '1', 'true'], true);
                $q->where('is_active', $isActive);
            })
            ->orderByDesc('id')
            ->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Actual Salary',
            'Books Basic',
            'Bonus',
            'Books HRA',
            'Other Allowances',
            'Total Salary',
            'Effective Start Date',
            'Effective End Date',
            'Status',
            'Created By',
            'Created At',
        ];
    }

    public function map($bifurcation): array
    {
        return [
            $bifurcation->id,
            $bifurcation->actual_salary,
            $bifurcation->books_basic,
            $bifurcation->bonus,
            $bifurcation->books_hra,
            $bifurcation->other_allowances,
            $bifurcation->total_salary,
            $bifurcation->effective_start_date ? $bifurcation->effective_start_date->format('Y-m-d') : '',
            $bifurcation->effective_end_date ? $bifurcation->effective_end_date->format('Y-m-d') : '',
            $bifurcation->is_active ? 'Active' : 'Inactive',
            $bifurcation->createdBy?->name ?? '',
            $bifurcation->created_at ? $bifurcation->created_at->format('Y-m-d H:i:s') : '',
        ];
    }
}