<?php

namespace Dmarte\Filterable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
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
     * @return JsonResource|Collection
     */
    public static function filter(Request $request): JsonResource|Collection|LengthAwarePaginator
    {
        $model = new static();

        $query = config('scout.driver') ? $model->buildScoutSearch($request) : $model->buildEloquentSearch($request);

        $hasSoftDeletes = property_exists(static::class, 'forceDeleting');

        if ($hasSoftDeletes && !$request->boolean('with_trashed') && !config('scout.driver')) {
            $query->withGlobalScope('soft_deletes', new SoftDeletingScope());
        }

        $perPage = $request->get('per_page', $model->getPerPage());

        return static::qualifiedResultType($request, $query, $perPage);
    }

    public static function filterQuery(Request $request): Builder
    {
        $model = new static();

        return $model->buildEloquentSearch($request);
    }

    protected static function qualifiedResultJson(Request $request, Builder|\Laravel\Scout\Builder $query, $perPage = 15)
    {

        if (!class_exists(static::qualifiedResource()) && !$request->boolean(static::keyNameOnRequestForPaginator())) {
            return $query->take($perPage)->get();
        }

        if (!class_exists(static::qualifiedResource()) && $request->boolean(static::keyNameOnRequestForPaginator())) {
            return $query->paginate($perPage);
        }

        if (!$request->boolean(static::keyNameOnRequestForPaginator())) {
            return static::qualifiedResource()::collection($query->take($perPage)->get());
        }

        return static::qualifiedResource()::collection($query->paginate($perPage));
    }

    protected static function qualifiedResultType(Request $request, Builder|\Laravel\Scout\Builder $query, $perPage = 15)
    {
        if ($request->wantsJson()) {
            return static::qualifiedResultJson($request, $query, $perPage);
        }

        if ($request->filled(static::keyNameOnRequestForPaginator()) && !$request->boolean(static::keyNameOnRequestForPaginator())) {
            return $query->take($perPage)->get();
        }

        return $query->paginate($perPage);
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

    protected static function KeyNameOnRequestForWhereNull(): string {
        return 'whereNull';
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
     * Indicate the list of columns that should
     * be allowed to search with full text functionality.
     *
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
     * Please read the limitations on scout where clauses.
     *
     * @link https://laravel.com/docs/8.x/scout#where-clauses
     * @return \Illuminate\Support\Collection
     */
    protected function filterableScoutQueries() {
        return collect();
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     *
     * @return \Laravel\Scout\Builder
     */
    protected function buildScoutSearch(Request $request): \Laravel\Scout\Builder
    {
        $query = static::search($request->get(static::keyNameOnRequestForFullTextSearch()));
        $fields = $this->filterableScoutQueries();
        $values = $request->only($fields->keys()->toArray());

        foreach ($values as $field => $value) ($fields->offsetGet($field))($query, $field, $value);

        $this->buildEloquentSort($request, $fields, $query);

        return $this->buildEloquentQuery($query, $request);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  Request  $request
     *
     * @return \Illuminate\Database\Eloquent\Builder|\Laravel\Scout\Builder|\Algolia\ScoutExtended\Builder
     */
    protected function buildEloquentQuery(Builder|\Laravel\Scout\Builder $query, Request $request)
    {
        return $query;
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     *
     * @return \Illuminate\Database\Eloquent\Builder|\Algolia\ScoutExtended\Builder
     */
    protected function buildEloquentSearch(Request $request)
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

        $this->buildEloquentWhereNullSearch($request, $query);

        return $this->buildEloquentQuery($query, $request);
    }

    /**
     * @param Request $request
     * @param Builder $query
     */
    protected function buildEloquentWhereNullSearch(Request $request, Builder $query): void {
        $whereNullColumns = $request->get(static::KeyNameOnRequestForWhereNull(), []);
        $query->whereNull($whereNullColumns);
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Support\Collection  $fields
     * @param  \Illuminate\Database\Eloquent\Builder|\Laravel\Scout\Builder  $query
     */
    protected function buildEloquentSort(Request $request, Collection $fields, Builder|\Laravel\Scout\Builder $query): void
    {
        $sortByColumn = $request->get(static::keyNameOnRequestForSortBy(), $this->getKeyName());
        $sortByDirection = $request->boolean(static::keyNameOnRequestForSortDesc()) ? 'desc' : 'asc';

        if (is_array($sortByColumn)) {
            $sortByColumn = current($sortByColumn);
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
