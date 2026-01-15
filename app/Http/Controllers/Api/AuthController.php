<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountToken;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /auth/login
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Account is not active'
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        ActivityLog::create([
            'user_id' => $user->id,
            'action' => 'login',
            'module' => 'auth',
            'description' => 'User logged in',
        ]);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    /**
     * POST /auth/verify-token
     * Verify activation token - dapat digunakan untuk setup password maupun reset password
     */
    public function verifyToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $accountToken = AccountToken::where('token', $request->token)
            ->where('is_used', false)
            ->first();

        if (!$accountToken) {
            return response()->json([
                'message' => 'Invalid token',
                'valid' => false
            ], 404);
        }

        if ($accountToken->isExpired()) {
            return response()->json([
                'message' => 'Token has expired',
                'valid' => false
            ], 400);
        }

        return response()->json([
            'message' => 'Token is valid',
            'valid' => true,
            'type' => $accountToken->type,
            'user' => [
                'id' => $accountToken->user->id,
                'name' => $accountToken->user->name,
                'email' => $accountToken->user->email,
                'has_password' => !empty($accountToken->user->password),
            ],
        ]);
    }

    /**
     * POST /auth/set-password
     * Set atau reset password menggunakan activation token
     * Token yang sama bisa digunakan untuk:
     * - Setup password pertama kali (user baru)
     * - Reset password (user lama yang lupa password)
     */
    public function setPassword(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $accountToken = AccountToken::where('token', $request->token)
            ->where('is_used', false)
            ->first();

        if (!$accountToken || $accountToken->isExpired()) {
            return response()->json([
                'message' => 'Invalid or expired token'
            ], 400);
        }

        $user = $accountToken->user;
        $isFirstTimeSetup = empty($user->password);
        
        $user->password = Hash::make($request->password);
        $user->is_active = true;
        $user->save();

        $accountToken->is_used = true;
        $accountToken->save();

        ActivityLog::create([
            'user_id' => $user->id,
            'action' => $isFirstTimeSetup ? 'activate' : 'reset_password',
            'module' => 'auth',
            'description' => $isFirstTimeSetup ? 'Account activated - password set' : 'Password reset successfully',
        ]);

        return response()->json([
            'message' => $isFirstTimeSetup ? 'Account activated successfully' : 'Password reset successfully',
            'action' => $isFirstTimeSetup ? 'activation' : 'reset',
        ]);
    }

    /**
     * POST /auth/logout
     */
    public function logout(Request $request)
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'logout',
            'module' => 'auth',
            'description' => 'User logged out',
        ]);

        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }
}
