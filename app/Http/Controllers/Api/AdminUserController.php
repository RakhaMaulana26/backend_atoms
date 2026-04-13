<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountToken;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\User;
use App\Jobs\SendActivationCodeEmail as SendActivationCodeEmailJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Mail\ActivationCodeEmail;

class AdminUserController extends Controller
{
    /**
     * GET /admin/users
     * Query params:
     * - search: search by name or email
     * - role: filter by user role (admin, cns, support, manager, gm)
     * - employee_type: filter by employee type (CNS, SUPPORT, MANAGER)
     * - is_active: filter by active status
     * - per_page: pagination limit
     */
    public function index(Request $request)
    {
        $currentUser = $request->user();
        $isAdmin = $currentUser && $currentUser->role === User::ROLE_ADMIN;

        // Build cache key with all filter parameters to ensure proper cache isolation
        $cacheParams = [
            'page' => $request->get('page', 1),
            'per_page' => $request->get('per_page', 15),
            'role' => $request->get('role', ''),
            'employee_type' => $request->get('employee_type', ''),
            'is_active' => $request->get('is_active', ''),
            'search' => $request->get('search', ''),
            'is_admin' => $isAdmin ? '1' : '0',
        ];
        $cacheKey = 'users_list_' . md5(json_encode($cacheParams));
        
        // Cache for 5 minutes
        $users = Cache::remember($cacheKey, 300, function () use ($request, $isAdmin) {
            if ($isAdmin) {
                $query = User::with(['employee' => function($q) {
                    $q->withTrashed();
                }])->withTrashed();
            } else {
                // Non-admin users can only view existing active users (no trashed records)
                $query = User::with('employee')->whereNull('deleted_at')->where('is_active', true);
            }

            // Filter by role
            if ($request->filled('role')) {
                $query->where('role', $request->role);
            }

            // Filter by employee_type (untuk karyawan CNS, SUPPORT, MANAGER)
            if ($request->filled('employee_type')) {
                $query->whereHas('employee', function($q) use ($request) {
                    $q->where('employee_type', $request->employee_type);
                });
            }

            // Filter by active status
            if ($isAdmin && $request->filled('is_active')) {
                $query->where('is_active', $request->is_active);
            }

            // Search by name or email
            if ($request->filled('search')) {
                $query->where(function ($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->search . '%')
                        ->orWhere('email', 'like', '%' . $request->search . '%');
                });
            }

            // Order by role first (Admin, Manager Teknik, Cns, Support), then by name
            $query->orderByRaw("
                CASE role
                    WHEN 'Admin' THEN 1
                    WHEN 'General Manager' THEN 2
                    WHEN 'Manager Teknik' THEN 3
                    WHEN 'Cns' THEN 4
                    WHEN 'Support' THEN 5
                    ELSE 6
                END
            ")->orderBy('name', 'asc');

            // If 'all' parameter is set or no pagination params, return all data without pagination
            if ($request->get('all') === 'true' || !$request->has('page')) {
                $users = $query->get();
                
                // Add employee_type to each user for easier frontend access
                foreach ($users as $user) {
                    $user->employee_type = $user->employee->employee_type ?? null;
                }
                
                return $users;
            }

            $users = $query->paginate($request->get('per_page', 15));

            // Add employee_type to each user for easier frontend access
            $users->getCollection()->transform(function ($user) {
                $user->employee_type = $user->employee ? 
                    $user->employee->employee_type : null;
                return $user;
            });

            return $users;
        });

        // Handle different response formats
        if (is_array($users) || $users instanceof \Illuminate\Support\Collection) {
            // Return all data without pagination wrapper
            return response()->json([
                'data' => $users
            ]);
        }

        return response()->json($users);
    }

    /**
     * POST /admin/users
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'role' => 'required|in:Admin,Cns,Support,Manager Teknik,General Manager',
            'employee_type' => 'required|in:Administrator,CNS,Support,Manager Teknik,General Manager',
            'grade' => 'sometimes|integer|min:1',
            'is_active' => 'sometimes|boolean',
        ]);

        DB::beginTransaction();
        try {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'role' => $request->role,
                'grade' => $request->grade,
                'is_active' => $request->get('is_active', true),
            ]);

            // Create employee record for all roles
            Employee::create([
                'user_id' => $user->id,
                'employee_type' => $request->employee_type,
                'is_active' => $request->get('is_active', true),
            ]);

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'create',
                'module' => 'user',
                'reference_id' => $user->id,
                'description' => 'Created user: ' . $user->email,
            ]);

            DB::commit();
            
            // Clear only users cache (more specific than flush)
            Cache::forget('users_list_*');
            $this->clearUsersCache();

            return response()->json([
                'message' => 'User created successfully',
                'data' => $user->load('employee'),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create user',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PATCH /admin/users/{id} - Partial update
     */
    public function update(Request $request, $id)
    {
        $user = User::withTrashed()->findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'role' => 'sometimes|in:Admin,Cns,Support,Manager Teknik,General Manager',
            'employee_type' => 'sometimes|in:Administrator,CNS,Support,Manager Teknik,General Manager',
            'grade' => 'sometimes|integer|min:1',
            'is_active' => 'sometimes|boolean',
        ]);

        DB::beginTransaction();
        try {
            $user->update($request->only(['name', 'email', 'role', 'grade', 'is_active']));

            // Update or create employee record for all roles
            if ($user->employee) {
                $user->employee->update([
                    'employee_type' => $request->employee_type,
                    'is_active' => $request->get('is_active', $user->is_active),
                ]);
            } else {
                Employee::create([
                    'user_id' => $user->id,
                    'employee_type' => $request->employee_type,
                    'is_active' => $request->get('is_active', true),
                ]);
            }

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'update',
                'module' => 'user',
                'reference_id' => $user->id,
                'description' => 'Updated user: ' . $user->email,
            ]);

            DB::commit();
            
            // Clear users cache
            $this->clearUsersCache();

            return response()->json([
                'message' => 'User updated successfully',
                'data' => $user->load('employee'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update user',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /admin/users/{id}
     */
    public function destroy($id)
    {
        $user = User::findOrFail($id);

        $user->delete();

        if ($user->employee) {
            $user->employee->delete();
        }

        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => 'delete',
            'module' => 'user',
            'reference_id' => $user->id,
            'description' => 'Soft deleted user: ' . $user->email,
        ]);        
        // Clear users cache
        $this->clearUsersCache();
        return response()->json([
            'message' => 'User deleted successfully',
        ]);
    }

    /**
     * POST /admin/users/{id}/restore
     */
    public function restore($id)
    {
        $user = User::withTrashed()->findOrFail($id);

        $user->restore();

        if ($user->employee) {
            $user->employee->restore();
        }

        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => 'restore',
            'module' => 'user',
            'reference_id' => $user->id,
            'description' => 'Restored user: ' . $user->email,
        ]);
        
        // Clear only users cache
        $this->clearUsersCache();

        return response()->json([
            'message' => 'User restored successfully',
            'data' => $user->load('employee'),
        ]);
    }

    /**
     * Helper method to clear users cache more efficiently
     */
    private function clearUsersCache()
    {
        // Clear all users list cache variations
        $patterns = ['users_list_*'];
        foreach ($patterns as $pattern) {
            // For Redis/Memcached
            if (config('cache.default') === 'redis') {
                $keys = Cache::getRedis()->keys($pattern);
                foreach ($keys as $key) {
                    Cache::forget($key);
                }
            } else {
                // For file/array cache, we need to flush (less optimal)
                Cache::flush();
                break;
            }
        }
    }

    /**
     * POST /admin/users/{id}/generate-token
     * Generate activation token yang bisa digunakan untuk:
     * - Setup password pertama kali (user baru)
     * - Reset password (user yang lupa password)
     */
    public function generateToken(Request $request, $id)
    {
        $request->validate([
            'type' => 'sometimes|in:activation,reset_password',
        ]);

        $user = User::findOrFail($id);

        // Check for recent token generation to prevent spam (within last 10 seconds)
        $cacheKey = "token_generated_{$user->id}";
        if (Cache::has($cacheKey)) {
            return response()->json([
                'message' => 'Token was generated recently. Please wait before generating a new one.',
            ], 429);
        }

        $hasPassword = !empty($user->password);

        $token = strtoupper(Str::random(3) . '-' . Str::random(6));
        $expiredAt = now()->addDays(7);

        // Type selalu 'activation' tapi bisa digunakan untuk kedua kasus
        $accountToken = AccountToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'type' => 'activation',
            'expired_at' => $expiredAt,
        ]);

        // Set cache to prevent duplicate generation for 10 seconds
        Cache::put($cacheKey, true, 10);

        // Notification message disesuaikan dengan kondisi user
        $notificationTitle = $hasPassword ? 'Password Reset Code' : 'Account Activation Code';
        $notificationMessage = $hasPassword 
            ? "Your password reset code is: {$token}. Use this code to set your new password."
            : "Welcome! Your account activation code is: {$token}. Use this code to set your password.";

        Notification::create([
            'user_id' => $user->id,
            'title' => $notificationTitle,
            'message' => $notificationMessage,
        ]);

        // Send activation code via email
        try {
            Mail::to($user->email)->send(
                new ActivationCodeEmail(
                    $token,
                    $user->name,
                    $hasPassword ? 'reset_password' : 'activation',
                    $expiredAt->format('d M Y, H:i')
                )
            );
            Log::info("Activation code email sent successfully to {$user->email}");
        } catch (\Exception $e) {
            Log::error("Failed to send activation code email to {$user->email}: " . $e->getMessage());
        }

        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => 'generate_token',
            'module' => 'user',
            'reference_id' => $user->id,
            'description' => "Generated activation token for {$user->email} (" . ($hasPassword ? 'password reset' : 'new account') . ')',
        ]);

        return response()->json([
            'message' => 'Token generated successfully and sent to user\'s email',
            'token' => $token,
            'expired_at' => $expiredAt->format('Y-m-d H:i:s'),
            'purpose' => $hasPassword ? 'reset_password' : 'activation',
        ]);
    }

    /**
     * POST /admin/users/{id}/send-activation-code
     * Send activation code via email (for manual sending from popup)
     */
    public function sendActivationCode(Request $request, $id)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $user = User::findOrFail($id);
        $token = $request->input('token');

        // Check for recent email send to prevent spam (within last 60 seconds)
        // Cache key includes user_id AND token, so different tokens can be sent immediately
        $cacheKey = "email_sent_{$user->id}_{$token}";
        Log::info("Checking cache key: {$cacheKey}");
        
        if (Cache::has($cacheKey)) {
            Log::warning("Duplicate email request blocked for user {$user->id} with token {$token}");
            return response()->json([
                'message' => 'This exact activation code was already sent recently to this user. Please wait at least 1 minute before sending again.',
            ], 429);
        }

        // Use database-level locking to prevent race conditions for the same user+token
        $lockKey = "send_email_lock_{$user->id}_{$token}";
        $lock = Cache::lock($lockKey, 10); // 10 second lock
        
        if (!$lock->get()) {
            Log::warning("Email send already in progress for user {$user->id}");
            return response()->json([
                'message' => 'Email sending is already in progress. Please wait.',
            ], 429);
        }

        try {
            // Verify token exists and not expired
            $accountToken = AccountToken::where('user_id', $user->id)
                ->where('token', $token)
                ->where('expired_at', '>', now())
                ->first();

            if (!$accountToken) {
                return response()->json([
                    'message' => 'Invalid or expired token',
                ], 400);
            }

            $hasPassword = !empty($user->password);
            $purpose = $hasPassword ? 'reset_password' : 'activation';

            // Dispatch email sending to queue for better performance
            SendActivationCodeEmailJob::dispatch(
                $user->id,
                $token,
                $purpose,
                $accountToken->expired_at->format('d M Y, H:i'),
                Auth::id()
            );

            // Set cache to prevent duplicate sends for 60 seconds (1 minute)
            // This is specific to user_id + token combination
            Cache::put($cacheKey, true, 60);

            Log::info("Activation code email queued for {$user->email}");

            return response()->json([
                'message' => 'Activation code is being sent to email',
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to queue activation code email to {$user->email}: " . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to send email: ' . $e->getMessage(),
            ], 500);
        } finally {
            // Always release the lock
            $lock->release();
        }
    }
}
