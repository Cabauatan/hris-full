<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\IndexUserLookupRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UserLookupController extends ApiController
{
    public function index(IndexUserLookupRequest $request): JsonResponse
    {
        $input = $request->validated();
        $query = User::query()->select(['id', 'name']);

        if ($input['purpose'] === 'delegator' && ! $request->user()->hasPermission('employees.view')) {
            $query->whereKey($request->user()->id);
        }

        $users = $query->orderBy('name')->orderBy('id')->paginate($input['per_page'] ?? 15)->withQueryString();

        return response()->json([
            'data' => $users->getCollection()->map(static fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->all(),
            'meta' => [
                'current_page' => $users->currentPage(), 'per_page' => $users->perPage(),
                'last_page' => $users->lastPage(), 'total' => $users->total(),
            ],
        ]);
    }
}
