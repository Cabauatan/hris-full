<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Report\ReportService;
use Illuminate\Support\Facades\Gate;

class ReportPolicy
{
    public function __construct(private readonly ReportService $reports) {}

    public function index(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        foreach (array_keys($this->reports->definitions()) as $report) {
            if ($this->view($user, $report)) {
                return true;
            }
        }

        return false;
    }

    public function view(User $user, string $report): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return Gate::forUser($user)->allows('viewAny', $this->reports->definition($report)['model']);
    }

    public function export(User $user, string $report): bool
    {
        return $this->view($user, $report);
    }
}
