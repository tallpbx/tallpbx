<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Base service class for standard CRUD operations on Eloquent models.
 *
 * Services that only need basic create, update, and delete operations
 * can extend this class and define the model class, eliminating the
 * need for a separate ServiceInterface and repetitive method bodies.
 *
 * Services with custom business logic beyond CRUD should keep their
 * own interface and method implementations.
 *
 * @template TModel of Model
 */
abstract class CrudService
{
    /**
     * The Eloquent model class to operate on (e.g., Bridge::class).
     * Subclasses must set this in their constructor or via a property.
     *
     * @var class-string<TModel>
     */
    protected string $modelClass;

    /**
     * Create a new record with the given data.
     *
     * @param  array<string, mixed>  $data
     * @return TModel
     */
    public function create(array $data): Model
    {
        $class = $this->modelClass;

        return $class::create($data);
    }

    /**
     * Update an existing record with the given data and return the fresh instance.
     *
     * @param  TModel  $record
     * @param  array<string, mixed>  $data
     * @return TModel
     */
    public function update(Model $record, array $data): Model
    {
        $record->update($data);

        return $record;
    }

    /**
     * Delete a record from the database.
     *
     * @param  TModel  $record
     */
    public function delete(Model $record): void
    {
        $record->delete();
    }
}
