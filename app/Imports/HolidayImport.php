<?php

namespace App\Imports;

use App\Models\Holiday;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HolidayImport
{
    protected array $errors = [];
    protected array $rawHeaders = [];

    /**
     * Import holidays from an uploaded CSV file.
     */
    public function import(UploadedFile $file): int
    {
        $this->errors = [];
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            throw new \Exception('Unable to open the uploaded file.');
        }

        $firstRow = fgetcsv($handle);
        if (!is_array($firstRow) || empty($firstRow)) {
            fclose($handle);
            throw new \Exception('The uploaded CSV file is empty.');
        }

        // UTF-8 BOM trim
        $firstRow[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $firstRow[0]);
        $this->rawHeaders = array_map('trim', $firstRow);
        $headers = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $firstRow);

        $dateCol   = null;
        $titleCol  = null;
        $typeCol   = null;
        $activeCol = null;

        foreach ($headers as $index => $header) {
            if (in_array($header, ['holiday_date', 'date', 'holiday date'], true)) {
                $dateCol = $index;
            } elseif (in_array($header, ['holiday_title', 'title', 'holiday title', 'occasion'], true)) {
                $titleCol = $index;
            } elseif (in_array($header, ['holiday_type', 'type', 'holiday type'], true)) {
                $typeCol = $index;
            } elseif (in_array($header, ['is_active', 'active', 'status'], true)) {
                $activeCol = $index;
            }
        }

        if ($dateCol === null || $titleCol === null) {
            fclose($handle);
            throw new \Exception('Invalid CSV structure. Missing holiday_date or holiday_title column.');
        }

        $rowNumber = 1;
        $seenDates = [];
        $recordsToInsert = [];
        $userId = Auth::id();

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (empty(array_filter($row, fn ($val) => trim((string) $val) !== ''))) {
                continue;
            }

            $rowErrors = [];
            $rawDate  = trim((string) ($row[$dateCol] ?? ''));
            $rawTitle = trim((string) ($row[$titleCol] ?? ''));
            $rawType  = strtolower(trim((string) ($typeCol !== null ? ($row[$typeCol] ?? '') : '')));
            $rawActive = strtolower(trim((string) ($activeCol !== null ? ($row[$activeCol] ?? '') : '')));

            // 1. Validate holiday_date
            $formattedDate = null;
            if ($rawDate === '') {
                $rowErrors[] = 'Holiday date is required.';
            } else {
                try {
                    $formattedDate = Carbon::parse($rawDate)->format('Y-m-d');
                } catch (\Throwable) {
                    $rowErrors[] = "Invalid holiday date format: '{$rawDate}'. Expected YYYY-MM-DD.";
                }
            }

            if ($formattedDate) {
                if (isset($seenDates[$formattedDate])) {
                    $rowErrors[] = "Duplicate holiday date '{$formattedDate}' found within the CSV file.";
                } else {
                    $seenDates[$formattedDate] = true;

                    $alreadyExists = Holiday::where('is_delete', false)
                        ->whereDate('holiday_date', $formattedDate)
                        ->exists();

                    if ($alreadyExists) {
                        $rowErrors[] = "A holiday on date '{$formattedDate}' already exists in the system.";
                    }
                }
            }

            // 2. Validate holiday_title
            if ($rawTitle === '') {
                $rowErrors[] = 'Holiday title is required.';
            } elseif (mb_strlen($rawTitle) > 255) {
                $rowErrors[] = 'Holiday title cannot exceed 255 characters.';
            }

            // 3. Validate holiday_type
            $holidayType = 'full day';
            if ($rawType !== '') {
                if (in_array($rawType, ['full day', 'half day'], true)) {
                    $holidayType = $rawType;
                } elseif ($rawType === 'full') {
                    $holidayType = 'full day';
                } elseif ($rawType === 'half') {
                    $holidayType = 'half day';
                } else {
                    $rowErrors[] = "Invalid holiday type: '{$rawType}'. Accepted values: 'full day', 'half day'.";
                }
            }

            // 4. Validate is_active
            $isActive = true;
            if ($rawActive !== '') {
                $isActive = in_array($rawActive, ['1', 'true', 'yes', 'active'], true);
            }

            if (!empty($rowErrors)) {
                $this->errors[] = [
                    'row'      => $rowNumber,
                    'errors'   => $rowErrors,
                    'row_data' => $row,
                ];
                continue;
            }

            $recordsToInsert[] = [
                'holiday_date'  => $formattedDate,
                'holiday_title' => $rawTitle,
                'holiday_type'  => $holidayType,
                'is_active'     => $isActive,
                'is_delete'     => false,
                'created_by'    => $userId,
            ];
        }

        fclose($handle);

        if (!empty($recordsToInsert)) {
            DB::transaction(function () use ($recordsToInsert): void {
                foreach ($recordsToInsert as $record) {
                    Holiday::create($record);
                }
            });
        }

        return count($recordsToInsert);
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
        $filename = 'holiday_errors_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.csv';
        $path = 'holiday-import-errors/' . $filename;

        $handle = fopen('php://temp', 'r+');
        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $headers = array_merge($this->rawHeaders ?: ['holiday_date', 'holiday_title', 'holiday_type', 'is_active'], ['errors']);
        fputcsv($handle, $headers);

        foreach ($this->errors as $error) {
            $rowData  = $error['row_data'] ?? [];
            $errorMsg = implode('; ', (array) ($error['errors'] ?? []));
            fputcsv($handle, array_merge($rowData, [$errorMsg]));
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        Storage::disk('local')->put($path, $content);

        return $path;
    }

    public function downloadTemplate(): StreamedResponse
    {
        $filename = 'holidays_import_template.csv';

        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, ['holiday_date', 'holiday_title', 'holiday_type', 'is_active']);
            fputcsv($handle, [now()->format('Y') . '-01-26', 'Republic Day', 'full day', '1']);
            fputcsv($handle, [now()->format('Y') . '-08-15', 'Independence Day', 'full day', '1']);
            fputcsv($handle, [now()->format('Y') . '-10-02', 'Gandhi Jayanti', 'half day', '1']);

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}