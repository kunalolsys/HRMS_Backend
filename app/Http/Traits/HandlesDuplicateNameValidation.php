<?php

namespace App\Http\Traits;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Eloquent\Model;

trait HandlesDuplicateNameValidation
{
    /**
     * Adds a case-insensitive duplicate name check to a validator.
     *
     * @param \Illuminate\Contracts\Validation\Validator $validator The validator instance.
     * @param class-string<Model> $modelClass The model class to query.
     * @param string $nameColumn The database column name for the name.
     * @param string|null $nameValue The value of the name to check.
     * @param int|null $ignoreId The ID to ignore during the check (for updates).
     * @param string|null $errorMessage The custom error message.
     */
    protected function addNameDuplicateValidation(
        ValidatorContract $validator,
        string $modelClass,
        string $nameColumn,
        ?string $nameValue,
        ?int $ignoreId = null,
        ?string $errorMessage = null
    ): void {
        if ($nameValue === null || trim($nameValue) === '') {
            return;
        }

        $normalizedName = mb_strtolower(trim($nameValue));

        $query = $modelClass::where('is_delete', false)->whereRaw("LOWER({$nameColumn}) = ?", [$normalizedName]);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            $errorMessage = $errorMessage ?? "The {$nameColumn} has already been taken.";
            $validator->after(fn ($v) => $v->errors()->add($nameColumn, $errorMessage));
        }
    }
}