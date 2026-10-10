<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreEmployeeDocumentRequest;
use App\Http\Requests\UpdateEmployeeDocumentRequest;
use App\Http\Resources\EmployeeDocumentResource;
use App\Models\EmployeeDocument;
use App\Services\Employee\EmployeeDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeDocumentController extends ApiController
{
    public function __construct(private EmployeeDocumentService $documents) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', EmployeeDocument::class);
        $query = EmployeeDocument::query();

        return EmployeeDocumentResource::collection($this->paginate($request, $query));
    }

    public function show(EmployeeDocument $employeeDocument): EmployeeDocumentResource
    {
        Gate::authorize('view', $employeeDocument);

        return new EmployeeDocumentResource($employeeDocument);
    }

    public function store(StoreEmployeeDocumentRequest $request): JsonResponse
    {
        $employeeDocument = $this->documents->store($request->validated(), $request->file('file'), $request->user());

        return (new EmployeeDocumentResource($employeeDocument->fresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateEmployeeDocumentRequest $request, EmployeeDocument $employeeDocument): EmployeeDocumentResource
    {
        $employeeDocument = $this->documents->update($employeeDocument, $request->validated(), $request->file('file'), $request->user());

        return new EmployeeDocumentResource($employeeDocument->fresh());
    }

    public function download(EmployeeDocument $employeeDocument): StreamedResponse
    {
        Gate::authorize('view', $employeeDocument);
        abort_unless($this->documents->ownsPath($employeeDocument->file_path) && Storage::disk('local')->exists($employeeDocument->file_path), 404);

        return Storage::disk('local')->download($employeeDocument->file_path, $employeeDocument->original_filename);
    }
}
