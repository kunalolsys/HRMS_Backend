<?php

namespace App\Http\Traits;

use Illuminate\Support\Facades\Storage;

trait HandlesImport
{
    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $errors = [];

    /**
     * Check if there are any import errors.
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Get all import errors.
     *
     * @return array<int, array<string, mixed>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Save rejected rows and return the local storage filename.
     */
    public function storeErrors(): string
    {
        $output = fopen('php://memory', 'r+');
        fputcsv($output, array_merge(['row'], static::HEADERS, ['error']));

        foreach ($this->errors as $error) {
            fputcsv($output, $error);
        }

        rewind($output);
        $contents = stream_get_contents($output);
        fclose($output);

        $filename = $this->getStoragePath() . '/' . uniqid('errors-', true) . '.csv';
        Storage::disk('local')->put($filename, $contents);

        return $filename;
    }

    /**
     * Normalize CSV headers, including removing BOM.
     *
     * @param array<int, string> $headers
     * @return array<int, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        if (isset($headers[0])) {
            // Remove UTF-8 BOM
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        }

        return array_map(static fn ($header) => trim((string) $header), $headers);
    }

    /**
     * Add an error to the errors array.
     *
     * @param array<string, string> $data
     * @param array<int, string> $messages
     */
    private function addError(int $row, array $data, array $messages): void
    {
        $errorData = ['row' => $row];
        foreach (static::HEADERS as $header) {
            $errorData[$header] = $data[$header] ?? '';
        }
        $errorData['error'] = implode(' ', $messages);

        $this->errors[] = $errorData;
    }

    /**
     * Parse a string value into a boolean for 'is_active' fields.
     */
    private function parseActiveValue(string $value): bool
    {
        $normalizedValue = strtolower(trim($value));

        if ($normalizedValue === '') {
            return true; // Default to true if empty
        }

        return in_array($normalizedValue, ['true', 'yes', 'active', '1'], true);
    }

    /**
     * Provide a user-friendly error message for common exceptions.
     */
    private function friendlyError(\Throwable $exception): string
    {
        if ($exception instanceof \Illuminate\Database\QueryException && $exception->getCode() === '23000') {
            return 'A unique value that already exists was provided.';
        }

        return 'The row could not be imported due to an unexpected error.';
    }
}