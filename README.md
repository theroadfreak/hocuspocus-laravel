# Hocuspocus for Laravel
Seamlessly integrates a [Hocuspocus](https://www.hocuspocus.dev) backend with Laravel.

## Installation
You can install the package via composer:

```bash
composer require ueberdosis/hocuspocus-laravel
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --provider="Hocuspocus\HocuspocusServiceProvider" --tag="hocuspocus-laravel-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --provider="Hocuspocus\HocuspocusServiceProvider" --tag="hocuspocus-laravel-config"
```

## Usage

Add the `CanCollaborate` trait to your user model:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Hocuspocus\Traits\CanCollaborate;

class User extends Authenticatable {
    use CanCollaborate;
}
```

Add the `Collaborative` interface and `IsCollaborative` trait to your documents and configure the `collaborativeAttributes`:

```php
use Illuminate\Database\Eloquent\Model;
use Hocuspocus\Contracts\Collaborative;
use Hocuspocus\Traits\IsCollaborative;

class TextDocument extends Model implements Collaborative {
    use IsCollaborative;

    protected array $collaborativeAttributes = [
        'title', 'body',
    ];
}
```

Add policies to your app that handle authorization for your models. The name of the policy method is configurable inside the `hocuspocus-laravel.php` config file. An example:

```php
use App\Models\TextDocument;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TextDocumentPolicy
{
    use HandlesAuthorization;

    public function update(User $user, TextDocument $document)
    {
        return true;
    }
}
```

In the frontend, add the document name to your WebSocket provider and authenticate using one of the available methods:

### CSRF Token Authentication

Use Laravel's built-in CSRF token for authentication:

```blade
<script>
  window.csrfToken = '{{ csrf_token() }}';
  window.collaborationDocumentName = '{{ $yourTextDocument->getCollaborationDocumentName() }}'
</script>
```

```js
import { HocuspocusProvider } from '@hocuspocus/provider'
import * as Y from 'yjs'

const provider = new HocuspocusProvider({
  document: new Y.Doc(),
  url: 'ws://localhost:1234',
  name: window.collaborationDocumentName,
  parameters: {
    csrf_token: window.csrfToken,
  },
})
```

Configure a random secret key in your `.env`:

```dotenv
HOCUSPOCUS_SECRET="459824aaffa928e05f5b1caec411ae5f"
```

Finally set up Hocuspocus with the webhook extension:

```js
import { Server } from '@hocuspocus/server'
import { Webhook, Events } from '@hocuspocus/extension-webhook'
import { TiptapTransformer } from '@hocuspocus/transformer'

const server = Server.configure({
  extensions: [
    new Webhook({
      // url to your application
      url: 'https://example.com/api/documents',
      // the same secret you configured earlier in your .env
      secret: '459824aaffa928e05f5b1caec411ae5f',

      transformer: TiptapTransformer,

      events: [Events.onConnect, Events.onCreate, Events.onChange, Events.onDisconnect],

    }),
  ],
})

server.listen()
```

## Custom Role-Based Permissions

This implementation includes custom role-based permission handling in the webhook processing:

### User Role Logic

The webhook handler implements different permission checks based on event type:

```php
// For change events, check edit permission
if ($json['event'] === self::EVENT_ON_CHANGE) {
    if (!$user->can('update', $document)) {
        throw new AuthorizationException("User is not allowed to edit this document");
    }
}
// For all other events (connect, create, disconnect), check view permission
else {
    if (!$user->can('view', $document)) {
        throw new AuthorizationException("User is not allowed to view this document");
    }
}
```

### Custom handleOnChange Implementation

The `handleOnChange` method includes specific role-based logic to prevent viewers from modifying documents:

```php
protected function handleOnChange(array $payload, Collaborative $document, Authenticatable $user)
{
    // If user is a viewer, reject the change
    if ($user->role->isViewer()) {
        return response()->json([
            'error' => 'Viewers cannot modify documents'
        ], 403);
    }

    dispatch(new Change($user, $document, $payload['document']))
        ->onConnection(config("hocuspocus-laravel.job_connection"))
        ->onQueue(config("hocuspocuslaravel.job_queue"));

    return response('handled');
}
```

This ensures that users with viewer roles can connect and view documents but cannot make changes to them.

### Custom handleOnCreate Implementation

The `handleOnCreate` method includes custom logic to handle JSON-encoded default values:

```php
// Decode the default attribute if it's a JSON string
$responseData = $data->toArray();
if (isset($responseData['default']) && is_string($responseData['default'])) {
    $responseData['default'] = json_decode($responseData['default'], true);
}
```

This automatically converts any JSON-encoded 'default' attribute to a PHP array before returning it to the client, which is useful when storing structured data in a single field.

## Credits
- [Kris Siepert](https://github.com/kriskbx)
- [All Contributors](../../contributors)

## License
The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
