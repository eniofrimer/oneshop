<?php

namespace App\Filament\Support;

use Closure;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class CatalogValidation
{
    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public static function run(Schema $schema, Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (ValidationException $exception) {
            $messages = [];
            $prefix = $schema->getStatePath();
            foreach ($exception->errors() as $field => $errors) {
                $messages[$prefix ? "$prefix.$field" : $field] = $errors;
            }
            throw ValidationException::withMessages($messages);
        }
    }
}
