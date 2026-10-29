<?php

namespace App\Imports;

use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorContract;

class CompanyImport
{
    private const HEADERS = [
        'company_code', 'company_name', 'company_type', 'pan_number', 'tan_number',
        'gstin_number', 'pf_number', 'esi_number', 'lwf_number', 'registered_address', 'is_active',
    ];

    protected array $errors = [];

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
                $this->errors[] = array_merge(['row' => 1], array_fill_keys(self::HEADERS, ''), [
                    'error' => 'The CSV header does not match the company import structure.',
                ]);
                return 0;
            }

            $imported = 0;
            $rowNumber = 1;
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $data = [];
                foreach (self::HEADERS as $index => $header) {
                    $data[$header] = trim((string) ($row[$index] ?? ''));
                }

                $data['is_active'] = strtolower($data['is_active']);

                $validator = Validator::make($data, [
                    'company_code' => ['required', 'string', 'max:255'],
                    'company_name' => ['required', 'string', 'max:255'],
                    'company_type' => ['nullable', 'string', 'max:255'],
                    'pan_number' => [
                        'nullable',
                        'string',
                        'max:10',
                        'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/i',
                    ],
                    'tan_number' => ['nullable', 'string', 'max:10'],
                    'gstin_number' => [
                        'nullable',
                        'string',
                        'max:15',
                        'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/i',
                    ],
                    'pf_number' => ['nullable', 'string', 'max:255'],
                    'esi_number' => ['nullable', 'string', 'max:255'],
                    'lwf_number' => ['nullable', 'string', 'max:255'],
                    'registered_address' => ['nullable', 'string'],
                    'is_active' => ['nullable', 'string', 'in:true,false,yes,no,active,inactive,1,0'],
                ], [
                    'company_code.required' => 'Company code is required.',
                    'company_name.required' => 'Company name is required.',
                    'pan_number.regex' => 'Please enter a valid PAN number format (e.g. ABCDE1234F).',
                    'gstin_number.regex' => 'Please enter a valid 15-character GSTIN format (e.g. 07AAAAA0000A1Z5).',
                    'is_active.in' => 'is_active must be true, false, yes, no, active, inactive, 1 or 0.',
                ]);

                $this->validateCompanyDuplicates($validator, $data);

                if ($validator->fails()) {
                    $this->addError($rowNumber, $data, $validator->errors()->all());
                    continue;
                }

                try {
                    DB::transaction(function () use ($data): void {
                        $values = array_map(fn ($value) => $value === '' ? null : $value, $data);
                        $values['is_active'] = $this->parseActiveValue((string) ($data['is_active'] ?? ''));
                        $values['created_by'] = Auth::id();

                        Company::create($values);
                    });
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

    public function hasErrors(): bool { return $this->errors !== []; }

    public function downloadTemplate()
    {
        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, self::HEADERS);
            fputcsv($output, ['COMPANY_CODE', 'Company Name', 'Private Limited', 'ABCDE1234F', 'DELA12345F', '07AAAAA0000A1Z5', 'PF_NUMBER', 'ESI_NUMBER', 'LWF_NUMBER', 'Registered office address', 'true']);
            fclose($output);
        }, 'company-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function storeErrors(): string
    {
        $output = fopen('php://memory', 'r+');
        fputcsv($output, array_merge(['row'], self::HEADERS, ['error']));
        foreach ($this->errors as $error) { fputcsv($output, $error); }
        rewind($output);
        $contents = stream_get_contents($output);
        fclose($output);
        $filename = 'company-import-errors/' . uniqid('errors-', true) . '.csv';
        Storage::disk('local')->put($filename, $contents);
        return $filename;
    }

    public function errors(): array { return $this->errors; }

    private function normalizeHeaders(array $headers): array
    {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        return array_map(static fn ($header) => trim((string) $header), $headers);
    }

    private function addError(int $row, array $data, array $messages): void
    {
        $this->errors[] = array_merge(['row' => $row], $data, ['error' => implode(' ', $messages)]);
    }

    private function validateCompanyDuplicates(ValidatorContract $validator, array $data): void
    {
        $labels = [
            'company_name' => 'company name',
            'company_code' => 'company code',
            'pan_number' => 'PAN number',
            'tan_number' => 'TAN number',
            'gstin_number' => 'GSTIN number',
            'pf_number' => 'PF number',
            'esi_number' => 'ESI number',
            'lwf_number' => 'LWF number',
        ];

        $validator->after(function ($validator) use ($data, $labels): void {
            foreach ($labels as $column => $label) {
                $value = $data[$column] ?? null;
                if (!is_string($value) || trim($value) === '') {
                    continue;
                }

                $existing = Company::withTrashed()
                    ->whereRaw("LOWER({$column}) = ?", [mb_strtolower(trim($value))])
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
                    $message = "The {$label} has already been taken.";
                }

                $validator->errors()->add($column, $message);
            }
        });
    }

    private function parseActiveValue(string $value): bool
    {
        return trim($value) === '' || in_array(strtolower(trim($value)), ['true', 'yes', 'active', '1'], true);
    }

    private function friendlyError(\Throwable $exception): string
    {
        if ($exception instanceof \Illuminate\Database\QueryException) {
            $message = strtolower($exception->getMessage());
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)
                || str_contains($message, 'duplicate')
                || str_contains($message, 'unique constraint')
                || str_contains($message, 'unique violation')) {
                return 'A unique company value already exists.';
            }
        }

        return 'The row could not be imported.';
    }
}