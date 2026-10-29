<?php

namespace App\Imports;

use App\Http\Controllers\Api\ShortLeaveDeductionSlabController;
use App\Http\Traits\HandlesImport;
use App\Models\ShortLeaveDeductionSlab;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShortLeaveDeductionSlabImport
{
    use HandlesImport;

    private const HEADERS = ['short_leaves_count', 'days_deducted'];

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
                $this->addError(1, [], ['The CSV header does not match the short leave deduction slab import structure.']);
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
                    'short_leaves_count' => [
                        'required',
                        'integer',
                        'min:1',
                    ],
                    'days_deducted' => ['required', 'numeric', 'min:0', 'max:99.9'],
                ]);
                ShortLeaveDeductionSlabController::validateShortLeavesCount(
                    $validator,
                    $data['short_leaves_count']
                );

                if ($data['short_leaves_count'] !== '') {
                    $countKey = (string) $data['short_leaves_count'];
                    if (isset($importedCounts[$countKey])) {
                        $validator->after(function ($validator): void {
                            $validator->errors()->add('short_leaves_count', 'The short leaves count is duplicated in this file.');
                        });
                    }
                }

                if ($validator->fails()) {
                    $this->addError($rowNumber, $data, $validator->errors()->all());
                    continue;
                }

                try {
                    DB::transaction(function () use ($data): void {
                        ShortLeaveDeductionSlab::create([
                            'short_leaves_count' => (int) $data['short_leaves_count'],
                            'days_deducted'      => (float) $data['days_deducted'],
                        ]);
                    });

                    $importedCounts[(string) $data['short_leaves_count']] = true;
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
        }, 'short-leave-deduction-slabs-template.csv', ['Content-Type' => 'text/csv']);
    }

    private function getStoragePath(): string
    {
        return 'short-leave-deduction-slab-import-errors';
    }
}