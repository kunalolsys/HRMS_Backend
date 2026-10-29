<?php

namespace App\Imports;

use App\Models\Designation;
use App\Models\NoticePeriod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NoticePeriodImport
{
    protected array $errors = [];
    protected array $rawFailedRows = [];

    public function import(UploadedFile $file): int
    {
        $path = $file->getRealPath();
        $handle = fopen($path, 'r');

        if (!$handle) {
            throw new \RuntimeException('Unable to read the uploaded CSV file.');
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            throw new \RuntimeException('CSV file is empty.');
        }

        // Clean and normalize headers
        $normalizedHeaders = array_map(function ($h) {
            return strtolower(trim((string) preg_replace('/[\x{FEFF}]/u', '', $h)));
        }, $header);

        $allDesignations = Designation::all();
        $designationsByCode = $allDesignations->keyBy(fn ($item) => mb_strtolower(trim((string) $item->code)));
        $designationsByName = $allDesignations->keyBy(fn ($item) => mb_strtolower(trim((string) $item->name)));
        $designationsById = $allDesignations->keyBy(fn ($item) => (string) $item->id);

        $existingNoticePeriods = NoticePeriod::orderByDesc('id')
            ->get()
            ->groupBy('designation_id');

        $importedDesignationIds = [];
        $importedCount = 0;
        $rowNumber = 1; // 1 is header

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            // Skip empty rows
            if (count(array_filter($row, fn ($val) => trim((string) $val) !== '')) === 0) {
                continue;
            }

            $rowData = [];
            foreach ($normalizedHeaders as $idx => $key) {
                $rowData[$key] = isset($row[$idx]) ? trim((string) $row[$idx]) : '';
            }

            $rowErrors = $this->validateRow(
                $rowData,
                $designationsByCode,
                $designationsByName,
                $designationsById,
                $existingNoticePeriods,
                $importedDesignationIds
            );

            if (!empty($rowErrors)) {
                $this->errors[] = [
                    'row'    => $rowNumber,
                    'errors' => $rowErrors,
                    'data'   => $rowData,
                ];
                $this->rawFailedRows[] = array_merge($row, [implode('; ', $rowErrors)]);
                continue;
            }

            // Resolve designation ID
            $designation = $this->resolveDesignation(
                $rowData,
                $designationsByCode,
                $designationsByName,
                $designationsById
            );

            $isActive = true;
            if (isset($rowData['is_active']) && $rowData['is_active'] !== '') {
                $isActive = in_array(strtolower($rowData['is_active']), ['1', 'true', 'active', 'yes'], true);
            }

            $probationDays = isset($rowData['probation_notice_period_days']) && $rowData['probation_notice_period_days'] !== ''
                ? (int) $rowData['probation_notice_period_days']
                : null;

            try {
                NoticePeriod::create([
                    'designation_id'               => $designation->id,
                    'notice_period_days'           => (int) $rowData['notice_period_days'],
                    'probation_notice_period_days' => $probationDays,
                    'description'                  => ($rowData['description'] ?? '') !== '' ? $rowData['description'] : null,
                    'is_active'                    => $isActive,
                    'is_delete'                    => false,
                    'created_by'                   => Auth::id(),
                ]);

                $importedDesignationIds[$designation->id] = true;
                $importedCount++;
            } catch (\Throwable $e) {
                $this->errors[] = [
                    'row'    => $rowNumber,
                    'errors' => [$e->getMessage()],
                    'data'   => $rowData,
                ];
                $this->rawFailedRows[] = array_merge($row, [$e->getMessage()]);
            }
        }

        fclose($handle);
        return $importedCount;
    }

    protected function validateRow(
        array $row,
        $designationsByCode,
        $designationsByName,
        $designationsById,
        $existingNoticePeriods,
        array $importedDesignationIds
    ): array
    {
        $errors = [];

        $designationValue = $this->designationValue($row);
        $designationKey = mb_strtolower($designationValue);

        if ($designationKey === '') {
            $errors[] = 'Designation is required.';
        } else {
            $designation = $this->resolveDesignation(
                $row,
                $designationsByCode,
                $designationsByName,
                $designationsById
            );
            if (!$designation) {
                $errors[] = "Designation '{$designationKey}' does not exist.";
            } elseif ($designation->is_delete) {
                $errors[] = "Designation '{$designationKey}' is deleted.";
            } elseif (!$designation->is_active) {
                $errors[] = "Designation '{$designationKey}' is inactive and cannot be assigned.";
            } else {
                if (isset($importedDesignationIds[$designation->id])) {
                    $errors[] = 'A notice period rule for this designation is duplicated in this file.';
                } elseif ($existingNoticePeriods->has($designation->id)) {
                    $existingList = $existingNoticePeriods->get($designation->id);
                    $existing = $existingList->first(fn ($item) => !$item->is_delete) ?? $existingList->first();

                    if ($existing->is_delete) {
                        $errors[] = 'A notice period rule found duplicated in deleted stage.';
                    } elseif (!$existing->is_active) {
                        $errors[] = 'A notice period rule found duplicated in deactivated stage.';
                    } else {
                        $errors[] = 'A notice period rule is already configured for this designation.';
                    }
                }
            }
        }

        if (!isset($row['notice_period_days']) || trim((string) $row['notice_period_days']) === '') {
            $errors[] = 'Notice period days is required.';
        } elseif (!is_numeric($row['notice_period_days']) || (int) $row['notice_period_days'] < 0) {
            $errors[] = 'Notice period days must be a non-negative integer.';
        }

        if (isset($row['probation_notice_period_days']) && trim((string) $row['probation_notice_period_days']) !== '') {
            if (!is_numeric($row['probation_notice_period_days']) || (int) $row['probation_notice_period_days'] < 0) {
                $errors[] = 'Probation notice period days must be a non-negative integer.';
            }
        }

        return $errors;
    }

    private function designationValue(array $row): string
    {
        foreach (['designation_code', 'designation', 'designation_name', 'designation_id'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveDesignation(array $row, $designationsByCode, $designationsByName, $designationsById): ?Designation
    {
        $key = mb_strtolower($this->designationValue($row));

        return $designationsByCode->get($key)
            ?? $designationsByName->get($key)
            ?? $designationsById->get($key);
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
        $directory = 'notice-period-import-errors';
        if (!Storage::disk('local')->exists($directory)) {
            Storage::disk('local')->makeDirectory($directory);
        }

        $filename = 'notice-period-errors-' . time() . '.csv';
        $path = $directory . '/' . $filename;

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, [
            'designation',
            'notice_period_days',
            'probation_notice_period_days',
            'description',
            'is_active',
            'error_reason',
        ]);

        foreach ($this->rawFailedRows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        Storage::disk('local')->put($path, $contents);
        return $path;
    }

    public function downloadTemplate(): StreamedResponse
    {
        $sampleDesignation = Designation::where('is_delete', false)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
        $sampleCode = $sampleDesignation?->code ?? '';

        $headers = [
            'designation',
            'notice_period_days',
            'probation_notice_period_days',
            'description',
            'is_active',
        ];

        $rows = [
            [
                $sampleCode,
                30,
                15,
                'Standard notice period policy',
                'active',
            ],
        ];

        $filename = 'notice_periods_import_template.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}