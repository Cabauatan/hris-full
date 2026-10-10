<?php

namespace App\Http\Resources;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class ApiResource extends JsonResource
{
    protected const FIELDS = [];

    protected const DATES = [];

    public function toArray(Request $request): array
    {
        $data = [];

        foreach (static::FIELDS as $field) {
            $value = $this->resource->getAttribute($field);
            $data[$field] = $value instanceof DateTimeInterface
                ? $value->format(in_array($field, static::DATES, true) ? 'Y-m-d' : DateTimeInterface::ATOM)
                : $value;
        }

        return $data;
    }
}
