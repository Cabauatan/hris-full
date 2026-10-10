<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'title' => $this->resource['title'],
            'message' => $this->resource['message'],
            'category' => $this->resource['category'],
            'event' => $this->resource['event'],
            'created_at' => $this->resource['created_at'],
            'read_at' => $this->resource['read_at'],
            'source' => $this->resource['source'],
        ];
    }
}
