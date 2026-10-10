<?php

use App\Models\Announcement;
use App\Models\ApprovalAction;
use App\Models\ApprovalDelegation;
use App\Models\AttendanceCorrectionRequest;
use App\Models\CompanyProfile;
use App\Models\CtoBalance;
use App\Models\CtoCreditTransaction;
use App\Models\CtoRequest;
use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Division;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeProfileChangeItem;
use App\Models\EmployeeProfileChangeRequest;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveCreditTransaction;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OfficialBusinessRequest;
use App\Models\OvertimeRequest;
use App\Models\PayrollEntry;
use App\Models\PayrollEntryItem;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Role;
use App\Models\Shift;
use App\Models\SystemSetting;
use App\Models\TimeLog;
use App\Models\TravelOrderRequest;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;

return [
    'announcement' => Announcement::class,
    'approval_action' => ApprovalAction::class,
    'approval_delegation' => ApprovalDelegation::class,

    // Existing approval aliases — do not rename.
    'attendance_correction' => AttendanceCorrectionRequest::class,
    'official_business' => OfficialBusinessRequest::class,
    'travel_order' => TravelOrderRequest::class,
    'overtime' => OvertimeRequest::class,
    'leave' => LeaveRequest::class,
    'cto' => CtoRequest::class,

    'company_profile' => CompanyProfile::class,
    'cto_balance' => CtoBalance::class,
    'cto_credit_transaction' => CtoCreditTransaction::class,
    'daily_attendance' => DailyAttendance::class,
    'department' => Department::class,
    'division' => Division::class,
    'employee' => Employee::class,
    'employee_compensation' => EmployeeCompensation::class,
    'employee_document' => EmployeeDocument::class,
    'employee_employment_history' => EmployeeEmploymentHistory::class,
    'employee_profile_change_item' => EmployeeProfileChangeItem::class,
    'employee_profile_change_request' => EmployeeProfileChangeRequest::class,
    'employee_schedule_assignment' => EmployeeScheduleAssignment::class,
    'holiday' => Holiday::class,
    'leave_balance' => LeaveBalance::class,
    'leave_credit_transaction' => LeaveCreditTransaction::class,
    'leave_type' => LeaveType::class,
    'payroll_entry' => PayrollEntry::class,
    'payroll_entry_item' => PayrollEntryItem::class,
    'payroll_period' => PayrollPeriod::class,
    'payroll_run' => PayrollRun::class,
    'payslip' => Payslip::class,
    'permission' => Permission::class,
    'position' => Position::class,
    'role' => Role::class,
    'shift' => Shift::class,
    'system_setting' => SystemSetting::class,
    'time_log' => TimeLog::class,
    'user' => User::class,
    'work_schedule' => WorkSchedule::class,
    'work_schedule_day' => WorkScheduleDay::class,
];
