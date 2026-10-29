<?php

namespace App\Imports;
use App\Http\Traits\HandlesImport;

use App\Models\Designation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class DesignationImport
{
    use HandlesImport;

    private const HEADERS = [
        'code', 'name', 'grade', 'pay_band_min', 'pay_band_max',
        'notice_period_confirmed', 'notice_period_probation', 'is_active'
    ];

    public function import(UploadedFile $file): int
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to read the import file.');
        }

        try {
            $headers = fgetcsv($handle);
            $headers = $headers === false ? [] : $this->normalizeHeaders($headers);
            if ($headers !== self::HEADERS) {
                $this->addError(1, [], ['The CSV header does not match the designation import structure.']);
                return 0;
            }

            $imported = 0;
            $rowNumber = 1;
            $importedCodes = [];
            $importedNames = [];

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $data = [];
                foreach (self::HEADERS as $index => $header) {
                    $data[$header] = trim((string) ($row[$index] ?? ''));
                }

                $data['is_active'] = strtolower($data['is_active']);

                $normalizedCode = mb_strtolower($data['code']);
                $normalizedName = mb_strtolower($data['name']);

                $validator = Validator::make($data, [
                    'code' => ['required', 'string', 'max:255'],
                    'name' => 'required|string|max:255',
                    'grade' => 'nullable|string|max:255',
                    'pay_band_min' => 'nullable|numeric|min:0',
                    'pay_band_max' => 'nullable|numeric|gte:pay_band_min',
                    'notice_period_confirmed' => 'nullable|integer|min:0',
                    'notice_period_probation' => 'nullable|integer|min:0',
                    'is_active' => 'nullable|string|in:true,false,yes,no,active,inactive,1,0',
                ], [
                    'is_active.in' => 'is_active must be true, false, yes, no, active, inactive, 1 or 0.',
                ]);

                if ($normalizedCode !== '') {
                    if (isset($importedCodes[$normalizedCode])) {
                        $validator->after(function ($validator): void {
                            $validator->errors()->add('code', 'The designation code already exists in this file.');
                        });
                    }
                }

                if ($normalizedName) {
                    if (isset($importedNames[$normalizedName])) {
                        $validator->after(function ($validator): void {
                            $validator->errors()->add('name', 'The designation name already exists in this file.');
                        });
                    }

                }

                $this->validateDesignationDuplicates($validator, $data);

                if ($validator->fails()) {
                    $this->addError($rowNumber, $data, $validator->errors()->all());
                    continue;
                }

                try {
                    DB::transaction(function () use ($data): void {
                        $values = array_map(fn ($value) => $value === '' ? null : $value, $data);
                        $values['is_active'] = $this->parseActiveValue((string) ($data['is_active'] ?? ''));
                        $values['created_by'] = Auth::id();

                        Designation::create($values);
                    });
                    $importedCodes[$normalizedCode] = true;
                    $importedNames[$normalizedName] = true;
                    $imported++;
                } catch (\Throwable $exception) {
                    $this->addError($rowNumber, $data, [$this->friendlyError($exception)]);
                }
            }

            return $imported;
        } finally {
            fclose($handle);
        }
    }

    public function downloadTemplate()
    {
        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, self::HEADERS);
            fputcsv($output, [
                'DESG001', 'Software Engineer', 'SE', '50000.00', '150000.00', '60', '30', 'true'
            ]);
            fclose($output);
        }, 'designation-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    private function getStoragePath(): string
    {
        return 'designation-import-errors';
    }

    private function validateDesignationDuplicates(
        \Illuminate\Contracts\Validation\Validator $validator,
        array $data
    ): void {
        $labels = [
            'code' => 'designation code',
            'name' => 'designation name',
        ];

        $validator->after(function ($validator) use ($data, $labels): void {
            foreach ($labels as $column => $label) {
                $value = $data[$column] ?? null;
                if (!is_string($value) || trim($value) === '') {
                    continue;
                }

                $existing = Designation::whereRaw("LOWER({$column}) = ?", [mb_strtolower(trim($value))])
                    ->orderBy('is_delete', 'asc')
                    ->orderByDesc('id')
                    ->first();

                if (!$existing) {
                    continue;
                }

                if ($existing->is_delete) {
                    $message = "The {$label} already exists in deleted stage.";
                } elseif (!$existing->is_active) {
                    $message = "The {$label} already exists in deactivated stage.";
                } else {
                    $message = "The {$label} already exists.";
                }

                $validator->errors()->add($column, $message);
            }
        });
    }
}