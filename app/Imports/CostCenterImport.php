<?php

namespace App\Imports;

use App\Models\CostCenter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class CostCenterImport
{
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
            $imported = 0;

            if ($headers !== ['code', 'name', 'is_active']) {
                $this->errors[] = [
                    'row' => 1,
                    'code' => '',
                    'name' => '',
                    'is_active' => '',
                    'error' => 'The CSV header must contain code, name and is_active.',
                ];

                return 0;
            }

            $rowNumber = 1;
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $data = [
                    'code' => trim((string) ($row[0] ?? '')),
                    'name' => trim((string) ($row[1] ?? '')),
                    'is_active' => strtolower(trim((string) ($row[2] ?? ''))),
                ];

                $validator = Validator::make($data, [
                    'code' => ['required', 'string', 'max:255'],
                    'name' => ['required', 'string', 'max:255'],
                    'is_active' => ['nullable', 'string', 'in:true,false,yes,no,active,inactive,1,0'],
                ], [
                    'code.required' => 'Cost center code is required.',
                    'name.required' => 'Cost center name is required.',
                    'is_active.in' => 'is_active must be true, false, yes, no, active, inactive, 1 or 0.',
                ]);
                $validator->after(function ($validator) use ($data): void {
                    foreach (['code', 'name'] as $column) {
                        $value = trim($data[$column]);
                        if ($value === '') {
                            continue;
                        }

                        $existing = CostCenter::whereRaw("LOWER({$column}) = ?", [mb_strtolower($value)])
                            ->orderBy('is_delete', 'asc')
                            ->orderBy('is_active', 'desc')
                            ->orderByDesc('id')
                            ->first();

                        if (!$existing) {
                            continue;
                        }

                        if ($existing->is_delete) {
                            $message = "A cost center {$column} found duplicated in deleted stage.";
                        } elseif (!$existing->is_active) {
                            $message = "A cost center {$column} found duplicated in deactivated stage.";
                        } else {
                            $message = "A cost center {$column} already exists.";
                        }

                        $validator->errors()->add($column, $message);
                    }
                });

                if ($validator->fails()) {
                    $this->addError($rowNumber, $data, $validator->errors()->all());
                    continue;
                }

                try {
                    DB::transaction(function () use ($data): void {
                        $values = array_map(fn ($value) => $value === '' ? null : $value, $data);
                        $values['is_active'] = $this->parseActiveValue((string) ($data['is_active'] ?? ''));
                        $values['created_by'] = Auth::id();

                        CostCenter::create($values);
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

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function downloadTemplate()
    {
        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['code', 'name', 'is_active']);
            fputcsv($output, ['COST_CENTER_CODE', 'Cost Center Name', 'true']);
            fclose($output);
        }, 'cost-center-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function storeErrors(): string
    {
        $output = fopen('php://memory', 'r+');
        fputcsv($output, ['row', 'code', 'name', 'is_active', 'error']);

        foreach ($this->errors as $error) {
            fputcsv($output, $error);
        }

        rewind($output);
        $contents = stream_get_contents($output);
        fclose($output);

        $filename = 'cost-center-import-errors/' . uniqid('errors-', true) . '.csv';
        Storage::disk('local')->put($filename, $contents);

        return $filename;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    private function normalizeHeaders(array $headers): array
    {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);

        return array_map(static fn ($header) => trim((string) $header), $headers);
    }

    private function addError(int $row, array $data, array $messages): void
    {
        $this->errors[] = [
            'row' => $row,
            'code' => $data['code'],
            'name' => $data['name'],
            'is_active' => $data['is_active'],
            'error' => implode(' ', $messages),
        ];
    }

    private function parseActiveValue(string $value): bool
    {
        if (trim($value) === '') {
            return true;
        }

        return in_array(strtolower(trim($value)), ['true', 'yes', 'active', '1'], true);
    }

    private function friendlyError(\Throwable $exception): string
    {
        if ($exception instanceof \Illuminate\Database\QueryException && $exception->getCode() === '23000') {
            return 'The cost center code already exists.';
        }

        return 'The row could not be imported.';
    }
}