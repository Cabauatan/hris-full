<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\AttendanceCorrectionRequestResource;
use App\Http\Resources\CtoBalanceResource;
use App\Http\Resources\CtoCreditTransactionResource;
use App\Http\Resources\CtoRequestResource;
use App\Http\Resources\DailyAttendanceResource;
use App\Http\Resources\EmployeeProfileChangeRequestResource;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\EmployeeScheduleAssignmentResource;
use App\Http\Resources\LeaveBalanceResource;
use App\Http\Resources\LeaveCreditTransactionResource;
use App\Http\Resources\LeaveRequestResource;
use App\Http\Resources\OfficialBusinessRequestResource;
use App\Http\Resources\OvertimeRequestResource;
use App\Http\Resources\PayslipResource;
use App\Http\Resources\TimeLogResource;
use App\Http\Resources\TravelOrderRequestResource;
use App\Models\AttendanceCorrectionRequest;
use App\Models\CtoBalance;
use App\Models\CtoCreditTransaction;
use App\Models\CtoRequest;
use App\Models\DailyAttendance;
use App\Models\EmployeeProfileChangeRequest;
use App\Models\EmployeeScheduleAssignment;
use App\Models\LeaveBalance;
use App\Models\LeaveCreditTransaction;
use App\Models\LeaveRequest;
use App\Models\OfficialBusinessRequest;
use App\Models\OvertimeRequest;
use App\Models\Payslip;
use App\Models\TimeLog;
use App\Models\TravelOrderRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class EmployeeSelfServiceController extends ApiController
{
    public function profile(Request $request): EmployeeResource
    {
        $employee = $this->currentEmployee($request);
        Gate::authorize('view', $employee);

        return new EmployeeResource($employee);
    }

    public function timeLogs(Request $request): AnonymousResourceCollection
    {
        return $this->records($request, TimeLog::class, TimeLogResource::class);
    }

    public function dailyAttendances(Request $request): AnonymousResourceCollection
    {
        return $this->records($request, DailyAttendance::class, DailyAttendanceResource::class);
    }

    public function scheduleAssignments(Request $request): AnonymousResourceCollection
    {
        return $this->records($request, EmployeeScheduleAssignment::class, EmployeeScheduleAssignmentResource::class);
    }

    public function profileChanges(Request $request): AnonymousResourceCollection
    {
        return $this->records($request, EmployeeProfileChangeRequest::class, EmployeeProfileChangeRequestResource::class);
    }

    public function attendanceCorrections(Request $request): AnonymousResourceCollection
    {
        return $this->records($request, AttendanceCorrectionRequest::class, AttendanceCorrectionRequestResource::class);
    }

    public function leaveRequests(Request $request): AnonymousResourceCollection
    {
        return $this->records($request, LeaveRequest::class, LeaveRequestResource::class);
    }

    public function ctoRequests(Request $request): AnonymousResourceCollection
    {
        return $this->records($request, CtoRequest::class, CtoRequestResource::class);
    }

    public function officialBusinessRequests(Request $request): AnonymousResourceCollection
    {
        return $this->records($request, OfficialBusinessRequest::class, OfficialBusinessRequestResource::class);
    }

    public function travelOrderRequests(Request $request): AnonymousResourceCollection
    {
        return $this->records($request, TravelOrderRequest::class, TravelOrderRequestResource::class);
    }

    public function overtimeRequests(Request $request): AnonymousResourceCollection
    {
        return $this->records($request, OvertimeRequest::class, OvertimeRequestResource::class);
    }

    public function leaveBalances(Request $request): AnonymousResourceCollection
    {
        return $this->records($request, LeaveBalance::class, LeaveBalanceResource::class);
    }

    public function ctoBalances(Request $request): AnonymousResourceCollection
    {
        return $this->records($request, CtoBalance::class, CtoBalanceResource::class);
    }

    public function leaveCreditTransactions(Request $request): AnonymousResourceCollection
    {
        return $this->records($request, LeaveCreditTransaction::class, LeaveCreditTransactionResource::class);
    }

    public function ctoCreditTransactions(Request $request): AnonymousResourceCollection
    {
        return $this->records($request, CtoCreditTransaction::class, CtoCreditTransactionResource::class);
    }

    public function payslips(Request $request): AnonymousResourceCollection
    {
        return $this->records($request, Payslip::class, PayslipResource::class);
    }

    private function records(Request $request, string $model, string $resource): AnonymousResourceCollection
    {
        $employee = $this->currentEmployee($request);
        Gate::authorize('viewAny', $model);
        $query = $model::query()->where('employee_id', $employee->id);

        if ($model === EmployeeProfileChangeRequest::class) {
            $query->with('items');
        }

        if ($model === Payslip::class) {
            $query->whereNotNull('published_at');
        }

        return $resource::collection($this->paginate($request, $query));
    }
}
