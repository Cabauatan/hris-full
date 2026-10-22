<?php

namespace App\Http\Resources;

use App\Services\Workspace\ResourceLabelService;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class ApiResource extends JsonResource
{
    protected const FIELDS = [];

    protected const DATES = [];

    public static function collection(mixed $resource): AnonymousResourceCollection
    {
        app(ResourceLabelService::class)->prepareCollection($resource, static::FIELDS);

        return parent::collection($resource);
    }

    public function toArray(Request $request): array
    {
        $data = [];

        foreach (static::FIELDS as $field) {
            $value = $this->resource->getAttribute($field);
            $data[$field] = $value instanceof DateTimeInterface
                ? $value->format(in_array($field, static::DATES, true) ? 'Y-m-d' : DateTimeInterface::ATOM)
                : $value;
        }

        $labels = app(ResourceLabelService::class)->labels($this->resource, static::FIELDS);

        return $labels === [] ? $data : $data + ['display_labels' => $labels];
    }
}
