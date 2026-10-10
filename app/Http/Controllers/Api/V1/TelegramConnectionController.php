<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ManageTelegramConnectionRequest;
use App\Services\Telegram\TelegramConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class TelegramConnectionController extends ApiController
{
    public function __construct(private readonly TelegramConnectionService $connections) {}

    public function show(ManageTelegramConnectionRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->connections->status($request->user())])
            ->header('Cache-Control', 'no-store');
    }

    public function link(ManageTelegramConnectionRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->connections->createLink($request->user())], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function destroy(ManageTelegramConnectionRequest $request): Response
    {
        $this->connections->unlink($request->user());

        return response()->noContent()->header('Cache-Control', 'no-store');
    }
}
