<?php

namespace App\Http\Requests;

use App\Models\TravelOrderRequest;
use Illuminate\Foundation\Http\FormRequest;

class CancelTravelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $travelOrderRequest = $this->route('travelOrderRequest');

        return $travelOrderRequest instanceof TravelOrderRequest
            && $this->user()?->can('cancel', $travelOrderRequest) === true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string'],
        ];
    }
}
