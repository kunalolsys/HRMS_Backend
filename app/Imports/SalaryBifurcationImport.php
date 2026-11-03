<?php

namespace App\Imports;

use App\Models\SalaryBifurcation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalaryBifurcationImport
{
    protected array $errors = [];

    public function import($file): int
    {
        $this->errors = [];
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw new \Exception('Unable to open the uploaded file.');
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            throw new \Exception('The file is empty.');
        }

        $normalizedHeader = array_map(function ($col) {
            $col = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', trim((string) $col));
            return strtolower(str_replace(' ', '_', $col));
        }, $header);

        $rowNumber = 1;
        $importedCount = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (empty(array_filter($row, fn ($val) => trim((string) $val) !== ''))) {
                continue;
            }

            if (count($normalizedHeader) !== count($row)) {
                $this->errors[] = [
                    'row'    => $rowNumber,
                    'errors' => ['Column count does not match the header count.'],
                    'data'   => $row,
                ];
                continue;
            }

            $rowData = array_combine($normalizedHeader, $row);

            $rules = [
                'actual_salary'        => 'required|numeric|min:0',
                'books_basic'          => 'required|numeric|min:0',
                'bonus'                => 'nullable|numeric|min:0',
                'books_hra'            => 'nullable|numeric|min:0',
                'other_allowances'     => 'nullable|numeric|min:0',
                'total_salary'         => 'nullable|numeric|min:0',
                'effective_start_date' => 'required|date',
                'effective_end_date'   => 'nullable|date|after_or_equal:effective_start_date',
                'is_active'            => 'nullable',
            ];

            $dataToValidate = [
                'actual_salary'        => isset($rowData['actual_salary']) && trim($rowData['actual_salary']) !== '' ? trim($rowData['actual_salary']) : null,
                'books_basic'          => isset($rowData['books_basic']) && trim($rowData['books_basic']) !== '' ? trim($rowData['books_basic']) : null,
                'bonus'                => isset($rowData['bonus']) && trim($rowData['bonus']) !== '' ? trim($rowData['bonus']) : 0,
                'books_hra'            => isset($rowData['books_hra']) && trim($rowData['books_hra']) !== '' ? trim($rowData['books_hra']) : 0,
                'other_allowances'     => isset($rowData['other_allowances']) && trim($rowData['other_allowances']) !== '' ? trim($rowData['other_allowances']) : 0,
                'total_salary'         => isset($rowData['total_salary']) && trim($rowData['total_salary']) !== '' ? trim($rowData['total_salary']) : null,
                'effective_start_date' => isset($rowData['effective_start_date']) && trim($rowData['effective_start_date']) !== '' ? trim($rowData['effective_start_date']) : null,
                'effective_end_date'   => isset($rowData['effective_end_date']) && trim($rowData['effective_end_date']) !== '' ? trim($rowData['effective_end_date']) : null,
                'is_active'            => $rowData['is_active'] ?? null,
            ];

            $validator = Validator::make($dataToValidate, $rules);

            $validator->after(function ($validator) use ($dataToValidate): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $actualSalary = $dataToValidate['actual_salary'];
                $booksBasic = $dataToValidate['books_basic'];
                $newStartDate = $dataToValidate['effective_start_date'];
                $newEndDate = $dataToValidate['effective_end_date'];

                if ($actualSalary !== null && $booksBasic !== null && (float) $actualSalary === (float) $booksBasic) {
                    $validator->errors()->add(
                        'books_basic',
                        'The actual salary and books basic values cannot be identical.'
                    );
                }

                $existingRecord = SalaryBifurcation::withTrashed()
                    ->where(function ($q): void {
                        $q->where('is_active', false)
                            ->orWhere('is_deleted', true)
                            ->orWhereNotNull('deleted_at');
                    })
                    ->where(function ($query) use ($newStartDate, $newEndDate): void {
                        $query->whereDate('effective_start_date', $newStartDate)
                            ->orWhere(function ($q) use ($newStartDate): void {
                                $q->whereDate('effective_start_date', '<=', $newStartDate)
                                    ->where(function ($sub) use ($newStartDate): void {
                                        $sub->whereNull('effective_end_date')
                                            ->orWhereDate('effective_end_date', '>=', $newStartDate);
                                    });
                            });

                        if (!empty($newEndDate)) {
                            $query->orWhere(function ($q) use ($newStartDate, $newEndDate): void {
                                $q->whereDate('effective_start_date', '<=', $newEndDate)
                                    ->where(function ($sub) use ($newStartDate): void {
                                        $sub->whereNull('effective_end_date')
                                            ->orWhereDate('effective_end_date', '>=', $newStartDate);
                                    });
                            });
                        }
                    })
                    ->first();

                if ($existingRecord) {
                    $dateRange = !empty($existingRecord->date_range)
                        ? $existingRecord->date_range
                        : $this->formatDateRange($existingRecord->effective_start_date, $existingRecord->effective_end_date);
                    $isDeleted = $existingRecord->trashed() || (bool) $existingRecord->is_deleted;
                    $stageMode = $isDeleted ? 'deleted' : 'inactive';
                    $validator->errors()->add(
                        'effective_start_date',
                        "A salary bifurcation entry with date range ({$dateRange}) already exists in the system in {$stageMode} mode."
                    );
                    return;
                }

                $previous = SalaryBifurcation::orderByDesc('effective_start_date')
                    ->orderByDesc('id')
                    ->first();

                if ($previous) {
                    if (empty($previous->effective_end_date)) {
                        $validator->errors()->add(
                            'effective_start_date',
                            'The previous salary bifurcation record has an empty effective end date. Please update the previous record\'s end date before adding a new entry.'
                        );
                        return;
                    }

                    if (Carbon::parse($newStartDate)->lte(Carbon::parse($previous->effective_end_date))) {
                        $prevEndDate = Carbon::parse($previous->effective_end_date)->format('d-m-Y');
                        $validator->errors()->add(
                            'effective_start_date',
                            "The effective start date must be greater than the previous record's effective end date ({$prevEndDate})."
                        );
                    }
                }
            });

            if ($validator->fails()) {
                $this->errors[] = [
                    'row'    => $rowNumber,
                    'errors' => $validator->errors()->all(),
                    'data'   => $row,
                ];
                continue;
            }

            $validated = $validator->validated();

            $isActive = true;
            if (isset($dataToValidate['is_active'])) {
                $activeVal = strtolower(trim((string) $dataToValidate['is_active']));
                if (in_array($activeVal, ['0', 'false', 'no', 'inactive'], true)) {
                    $isActive = false;
                }
            }

            $bonus = (float) ($validated['bonus'] ?? 0);
            $booksHra = (float) ($validated['books_hra'] ?? 0);
            $otherAllowances = (float) ($validated['other_allowances'] ?? 0);
            $booksBasic = (float) $validated['books_basic'];
            $totalSalary = isset($validated['total_salary']) && is_numeric($validated['total_salary'])
                ? (float) $validated['total_salary']
                : ($booksBasic + $bonus + $booksHra + $otherAllowances);

            SalaryBifurcation::create([
                'actual_salary'        => $validated['actual_salary'],
                'books_basic'          => $booksBasic,
                'bonus'                => $bonus,
                'books_hra'            => $booksHra,
                'other_allowances'     => $otherAllowances,
                'total_salary'         => $totalSalary,
                'effective_start_date' => Carbon::parse($validated['effective_start_date'])->toDateString(),
                'effective_end_date'   => !empty($validated['effective_end_date']) ? Carbon::parse($validated['effective_end_date'])->toDateString() : null,
                'is_active'            => $isActive,
                'is_deleted'           => false,
                'created_by'           => Auth::id(),
            ]);

            $importedCount++;
        }

        fclose($handle);
        return $importedCount;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function storeErrors(): string
    {
        $filename = 'salary_bifurcation_errors_' . Str::random(10) . '_' . time() . '.csv';
        $path = 'salary-bifurcation-import-errors/' . $filename;

        $content = fopen('php://temp', 'r+');
        fputcsv($content, ['Row', 'Errors', 'Data']);

        foreach ($this->errors as $err) {
            fputcsv($content, [
                $err['row'],
                implode('; ', (array) $err['errors']),
                is_array($err['data']) ? implode(', ', $err['data']) : $err['data'],
            ]);
        }

        rewind($content);
        $csvData = stream_get_contents($content);
        fclose($content);

        Storage::disk('local')->put($path, $csvData);

        return $filename;
    }

    public function downloadTemplate(): StreamedResponse
    {
        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="salary_bifurcations_template.csv"',
        ];

        return response()->stream(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'actual_salary',
                'books_basic',
                'bonus',
                'books_hra',
                'other_allowances',
                'total_salary',
                'effective_start_date',
                'effective_end_date',
                'is_active',
            ]);

            fputcsv($handle, [
                '50000.00',
                '25000.00',
                '5000.00',
                '12500.00',
                '7500.00',
                '50000.00',
                date('Y-01-01'),
                '',
                'true',
            ]);

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Format start and end dates as a consistent range string (e.g. "01-04-2024 to 31-03-2025").
     */
    private function formatDateRange(mixed $startDate, mixed $endDate): string
    {
        if (empty($startDate)) {
            return 'N/A';
        }

        $formattedStart = Carbon::parse($startDate)->format('d-m-Y');

        if (empty($endDate)) {
            return "{$formattedStart} onwards";
        }

        $formattedEnd = Carbon::parse($endDate)->format('d-m-Y');

        return "{$formattedStart} to {$formattedEnd}";
    }
}