<?php

namespace App\Imports;

use App\Models\Division;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class DivisionImport
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
            if ($headers !== ['name', 'is_active']) {
                $this->errors[] = ['row' => 1, 'name' => '', 'is_active' => '', 'error' => 'The CSV header must contain name and is_active.'];
                return 0;
            }

            $imported = 0;
            $rowNumber = 1;
            $importedNames = [];

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $data = [
                    'name' => trim((string) ($row[0] ?? '')),
                    'is_active' => strtolower(trim((string) ($row[1] ?? ''))),
                ];
                $normalizedName = mb_strtolower($data['name']);
                $validator = Validator::make($data, [
                    'name' => ['required', 'string', 'max:255'],
                    'is_active' => ['nullable', 'string', 'in:true,false,yes,no,active,inactive,1,0'],
                ], [
                    'is_active.in' => 'is_active must be true, false, yes, no, active, inactive, 1 or 0.',
                ]);

                if (isset($importedNames[$normalizedName])) {
                    $validator->after(fn ($validator) => $validator->errors()->add('name', 'The division name already exists in this file.'));
                } else {
                    $existing = Division::whereRaw('LOWER(name) = ?', [$normalizedName])
                        ->orderBy('is_delete', 'asc')
                        ->orderByDesc('id')
                        ->first();

                    if ($existing) {
                        if ($existing->is_delete) {
                            $message = 'The division name already exists in deleted stage.';
                        } elseif (!$existing->is_active) {
                            $message = 'The division name already exists in deactivated stage.';
                        } else {
                            $message = 'The division name already exists.';
                        }
                        $validator->after(fn ($validator) => $validator->errors()->add('name', $message));
                    }
                }
                if ($validator->fails()) {
                    $this->addError($rowNumber, $data, $validator->errors()->all());
                    continue;
                }

                $values = array_map(fn ($value) => $value === '' ? null : $value, $data);
                $values['is_active'] = $this->parseActiveValue((string) ($data['is_active'] ?? ''));
                $values['created_by'] = \Illuminate\Support\Facades\Auth::id();

                Division::create($values);

                $importedNames[$normalizedName] = true;
                $imported++;
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
            fputcsv($output, ['name', 'is_active']);
            fputcsv($output, ['DIVISION_NAME_TEXT', 'true']);
            fclose($output);
        }, 'division-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function storeErrors(): string
    {
        $output = fopen('php://memory', 'r+');
        fputcsv($output, ['row', 'name', 'is_active', 'error']);
        foreach ($this->errors as $error) { fputcsv($output, $error); }
        rewind($output);
        $contents = stream_get_contents($output);
        fclose($output);
        $filename = 'division-import-errors/' . uniqid('errors-', true) . '.csv';
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

    private function parseActiveValue(string $value): bool
    {
        return $value === '' || in_array($value, ['true', 'yes', 'active', '1'], true);
    }
}