<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    protected $fillable = [
        'user_id',
        'position_id',
        'supervisor_employee_id',
        'employee_number',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'birth_date',
        'gender',
        'civil_status',
        'personal_email',
        'company_email',
        'mobile_number',
        'address_line_1',
        'address_line_2',
        'city',
        'province',
        'postal_code',
        'country',
        'hire_date',
        'regularization_date',
        'separation_date',
        'employment_type',
        'employment_status',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'hire_date' => 'date',
            'regularization_date' => 'date',
            'separation_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(
            Employee::class,
            'supervisor_employee_id'
        );
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(
            Employee::class,
            'supervisor_employee_id'
        );
    }

    public function employmentHistories(): HasMany
    {
        return $this->hasMany(EmployeeEmploymentHistory::class);
    }

    public function compensations(): HasMany
    {
        return $this->hasMany(EmployeeCompensation::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function scheduleAssignments(): HasMany
    {
        return $this->hasMany(EmployeeScheduleAssignment::class);
    }

    public function timeLogs(): HasMany
    {
        return $this->hasMany(TimeLog::class);
    }

    public function dailyAttendances(): HasMany
    {
        return $this->hasMany(DailyAttendance::class);
    }

    public function attendanceCorrectionRequests(): HasMany
    {
        return $this->hasMany(AttendanceCorrectionRequest::class);
    }

    public function officialBusinessRequests(): HasMany
    {
        return $this->hasMany(OfficialBusinessRequest::class);
    }

    public function travelOrderRequests(): HasMany
    {
        return $this->hasMany(TravelOrderRequest::class);
    }

    public function overtimeRequests(): HasMany
    {
        return $this->hasMany(OvertimeRequest::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function leaveCreditTransactions(): HasMany
    {
        return $this->hasMany(LeaveCreditTransaction::class);
    }

    public function ctoBalance(): HasOne
    {
        return $this->hasOne(CtoBalance::class);
    }

    public function ctoRequests(): HasMany
    {
        return $this->hasMany(CtoRequest::class);
    }

    public function ctoCreditTransactions(): HasMany
    {
        return $this->hasMany(CtoCreditTransaction::class);
    }

    public function payrollEntries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function profileChangeRequests(): HasMany
    {
        return $this->hasMany(EmployeeProfileChangeRequest::class);
    }
}
