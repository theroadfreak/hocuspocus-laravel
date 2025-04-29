<?php


namespace Hocuspocus\Jobs;


use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Hocuspocus\Contracts\Collaborative;

class Connect implements ShouldQueue
{
    use Dispatchable, Queueable, InteractsWithQueue, SerializesModels;

    protected Authenticatable $user;

    protected Collaborative $document;

    public function __construct(Authenticatable $user, Collaborative $document)
    {
        $this->user = $user;
        $this->document = $document;
    }

    public function handle()
    {
        // Get or create document connection
        $document = \Hocuspocus\Models\Document::firstOrCreate([
            'user_id' => $this->user->id,
            'model_type' => get_class($this->document),
            'model_id' => $this->document->id,
        ], [
            'connected' => true,
            'connected_at' => now(),
        ]);
        
        // If it exists but isn't connected, update it
        if (!$document->connected) {
            $document->update([
                'connected' => true,
                'connected_at' => now(),
            ]);
        }
    }
}
