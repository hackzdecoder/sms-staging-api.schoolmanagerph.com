<?php
namespace App\Http\Controllers\api\auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PasswordController extends Controller
{
    public function validateResetLink(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|exists:users,username',
            'token' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'valid' => false,
                'message' => 'Validation failed.',
            ], 422);
        }

        // Find user first
        $user = User::where('username', $request->username)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'status' => 401,
                'valid' => false,
                'message' => 'Invalid reset token.',
            ], 401);
        }

        // CRITICAL CHECK: If token is NULL (already used) or doesn't match
        if (!$user->reset_password_token || $user->reset_password_token !== $request->token) {
            return response()->json([
                'success' => false,
                'status' => 410,
                'valid' => false,
                'message' => 'Reset token has already been used or is invalid.',
                'reset_level' => $request->get('level') ? intval($request->get('level')) : 1
            ], 410);
        }

        if (Carbon::now()->gt($user->reset_token_expires_at)) {
            return response()->json([
                'success' => false,
                'status' => 410,
                'valid' => false,
                'message' => 'Reset token has expired.',
                'reset_level' => $request->get('level') ? intval($request->get('level')) : 1
            ], 410);
        }

        return response()->json([
            'success' => true,
            'status' => 200,
            'valid' => true,
            'message' => 'Reset token is valid.',
            'data' => [
                'reset_token_expires_at' => $user->reset_token_expires_at,
                'fullname' => $user->fullname, // ADDED THIS LINE
            ]
        ], 200);
    }

    public function resetPasswordUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|exists:users,username',
            'token' => 'required',
            'new_password' => 'required|min:8|confirmed',
            'new_password_confirmation' => 'required',
            'password_update_by' => 'required|in:1,2',
        ], [
            'password_update_by.in' => 'Invalid password update level. Must be 1 (Self-service) or 2 (Admin-assisted).',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('username', $request->username)
                    ->where('reset_password_token', $request->token)
                    ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'status' => 401,
                'message' => 'Invalid reset token.',
            ], 401);
        }

        if (Carbon::now()->gt($user->reset_token_expires_at)) {
            return response()->json([
                'success' => false,
                'status' => 410,
                'message' => 'Reset token has expired.',
            ], 410);
        }

        $passwordUpdateBy = $request->password_update_by;

        DB::table('users')
            ->where('username', $request->username)
            ->update([
                'password' => Hash::make($request->new_password),
                'password_update_by' => $passwordUpdateBy,
                'reset_password_token' => null,
                'reset_token_expires_at' => null,
                'otp_verified_at' => null,
                'updated_at' => Carbon::now(),
            ]);

        // CRITICAL: Only return Google redirect for LEVEL 2
        if ($passwordUpdateBy == 2) {
            return response()->json([
                'success' => true,
                'status' => 200,
                'message' => 'Password Reset was successful, please give the new password to the user who requested the reset.',
                'redirect_url' => 'https://www.google.com/', // GOOGLE REDIRECT FOR LEVEL 2
                'is_external_redirect' => true, // Flag for frontend
                'reset_level' => 2 // Include level for verification
            ], 200);
        }

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Password Reset was successful',
            'reset_level' => 1
        ], 200);
    }

    /**
     * Update password for authenticated users
     */
    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'current_password' => 'required',
            'new_password' => 'required|min:8|confirmed',
            'new_password_confirmation' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Verify current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'status' => 401,
                'message' => 'Current password is incorrect.',
            ], 401);
        }

        // ADD THIS CHECK: Verify new password is different from current password
        if (Hash::check($request->new_password, $user->password)) {
            return response()->json([
                'success' => false,
                'status' => 422,
                'message' => 'New password must be different from current password.',
                'errors' => ['new_password' => ['New password must be different from current password.']],
            ], 422);
        }

        // Update password with password_update_by = 1 (authenticated user change)
        DB::table('users')
            ->where('id', $user->id)
            ->update([
                'password' => Hash::make($request->new_password),
                'password_update_by' => 1,
                'updated_at' => Carbon::now(), // Explicitly update timestamp
            ]);

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Password updated successfully.',
        ], 200);
    }
}