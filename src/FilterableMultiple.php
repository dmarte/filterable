<?php


namespace Dmarte\Filterable;


use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FilterableMultiple
{
    private array $filters = [];

    /**
     * FilterableMultiple constructor.
     *
     * @param  array<string>  $models
     * @param  Request  $request
     * @param  int  $hitsPerModel
     * @param  int  $maxHitsPerPage
     */
    public function __construct(private array $models, private Request $request, private int $hitsPerModel = 5, private int $maxHitsPerPage = 10)
    {
        $this->request->merge([
            'paginator' => false,
            'per_page'  => $this->hitsPerModel,
        ]);
    }

    public function query(string $model, \Closure $callback): static
    {
        $this->filters[$model] = $callback;

        return $this;
    }

    public function get(): \Illuminate\Support\Collection
    {
        $collection = [];

        foreach ($this->models as $model) {
            $class = new $model();
            if (!isset($collection[$class->getMorphClass()])) {
                $collection[$class->getMorphClass()] = [];
            }

            $query = $model::filterQuery($this->request);

            if (isset($this->filters[$model])) {
                $this->filters[$model]($query, $this->request);
            }

            $collection[$class->getMorphClass()] = array_merge(
                $collection[$class->getMorphClass()],
                $query->take($this->hitsPerModel)->get()->toArray()
            );
        }

        return collect($collection);
    }
}
