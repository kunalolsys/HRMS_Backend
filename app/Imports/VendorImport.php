<?php

namespace App\Imports;

use App\Models\Brand;
use App\Models\Vendor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorContract;

class VendorImport
{
    protected array $errors = [];

    public function import(UploadedFile $file): int
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) throw new \RuntimeException('Unable to read the import file.');

        try {
            $headers = fgetcsv($handle);
            $headers = $headers === false ? [] : $this->normalizeHeaders($headers);
            $expectedHeaders = ['vendor_code', 'vendor_name', 'gstin_number', 'phone_number', 'registered_address', 'brands', 'is_active'];
            if ($headers !== $expectedHeaders) {
                $this->errors[] = $this->errorRow(1, [], 'The CSV header must contain vendor_code, vendor_name, gstin_number, phone_number, registered_address, brands and is_active.');
                return 0;
            }

            $imported = 0;
            $rowNumber = 1;
            $importedCodes = [];
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $data = [
                    'vendor_code' => trim((string) ($row[0] ?? '')),
                    'vendor_name' => trim((string) ($row[1] ?? '')),
                    'gstin_number' => trim((string) ($row[2] ?? '')),
                    'phone_number' => trim((string) ($row[3] ?? '')),
                    'registered_address' => trim((string) ($row[4] ?? '')),
                    'brands' => trim((string) ($row[5] ?? '')),
                    'is_active' => strtolower(trim((string) ($row[6] ?? ''))),
                ];
                $validator = Validator::make($data, [
                    'vendor_code' => ['required', 'string', 'max:255'],
                    'vendor_name' => ['required', 'string', 'max:255'],
                    'gstin_number' => [
                        'required',
                        'string',
                        'max:15',
                        'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/i',
                    ],
                    'phone_number' => ['required', 'string', 'max:255'],
                    'registered_address' => ['nullable', 'string'],
                    'brands' => ['required', 'string', 'max:255'],
                    'is_active' => ['nullable', 'string', 'in:true,false,yes,no,active,inactive,1,0'],
                ], [
                    'gstin_number.regex' => 'Please enter a valid 15-character GSTIN format (e.g. 07AAAAA0000A1Z5).',
                ]);
                $this->validateVendorDuplicates($validator, $data);
                if (isset($importedCodes[strtolower($data['vendor_code'])])) {
                    $validator->after(fn ($validator) => $validator->errors()->add('vendor_code', 'The vendor code is duplicated in this file.'));
                }
                if ($validator->fails()) {
                    $this->addError($rowNumber, $data, $validator->errors()->all());
                    continue;
                }

                try {
                    $brandNames = $this->names($data['brands']);
                    $brands = Brand::where('is_delete', false)->get();
                    $brandsByName = $brands->keyBy(fn (Brand $brand) => strtolower(trim($brand->brand_name)));
                    $missing = array_values(array_filter($brandNames, fn (string $name): bool => !$brandsByName->has(strtolower($name))));
                    if ($missing !== []) {
                        $this->addError($rowNumber, $data, ['Brand not found: ' . implode(', ', $missing) . '.']);
                        continue;
                    }

                    DB::transaction(function () use ($data, $brandNames, $brandsByName): void {
                        $vendor = Vendor::create([
                            'vendor_code' => $data['vendor_code'],
                            'vendor_name' => $data['vendor_name'],
                            'gstin_number' => $data['gstin_number'],
                            'phone_number' => $data['phone_number'],
                            'registered_address' => $data['registered_address'] ?: null,
                            'is_active' => $this->parseActiveValue($data['is_active']),
                            'created_by' => Auth::id(),
                        ]);
                        $brandIds = array_map(fn (string $name) => $brandsByName->get(strtolower($name))->id, $brandNames);
                        $vendor->brands()->syncWithPivotValues($brandIds, ['created_by' => Auth::id(), 'updated_by' => Auth::id()]);
                    });
                    $importedCodes[strtolower($data['vendor_code'])] = true;
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
            fputcsv($output, ['vendor_code', 'vendor_name', 'gstin_number', 'phone_number', 'registered_address', 'brands', 'is_active']);
            fputcsv($output, ['VENDOR_CODE_TEXT', 'VENDOR_NAME_TEXT', 'GSTIN_TEXT', 'PHONE_TEXT', 'REGISTERED_ADDRESS_TEXT', 'BRAND_NAME_TEXT', 'true']);
            fclose($output);
        }, 'vendor-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function storeErrors(): string
    {
        $output = fopen('php://memory', 'r+');
        fputcsv($output, ['row', 'vendor_code', 'vendor_name', 'gstin_number', 'phone_number', 'registered_address', 'brands', 'is_active', 'error']);
        foreach ($this->errors as $error) fputcsv($output, $error);
        rewind($output);
        $contents = stream_get_contents($output);
        fclose($output);
        $filename = 'vendor-import-errors/' . uniqid('errors-', true) . '.csv';
        Storage::disk('local')->put($filename, $contents);
        return $filename;
    }

    public function errors(): array { return $this->errors; }

    private function validateVendorDuplicates(ValidatorContract $validator, array $data): void
    {
        $labels = [
            'vendor_code' => 'vendor code',
            'vendor_name' => 'vendor name',
            'gstin_number' => 'GSTIN number',
            'phone_number' => 'phone number',
        ];

        $validator->after(function ($validator) use ($data, $labels): void {
            foreach ($labels as $column => $label) {
                $value = $data[$column] ?? null;
                if (!is_string($value) || trim($value) === '') {
                    continue;
                }

                $existing = Vendor::withTrashed()
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

    private function normalizeHeaders(array $headers): array
    {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        return array_map(static fn ($header) => trim((string) $header), $headers);
    }

    private function addError(int $row, array $data, array $messages): void
    {
        $this->errors[] = $this->errorRow($row, $data, implode(' ', $messages));
    }

    private function errorRow(int $row, array $data, string $message): array
    {
        return [
            'row' => $row,
            'vendor_code' => $data['vendor_code'] ?? '',
            'vendor_name' => $data['vendor_name'] ?? '',
            'gstin_number' => $data['gstin_number'] ?? '',
            'phone_number' => $data['phone_number'] ?? '',
            'registered_address' => $data['registered_address'] ?? '',
            'brands' => $data['brands'] ?? '',
            'is_active' => $data['is_active'] ?? '',
            'error' => $message,
        ];
    }

    private function names(string $value): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (string $name): string => trim($name), explode(',', $value)))));
    }

    private function parseActiveValue(string $value): bool
    {
        return $value === '' || in_array(strtolower(trim($value)), ['true', 'yes', 'active', '1'], true);
    }

    private function friendlyError(\Throwable $exception): string
    {
        if ($exception instanceof \Illuminate\Database\QueryException && $exception->getCode() === '23000') return 'The vendor record already exists.';
        return 'The row could not be imported.';
    }
}
