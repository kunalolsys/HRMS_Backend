<?php

namespace App\Imports;

use App\Http\Traits\HandlesImport;
use App\Models\LateDeductionSlab;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LateDeductionSlabImport
{
    use HandlesImport;

    private const HEADERS = ['lates_count', 'days_deducted'];

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
                $this->addError(1, [], ['The CSV header does not match the late deduction slab import structure.']);
                return 0;
            }

            $imported = 0;
            $rowNumber = 1;
            $importedCounts = [];

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                if (empty(array_filter($row, fn ($val) => trim((string) $val) !== ''))) {
                    continue;
                }

                $data = [];
                foreach (self::HEADERS as $index => $header) {
                    $data[$header] = trim((string) ($row[$index] ?? ''));
                }

                $validator = Validator::make($data, [
                    'lates_count' => ['required', 'integer', 'min:1'],
                    'days_deducted' => ['required', 'numeric', 'min:0', 'max:99.9'],
                ]);

                if ($data['lates_count'] !== '' && is_numeric($data['lates_count'])) {
                    $existing = LateDeductionSlab::where('lates_count', (int) $data['lates_count'])
                        ->orderBy('is_delete', 'asc')
                        ->orderByDesc('id')
                        ->first();

                    if ($existing) {
                        if ($existing->is_delete) {
                            $message = 'The lates count already exists in deleted stage.';
                        } elseif (!$existing->is_active) {
                            $message = 'The lates count already exists in deactivated stage.';
                        } else {
                            $message = 'The lates count already exists.';
                        }

                        $validator->after(function ($validator) use ($message): void {
                            $validator->errors()->add('lates_count', $message);
                        });
                    }
                }

                if ($data['lates_count'] !== '') {
                    $countKey = (string) $data['lates_count'];
                    if (isset($importedCounts[$countKey])) {
                        $validator->after(function ($validator): void {
                            $validator->errors()->add('lates_count', 'The lates count is duplicated in this file.');
                        });
                    }
                }

                if ($validator->fails()) {
                    $this->addError($rowNumber, $data, $validator->errors()->all());
                    continue;
                }

                try {
                    DB::transaction(function () use ($data): void {
                        LateDeductionSlab::create([
                            'lates_count'   => (int) $data['lates_count'],
                            'days_deducted' => (float) $data['days_deducted'],
                        ]);
                    });

                    $importedCounts[(string) $data['lates_count']] = true;
                    $imported++;
                } catch (Exception $exception) {
                    $this->addError($rowNumber, $data, [$this->friendlyError($exception->getMessage())]);
                }
            }

            return $imported;
        } finally {
            fclose($handle);
        }
    }

    public function downloadTemplate(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, self::HEADERS);
            fputcsv($output, [3, 0.5]);
            fclose($output);
        }, 'late-deduction-slabs-template.csv', ['Content-Type' => 'text/csv']);
    }

    private function getStoragePath(): string
    {
        return 'late-deduction-slab-import-errors';
    }
}