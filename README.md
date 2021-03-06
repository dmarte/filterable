# Filterable

A Laravel library to handle filters for Eloquent models or Scout database records with a single API.

1. [How it works](#how-it-works)
2. [Implementation](#implementation)
3. [Pagination](#pagination)
5. [Reserved query string parameters](#reserved-query-string-parameters)
6. [Limiting the Full-Text columns check](#limiting-the-full-text-columns-check)
7. [Filterable queries](#filterable-queries)
8. [Resource response](#resource-response)

### How it works

Filterable automatically will perform the required implementation to fetch records from an eloquent model. At first, will try to check if want to perform a
Laravel Scout search, or a full eloquent model search.

It also supports Full text search.

```
IMPORTANT NOTE:
Filterable will return a JsonResource if the HTTP Accept is present and is application/json.
```

#### Implementation

```php
// IN YOUR ELOQUENT MODEL

namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use \Dmarte\Filterable\Filterable;

class Contact extends Model {
    // Just, import the trait filterable
    use Filterable;
}
```

```php
// IN YOUR CONTROLLER

use App\Models\Contact;
use Illuminate\Support\Facades\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Controllers\Controller;

class ContactController extends Controller {

    public function index(Request $request) : JsonResource{
        // Just call the static function "filter" to perform the query.
        return Contact::filter($request);
        
    }
}
```
### Pagination
Filterable package by default will return a **collection of records**, if you would like to get the paginator format you must include the query string `paginator=true` in the request.


With `?paginator=false` response will be:
```json
[]
```

With `?paginator=true` response will be:
```typescript
{
 "data": Array,
 "meta": {
  "per_page": Number
  "current_page": Number
  "last_page": Number
  "from": Number
  "to": Number
 }
}
```

### Reserved `query string` parameters

In order to change the expected results in search, filterable check with params you have in your request.

|Parameter|Default|Description|Example|
|---|---|---|---|
|`with_trashed`|false|Allow to include `deleted` records in the result set.|`?with_trashed=true`
|`per_page`|15| Determine the number or records to get in the query.|`?per_page=20`
|`with`|null| An `array` or `string` with the list of model relations to include.|`with[]=relation1&with[]=relation2`
|`search`|null| A string to activate the full-text search support.|`?search=John%sdoe`
|`paginator`|false|Enable or disable the paginator format in data response.

### Limiting the Full-Text columns check

By specifying the list of columns that should be full-text searchable you can globally search based on generic criterea.

```php
// IN YOUR MODEL 
// just override the method that indicate 
// the columns should be full-text filterable
/**
 * @return array
 */
protected static function fullTextColumns(): array
{
    return [
        'name',
        'description',
        //...
    ];
}
```

### Filterable queries

You could create your own logic for each column when you override the method `filterableQueries`.

#### **IMPORTANT**
You must be sure to return an instance of `Illuminate\Support\Collection` with each callback.
The filterable engine will check the "key" on the collection to match the request "key", then will expect each value should be a callback function that perform your desired query.

#### Here is an example

```php
    // IN YOUR MODEL 
    protected function filterableQueries(): Collection
    {
        return collect([
            'team_id'    => fn(Builder $query, $column, $value) => $query->where($column, $value),
            'emitted_at' => function (Builder $query, $column, $value) {
                if (is_array($value)) {
                    return $query->whereBetween($column, $value);
                }

                return $query->where($column, $value);
            },
        ]);
    }
```

#### Resource response
You could change the resource class used as response using the function `qualifiedResource`. With this `static` function you return the path of your resource.

> **NOTE**
> By default it will take the name of the model with the suffix `Resource`. Eg. for `\App\Models\User` model will try to find `\App\Http\Resources\UserResource`.


You can change this behavior overriding that function.

```php
    protected static function qualifiedResource(): string
    {
        return \App\Http\Resources\ModelResource::class;
    }
```

#### Filter by multiple models
Useful for global searches you could create a multi-model filter.

```php

        // Add column filter that will be applied to all models.
        $request->merge([
            'team_id' => $request->user()->team_id,
        ]);

        $engine = new FilterableMultiple(
            models: [
            Service::class,
            Product::class,
        ],
            request: $request
        );
        
        // Customize the query used for a given model
        $engine->query(Service::class, function (Builder $query) {
            $query->where('kind', 'service_custome_value');
        });

        // Return the collection
        return $engine->get()
```
