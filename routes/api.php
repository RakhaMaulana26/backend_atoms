<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployeeScheduleController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\RosterController;
use App\Http\Controllers\Api\RosterImportController;
use App\Http\Controllers\Api\RosterTaskController;
use App\Http\Controllers\Api\ShiftRequestController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// =======================================
// AUTH & PASSWORD (Public Routes)
// =======================================
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify-token', [AuthController::class, 'verifyToken']);
    Route::post('/set-password', [AuthController::class, 'setPassword']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
    });
});

// =======================================
// ACTIVITY LOGS (Public for testing - remove auth temporarily)
// =======================================
Route::prefix('activity-logs')->group(function () {
    Route::get('/', [ActivityLogController::class, 'index']);
    Route::get('/recent', [ActivityLogController::class, 'recent']);
    Route::get('/statistics', [ActivityLogController::class, 'statistics']);
});

// =======================================
// PROTECTED ROUTES (Require Authentication)
// =======================================
Route::middleware('auth:sanctum')->group(function () {

    // =======================================
    // USERS (Read-only for all authenticated users)
    // =======================================
    Route::get('/users', [AdminUserController::class, 'index']);
    
    // =======================================
    // ADMIN - USER & EMPLOYEE MANAGEMENT
    // =======================================
    Route::prefix('admin')->middleware('role:' . User::ROLE_ADMIN)->group(function () {
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::post('/users', [AdminUserController::class, 'store']);
        Route::patch('/users/{id}', [AdminUserController::class, 'update']);
        Route::delete('/users/{id}', [AdminUserController::class, 'destroy']);
        Route::post('/users/{id}/restore', [AdminUserController::class, 'restore']);
        Route::post('/users/{id}/generate-token', [AdminUserController::class, 'generateToken'])
            ->middleware('throttle:3,1'); // Max 3 requests per minute
        Route::post('/users/{id}/send-activation-code', [AdminUserController::class, 'sendActivationCode'])
            ->middleware('throttle:1,1,send-email-{id}'); // Max 1 email per user per minute
    });

    // =======================================
    // ROSTERING (Read-only: All authenticated users)
    // =======================================
    // Kategori frontend: roster
    Route::get('roster/today', [RosterController::class, 'today']);
    // RosterController::tasks endpoint masih dipakai untuk assignment-view, agar tidak konflik dengan roster task API,
    // kita pindahkan ke path lain.
    Route::get('roster/tasks/assignments', [RosterController::class, 'tasks']);
    Route::get('roster/auto-assignment', [RosterController::class, 'autoAssignment']);
    Route::prefix('rosters')->group(function () {
        Route::get('/', [RosterController::class, 'index']);
        Route::get('/{id}', [RosterController::class, 'show']);
        Route::get('/{id}/validate', [RosterController::class, 'validateBeforePublish']);
        Route::get('/{roster_id}/days/{day_id}', [RosterController::class, 'showDay']);
    });

    // =======================================
    // ROSTERING (Write: Admin, Manager Teknik, General Manager only)
    // =======================================
    Route::prefix('rosters')->middleware('role:' . User::ROLE_ADMIN . ',' . User::ROLE_MANAGER_TEKNIK . ',' . User::ROLE_GENERAL_MANAGER)->group(function () {
        Route::post('/', [RosterController::class, 'store']);
        // Route::post('/import', [RosterImportController::class, 'import']);
        // Route::post('/import-url', [RosterImportController::class, 'importFromUrl']);
        Route::put('/{id}', [RosterController::class, 'update']);
        Route::delete('/{id}', [RosterController::class, 'destroy']);
        Route::post('/{id}/publish', [RosterController::class, 'publish']);
        Route::post('/{id}/unpublish', [RosterController::class, 'unpublish']);
        // Route::post('/{id}/sync', [RosterImportController::class, 'syncFromSpreadsheet']);
        // Route::post('/{id}/push', [RosterImportController::class, 'pushToSpreadsheet']);
        // Route::put('/{id}/spreadsheet-url', [RosterImportController::class, 'updateSpreadsheetUrl']);
        
        // Roster day assignments
        Route::post('/{roster_id}/days/{day_id}/assignments', [RosterController::class, 'storeAssignments']);
        Route::put('/{roster_id}/days/{day_id}/assignments', [RosterController::class, 'updateAssignments']);
        Route::delete('/{roster_id}/days/{day_id}/assignments/{assignment_id}', [RosterController::class, 'deleteAssignment']);
        
        // Quick update assignment (simplified endpoint)
        Route::post('/{roster_id}/assignments/quick-update', [RosterController::class, 'quickUpdateAssignment']);
        
        // Batch update multiple employees and dates at once
        Route::post('/{roster_id}/assignments/batch-update', [RosterController::class, 'batchUpdateAssignments']);
        
        // Manager assignment endpoints (add/remove managers for roster period)
        Route::post('/{id}/managers/add', [RosterController::class, 'addManager']);
        Route::delete('/{id}/managers/{employeeId}', [RosterController::class, 'removeManager']);

        // Group formation endpoints for CNS/Support
        Route::post('/{id}/groups/assign', [RosterController::class, 'assignEmployeeToGroup']);
        Route::delete('/{id}/groups/{employeeId}', [RosterController::class, 'removeEmployeeFromGroup']);
    });

    // =======================================
    // ROSTER TASKS
    // =======================================
    Route::resource('roster/tasks', RosterTaskController::class)->except(['create', 'edit']);
    // =======================================
    Route::prefix('shift-requests')->group(function () {
        // List & Read
        Route::get('/', [ShiftRequestController::class, 'index']);
        Route::get('/my-shifts', [ShiftRequestController::class, 'getMyShifts']);
        Route::get('/available-partners', [ShiftRequestController::class, 'getAvailablePartners']);
        Route::get('/pending-count', [ShiftRequestController::class, 'getPendingCount']);
        Route::get('/manager-for-shift', [ShiftRequestController::class, 'getManagerForShift']);
        Route::get('/check-manager-status', [ShiftRequestController::class, 'checkManagerStatus']);
        Route::get('/{id}', [ShiftRequestController::class, 'show']);
        
        // Create & Actions
        Route::post('/', [ShiftRequestController::class, 'store']);
        Route::post('/{id}/approve-target', [ShiftRequestController::class, 'approveByTarget']);
        Route::post('/{id}/approve-manager', [ShiftRequestController::class, 'approveByManager']);
        Route::post('/{id}/reject', [ShiftRequestController::class, 'reject']);
        Route::post('/{id}/cancel', [ShiftRequestController::class, 'cancel']);
    });

    // =======================================
    // LEAVE REQUEST
    // =======================================
    Route::prefix('leave-requests')->group(function () {
        // Employee routes - any authenticated user can access
        Route::get('/my-requests', [LeaveRequestController::class, 'myRequests']);
        Route::get('/statistics', [LeaveRequestController::class, 'statistics']);
        Route::get('/approval-preview', [LeaveRequestController::class, 'approvalPreview']);
        Route::post('/', [LeaveRequestController::class, 'store']);
        Route::get('/{id}/document', [LeaveRequestController::class, 'document']);
        Route::get('/{id}', [LeaveRequestController::class, 'show']);
        Route::delete('/{id}', [LeaveRequestController::class, 'destroy']);
        
        // Manager routes - only managers can approve/reject and view all requests
        Route::middleware('role:' . User::ROLE_MANAGER_TEKNIK . ',' . User::ROLE_GENERAL_MANAGER)->group(function () {
            Route::get('/', [LeaveRequestController::class, 'index']);
            Route::post('/{id}/update-status', [LeaveRequestController::class, 'updateStatus']);
        });
    });

    // =======================================
    // MAILBOX / NOTIFICATION
    // =======================================
    Route::prefix('notifications')->group(function () {
        // Debug endpoints (development only) - MUST be before {id} routes
        Route::get('/debug/{id}', [NotificationController::class, 'debugNotification']);
        Route::post('/debug/create-test', [NotificationController::class, 'createTestNotification']);
        Route::post('/debug/create-test-scheduled', [NotificationController::class, 'createTestScheduledNotification']);
        
        // Standard endpoints
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/all', [NotificationController::class, 'all']); // Single endpoint for all categories
        Route::get('/daily-tasks', [NotificationController::class, 'dailyTasks']);
        Route::post('/send', [NotificationController::class, 'send']);
        Route::match(['put', 'post'], '/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::put('/{id}', [NotificationController::class, 'update']);
        Route::post('/{id}/star', [NotificationController::class, 'toggleStar']);
        Route::post('/{id}/restore', [NotificationController::class, 'restore']);
        Route::delete('/{id}', [NotificationController::class, 'destroy']);
        Route::delete('/{id}/permanent', [NotificationController::class, 'forceDestroy']);
        Route::post('/{id}/resend-email', [NotificationController::class, 'resendEmail']);
        
        // Scheduled notifications
        Route::post('/save-scheduled', [NotificationController::class, 'saveScheduled']);
        Route::get('/scheduled', [NotificationController::class, 'getScheduled']);
        Route::put('/scheduled/{id}', [NotificationController::class, 'updateScheduled']);
        Route::delete('/scheduled/{id}', [NotificationController::class, 'deleteScheduled']);
        
        // Admin and managers can create notifications
        Route::post('/create', [NotificationController::class, 'create'])->middleware('role:' . User::ROLE_ADMIN . ',' . User::ROLE_GENERAL_MANAGER);
    });

    // =======================================
    // EMPLOYEE PERSONAL SCHEDULE
    // =======================================
    Route::prefix('employee')->group(function () {
        Route::get('/my-schedule', [EmployeeScheduleController::class, 'getMySchedule']);
        Route::get('/roster/{rosterId}/my-schedule', [EmployeeScheduleController::class, 'getMyScheduleByRoster']);
    });
});