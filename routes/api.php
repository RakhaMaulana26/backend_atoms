<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\RosterController;
use App\Http\Controllers\Api\ShiftRequestController;
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
    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::post('/users', [AdminUserController::class, 'store']);
        Route::put('/users/{id}', [AdminUserController::class, 'update']);
        Route::delete('/users/{id}', [AdminUserController::class, 'destroy']);
        Route::post('/users/{id}/restore', [AdminUserController::class, 'restore']);
        Route::post('/users/{id}/generate-token', [AdminUserController::class, 'generateToken']);
        Route::post('/users/{id}/send-activation-code', [AdminUserController::class, 'sendActivationCode']);
    });

    // =======================================
    // ROSTERING
    // =======================================
    Route::prefix('rosters')->middleware('role:admin,manager')->group(function () {
        Route::get('/', [RosterController::class, 'index']);
        Route::post('/', [RosterController::class, 'store']);
        Route::get('/{id}', [RosterController::class, 'show']);
        Route::post('/{id}/publish', [RosterController::class, 'publish']);
    });

    // =======================================
    // SHIFT REQUEST
    // =======================================
    Route::prefix('shift-requests')->group(function () {
        Route::post('/', [ShiftRequestController::class, 'store']);
        Route::post('/{id}/approve-target', [ShiftRequestController::class, 'approveByTarget']);
        Route::post('/{id}/approve-manager', [ShiftRequestController::class, 'approveByManager'])->middleware('role:manager');
        Route::post('/{id}/reject', [ShiftRequestController::class, 'reject']);
    });

    // =======================================
    // MAILBOX / NOTIFICATION
    // =======================================
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/{id}/resend-email', [NotificationController::class, 'resendEmail']);
        
        // Admin only: create notification
        Route::post('/create', [NotificationController::class, 'create'])->middleware('role:admin');
    });

    // =======================================
    // ACTIVITY LOGS
    // =======================================
    Route::prefix('activity-logs')->group(function () {
        Route::get('/', [ActivityLogController::class, 'index']);
        Route::get('/recent', [ActivityLogController::class, 'recent']);
        Route::get('/statistics', [ActivityLogController::class, 'statistics']);
    });
});
