# Filterable

A Laravel library to filter Eloquent or Scout database records.

1. [How it works](#how-it-works)
2. [Implementation](#implementation)
3. 

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

### Reserved `query string` parameters

In order to change the expected results in search, filterable check with params you have in your request.

|Parameter|Default|Description|Example|
|---|---|---|---|
|`with_trashed`|false|Allow to include `deleted` records in the result set.|`?with_trashed=true`
|`per_page`|15| Determine the number or records to get in the query.|`?per_page=20`
|`with`|null| An `array` or `string` with the list of model relations to include.|`with[]=relation1&with[]=relation2`
|`search`|null| A string to activate the full-text search support.|`?search=John%sdoe`
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
