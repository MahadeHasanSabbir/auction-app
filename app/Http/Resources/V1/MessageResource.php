<?php

namespace App\Http\Resources\V1;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A chat message. Load author first.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'mine' => $this->user_id === $request->user()?->getAuthIdentifier(),
            'author' => $this->is_system ? null : $this->author?->name,
            'system' => (bool) $this->is_system,
            'sent_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
