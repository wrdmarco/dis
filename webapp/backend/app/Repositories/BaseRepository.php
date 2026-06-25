<?php

namespace App\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TModel of Model
 */
abstract class BaseRepository
{
    /**
     * @return class-string<TModel>
     */
    abstract protected function modelClass(): string;

    /**
     * @return Builder<TModel>
     */
    protected function query(): Builder
    {
        $modelClass = $this->modelClass();

        return $modelClass::query();
    }

    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        return $this->query()->paginate(min(max($perPage, 1), 100));
    }

    /**
     * @param array<string, mixed> $attributes
     * @return TModel
     */
    public function create(array $attributes): Model
    {
        return $this->query()->create($attributes);
    }

    /**
     * @return TModel
     */
    public function findOrFail(string $id): Model
    {
        return $this->query()->findOrFail($id);
    }
}
