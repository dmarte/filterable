<?php

namespace Dmarte\Filterable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Laravel\Scout\Searchable;

/**
 * Trait Searchable
 *
 * @package App
 */
trait Filterable
{
    use Searchable;


    /**
     * The main entry point to filter your models.
     *
     * IMPORTANT:
     * Please note that you should add the corresponding index to ALGOLIA before query any model type.
     *
     * @param  \Illuminate\Http\Request  $request
     *
     * @return JsonResource
     */
    public static function filter(Request $request): JsonResource
    {
        $model = new static();

        $query = config('scout.driver') ? $model->buildScoutSearch($request) : $model->buildEloquentSearch($request);

        $hasSoftDeletes = property_exists(static::class, 'forceDeleting');

        if ($hasSoftDeletes) {
            $query->withGlobalScope('soft_deletes', new SoftDeletingScope());
        }

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        $perPage = $request->get('per_page', $model->getPerPage());

        if (!$request->boolean(static::keyNameOnRequestForPaginator())) {
            return static::qualifiedResource()::collection($query->take($perPage)->get());
        }

        return static::qualifiedResource()::collection($query->paginate($perPage));
    }

    protected static function qualifiedResource(): string
    {
        return '\\App\\Http\\Resources\\'.substr(static::class, strripos(static::class, '\\') + 1).'Resource';
    }

    /**
     * Get the name of the key used to search globally.
     *
     * @return string
     */
    protected static function keyNameOnRequestForFullTextSearch(): string
    {
        return 'search';
    }

    /**
     * Get the key used to catch the value of the column to be sorted.
     *
     * @return string
     */
    protected static function keyNameOnRequestForSortBy(): string
    {
        return 'sort_by';
    }

    /**
     * @return string
     */
    protected static function keyNameOnRequestForSortDesc(): string
    {
        return 'sort_desc';
    }

    /**
     * @return string
     */
    protected static function keyNameOnRequestForPaginator(): string
    {
        return 'paginator';
    }

    /**
     * @return array
     */
    protected static function fullTextColumns(): array
    {
        return [];
    }

    /**
     * This method let you specify the query you want
     * to be used for each column that must be queried into the DB.
     *
     * Each "key" must be the name of the eloquent column or relationship.
     * Each "value" must be callback that receive the $query, $column and $value an return the modified query.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function filterableQueries(): Collection
    {
        return collect($this->getFillable())
            ->mapWithKeys(fn($value) => [
                $value => fn(
                    Builder $query,
                    $column,
                    $value
                ) => $query->where("{$this->getTable()}.{$column}", $value),
            ]);
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     *
     * @return \Laravel\Scout\Builder
     */
    protected function buildScoutSearch(Request $request): \Laravel\Scout\Builder
    {
        $query = static::search($request->get(static::keyNameOnRequestForFullTextSearch()));
        $fields = $this->filterableQueries()->keys();
        $values = $request->only($fields->keys()->toArray());

        foreach ($values as $field => $value) {
            if (!is_string($value) || !is_numeric($value)) {
                continue;
            }
            $query->where($field, $value);
        }

        return $query;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  Request  $request
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function buildEloquentQuery(Builder $query, Request $request): Builder
    {
        return $query;
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function buildEloquentSearch(Request $request): Builder
    {
        $query = $this->newModelQuery();
        $fields = $this->filterableQueries();
        $values = $request->only($fields->keys()->toArray());

        $fieldsUsed = [];

        foreach ($values as $field => $value) {
            ($fields->offsetGet($field))($query, $field, $value);
            array_push($fieldsUsed, $field);
        }

        if ($request->filled('with')) {
            $query->with($request->get('with'));
        }

        $this->buildEloquentSort($request, $fields, $query);

        $this->searchableBuildFullTextSearch($request, $query);

        return $this->buildEloquentQuery($query, $request);
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Support\Collection  $fields
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    protected function buildEloquentSort(Request $request, Collection $fields, Builder $query): void
    {
        $sortByColumn = $request->get(static::keyNameOnRequestForSortBy(), static::keyNameOnRequestForSortBy());
        $sortByDirection = $request->boolean(static::keyNameOnRequestForSortDesc()) ? 'desc' : 'asc';

        if (is_array($sortByColumn)) {
            $sortByColumn = current($sortByColumn);
        }

        if (!$fields->offsetExists($sortByColumn)) {
            $sortByColumn = $this->getKeyName();
        }

        if (!str_contains($sortByColumn, '.')) {
            $query->orderBy($sortByColumn, $sortByDirection);

            return;
        }

        $dot = strpos($sortByColumn, '.');
        $relation = substr($sortByColumn, 0, $dot);
        $column = substr($sortByColumn, $dot + 1);

        // Prevent non requested relationship being queried.
        if (!method_exists($this, $relation)) {
            return;
        }
        /** @var \Illuminate\Database\Eloquent\Relations\BelongsTo $builder */
        $builder = $this->{$relation}();

        $table = $builder->getRelated()->getTable();
        $primaryKey = $builder->getRelated()->getKeyName();
        $foreignKey = $builder->getQualifiedForeignKeyName();

        $query->join($table, "{$table}.{$primaryKey}", '=', $foreignKey);

        $query->orderBy("{$table}.{$column}", $sortByDirection);
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    protected function searchableBuildFullTextSearch(Request $request, Builder $query): void
    {

        if (!$request->filled(static::keyNameOnRequestForFullTextSearch())) {
            return;
        }

        $query
            ->where(function (Builder $query) use ($request) {

                $search = strtoupper(trim($request->get(static::keyNameOnRequestForFullTextSearch())));

                foreach (static::fullTextColumns() as $index => $key) {

                    $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';

                    // When not relationship just use the normal query
                    if (!str_contains($key, '.')) {
                        $query->{$method}("UPPER(`{$this->getTable()}`.`{$key}`) LIKE '%{$search}%'");
                        continue;
                    }

                    $dot = strpos($key, '.');
                    $relation = substr($key, 0, $dot);
                    $column = substr($key, $dot + 1);

                    // Prevent non requested relationship being queried.
                    if (!method_exists($this, $relation)) {
                        continue;
                    }

                    /** @var \Illuminate\Database\Eloquent\Relations\BelongsTo $builder */
                    $builder = $this->{$relation}();

                    $table = $builder->getRelated()->getTable();
                    $primaryKey = $builder->getRelated()->getKeyName();
                    $foreignKey = $builder->getQualifiedForeignKeyName();

                    $query->{$method}("EXISTS(
                        SELECT `{$relation}`.*
                        FROM {$table} AS {$relation}
                        WHERE
                        `{$relation}`.`{$primaryKey}` = {$foreignKey}
                        AND `{$relation}`.`{$column}` LIKE '%{$search}%'
                    )");
                }

            });
    }
}
