<?php

namespace App\Services\Employee;

use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class EmployeeDocumentService
{
    public function store(array $attributes, UploadedFile $file, User $actor): EmployeeDocument
    {
        $metadata = $this->storeFile($file, $actor);

        try {
            return DB::transaction(fn (): EmployeeDocument => EmployeeDocument::query()->create(
                Arr::only($attributes, ['employee_id', 'document_type', 'title', 'issued_at', 'expires_at', 'remarks']) + $metadata
            ));
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($metadata['file_path']);

            throw $exception;
        }
    }

    public function update(EmployeeDocument $document, array $attributes, ?UploadedFile $file, User $actor): EmployeeDocument
    {
        $metadata = $file === null ? [] : $this->storeFile($file, $actor);
        $oldPath = null;

        try {
            $updated = DB::transaction(function () use ($document, $attributes, $metadata, &$oldPath): EmployeeDocument {
                $locked = EmployeeDocument::query()->lockForUpdate()->findOrFail($document->id);
                $oldPath = $locked->file_path;
                $locked->update(Arr::only($attributes, ['document_type', 'title', 'issued_at', 'expires_at', 'remarks']) + $metadata);

                return $locked->fresh();
            });
        } catch (Throwable $exception) {
            if ($metadata !== []) {
                Storage::disk('local')->delete($metadata['file_path']);
            }

            throw $exception;
        }

        if ($metadata !== [] && $this->ownsPath($oldPath)) {
            Storage::disk('local')->delete($oldPath);
        }

        return $updated;
    }

    public function ownsPath(?string $path): bool
    {
        return is_string($path)
            && str_starts_with($path, 'employee-documents/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\');
    }

    private function storeFile(UploadedFile $file, User $actor): array
    {
        $path = $file->store('employee-documents', 'local');
        abort_if($path === false, 500, 'Unable to store the employee document.');

        return [
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by_user_id' => $actor->id,
        ];
    }
}
