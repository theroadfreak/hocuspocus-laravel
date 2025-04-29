<?php

namespace Hocuspocus;

use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Hocuspocus\Contracts\Collaborative;
use Hocuspocus\Jobs\Change;
use Hocuspocus\Jobs\Connect;
use Hocuspocus\Jobs\Disconnect;
use Illuminate\Support\Facades\DB;

class HocuspocusLaravel
{
    const EVENT_ON_CHANGE = 'change';
    const EVENT_ON_CONNECT = 'connect';
    const EVENT_ON_CREATE_DOCUMENT = 'create';
    const EVENT_ON_DISCONNECT = 'disconnect';

    /**
     * Handle an incoming webhook.
     * @param Request $request
     * @throws ReflectionException|AuthorizationException|AuthenticationException
     */
    public function handleWebhook(Request $request)
    {
        Log::alert("moze da log");
        if (!$this->verifySignature($request)) {
            throw new BadRequestException('Invalid signature');
        }

        $json = json_decode($request->getContent() ?: '{}', true);

        if (
            !isset($json['event'])
            || !isset($json['payload']['requestParameters'])
            || !isset($json['payload']['documentName'])
        ) {
            throw new BadRequestException('Invalid payload');
        }

        if (!in_array($json['event'], config('hocuspocus-laravel.events'))) {
            return response();
        }

        $user = $this->getUser($json['payload']['requestParameters']);
        $document = $this->getDocument($json['payload']['documentName']);

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

        $handler = "handleOn{$json['event']}";

        if (method_exists($this, $handler)) {
            return $this->$handler($json['payload'], $document, $user);
        }
    }

    /**
     * Handle onConnect webhook
     * @param array $payload
     * @param Collaborative $document
     * @param Authenticatable $user
     * @return JsonResponse
     */
    protected function handleOnConnect(array $payload, Collaborative $document, Authenticatable $user): JsonResponse
    {
        dispatch(new Connect($user, $document))
            ->onConnection(config("hocuspocus-laravel.job_connection"))
            ->onQueue(config("hocuspocuslaravel.job_queue"));

        return response()->json(
            $user->toArray()
        );
    }

    /**
     * Handle onDisconnect webhook
     * @param array $payload
     * @param Collaborative $document
     * @param Authenticatable $user
     */
    protected function handleOnDisconnect(array $payload, Collaborative $document, Authenticatable $user)
    {
        dispatch(new Disconnect($user, $document))
            ->onConnection(config("hocuspocus-laravel.job_connection"))
            ->onQueue(config("hocuspocuslaravel.job_queue"));

        return response('handled');
    }

    /**
     * Handle onCreate webhook
     * @param array $payload
     * @param Collaborative $document
     * @param Authenticatable $user
     * @return JsonResponse
     */
    protected function handleOnCreate(array $payload, Collaborative $document, Authenticatable $user): JsonResponse
    {
        $data = collect($document->getCollaborativeAttributes())->mapWithKeys(function ($attribute) use ($document) {
            return [$attribute => $document->{$attribute}];
        });

        // Decode the default attribute if it's a JSON string
        $responseData = $data->toArray();
        if (isset($responseData['default']) && is_string($responseData['default'])) {
            $responseData['default'] = json_decode($responseData['default'], true);
        }

        // Return the data as a JSON response
        return response()->json($responseData);
    }

    /**
     * Handle onChange webhook
     * @param array $payload
     * @param Collaborative $document
     * @param Authenticatable $user
     */
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

    /**
     * Get the user by the given request parameters.
     * @param array $requestParameters
     * @return Authenticatable
     * @throws AuthenticationException
     */
    protected function getUser(array $requestParameters): Authenticatable
    {
        // Retrieve CSRF token from request parameters
        $tokenParam = config('hocuspocus-laravel.access_token_parameter');
        $token = $requestParameters[$tokenParam] ?? null;

        if (! $token) {
            throw new AuthenticationException("CSRF token not set");
        }

        $userId = null;

        // Stream through sessions to find matching token
        foreach (DB::table('sessions')->cursor() as $session) {
            try {
                $data = unserialize(base64_decode($session->payload));
            } catch (\Throwable $e) {
                // Skip invalid or corrupt payloads
                continue;
            }

            if (is_array($data) && ($data['_token'] ?? null) === $token) {
                // Extract the user ID (session key starts with 'login_web_')
                $userId = collect($data)
                    ->filter(fn($value, $key) => str_starts_with($key, 'login_web_'))
                    ->first();
                break;
            }
        }

        if (! $userId) {
            throw new AuthenticationException("Invalid CSRF token or user not authenticated");
        }

        // Fetch and return the user model
        $userModel = config('auth.providers.users.model');

        return $userModel::findOrFail($userId);
    }

    /**
     * Get the document by the given name.
     * @param string $name
     * @return mixed
     * @throws ReflectionException|Exception|ModelNotFoundException
     */
    protected function getDocument(string $name)
    {
        // class name colon id e.g. "App\Models\TextDocument:1"
        $parts = explode(':', urldecode($name));

        if (count($parts) != 2) {
            throw new Exception("Invalid document name format \"{$name}\"");
        }

        $interface = Collaborative::class;
        $reflection = new ReflectionClass($parts[0]);

        if (!$reflection->implementsInterface($interface)) {
            throw new Exception("\"{$parts[0]}\" doesn't implement \"{$interface}\"");
        }

        if (!$reflection->isSubclassOf(Model::class)) {
            throw new Exception("\"{$parts[0]}\" is not an Eloquent Model");
        }

        return call_user_func([$parts[0], 'findOrFail'], $parts[1]);
    }

    /**
     * Verify the signature of the given request.
     * @param Request $request
     * @return bool
     */
    protected function verifySignature(Request $request): bool
    {
        if (($signature = $request->headers->get('X-Hocuspocus-Signature-256')) == null) {
            throw new BadRequestException('Header not set');
        }

        $parts = explode('=', $signature);

        if (count($parts) != 2) {
            throw new BadRequestException('Invalid signature format');
        }

        $digest = hash_hmac('sha256', $request->getContent(), config('hocuspocus-laravel.secret'));

        return hash_equals($digest, $parts[1]);
    }
}
