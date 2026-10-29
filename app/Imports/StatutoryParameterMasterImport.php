<?php

namespace App\Imports;

use App\Models\StatutoryParameterMaster;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StatutoryParameterMasterImport
{
    protected array $errors = [];

    private const DEFAULT_EFFECTIVE_TO = '2099-12-31';

    public function errors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Store rejected rows into local storage disk.
     */
    public function storeErrors(): string
    {
        $filename = 'statutory_parameter_errors_' . time() . '_' . Str::random(6) . '.csv';
        $path     = 'statutory-parameter-import-errors/' . $filename;

        $handle = fopen('php://temp', 'r+');
        fputs($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['row', 'rule_code', 'error']);

        foreach ($this->errors as $error) {
            $messages = is_array($error['errors'] ?? null)
                ? implode('; ', $error['errors'])
                : ($error['error'] ?? 'Unknown error');

            fputcsv($handle, [
                $error['row'] ?? '',
                $error['rule_code'] ?? '',
                $messages,
            ]);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        Storage::disk('local')->put($path, $content);

        return $filename;
    }

    /**
     * Download template for statutory parameter CSV import.
     */
    public function downloadTemplate(): StreamedResponse
    {
        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="statutory_parameters_template.csv"',
        ];

        return response()->stream(function () {
            $handle = fopen('php://output', 'w');
            fputs($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'rule_code',
                'description',
                'pf_employee_rate',
                'pf_employer_rate',
                'pf_wage_ceiling',
                'pf_eps_rate',
                'pf_eps_max_cap',
                'pf_start_month',
                'esi_employee_rate',
                'esi_employer_rate',
                'esi_gross_ceiling',
                'esi_start_month',
                'total_deduction_cap_pct',
                'effective_from',
                'effective_to',
                'is_active',
            ]);

            fputcsv($handle, [
                'RULE_STD_01',
                'Standard PF and ESI Rules',
                '12.00',
                '12.00',
                '15000.00',
                '8.33',
                '1250.00',
                '4',
                '0.75',
                '3.25',
                '21000.00',
                '4',
                '50.00',
                '2024-04-01',
                self::DEFAULT_EFFECTIVE_TO,
                '1',
            ]);

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Import statutory parameter records from CSV.
     */
    public function import($file): int
    {
        $imported = 0;
        $filePath = is_string($file) ? $file : $file->getRealPath();

        if (($handle = fopen($filePath, 'r')) === false) {
            throw new \RuntimeException('Unable to open CSV file.');
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            throw new \RuntimeException('CSV file is empty.');
        }

        $header[0] = preg_replace('/[\xEF\xBB\xBF]/', '', $header[0]);
        $header    = array_map(fn ($h) => trim(strtolower($h)), $header);

        $rowIndex = 1;
        $authId   = Auth::id();

        while (($row = fgetcsv($handle)) !== false) {
            $rowIndex++;

            if (count(array_filter($row, fn ($val) => trim((string) $val) !== '')) === 0) {
                continue;
            }

            if (count($row) !== count($header)) {
                $this->errors[] = [
                    'row'       => $rowIndex,
                    'rule_code' => $row[0] ?? 'N/A',
                    'error'     => 'Column count mismatch with header.',
                ];
                continue;
            }

            $data = array_combine($header, array_map('trim', $row));
            $isActiveRaw = strtolower((string) ($data['is_active'] ?? '1'));
            $data['is_active'] = in_array($isActiveRaw, ['1', 'true', 'yes', 'active'], true);
            $data['effective_to'] = trim((string) ($data['effective_to'] ?? '')) !== ''
                ? trim((string) $data['effective_to'])
                : self::DEFAULT_EFFECTIVE_TO;
            $data['description']  = !empty($data['description']) ? $data['description'] : null;

            $validator = Validator::make($data, [
                'rule_code'               => ['required', 'string', 'max:50'],
                'description'             => ['nullable', 'string'],
                'pf_employee_rate'        => ['required', 'numeric', 'min:0', 'max:100'],
                'pf_employer_rate'        => ['required', 'numeric', 'min:0', 'max:100'],
                'pf_wage_ceiling'         => ['required', 'numeric', 'min:0'],
                'pf_eps_rate'             => ['required', 'numeric', 'min:0', 'max:100'],
                'pf_eps_max_cap'          => ['required', 'numeric', 'min:0'],
                'pf_start_month'          => ['required', 'integer', 'between:1,12'],
                'esi_employee_rate'       => ['required', 'numeric', 'min:0', 'max:100'],
                'esi_employer_rate'       => ['required', 'numeric', 'min:0', 'max:100'],
                'esi_gross_ceiling'       => ['required', 'numeric', 'min:0'],
                'esi_start_month'         => ['required', 'integer', 'between:1,12'],
                'total_deduction_cap_pct' => ['required', 'numeric', 'min:0', 'max:100'],
                'effective_from'          => ['required', 'date'],
                'effective_to'            => ['nullable', 'date', 'after_or_equal:effective_from'],
                'is_active'               => ['boolean'],
            ]);
            $this->validateRuleCode($validator, $data['rule_code'] ?? null);

            if ($validator->fails()) {
                $this->errors[] = [
                    'row'       => $rowIndex,
                    'rule_code' => $data['rule_code'] ?? 'N/A',
                    'errors'    => $validator->errors()->all(),
                ];
                continue;
            }

            $payload               = $validator->validated();
            $payload['created_by'] = $authId;
            $payload['is_delete']  = false;

            StatutoryParameterMaster::create($payload);
            $imported++;
        }

        fclose($handle);

        return $imported;
    }

    private function validateRuleCode(ValidatorContract $validator, ?string $ruleCode): void
    {
        if ($ruleCode === null || trim($ruleCode) === '') {
            return;
        }

        $validator->after(function ($validator) use ($ruleCode): void {
            $existing = StatutoryParameterMaster::whereRaw('LOWER(rule_code) = ?', [mb_strtolower(trim($ruleCode))])
                ->orderBy('is_delete', 'asc')
                ->orderByDesc('id')
                ->first();

            if (!$existing) {
                return;
            }

            if ($existing->is_delete) {
                $message = 'The rule code already exists in deleted stage.';
            } elseif (!$existing->is_active) {
                $message = 'The rule code already exists in deactivated stage.';
            } else {
                $message = 'The rule code already exists.';
            }

            $validator->errors()->add('rule_code', $message);
        });
    }
}