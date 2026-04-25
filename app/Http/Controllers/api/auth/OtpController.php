<?php

namespace App\Http\Controllers\api\auth;

use App\Http\Controllers\Controller;
use App\Helpers\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use Carbon\Carbon;
use Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OtpController extends Controller
{
  protected string $trademarksConnection = 'trademarks';

  /**
   * Get OTP session status and expiration
   */
  public function otpSession(Request $request)
  {
    $validator = Validator::make($request->all(), [
      'username' => 'required|exists:users,username',
      'school_code' => 'nullable|string',
    ], [
      'username.required' => 'Username is required.',
      'username.exists' => 'Username not found.',
    ]);

    if ($validator->fails()) {
      return response()->json([
        'success' => false,
        'status' => 422,
        'message' => 'Validation failed.',
        'errors' => $validator->errors(),
      ], 422);
    }

    $query = User::where('username', $request->username);

    if ($request->has('school_code') && !empty($request->school_code)) {
      $query->where('school_code', $request->school_code);
    }

    $user = $query->first();

    if (!$user) {
      return response()->json([
        'success' => false,
        'status' => 404,
        'message' => 'User not found.',
      ], 404);
    }

    // Check if account is active
    if ($user->account_status !== 'active') {
      return response()->json([
        'success' => false,
        'status' => 403,
        'message' => 'Your account has been deactivated',
        'account_status' => $user->account_status,
      ], 403);
    }

    // Check if user has an active OTP
    $hasOtp = !empty($user->otp_code);

    return response()->json([
      'success' => true,
      'status' => 200,
      'data' => [
        'otp_code_expired_at' => $user->otp_code_expired_at,
        'has_otp' => $hasOtp,
      ],
    ], 200);
  }

  /**
   * Get company name from trademarks database
   */
  private function getCompanyName(): string
  {
    try {
      $company = DB::connection('trademarks')
        ->table('companies')
        ->select('company_name')
        ->first();

      return $company->company_name ?? 'TaparSoft Enterprise';
    } catch (\Exception $e) {
      return 'TaparSoft Enterprise';
    }
  }

  /**
   * Get user's full name from student_records
   */
  private function getUserFullName($user): string
  {
    $fullName = $user->username;
    $schoolCode = $user->school_code ?? null;

    if ($schoolCode) {
      try {
        $targetDatabaseName = DatabaseManager::generateDatabaseName($schoolCode);
        $schoolDatabaseConnection = DatabaseManager::connect($targetDatabaseName);

        $studentProfile = $schoolDatabaseConnection
          ->table('student_records')
          ->where('user_id', $user->user_id)
          ->first();

        if ($studentProfile) {
          if (isset($studentProfile->fullname) && !empty($studentProfile->fullname)) {
            $fullName = $studentProfile->fullname;
          }
        }

        DatabaseManager::disconnect($targetDatabaseName);

      } catch (\Exception $e) {
        $fullName = $user->username;
      }
    }

    return $fullName;
  }

  /**
   * Verify OTP for BOTH flows:
   * 1. First-user registration (no reset_token)
   * 2. Password reset (with reset_token)
   */
  public function verifyOtp(Request $request)
  {
    $request->merge([
      'otp_code' => trim($request->otp_code),
      'username' => trim($request->username),
      'school_code' => trim($request->school_code ?? ''),
    ]);

    $validator = Validator::make($request->all(), [
      'otp_code' => 'required|digits:6',
      'username' => 'required',
      'school_code' => 'required|string',
    ], [
      'otp_code.required' => 'OTP code is required.',
      'otp_code.digits' => 'OTP code must be 6 digits.',
      'username.required' => 'Username is required.',
      'school_code.required' => 'School code is required.',
    ]);

    if ($validator->fails()) {
      return response()->json([
        'success' => false,
        'status' => 422,
        'message' => 'Validation failed.',
        'errors' => $validator->errors(),
      ], 422);
    }

    // ✅ Find user with BOTH username AND school_code AND otp_code
    $user = User::where('username', $request->username)
      ->where('school_code', $request->school_code)
      ->where('otp_code', $request->otp_code)
      ->first();

    if (!$user) {
      return response()->json([
        'success' => false,
        'status' => 401,
        'message' => 'Invalid OTP code or user not found for this school.',
      ], 401);
    }

    // Check if OTP has expired
    if (Carbon::now()->gt($user->otp_code_expired_at)) {
      return response()->json([
        'success' => false,
        'status' => 410,
        'message' => 'OTP has expired. Please request a new OTP.',
      ], 410);
    }

    // Check if account is active
    if ($user->account_status !== 'active') {
      return response()->json([
        'success' => false,
        'status' => 403,
        'message' => 'Your account has been deactivated',
        'account_status' => $user->account_status,
      ], 403);
    }

    // DETECT FLOW: Check if user already has email
    $isFirstUserFlow = empty($user->email) || $user->email_verified_at === null;
    $resetToken = null;

    if ($isFirstUserFlow) {
      // FIRST-USER REGISTRATION FLOW
      $firstUserToken = Str::random(60);
      $tokenExpiry = Carbon::now()->addMinutes(15);

      DB::table('users')
        ->where('id', $user->id)
        ->update([
          'otp_verified_at' => Carbon::now(),
          'otp_code' => null,
          'otp_code_expired_at' => null,
          'first_user_token' => $firstUserToken,
          'first_user_token_expiry_at' => $tokenExpiry,
          'updated_at' => DB::raw('updated_at'),
        ]);

      return response()->json([
        'success' => true,
        'status' => 200,
        'message' => 'OTP verified successfully. You can now proceed with registration.',
        'data' => [
          'username' => $user->username,
          'email' => $user->email,
          'email_hint' => $user->email ? substr($user->email, 0, 3) . '****' . strstr($user->email, '@') : null,
          'first_user_token' => $firstUserToken,
          'first_user_token_expiry_at' => $tokenExpiry->toDateTimeString(),
        ],
      ], 200);
    } else {
      // PASSWORD RESET FLOW
      $resetToken = Str::random(60);

      DB::table('users')
        ->where('id', $user->id)
        ->update([
          'otp_verified_at' => Carbon::now(),
          'otp_code' => null,
          'otp_code_expired_at' => null,
          'reset_password_token' => $resetToken,
          'reset_token_expires_at' => Carbon::now()->addMinutes(5),
          'updated_at' => DB::raw('updated_at'),
        ]);

      return response()->json([
        'success' => true,
        'status' => 200,
        'message' => 'OTP verified successfully. You can now reset your password.',
        'data' => [
          'username' => $user->username,
          'email_hint' => $user->email ? substr($user->email, 0, 3) . '****' . strstr($user->email, '@') : null,
          'reset_token' => $resetToken,
        ],
      ], 200);
    }
  }

  /**
   * Send OTP for first-user email registration
   */
  public function sendOtpFirstUser(Request $request)
  {
    $request->merge([
      'username' => trim($request->username),
      'email' => trim($request->email),
      'school_code' => trim($request->school_code ?? ''),
    ]);

    $validator = Validator::make($request->all(), [
      'username' => 'required',
      'email' => 'required|email',
      'school_code' => 'required|string',
    ], [
      'username.required' => 'Username is required.',
      'email.required' => 'Email is required.',
      'email.email' => 'Invalid email format.',
      'school_code.required' => 'School code is required.',
    ]);

    if ($validator->fails()) {
      return response()->json([
        'success' => false,
        'status' => 422,
        'message' => 'Validation failed.',
        'errors' => $validator->errors(),
      ], 422);
    }

    // ✅ Find user with BOTH username AND school_code
    $user = User::where('username', $request->username)
      ->where('school_code', $request->school_code)
      ->first();

    if (!$user) {
      return response()->json([
        'success' => false,
        'status' => 404,
        'message' => 'User not found for this school.',
      ], 404);
    }

    // Check if account is active
    if ($user->account_status !== 'active') {
      return response()->json([
        'success' => false,
        'status' => 403,
        'message' => 'Your account has been deactivated',
        'account_status' => $user->account_status,
      ], 403);
    }

    // Temporarily update email for OTP sending
    $originalEmail = $user->email;
    $tempEmail = $request->email;

    // Generate new OTP
    $newOtp = rand(100000, 999999);

    DB::table('users')
      ->where('id', $user->id)
      ->update([
        'otp_code' => $newOtp,
        'otp_verified_at' => null,
        'otp_code_expired_at' => Carbon::now()->addMinutes(5),
        'email' => $tempEmail,
        'updated_at' => DB::raw('updated_at'),
      ]);

    $companyName = $this->getCompanyName();
    $fullName = $this->getUserFullName($user);

    $otpBody = <<<TXT
            Hello {$fullName},

            Your One-Time Password (OTP) for Email Registration is: {$newOtp}

            ***Please do not reply to this email.***

            This e-mail transmission is intended only for the addressee and may contain confidential information.
        TXT;

    try {
      Mail::raw($otpBody, function ($message) use ($tempEmail) {
        $message->to($tempEmail)
          ->subject('Email Registration OTP');
      });

      if (empty($originalEmail)) {
        DB::table('users')
          ->where('id', $user->id)
          ->update([
            'email' => null,
            'updated_at' => DB::raw('updated_at'),
          ]);
      }

    } catch (\Exception $e) {
      DB::table('users')
        ->where('id', $user->id)
        ->update([
          'email' => $originalEmail,
          'updated_at' => DB::raw('updated_at'),
        ]);

      return response()->json([
        'success' => false,
        'status' => 500,
        'message' => 'Failed to send OTP email.',
      ], 500);
    }

    return response()->json([
      'success' => true,
      'status' => 200,
      'message' => 'OTP has been sent to ' . substr($tempEmail, 0, 3) . '****' . strstr($tempEmail, '@'),
      'data' => [
        'email_hint' => substr($tempEmail, 0, 3) . '****' . strstr($tempEmail, '@'),
      ],
    ], 200);
  }

  /**
   * Resend OTP to user by username (for existing users)
   */
  public function resendOtp(Request $request)
  {
    $request->merge([
      'username' => trim($request->username),
      'school_code' => trim($request->school_code ?? ''),
    ]);

    $validator = Validator::make($request->all(), [
      'username' => 'required',
      'school_code' => 'required|string',
    ], [
      'username.required' => 'Username is required.',
      'school_code.required' => 'School code is required.',
    ]);

    if ($validator->fails()) {
      return response()->json([
        'success' => false,
        'status' => 422,
        'message' => 'Validation failed.',
        'errors' => $validator->errors(),
      ], 422);
    }

    // ✅ Find user with BOTH username AND school_code
    $user = User::where('username', $request->username)
      ->where('school_code', $request->school_code)
      ->first();

    if (!$user) {
      return response()->json([
        'success' => false,
        'status' => 404,
        'message' => 'User not found for this school.',
      ], 404);
    }

    if ($user->account_status !== 'active') {
      return response()->json([
        'success' => false,
        'status' => 403,
        'message' => 'Your account has been deactivated',
        'account_status' => $user->account_status,
      ], 403);
    }

    $newOtp = rand(100000, 999999);

    DB::table('users')
      ->where('id', $user->id)
      ->update([
        'otp_code' => $newOtp,
        'otp_verified_at' => null,
        'otp_code_expired_at' => Carbon::now()->addMinutes(5),
        'updated_at' => DB::raw('updated_at'),
      ]);

    $companyName = $this->getCompanyName();
    $fullName = $this->getUserFullName($user);

    $otpBody = <<<TXT
            Hello {$fullName},

            Your new OTP code is: {$newOtp}

            It is valid for the next 5 minutes.

            ***Please do not reply to this email.***
        TXT;

    try {
      Mail::raw($otpBody, function ($message) use ($user) {
        $message->to($user->email)
          ->subject('Your OTP Code');
      });
    } catch (\Exception $e) {
      return response()->json([
        'success' => false,
        'status' => 500,
        'message' => 'Failed to send OTP email.',
      ], 500);
    }

    return response()->json([
      'success' => true,
      'status' => 200,
      'message' => 'A new OTP has been sent to your email.',
      'data' => [
        'email_hint' => $user->email ? substr($user->email, 0, 3) . '****' . strstr($user->email, '@') : null,
      ],
    ], 200);
  }
}