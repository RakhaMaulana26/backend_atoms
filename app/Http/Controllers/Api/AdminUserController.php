<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountToken;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\User;
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
        // Cache key based on request parameters
        $cacheKey = 'users_list_' . md5(json_encode($request->all()));
        
        // Cache for 5 minutes
        $users = Cache::remember($cacheKey, 300, function () use ($request) {
            $query = User::with(['employee' => function($q) {
                $q->withTrashed();
            }])->withTrashed();

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
            if ($request->filled('is_active')) {
                $query->where('is_active', $request->is_active);
            }

            // Search by name or email
            if ($request->filled('search')) {
                $query->where(function ($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->search . '%')
                        ->orWhere('email', 'like', '%' . $request->search . '%');
                });
            }

            // Order by created_at descending (newest first)
            $query->orderBy('created_at', 'desc');

            $users = $query->paginate($request->get('per_page', 15));

            // Add employee_type to each user for easier frontend access
            $users->getCollection()->transform(function ($user) {
                $user->employee_type = $user->employee ? $user->employee->employee_type : null;
                return $user;
            });

            return $users;
        });

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
            'role' => 'required|in:admin,cns,support,manager,gm',
            'employee_type' => 'required_if:role,cns,support,manager|in:CNS,SUPPORT,MANAGER',
            'is_active' => 'sometimes|boolean',
        ]);

        DB::beginTransaction();
        try {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'role' => $request->role,
                'is_active' => $request->get('is_active', true),
            ]);

            // Create employee if role is not admin or gm
            if (in_array($request->role, ['cns', 'support', 'manager'])) {
                Employee::create([
                    'user_id' => $user->id,
                    'employee_type' => $request->employee_type,
                    'is_active' => $request->get('is_active', true),
                ]);
            }

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'create',
                'module' => 'user',
                'reference_id' => $user->id,
                'description' => 'Created user: ' . $user->email,
            ]);

            DB::commit();
            
            // Clear users cache
            Cache::flush();

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
     * PUT /admin/users/{id}
     */
    public function update(Request $request, $id)
    {
        $user = User::withTrashed()->findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'role' => 'sometimes|in:admin,cns,support,manager,gm',
            'employee_type' => 'required_if:role,cns,support,manager|in:CNS,SUPPORT,MANAGER',
            'is_active' => 'sometimes|boolean',
        ]);

        DB::beginTransaction();
        try {
            $user->update($request->only(['name', 'email', 'role', 'is_active']));

            // Update or create employee
            if (in_array($user->role, ['cns', 'support', 'manager'])) {
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
            Cache::flush();

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
        Cache::flush();
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
        
        // Clear users cache
        Cache::flush();

        return response()->json([
            'message' => 'User restored successfully',
            'data' => $user->load('employee'),
        ]);
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

        try {
            Mail::to($user->email)->send(
                new ActivationCodeEmail(
                    $token,
                    $user->name,
                    $purpose,
                    $accountToken->expired_at->format('d M Y, H:i')
                )
            );

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'send_activation_code',
                'module' => 'user',
                'reference_id' => $user->id,
                'description' => "Sent activation code to {$user->email}",
            ]);

            Log::info("Activation code sent successfully to {$user->email}");

            return response()->json([
                'message' => 'Activation code sent to email successfully',
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send activation code to {$user->email}: " . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to send email: ' . $e->getMessage(),
            ], 500);
        }
    }
}
