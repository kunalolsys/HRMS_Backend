<?php

namespace App\Imports;

use App\Models\MinimumWageMaster;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MinimumWageMasterImport
{
    protected array $errors = [];
    protected array $rawFailedRows = [];

    private const DEFAULT_EFFECTIVE_TO = '2099-12-31';

    private const HEADERS = [
        'state_code',
        'skill_category',
        'basic_minimum_wage',
        'vda_amount',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    public function import($file): int
    {
        $filePath = $file instanceof UploadedFile ? $file->getRealPath() : (string) $file;
        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            throw new \RuntimeException('Unable to read the uploaded CSV file.');
        }

        try {
            $headers = fgetcsv($handle);
            if ($headers === false) {
                throw new \RuntimeException('CSV file is empty.');
            }

            $normalizedHeaders = $this->normalizeHeaders($headers);
            if ($normalizedHeaders !== self::HEADERS) {
                $this->errors[] = [
                    'row'    => 1,
                    'errors' => ['The CSV header does not match the minimum wage import structure.'],
                    'data'   => [],
                ];
                return 0;
            }

            $imported = 0;
            $rowNumber = 1;
            $importedKeys = [];

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                // Skip completely empty rows
                if (count(array_filter($row, fn ($val) => trim((string) $val) !== '')) === 0) {
                    continue;
                }

                $data = [];
                foreach (self::HEADERS as $index => $header) {
                    $data[$header] = trim((string) ($row[$index] ?? ''));
                }

                $data['vda_amount'] = $data['vda_amount'] !== '' ? $data['vda_amount'] : null;
                $data['effective_to'] = $data['effective_to'] !== ''
                    ? $data['effective_to']
                    : self::DEFAULT_EFFECTIVE_TO;

                $validator = Validator::make($data, [
                    'state_code'         => ['required', 'string', 'max:50'],
                    'skill_category'     => ['required', 'string', 'max:100'],
                    'basic_minimum_wage' => ['required', 'numeric', 'min:0'],
                    'vda_amount'         => ['nullable', 'numeric', 'min:0'],
                    'effective_from'     => ['required', 'date'],
                    'effective_to'       => ['nullable', 'date', 'after_or_equal:effective_from'],
                    'is_active'          => ['nullable', 'string', 'in:true,false,yes,no,active,inactive,1,0'],
                ], [
                    'state_code.required'         => 'State code is required.',
                    'skill_category.required'     => 'Skill category is required.',
                    'basic_minimum_wage.required' => 'Basic minimum wage is required.',
                    'basic_minimum_wage.numeric'  => 'Basic minimum wage must be a valid number.',
                    'vda_amount.numeric'          => 'VDA amount must be a valid number.',
                    'effective_from.required'     => 'Effective from date is required.',
                    'effective_to.after_or_equal' => 'Effective to date must be on or after effective from date.',
                    'is_active.in'                => 'is_active must be true, false, yes, no, active, inactive, 1, or 0.',
                ]);

                $stateCodeKey = mb_strtolower(trim((string) $data['state_code']));

                if ($stateCodeKey !== '') {
                    if (isset($importedKeys[$stateCodeKey])) {
                        $validator->after(function ($validator): void {
                            $validator->errors()->add('state_code', 'A wage record for this state code is duplicated in this file.');
                        });
                    } else {
                        $alreadyExists = MinimumWageMaster::whereRaw('LOWER(state_code) = ?', [$stateCodeKey])
                            ->exists();

                        if ($alreadyExists) {
                            $validator->after(function ($validator): void {
                                $validator->errors()->add('state_code', 'A wage record for this state code already exists.');
                            });
                        }
                    }
                }

                if ($validator->fails()) {
                    $this->errors[] = [
                        'row'    => $rowNumber,
                        'errors' => $validator->errors()->all(),
                        'data'   => $data,
                    ];
                    $this->rawFailedRows[] = array_merge($row, [implode('; ', $validator->errors()->all())]);
                    continue;
                }

                try {
                    DB::transaction(function () use ($data): void {
                        MinimumWageMaster::create([
                            'state_code'         => trim((string) $data['state_code']),
                            'skill_category'     => trim((string) $data['skill_category']),
                            'basic_minimum_wage' => (float) $data['basic_minimum_wage'],
                            'vda_amount'         => $data['vda_amount'] !== null ? (float) $data['vda_amount'] : null,
                            'effective_from'     => $data['effective_from'],
                            'effective_to'       => $data['effective_to'],
                            'is_active'          => $this->parseActiveValue((string) ($data['is_active'] ?? '')),
                            'is_delete'          => false,
                            'created_by'         => Auth::id(),
                        ]);
                    });

                    $importedKeys[$stateCodeKey] = true;
                    $imported++;
                } catch (\Throwable $exception) {
                    $this->errors[] = [
                        'row'    => $rowNumber,
                        'errors' => [$exception->getMessage()],
                        'data'   => $data,
                    ];
                    $this->rawFailedRows[] = array_merge($row, [$exception->getMessage()]);
                }
            }

            return $imported;
        } finally {
            fclose($handle);
        }
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
        $directory = 'minimum-wage-import-errors';
        if (!Storage::disk('local')->exists($directory)) {
            Storage::disk('local')->makeDirectory($directory);
        }

        $filename = 'minimum-wage-errors-' . time() . '_' . Str::random(6) . '.csv';
        $path = $directory . '/' . $filename;

        $handle = fopen('php://temp', 'r+');
        fputs($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_merge(self::HEADERS, ['error_reason']));

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
        $headers = self::HEADERS;
        $rows = [
            [
                'DL',
                'Unskilled',
                '17494.00',
                '0.00',
                '2024-04-01',
                '2024-09-30',
                'true',
            ],
            [
                'HR',
                'Semi-Skilled',
                '12000.00',
                '500.00',
                '2024-04-01',
                '',
                '1',
            ],
        ];

        $filename = 'minimum_wage_masters_template.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            fputs($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function normalizeHeaders(array $headers): array
    {
        $headers[0] = preg_replace('/[\x{FEFF}\xEF\xBB\xBF]/u', '', (string) $headers[0]);
        return array_map(fn ($header) => strtolower(trim((string) $header)), $headers);
    }

    private function parseActiveValue(string $value): bool
    {
        if (trim($value) === '') {
            return true;
        }

        return in_array(strtolower(trim($value)), ['true', 'yes', 'active', '1'], true);
    }
}