<?php


namespace Hocuspocus\Traits;


use Illuminate\Support\Collection;
use Hocuspocus\Models\Document;

trait IsCollaborative
{
    public static function bootIsCollaborative()
    {
        static::deleted(fn($model) => $model->documents->each->delete());
    }

    public function documents()
    {
        return $this->morphMany(Document::class, 'model');
    }

    public function getConnectedUsers(): Collection
    {
        return $this->documents()
            ->where('connected', true)
            ->with('user')
            ->get()
            ->pluck('user');
    }

    public function getCollaborativeAttributes(): array
    {
        return $this->collaborativeAttributes ?? [];
    }

    public function getCollaborationDocumentName(): string
    {
        return urlencode(get_called_class() . ":" . $this->id);
    }
}
