<?php

namespace App\Imports;
use App\Http\Traits\HandlesImport;

use App\Models\Brand;
use App\Models\Division;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BrandImport
{
    use HandlesImport;

    /**
     * @var array<int, array{row: int, brand_code: string, brand_name: string, divisions: string, is_active: string, error: string}>
     */

    private const HEADERS = ['brand_code', 'brand_name', 'divisions', 'is_active'];

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

            if ($headers !== self::HEADERS) {
                $this->addError(1, [], ['The CSV header must contain brand_code, brand_name, divisions and is_active.']);
                return 0;
            }

            $rowNumber = 1;
            $importedBrandNames = [];
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $data = [];
                foreach (self::HEADERS as $index => $header) {
                    $data[$header] = trim((string) ($row[$index] ?? ''));
                }
                $data['is_active'] = strtolower($data['is_active']);

                $normalizedBrandName = mb_strtolower($data['brand_name']);

                $validator = Validator::make($data, [
                    'brand_code' => [
                        'required',
                        'string',
                        'max:255',
                        Rule::unique('brands', 'brand_code')->where(fn ($query) => $query->where('is_delete', false)),
                    ],
                    'brand_name' => [
                        'required',
                        'string',
                        'max:255',
                    ],
                    'divisions' => [
                        'required',
                        'string',
                        'max:255',
                    ],
                    'is_active' => [
                        'nullable',
                        'string',
                        'in:true,false,yes,no,active,inactive,1,0',
                    ],
                ], [
                    'brand_code.required' => 'Brand code is required.',
                    'brand_name.required' => 'Brand name is required.',
                    'divisions.required' => 'Division is required.',
                    'is_active.in' => 'is_active must be true, false, yes, no, active, inactive, 1 or 0.',
                ]);

                if (isset($importedBrandNames[$normalizedBrandName])) {
                    $validator->after(function ($validator) use ($data): void {
                        $validator->errors()->add('brand_name', 'The brand name already exists in this file.');
                    });
                }

                if (Brand::where('is_delete', false)
                    ->whereRaw('LOWER(brand_name) = ?', [$normalizedBrandName])
                    ->exists()) {
                    $validator->after(function ($validator): void {
                        $validator->errors()->add('brand_name', 'The brand name already exists.');
                    });
                }

                $deletedBrand = $this->findDeletedDuplicateBrand($data);

                if ($deletedBrand) {
                    $validator->after(function ($validator) use ($deletedBrand): void {
                        $validator->errors()->add(
                            'brand',
                            "This entry already exists in deleted records (Brand ID: {$deletedBrand->id}). Please restore it before importing."
                        );
                    });
                }

                if ($validator->fails()) {
                    $this->addError($rowNumber, $data, $validator->errors()->all());
                    continue;
                }

                try {
                    $divisionNames = $this->divisionNames($data['divisions']);

                    if ($divisionNames === []) {
                        $this->addError($rowNumber, $data, ['Division is required.']);
                        continue;
                    }

                    $divisions = Division::whereIn('name', $divisionNames)->get();
                    $foundDivisions = $divisions->keyBy(fn (Division $division) => strtolower(trim($division->name)));
                    $missingDivisions = array_values(array_filter(
                        $divisionNames,
                        fn (string $name): bool => !$foundDivisions->has(strtolower($name))
                    ));

                    if ($missingDivisions !== []) {
                        $this->addError($rowNumber, $data, [
                            'Division not found: ' . implode(', ', $missingDivisions) . '.',
                        ]);
                        continue;
                    }

                    $invalidDivisions = $divisions->filter(
                        fn (Division $division): bool => $division->is_delete || !$division->is_active
                    );

                    if ($invalidDivisions->isNotEmpty()) {
                        $divisionErrors = $invalidDivisions->map(function (Division $division): string {
                            if ($division->is_delete) {
                                return "Division {$division->name} is deleted.";
                            }

                            return "Division {$division->name} is inactive and cannot be assigned.";
                        })->all();
                        $this->addError($rowNumber, $data, $divisionErrors);
                        continue;
                    }

                    DB::transaction(function () use ($data, $divisions): void {
                        $isActive = $this->parseActiveValue($data['is_active']);
                        $brand = Brand::create([
                            'brand_code' => $data['brand_code'],
                            'brand_name' => $data['brand_name'],
                            'is_active' => $isActive,
                        ]);

                        $brand->divisions()->sync($divisions->pluck('id')->all());
                    });
                    $importedBrandNames[$normalizedBrandName] = true;
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

    //For downloading the template for brand import
    public function downloadTemplate()
    {
        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, self::HEADERS);
            fputcsv($output, ['BRAND_CODE_TEXT', 'BRAND_NAME_TEXT', 'DIVISION_NAME_TEXT', 'true']);
            fclose($output);
        }, 'brand-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Save rejected rows and return the local storage filename.
     */
    private function getStoragePath(): string
    {
        return 'brand-import-errors';
    }

    /**
     * @return array<int, string>
     */
    private function divisionNames(string $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $name): string => trim($name),
            explode(',', $value)
        ))));
    }

    private function findDeletedDuplicateBrand(array $data): ?Brand
    {
        $brandCode = mb_strtolower(trim((string) ($data['brand_code'] ?? '')));
        $brandName = mb_strtolower(trim((string) ($data['brand_name'] ?? '')));

        $hasCode = $brandCode !== '';
        $hasName = $brandName !== '';

        if (!$hasCode && !$hasName) {
            return null;
        }

        return Brand::withTrashed()
            ->where(function ($query): void {
                $query->where('is_delete', true)
                    ->orWhereNotNull('deleted_at');
            })
            ->where(function ($query) use ($brandCode, $brandName, $hasCode, $hasName): void {
                if ($hasCode) {
                    $query->whereRaw('LOWER(brand_code) = ?', [$brandCode]);
                }
                if ($hasName) {
                    $method = $hasCode ? 'orWhereRaw' : 'whereRaw';
                    $query->{$method}('LOWER(brand_name) = ?', [$brandName]);
                }
            })
            ->orderByRaw(
                'CASE WHEN LOWER(brand_code) = ? AND LOWER(brand_name) = ? THEN 0 ELSE 1 END',
                [$brandCode, $brandName]
            )
            ->first();
    }
}
