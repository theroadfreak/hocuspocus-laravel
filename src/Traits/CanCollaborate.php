<?php


namespace Hocuspocus\Traits;


use Exception;
use Illuminate\Contracts\Auth\Access\Authorizable;
use ReflectionClass;
use Hocuspocus\Models\Document;

trait CanCollaborate
{
    public static function bootCanCollaborate()
    {
        static::deleted(fn($user) => $user->documents->each->delete());

        if (!(new ReflectionClass(static::class))->implementsInterface(Authorizable::class)) {
            throw new Exception("Model \"" . static::class . "\" doesn't implement \"" . Authorizable::class . "\"");
        }
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'user_id');
    }

    /**
     * Get the connected documents for this user
     * @return \Illuminate\Support\Collection
     */
    public function getConnectedDocuments()
    {
        return $this->documents()->where('connected', true)->get();
    }
}
