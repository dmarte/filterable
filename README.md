# Filterable
A Laravel library to filter Eloquent or Scout records with an easy to use implementation.

### How it works
Filterable automatically will perform the required implementation to fetch records from an eloquent model.
At first, will try to check if want to perform a Laravel Scout search or a full eloquent model search.

It also supports Full text search.

#### Implementation example
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
