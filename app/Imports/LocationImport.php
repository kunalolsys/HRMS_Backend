<?php

namespace App\Imports;

use App\Models\CostCenter;
use App\Models\Location;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorContract;

class LocationImport
{
    protected array $errors = [];

    public function import(UploadedFile $file): int
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) throw new \RuntimeException('Unable to read the import file.');
        try {
            $headers = fgetcsv($handle);
            $headers = $headers === false ? [] : $this->normalizeHeaders($headers);
            $expected = ['location_code', 'location_name', 'group_type', 'state', 'cost_center', 'est_registration', 'renewal_date', 'biometric_device_id', 'weekly_off_day', 'is_active'];
            if ($headers !== $expected) {
                $this->errors[] = $this->errorRow(1, [], 'The CSV header must contain location_code, location_name, group_type, state, cost_center, est_registration, renewal_date, biometric_device_id, weekly_off_day and is_active.');
                return 0;
            }
            $imported = 0;
            $rowNumber = 1;
            $seenValues = ['location_code' => [], 'location_name' => []];
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $data = [
                    'location_code' => trim((string) ($row[0] ?? '')),
                    'location_name' => trim((string) ($row[1] ?? '')),
                    'group_type' => trim((string) ($row[2] ?? '')),
                    'state' => trim((string) ($row[3] ?? '')),
                    'cost_center' => trim((string) ($row[4] ?? '')),
                    'est_registration' => trim((string) ($row[5] ?? '')),
                    'renewal_date' => trim((string) ($row[6] ?? '')),
                    'biometric_device_id' => trim((string) ($row[7] ?? '')),
                    'weekly_off_day' => trim((string) ($row[8] ?? '')),
                    'is_active' => strtolower(trim((string) ($row[9] ?? ''))),
                ];
                $validator = Validator::make($data, [
                    'location_code' => ['required', 'string', 'max:255'],
                    'location_name' => ['required', 'string', 'max:255'],
                    'group_type' => ['nullable', 'string', 'max:255'],
                    'state' => ['nullable', 'string', 'max:255'],
                    'cost_center' => ['nullable', 'string', 'exists:cost_centers,name'],
                    'est_registration' => ['nullable', 'string'],
                    'renewal_date' => ['nullable', 'date'],
                    'biometric_device_id' => ['nullable', 'string'],
                    'weekly_off_day' => ['nullable', 'string', 'max:255'],
                    'is_active' => ['nullable', 'string', 'in:true,false,yes,no,active,inactive,1,0'],
                ], [
                    'location_code.required' => 'Location code is required.',
                    'location_name.required' => 'Location name is required.',
                    'cost_center.exists' => 'The cost center does not exist.',
                ]);
                $this->validateLocationDuplicates($validator, $data, $seenValues);
                foreach (['location_code', 'location_name'] as $column) {
                    if (trim($data[$column]) !== '') {
                        $seenValues[$column][mb_strtolower(trim($data[$column]))] = true;
                    }
                }

                if ($validator->fails()) {
                    $this->addError($rowNumber, $data, $validator->errors()->all());
                    continue;
                }

                $costCenterId = null;
                if ($data['cost_center']) {
                    $costCenter = CostCenter::where('name', $data['cost_center'])->first();
                    if ($costCenter && !$costCenter->is_active) {
                        $this->addError($rowNumber, $data, ['The selected cost center is inactive and cannot be assigned.']);
                        continue;
                    }
                    $costCenterId = $costCenter?->id;
                }

                try {
                    DB::transaction(function () use ($data, $costCenterId): void {
                        $values = array_map(fn ($value) => $value === '' ? null : $value, $data);
                        unset($values['cost_center']);
                        $values['cost_center_id'] = $costCenterId;
                        $values['is_active'] = $this->parseActiveValue((string) ($data['is_active'] ?? ''));
                        $values['created_by'] = Auth::id();

                        Location::create($values);
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
            fputcsv($output, ['location_code', 'location_name', 'group_type', 'state', 'cost_center', 'est_registration', 'renewal_date', 'biometric_device_id', 'weekly_off_day', 'is_active']);
            fputcsv($output, ['LOCATION_CODE', 'Location Name', 'A', 'Haryana', 'cost center', 'Registration', '2026-12-31', 'BIOMETRIC_ID', 'Sunday', 'true']);
            fclose($output);
        }, 'location-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function storeErrors(): string
    {
        $output = fopen('php://memory', 'r+');
        fputcsv($output, ['row', 'location_code', 'location_name', 'group_type', 'state', 'cost_center', 'est_registration', 'renewal_date', 'biometric_device_id', 'weekly_off_day', 'is_active', 'error']);
        foreach ($this->errors as $error) fputcsv($output, $error);
        rewind($output);
        $contents = stream_get_contents($output);
        fclose($output);
        $filename = 'location-import-errors/' . uniqid('errors-', true) . '.csv';
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
        $this->errors[] = $this->errorRow($row, $data, implode(' ', $messages));
    }

    private function validateLocationDuplicates(
        ValidatorContract $validator,
        array $data,
        array $seenValues
    ): void {
        $labels = [
            'location_code' => 'location code',
            'location_name' => 'location name',
        ];

        $validator->after(function ($validator) use ($data, $seenValues, $labels): void {
            foreach ($labels as $column => $label) {
                $value = $data[$column] ?? '';
                if (trim($value) === '') {
                    continue;
                }

                $normalizedValue = mb_strtolower(trim($value));
                if (isset($seenValues[$column][$normalizedValue])) {
                    $validator->errors()->add($column, "The {$label} already exists in this file.");
                    continue;
                }

                $existing = Location::whereRaw("LOWER({$column}) = ?", [$normalizedValue])
                    ->orderBy('is_delete', 'asc')
                    ->orderBy('is_active', 'desc')
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

    private function errorRow(int $row, array $data, string $message): array
    {
        return array_merge(['row' => $row], array_intersect_key($data, array_flip(['location_code', 'location_name', 'group_type', 'state', 'cost_center', 'est_registration', 'renewal_date', 'biometric_device_id', 'weekly_off_day', 'is_active'])), ['error' => $message]);
    }

    private function parseActiveValue(string $value): bool
    {
        return $value === '' || in_array(strtolower(trim($value)), ['true', 'yes', 'active', '1'], true);
    }

    private function friendlyError(\Throwable $exception): string
    {
        if ($exception instanceof \Illuminate\Database\QueryException) {
            $message = strtolower($exception->getMessage());
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)
                || str_contains($message, 'duplicate')
                || str_contains($message, 'unique constraint')
                || str_contains($message, 'unique violation')) {
                return 'The location code or name already exists.';
            }
        }

        return 'The row could not be imported.';
    }
}
