<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployeeScheduleController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\RosterController;
use App\Http\Controllers\Api\RosterImportController;
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
// PROTECTED ROUTES (Require Authentication)
// =======================================
Route::middleware('auth:sanctum')->group(function () {
    
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
        Route::post('/import', [RosterImportController::class, 'import']);
        Route::post('/import-url', [RosterImportController::class, 'importFromUrl']);
        Route::put('/{id}', [RosterController::class, 'update']);
        Route::delete('/{id}', [RosterController::class, 'destroy']);
        Route::post('/{id}/publish', [RosterController::class, 'publish']);
        Route::post('/{id}/sync', [RosterImportController::class, 'syncFromSpreadsheet']);
        Route::post('/{id}/push', [RosterImportController::class, 'pushToSpreadsheet']);
        Route::put('/{id}/spreadsheet-url', [RosterImportController::class, 'updateSpreadsheetUrl']);
        
        // Roster day assignments
        Route::post('/{roster_id}/days/{day_id}/assignments', [RosterController::class, 'storeAssignments']);
        Route::put('/{roster_id}/days/{day_id}/assignments', [RosterController::class, 'updateAssignments']);
        Route::delete('/{roster_id}/days/{day_id}/assignments/{assignment_id}', [RosterController::class, 'deleteAssignment']);
        
        // Quick update assignment (simplified endpoint)
        Route::post('/{roster_id}/assignments/quick-update', [RosterController::class, 'quickUpdateAssignment']);
        
        // Batch update multiple employees and dates at once
        Route::post('/{roster_id}/assignments/batch-update', [RosterController::class, 'batchUpdateAssignments']);
    });

    // =======================================
    // SHIFT REQUEST
    // =======================================
    Route::prefix('shift-requests')->group(function () {
        Route::get('/my-shifts', [ShiftRequestController::class, 'getMyShifts']);
        Route::get('/available-partners', [ShiftRequestController::class, 'getAvailablePartners']);
        Route::post('/', [ShiftRequestController::class, 'store']);
        Route::post('/{id}/approve-target', [ShiftRequestController::class, 'approveByTarget']);
        Route::post('/{id}/approve-manager', [ShiftRequestController::class, 'approveByManager'])->middleware('role:' . User::ROLE_MANAGER_TEKNIK . ',' . User::ROLE_GENERAL_MANAGER);
        Route::post('/{id}/reject', [ShiftRequestController::class, 'reject']);
    });

    // =======================================
    // LEAVE REQUEST
    // =======================================
    Route::prefix('leave-requests')->group(function () {
        // Employee routes - any authenticated user can access
        Route::get('/my-requests', [LeaveRequestController::class, 'myRequests']);
        Route::get('/statistics', [LeaveRequestController::class, 'statistics']);
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
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/send', [NotificationController::class, 'send']);
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/{id}/star', [NotificationController::class, 'toggleStar']);
        Route::post('/{id}/restore', [NotificationController::class, 'restore']);
        Route::delete('/{id}', [NotificationController::class, 'destroy']);
        Route::delete('/{id}/permanent', [NotificationController::class, 'forceDestroy']);
        Route::post('/{id}/resend-email', [NotificationController::class, 'resendEmail']);
        
        // Admin and managers can create notifications
        Route::post('/create', [NotificationController::class, 'create'])->middleware('role:' . User::ROLE_ADMIN . ',' . User::ROLE_GENERAL_MANAGER);
    });

    // =======================================
    // ACTIVITY LOGS
    // =======================================
    Route::prefix('activity-logs')->group(function () {
        Route::get('/', [ActivityLogController::class, 'index']);
        Route::get('/recent', [ActivityLogController::class, 'recent']);
        Route::get('/statistics', [ActivityLogController::class, 'statistics']);
    });

    // =======================================
    // EMPLOYEE PERSONAL SCHEDULE
    // =======================================
    Route::prefix('employee')->group(function () {
        Route::get('/my-schedule', [EmployeeScheduleController::class, 'getMySchedule']);
        Route::get('/roster/{rosterId}/my-schedule', [EmployeeScheduleController::class, 'getMyScheduleByRoster']);
    });
});
