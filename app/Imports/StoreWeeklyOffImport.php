<?php

namespace App\Imports;

use App\Http\Traits\HandlesImport;
use App\Models\Location;
use App\Models\StoreWeeklyOff;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StoreWeeklyOffImport
{
    use HandlesImport;

    private const HEADERS = ['location_name', 'weekly_off_day', 'is_active'];
    private const ALT_HEADERS = ['location_code', 'weekly_off_day', 'is_active'];

    public function import(UploadedFile $file): int
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to read the import file.');
        }

        try {
            $headers = fgetcsv($handle);
            $headers = $headers === false ? [] : $this->normalizeHeaders($headers);

            $isAlternate = ($headers === self::ALT_HEADERS);
            if ($headers !== self::HEADERS && !$isAlternate) {
                $this->addError(1, [], ['The CSV header does not match the store weekly off import structure.']);
                return 0;
            }

            $imported = 0;
            $rowNumber = 1;
            $importedCombinations = [];

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                if (empty(array_filter($row, fn ($val) => trim((string) $val) !== ''))) {
                    continue;
                }

                $data = [];
                $headerKeys = $isAlternate ? self::ALT_HEADERS : self::HEADERS;
                foreach ($headerKeys as $index => $header) {
                    $data[$header] = trim((string) ($row[$index] ?? ''));
                }

                if ($isAlternate) {
                    $data['location_name'] = $data['location_code'];
                    unset($data['location_code']);
                }

                $data['is_active'] = strtolower($data['is_active']);

                $rawLocation = $data['location_name'];
                $rawDay = $data['weekly_off_day'];
                $formattedDay = ucfirst(strtolower($rawDay));

                $validator = Validator::make($data, [
                    'location_name'  => ['required', 'string', 'max:255'],
                    'weekly_off_day' => ['required', 'string', 'max:255'],
                    'is_active'      => ['nullable', 'string', 'in:true,false,yes,no,active,inactive,1,0'],
                ], [
                    'location_name.required'  => 'Location name is required.',
                    'weekly_off_day.required' => 'Weekly off day is required.',
                    'is_active.in'            => 'is_active must be true, false, yes, no, active, inactive, 1 or 0.',
                ]);

                if ($rawDay !== '' && !in_array($formattedDay, StoreWeeklyOff::VALID_DAYS, true)) {
                    $validator->after(function ($validator) use ($rawDay): void {
                        $validator->errors()->add('weekly_off_day', "Invalid weekly off day '{$rawDay}'. Allowed days are: " . implode(', ', StoreWeeklyOff::VALID_DAYS) . '.');
                    });
                }

                $location = null;
                if ($rawLocation !== '') {
                    $location = Location::where('is_delete', false)
                        ->where(function ($q) use ($rawLocation) {
                            $q->whereRaw('LOWER(location_code) = ?', [mb_strtolower($rawLocation)])
                              ->orWhereRaw('LOWER(location_name) = ?', [mb_strtolower($rawLocation)]);
                        })
                        ->first();

                    if (!$location) {
                        $validator->after(function ($validator) use ($rawLocation): void {
                            $validator->errors()->add('location_name', "Location '{$rawLocation}' does not exist.");
                        });
                    }
                }

                if ($location && in_array($formattedDay, StoreWeeklyOff::VALID_DAYS, true)) {
                    $comboKey = $location->id . '_' . strtolower($formattedDay);

                    if (isset($importedCombinations[$comboKey])) {
                        $validator->after(function ($validator) use ($formattedDay, $rawLocation): void {
                            $validator->errors()->add('weekly_off_day', "Weekly off day '{$formattedDay}' for location '{$rawLocation}' is duplicated in this file.");
                        });
                    } else {
                        $existing = StoreWeeklyOff::withTrashed()
                            ->where('location_id', $location->id)
                            ->whereRaw('LOWER(weekly_off_day) = ?', [mb_strtolower($formattedDay)])
                            ->orderBy('is_delete', 'asc')
                            ->orderByDesc('id')
                            ->first();

                        if ($existing) {
                            if ($existing->is_delete) {
                                $message = "Weekly off day '{$formattedDay}' for location '{$rawLocation}' already exists in deleted stage.";
                            } elseif (!$existing->is_active) {
                                $message = "Weekly off day '{$formattedDay}' for location '{$rawLocation}' already exists in deactivated stage.";
                            } else {
                                $message = "Weekly off day '{$formattedDay}' for location '{$rawLocation}' already exists.";
                            }

                            $validator->after(function ($validator) use ($message): void {
                                $validator->errors()->add('weekly_off_day', $message);
                            });
                        }
                    }
                }

                if ($validator->fails()) {
                    $this->addError($rowNumber, $data, $validator->errors()->all());
                    continue;
                }

                try {
                    DB::transaction(function () use ($location, $formattedDay, $data): void {
                        StoreWeeklyOff::create([
                            'location_id'    => $location->id,
                            'weekly_off_day' => $formattedDay,
                            'is_active'      => $this->parseActiveValue((string) ($data['is_active'] ?? '')),
                            'created_by'     => Auth::id(),
                        ]);
                    });

                    $comboKey = $location->id . '_' . strtolower($formattedDay);
                    $importedCombinations[$comboKey] = true;
                    $imported++;
                } catch (Exception $exception) {
                    $this->addError($rowNumber, $data, [$this->friendlyError($exception)]);
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
            fputcsv($output, ['Hisar', 'Sunday', 'true']);
            fclose($output);
        }, 'store-weekly-offs-template.csv', ['Content-Type' => 'text/csv']);
    }

    private function getStoragePath(): string
    {
        return 'store-weekly-off-import-errors';
    }
}