<?php

namespace App\Imports;

use App\Http\Traits\HandlesImport;
use App\Models\Designation;
use App\Models\Location;
use App\Models\Shift;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ShiftImport
{
    use HandlesImport;

    private const HEADERS = [
        'shift_code', 'shift_name', 'shift_start_time', 'shift_end_time', 'grace_time',
        'full_day_min_hours', 'short_leave_min_hours', 'half_day_min_hours', 'no_salary_max_hours',
        'effective_from', 'effective_to', 'location_names', 'designation_names', 'is_active'
    ];

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
                $this->addError(1, [], ['The CSV header does not match the shift import structure.']);
                return 0;
            }

            $imported = 0;
            $rowNumber = 1;
            $importedCodes = [];
            $importedNames = [];

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $data = [];
                foreach (self::HEADERS as $index => $header) {
                    $data[$header] = trim((string) ($row[$index] ?? ''));
                }

                $data['is_active'] = strtolower($data['is_active']);

                $normalizedCode = mb_strtolower($data['shift_code']);
                $normalizedName = mb_strtolower($data['shift_name']);

                $validator = Validator::make($data, [
                    'shift_code' => [
                        'required',
                        'string',
                        'max:30',
                        Rule::unique('shifts', 'shift_code')->where(fn ($query) => $query->where('is_delete', false)),
                    ],
                    'shift_name' => 'required|string|max:100',
                    'shift_start_time' => 'required|date_format:H:i:s',
                    'shift_end_time' => 'required|date_format:H:i:s|after:shift_start_time',
                    'grace_time' => 'required|integer|min:0',
                    'full_day_min_hours' => 'required|date_format:H:i:s',
                    'short_leave_min_hours' => 'required|date_format:H:i:s',
                    'half_day_min_hours' => 'required|date_format:H:i:s',
                    'no_salary_max_hours' => 'required|date_format:H:i:s',
                    'effective_from' => 'required|date',
                    'effective_to' => 'required|date|after_or_equal:effective_from',
                    'location_names' => 'required|string',
                    'designation_names' => 'required|string',
                    'is_active' => 'nullable|string|in:true,false,yes,no,active,inactive,1,0',
                ]);

                if ($normalizedCode !== '') {
                    if (isset($importedCodes[$normalizedCode])) {
                        $validator->after(function ($validator): void {
                            $validator->errors()->add('shift_code', 'The shift code already exists in this file.');
                        });
                    }
                }

                if ($normalizedName) {
                    if (isset($importedNames[$normalizedName])) {
                        $validator->after(function ($validator): void {
                            $validator->errors()->add('shift_name', 'The shift name already exists in this file.');
                        });
                    }

                    if (Shift::where('is_delete', false)
                        ->whereRaw('LOWER(shift_name) = ?', [$normalizedName])
                        ->exists()) {
                        $validator->after(function ($validator): void {
                            $validator->errors()->add('shift_name', 'The shift name already exists.');
                        });
                    }
                }

                if ($validator->fails()) {
                    $this->addError($rowNumber, $data, $validator->errors()->all());
                    continue;
                }

                $locationInputs = $this->splitString($data['location_names']);
                $designationInputs = $this->splitString($data['designation_names']);

                $normalizedLocationInputs = array_map('mb_strtolower', $locationInputs);
                $normalizedDesignationInputs = array_map('mb_strtolower', $designationInputs);

                $locations = Location::where('is_delete', false)->where('is_active', true)
                    ->where(function ($query) use ($normalizedLocationInputs): void {
                        $query->whereIn(DB::raw('LOWER(location_code)'), $normalizedLocationInputs)
                              ->orWhereIn(DB::raw('LOWER(location_name)'), $normalizedLocationInputs);
                    })
                    ->get();

                $designations = Designation::where('is_delete', false)->where('is_active', true)
                    ->where(function ($query) use ($normalizedDesignationInputs): void {
                        $query->whereIn(DB::raw('LOWER(code)'), $normalizedDesignationInputs)
                              ->orWhereIn(DB::raw('LOWER(name)'), $normalizedDesignationInputs);
                    })
                    ->get();

                $foundLocations = [];
                foreach ($locations as $loc) {
                    $foundLocations[mb_strtolower(trim($loc->location_code))] = $loc;
                    $foundLocations[mb_strtolower(trim($loc->location_name))] = $loc;
                }

                $foundDesignations = [];
                foreach ($designations as $desg) {
                    $foundDesignations[mb_strtolower(trim($desg->code))] = $desg;
                    $foundDesignations[mb_strtolower(trim($desg->name))] = $desg;
                }

                $missingLocations = array_values(array_filter(
                    $locationInputs,
                    fn (string $input): bool => !isset($foundLocations[mb_strtolower($input)])
                ));
                $missingDesignations = array_values(array_filter(
                    $designationInputs,
                    fn (string $input): bool => !isset($foundDesignations[mb_strtolower($input)])
                ));

                $errors = [];
                if (!empty($missingLocations)) {
                    $errors[] = 'Locations not found (by code or name): ' . implode(', ', $missingLocations);
                }
                if (!empty($missingDesignations)) {
                    $errors[] = 'Designations not found (by code or name): ' . implode(', ', $missingDesignations);
                }

                if (!empty($errors)) {
                    $this->addError($rowNumber, $data, $errors);
                    continue;
                }

                try {
                    DB::transaction(function () use ($data, $locations, $designations): void {
                        $shiftData = array_map(fn ($value) => $value === '' ? null : $value, $data);
                        unset($shiftData['location_names'], $shiftData['designation_names']);

                        // --- Convert HH:MM:SS fields to Minutes ---
                        $timeFieldsToConvert = [                           
                            'full_day_min_hours',
                            'short_leave_min_hours',
                            'half_day_min_hours',
                            'no_salary_max_hours',
                        ];

                        foreach ($timeFieldsToConvert as $field) {
                            if (!empty($shiftData[$field])) {
                                $shiftData[$field] = $this->timeToMinutes($shiftData[$field]);
                            }
                        }

                        $shiftData['is_active'] = $this->parseActiveValue((string) ($data['is_active'] ?? ''));
                        $shiftData['created_by'] = Auth::id();

                        $shift = Shift::create($shiftData);

                        $associations = [];
                        foreach ($locations->unique('id') as $location) {
                            foreach ($designations->unique('id') as $designation) {
                                $associations[] = ['location_id' => $location->id, 'designation_id' => $designation->id];
                            }
                        }
                        $shift->shiftLocationDesignations()->createMany($associations);
                    });

                    $importedCodes[$normalizedCode] = true;
                    $importedNames[$normalizedName] = true;
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

    public function downloadTemplate()
    {
        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, self::HEADERS);
            fputcsv($output, [
                'SFT001', 'Morning Shift', '09:00:00', '18:00:00', '15',
                '08:00:00', '02:00:00', '04:00:00', '08:30:00',
                '2024-01-01', '2025-12-31', 'Head Office', 'Software Engineer', 'true'
            ]);
            fclose($output);
        }, 'shift-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    private function getStoragePath(): string
    {
        return 'shift-import-errors';
    }

    private function splitString(string $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', $value)
        ))));
    }

    /**
     * Convert HH:MM:SS string to total minutes (Integer)
     * Example: "08:30:00" -> (8 * 60) + 30 = 510 minutes
     */
    private function timeToMinutes(?string $timeString): int
    {
        if (!$timeString) {
            return 0;
        }

        $parts = explode(':', $timeString);
        $hours = (int) ($parts[0] ?? 0);
        $minutes = (int) ($parts[1] ?? 0);

        return ($hours * 60) + $minutes;
    }
}