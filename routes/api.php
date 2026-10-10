<?php

use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\ApprovalDelegationController;
use App\Http\Controllers\Api\V1\AttendanceCorrectionRequestController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthenticationController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\CompanyProfileController;
use App\Http\Controllers\Api\V1\CtoBalanceController;
use App\Http\Controllers\Api\V1\CtoCreditTransactionController;
use App\Http\Controllers\Api\V1\CtoRequestController;
use App\Http\Controllers\Api\V1\DailyAttendanceController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\DivisionController;
use App\Http\Controllers\Api\V1\EmployeeCompensationController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeDocumentController;
use App\Http\Controllers\Api\V1\EmployeeEmploymentHistoryController;
use App\Http\Controllers\Api\V1\EmployeeProfileChangeRequestController;
use App\Http\Controllers\Api\V1\EmployeeScheduleAssignmentController;
use App\Http\Controllers\Api\V1\EmployeeSelfServiceController;
use App\Http\Controllers\Api\V1\HolidayController;
use App\Http\Controllers\Api\V1\LeaveBalanceController;
use App\Http\Controllers\Api\V1\LeaveCreditTransactionController;
use App\Http\Controllers\Api\V1\LeaveRequestController;
use App\Http\Controllers\Api\V1\LeaveTypeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OfficialBusinessRequestController;
use App\Http\Controllers\Api\V1\OvertimeRequestController;
use App\Http\Controllers\Api\V1\PayrollEntryController;
use App\Http\Controllers\Api\V1\PayrollEntryItemController;
use App\Http\Controllers\Api\V1\PayrollPeriodController;
use App\Http\Controllers\Api\V1\PayrollRunController;
use App\Http\Controllers\Api\V1\PayslipController;
use App\Http\Controllers\Api\V1\PositionController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\SystemSettingController;
use App\Http\Controllers\Api\V1\TelegramConnectionController;
use App\Http\Controllers\Api\V1\TimeLogController;
use App\Http\Controllers\Api\V1\TravelOrderRequestController;
use App\Http\Controllers\Api\V1\WorkScheduleController;
use App\Http\Controllers\Api\V1\WorkScheduleDayController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleApiBusinessExceptions;
use App\Services\Report\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->name('api.v1.auth.')->middleware('web')->group(function (): void {
    Route::get('csrf-cookie', [AuthenticationController::class, 'csrfCookie'])->name('csrf-cookie');
    Route::post('login', [AuthenticationController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::post('logout', [AuthenticationController::class, 'logout'])->middleware(['auth:web', 'auth.session'])->name('logout');
    Route::get('me', [AuthenticationController::class, 'me'])->middleware(['auth:web', 'auth.session', EnsureUserIsActive::class])->name('me');
    Route::put('password', [AuthenticationController::class, 'password'])->middleware(['auth:web', 'auth.session', EnsureUserIsActive::class, 'throttle:password-changes'])->name('password');
    Route::middleware(['auth:web', 'auth.session', EnsureUserIsActive::class])->prefix('telegram')->name('telegram.')->group(function (): void {
        Route::get('/', [TelegramConnectionController::class, 'show'])->name('show');
        Route::post('link', [TelegramConnectionController::class, 'link'])->middleware('throttle:telegram-links')->name('link');
        Route::delete('/', [TelegramConnectionController::class, 'destroy'])->name('destroy');
    });
});

Route::prefix('v1')->name('api.v1.')->middleware(['web', 'auth:web', 'auth.session', EnsureUserIsActive::class, HandleApiBusinessExceptions::class])->group(function (): void {
    Route::post('broadcasting/auth', function (Request $request): JsonResponse {
        $request->validate([
            'socket_id' => ['required', 'string', 'max:100', 'regex:/\A\d+\.\d+\z/'],
            'channel_name' => ['required', 'string', 'max:255', 'regex:/\Aprivate-App\.Models\.User\.[1-9]\d*\z/'],
        ]);

        return response()->json(Broadcast::auth($request));
    })->middleware('throttle:broadcast-auth')->name('broadcasting.auth');

    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('calendar', CalendarController::class)->name('calendar');
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/{report}/export', [ReportController::class, 'export'])->whereIn('report', array_keys(app(ReportService::class)->definitions()))->middleware('throttle:report-exports')->name('reports.export');
    Route::get('reports/{report}', [ReportController::class, 'show'])->whereIn('report', array_keys(app(ReportService::class)->definitions()))->name('reports.show');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
    Route::patch('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::patch('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->name('notifications.read');

    Route::apiResource('divisions', DivisionController::class)
        ->only(['index', 'show', 'store', 'update'])
        ->parameters(['divisions' => 'division']);
    Route::apiResource('company-profiles', CompanyProfileController::class)
        ->only(['index', 'show', 'update'])
        ->parameters(['company-profiles' => 'companyProfile']);
    Route::apiResource('departments', DepartmentController::class)
        ->only(['index', 'show', 'store', 'update'])
        ->parameters(['departments' => 'department']);
    Route::apiResource('positions', PositionController::class)
        ->only(['index', 'show', 'store', 'update'])
        ->parameters(['positions' => 'position']);
    Route::apiResource('shifts', ShiftController::class)
        ->only(['index', 'show', 'store', 'update'])
        ->parameters(['shifts' => 'shift']);
    Route::apiResource('work-schedules', WorkScheduleController::class)
        ->only(['index', 'show', 'store', 'update'])
        ->parameters(['work-schedules' => 'workSchedule']);
    Route::apiResource('work-schedule-days', WorkScheduleDayController::class)
        ->only(['index', 'show', 'store', 'update'])
        ->parameters(['work-schedule-days' => 'workScheduleDay']);
    Route::apiResource('holidays', HolidayController::class)
        ->only(['index', 'show', 'store', 'update'])
        ->parameters(['holidays' => 'holiday']);
    Route::apiResource('employee-schedule-assignments', EmployeeScheduleAssignmentController::class)
        ->only(['index', 'show', 'store', 'update'])
        ->parameters(['employee-schedule-assignments' => 'employeeScheduleAssignment']);
    Route::apiResource('approval-delegations', ApprovalDelegationController::class)
        ->only(['index', 'show', 'store', 'update'])
        ->parameters(['approval-delegations' => 'approvalDelegation']);
    Route::apiResource('time-logs', TimeLogController::class)
        ->only(['index', 'show', 'store', 'update'])
        ->parameters(['time-logs' => 'timeLog']);
    Route::apiResource('employees', EmployeeController::class)
        ->only(['index', 'show', 'store', 'update'])
        ->parameters(['employees' => 'employee']);
    Route::apiResource('employee-employment-histories', EmployeeEmploymentHistoryController::class)
        ->only(['index', 'show', 'store', 'update'])
        ->parameters(['employee-employment-histories' => 'employeeEmploymentHistory']);
    Route::apiResource('employee-compensations', EmployeeCompensationController::class)
        ->only(['index', 'show', 'store', 'update'])
        ->parameters(['employee-compensations' => 'employeeCompensation']);
    Route::apiResource('employee-documents', EmployeeDocumentController::class)
        ->only(['index', 'show', 'store', 'update'])
        ->parameters(['employee-documents' => 'employeeDocument']);
    Route::get('employee-documents/{employeeDocument}/download', [EmployeeDocumentController::class, 'download'])->name('employee-documents.download');
    Route::apiResource('leave-types', LeaveTypeController::class)
        ->only(['index', 'show', 'store', 'update'])
        ->parameters(['leave-types' => 'leaveType']);
    Route::apiResource('daily-attendances', DailyAttendanceController::class)
        ->only(['index', 'show'])
        ->parameters(['daily-attendances' => 'dailyAttendance']);
    Route::apiResource('system-settings', SystemSettingController::class)
        ->only(['index', 'show', 'store', 'update'])
        ->parameters(['system-settings' => 'systemSetting']);
    Route::get('announcements/dashboard', [AnnouncementController::class, 'dashboard'])->name('announcements.dashboard');
    Route::apiResource('announcements', AnnouncementController::class)
        ->only(['index', 'show', 'store', 'update'])
        ->parameters(['announcements' => 'announcement']);
    Route::post('announcements/{announcement}/publish', [AnnouncementController::class, 'publish'])->name('announcements.publish');
    Route::post('announcements/{announcement}/schedule', [AnnouncementController::class, 'schedule'])->name('announcements.schedule');
    Route::post('announcements/{announcement}/reschedule', [AnnouncementController::class, 'reschedule'])->name('announcements.reschedule');
    Route::post('announcements/{announcement}/cancel-schedule', [AnnouncementController::class, 'cancelSchedule'])->name('announcements.cancel-schedule');
    Route::post('announcements/{announcement}/deactivate', [AnnouncementController::class, 'deactivate'])->name('announcements.deactivate');
    Route::post('announcements/{announcement}/reactivate', [AnnouncementController::class, 'reactivate'])->name('announcements.reactivate');
    Route::apiResource('employee-profile-change-requests', EmployeeProfileChangeRequestController::class)
        ->only(['index', 'show', 'store'])
        ->parameters(['employee-profile-change-requests' => 'employeeProfileChangeRequest']);
    Route::post('employee-profile-change-requests/{employeeProfileChangeRequest}/approve', [EmployeeProfileChangeRequestController::class, 'approve'])->name('employee-profile-change-requests.approve');
    Route::post('employee-profile-change-requests/{employeeProfileChangeRequest}/reject', [EmployeeProfileChangeRequestController::class, 'reject'])->name('employee-profile-change-requests.reject');
    Route::post('employee-profile-change-requests/{employeeProfileChangeRequest}/cancel', [EmployeeProfileChangeRequestController::class, 'cancel'])->name('employee-profile-change-requests.cancel');
    Route::post('employee-profile-change-requests/{employeeProfileChangeRequest}/apply', [EmployeeProfileChangeRequestController::class, 'apply'])->name('employee-profile-change-requests.apply');
    Route::apiResource('attendance-correction-requests', AttendanceCorrectionRequestController::class)
        ->only(['index', 'show', 'store'])
        ->parameters(['attendance-correction-requests' => 'attendanceCorrectionRequest']);
    Route::post('attendance-correction-requests/{attendanceCorrectionRequest}/approve', [AttendanceCorrectionRequestController::class, 'approve'])->name('attendance-correction-requests.approve');
    Route::post('attendance-correction-requests/{attendanceCorrectionRequest}/reject', [AttendanceCorrectionRequestController::class, 'reject'])->name('attendance-correction-requests.reject');
    Route::post('attendance-correction-requests/{attendanceCorrectionRequest}/cancel', [AttendanceCorrectionRequestController::class, 'cancel'])->name('attendance-correction-requests.cancel');
    Route::apiResource('leave-requests', LeaveRequestController::class)
        ->only(['index', 'show', 'store'])
        ->parameters(['leave-requests' => 'leaveRequest']);
    Route::post('leave-requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve'])->name('leave-requests.approve');
    Route::post('leave-requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject'])->name('leave-requests.reject');
    Route::post('leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->name('leave-requests.cancel');
    Route::apiResource('cto-requests', CtoRequestController::class)
        ->only(['index', 'show', 'store'])
        ->parameters(['cto-requests' => 'ctoRequest']);
    Route::post('cto-requests/{ctoRequest}/approve', [CtoRequestController::class, 'approve'])->name('cto-requests.approve');
    Route::post('cto-requests/{ctoRequest}/reject', [CtoRequestController::class, 'reject'])->name('cto-requests.reject');
    Route::post('cto-requests/{ctoRequest}/cancel', [CtoRequestController::class, 'cancel'])->name('cto-requests.cancel');
    Route::apiResource('official-business-requests', OfficialBusinessRequestController::class)
        ->only(['index', 'show', 'store'])
        ->parameters(['official-business-requests' => 'officialBusinessRequest']);
    Route::post('official-business-requests/{officialBusinessRequest}/approve', [OfficialBusinessRequestController::class, 'approve'])->name('official-business-requests.approve');
    Route::post('official-business-requests/{officialBusinessRequest}/reject', [OfficialBusinessRequestController::class, 'reject'])->name('official-business-requests.reject');
    Route::post('official-business-requests/{officialBusinessRequest}/cancel', [OfficialBusinessRequestController::class, 'cancel'])->name('official-business-requests.cancel');
    Route::apiResource('travel-order-requests', TravelOrderRequestController::class)
        ->only(['index', 'show', 'store'])
        ->parameters(['travel-order-requests' => 'travelOrderRequest']);
    Route::post('travel-order-requests/{travelOrderRequest}/approve', [TravelOrderRequestController::class, 'approve'])->name('travel-order-requests.approve');
    Route::post('travel-order-requests/{travelOrderRequest}/reject', [TravelOrderRequestController::class, 'reject'])->name('travel-order-requests.reject');
    Route::post('travel-order-requests/{travelOrderRequest}/cancel', [TravelOrderRequestController::class, 'cancel'])->name('travel-order-requests.cancel');
    Route::apiResource('overtime-requests', OvertimeRequestController::class)
        ->only(['index', 'show', 'store'])
        ->parameters(['overtime-requests' => 'overtimeRequest']);
    Route::post('overtime-requests/{overtimeRequest}/approve', [OvertimeRequestController::class, 'approve'])->name('overtime-requests.approve');
    Route::post('overtime-requests/{overtimeRequest}/reject', [OvertimeRequestController::class, 'reject'])->name('overtime-requests.reject');
    Route::post('overtime-requests/{overtimeRequest}/cancel', [OvertimeRequestController::class, 'cancel'])->name('overtime-requests.cancel');
    Route::apiResource('payroll-periods', PayrollPeriodController::class)
        ->only(['index', 'show', 'store', 'update'])
        ->parameters(['payroll-periods' => 'payrollPeriod']);
    Route::apiResource('payroll-runs', PayrollRunController::class)
        ->only(['index', 'show', 'store'])
        ->parameters(['payroll-runs' => 'payrollRun']);
    Route::post('payroll-runs/{payrollRun}/start', [PayrollRunController::class, 'start'])->name('payroll-runs.start');
    Route::post('payroll-runs/{payrollRun}/complete', [PayrollRunController::class, 'complete'])->name('payroll-runs.complete');
    Route::post('payroll-runs/{payrollRun}/finalize', [PayrollRunController::class, 'finalize'])->name('payroll-runs.finalize');
    Route::apiResource('payroll-entries', PayrollEntryController::class)
        ->only(['index', 'show'])
        ->parameters(['payroll-entries' => 'payrollEntry']);
    Route::post('payroll-runs/{payrollRun}/prepare-entry', [PayrollEntryController::class, 'prepare'])->name('payroll-runs.prepare-entry');
    Route::put('payroll-entries/{payrollEntry}/items', [PayrollEntryController::class, 'replaceItems'])->name('payroll-entries.items');
    Route::apiResource('payroll-entry-items', PayrollEntryItemController::class)
        ->only(['index', 'show'])
        ->parameters(['payroll-entry-items' => 'payrollEntryItem']);
    Route::post('leave-balances/adjust', [LeaveBalanceController::class, 'adjust'])->name('leave-balances.adjust');
    Route::apiResource('leave-balances', LeaveBalanceController::class)
        ->only(['index', 'show'])
        ->parameters(['leave-balances' => 'leaveBalance']);
    Route::apiResource('leave-credit-transactions', LeaveCreditTransactionController::class)
        ->only(['index', 'show'])
        ->parameters(['leave-credit-transactions' => 'leaveCreditTransaction']);
    Route::post('cto-balances/adjust', [CtoBalanceController::class, 'adjust'])->name('cto-balances.adjust');
    Route::apiResource('cto-balances', CtoBalanceController::class)
        ->only(['index', 'show'])
        ->parameters(['cto-balances' => 'ctoBalance']);
    Route::apiResource('cto-credit-transactions', CtoCreditTransactionController::class)
        ->only(['index', 'show'])
        ->parameters(['cto-credit-transactions' => 'ctoCreditTransaction']);
    Route::apiResource('payslips', PayslipController::class)
        ->only(['index', 'show'])
        ->parameters(['payslips' => 'payslip']);
    Route::post('payroll-entries/{payrollEntry}/generate-payslip', [PayslipController::class, 'generate'])->name('payroll-entries.generate-payslip');
    Route::post('payslips/{payslip}/publish', [PayslipController::class, 'publish'])->name('payslips.publish');
    Route::post('payslips/{payslip}/mark-viewed', [PayslipController::class, 'markViewed'])->name('payslips.mark-viewed');
    Route::get('payslips/{payslip}/download', [PayslipController::class, 'download'])->name('payslips.download');
    Route::apiResource('audit-logs', AuditLogController::class)
        ->only(['index', 'show'])
        ->parameters(['audit-logs' => 'auditLog']);
    Route::prefix('ess')->name('ess.')->group(function (): void {
        Route::get('profile', [EmployeeSelfServiceController::class, 'profile'])->name('profile');
        Route::get('time-logs', [EmployeeSelfServiceController::class, 'timeLogs'])->name('time-logs');
        Route::get('daily-attendances', [EmployeeSelfServiceController::class, 'dailyAttendances'])->name('daily-attendances');
        Route::get('schedule-assignments', [EmployeeSelfServiceController::class, 'scheduleAssignments'])->name('schedule-assignments');
        Route::get('employee-profile-change-requests', [EmployeeSelfServiceController::class, 'profileChanges'])->name('employee-profile-change-requests');
        Route::get('attendance-correction-requests', [EmployeeSelfServiceController::class, 'attendanceCorrections'])->name('attendance-correction-requests');
        Route::get('leave-requests', [EmployeeSelfServiceController::class, 'leaveRequests'])->name('leave-requests');
        Route::get('cto-requests', [EmployeeSelfServiceController::class, 'ctoRequests'])->name('cto-requests');
        Route::get('official-business-requests', [EmployeeSelfServiceController::class, 'officialBusinessRequests'])->name('official-business-requests');
        Route::get('travel-order-requests', [EmployeeSelfServiceController::class, 'travelOrderRequests'])->name('travel-order-requests');
        Route::get('overtime-requests', [EmployeeSelfServiceController::class, 'overtimeRequests'])->name('overtime-requests');
        Route::get('leave-balances', [EmployeeSelfServiceController::class, 'leaveBalances'])->name('leave-balances');
        Route::get('cto-balances', [EmployeeSelfServiceController::class, 'ctoBalances'])->name('cto-balances');
        Route::get('leave-credit-transactions', [EmployeeSelfServiceController::class, 'leaveCreditTransactions'])->name('leave-credit-transactions');
        Route::get('cto-credit-transactions', [EmployeeSelfServiceController::class, 'ctoCreditTransactions'])->name('cto-credit-transactions');
        Route::get('payslips', [EmployeeSelfServiceController::class, 'payslips'])->name('payslips');
    });
});

require __DIR__.'/applicant.php';
require __DIR__.'/recruitment.php';
require __DIR__.'/recruitment_interviews.php';
