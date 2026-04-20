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

    $user = User::where('username', $request->username)->first();

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
      // FIXED: Use DB::connection('trademarks') instead of DatabaseManager::connectSpecial()
      $company = DB::connection('trademarks')
        ->table('companies')
        ->select('company_name')
        ->first();

      // REMOVED: DatabaseManager::disconnect('special_trademarks');

      return $company->company_name;
    } catch (\Exception $e) {
      // Log error if needed
      return 'TaparSoft Enterprise'; // Default fallback
    }
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
    ]);

    $validator = Validator::make($request->all(), [
      'otp_code' => 'required|digits:6',
      'username' => 'required|exists:users,username',
    ], [
      'otp_code.required' => 'OTP code is required.',
      'otp_code.digits' => 'OTP code must be 6 digits.',
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

    $user = User::where('username', $request->username)
      ->where('otp_code', $request->otp_code)
      ->first();

    if (!$user) {
      return response()->json([
        'success' => false,
        'status' => 401,
        'message' => 'Invalid OTP code.',
      ], 401);
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

    // ✅ DETECT FLOW: Check if user already has email
    $isFirstUserFlow = empty($user->email) || $user->email_verified_at === null;
    $resetToken = null;

    // In verifyOtp method for first-user flow:
    if ($isFirstUserFlow) {
      // ✅ FIRST-USER REGISTRATION FLOW
      // Generate token AFTER OTP verification
      $firstUserToken = Str::random(60);
      $tokenExpiry = Carbon::now()->addMinutes(15); // ✅ Store expiry

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
          'email_hint' => substr($user->email, 0, 3) . '****' . strstr($user->email, '@'),
          'first_user_token' => $firstUserToken, // ✅ RETURN TOKEN
          'first_user_token_expiry_at' => $tokenExpiry->toDateTimeString(), // ✅ ADD THIS LINE
        ],
      ], 200);
    } else {
      // ✅ PASSWORD RESET FLOW
      // Generate reset token
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
          'email_hint' => substr($user->email, 0, 3) . '****' . strstr($user->email, '@'),
          'reset_token' => $resetToken, // ✅ CRITICAL: Return reset_token
        ],
      ], 200);
    }
  }

  /**
   * Send OTP for first-user email registration (NEW METHOD)
   */
  public function sendOtpFirstUser(Request $request)
  {
    $request->merge([
      'username' => trim($request->username),
      'email' => trim($request->email),
    ]);

    $validator = Validator::make($request->all(), [
      'username' => 'required|exists:users,username',
      'email' => 'required|email',
    ], [
      'username.required' => 'Username is required.',
      'username.exists' => 'Username not found.',
      'email.required' => 'Email is required.',
      'email.email' => 'Invalid email format.',
    ]);

    if ($validator->fails()) {
      return response()->json([
        'success' => false,
        'status' => 422,
        'message' => 'Validation failed.',
        'errors' => $validator->errors(),
      ], 422);
    }

    $user = User::where('username', $request->username)->first();

    // Check if account is active
    if ($user->account_status !== 'active') {
      return response()->json([
        'success' => false,
        'status' => 403,
        'message' => 'Your account has been deactivated',
        'account_status' => $user->account_status,
      ], 403);
    }

    // ✅ CHANGED: Check if email already exists (for other users)
    $emailExists = User::where('email', $request->email)
      ->where('username', '!=', $request->username)
      ->exists();

    if ($emailExists) {
      return response()->json([
        'success' => false,
        'status' => 422,
        'message' => 'Email already registered by another user.',
        'errors' => ['email' => ['Email already registered by another user.']],
      ], 422);
    }

    // ✅ CHANGED: Temporarily update email for OTP sending
    // Store original email if exists, otherwise use new email
    $originalEmail = $user->email;
    $tempEmail = $request->email;

    // Generate new OTP
    $newOtp = rand(100000, 999999);

    // Use DB to avoid updating updated_at
    DB::table('users')
      ->where('id', $user->id)
      ->update([
        'otp_code' => $newOtp,
        'otp_verified_at' => null,
        'otp_code_expired_at' => Carbon::now()->addMinutes(5)->addSeconds(10),
        'email' => $tempEmail,
        'updated_at' => DB::raw('updated_at'),
      ]);

    // Get company name from trademarks database
    $companyName = $this->getCompanyName();

    // ✅ CHANGED: Email content for REGISTRATION (not password reset)
    $otpBody = <<<TXT
            Hello {$user->username},

            Your One-Time Password (OTP) for Email Registration is: {$newOtp}

            ***Please do not reply to this email. This is an automated confirmation that we have received your request for email registration.***

            This e-mail transmission is intended only for the addressee and may contain confidential information. Confidentiality is not waived if you are not the intended recipient of this e-mail, nor may you use, review, disclose, disseminate or copy any information contained in or attached to it. If you received this e-mail in error please delete it and any attachments and notify us immediately by reply e-mail. {$companyName} does not warrant that any attachments are free from viruses or other defects. You assume all liability for any loss, damage or other consequences which may arise from opening or using the attachments.
        TXT;

    try {
      Mail::raw($otpBody, function ($message) use ($tempEmail, $user) {
        $message->to($tempEmail)
          ->subject('Email Registration OTP');
      });

      // ✅ Restore original email after sending (if it was empty)
      if (empty($originalEmail)) {
        DB::table('users')
          ->where('id', $user->id)
          ->update([
            'email' => null, // Will be set later in update-first-user
            'updated_at' => DB::raw('updated_at'),
          ]);
      }

    } catch (\Exception $e) {
      // Restore email on error
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
        'error' => $e->getMessage(),
      ], 500);
    }

    return response()->json([
      'success' => true,
      'status' => 200,
      'message' => 'OTP has been sent to your email for registration.',
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
    $request->merge(['username' => trim($request->username)]);

    $validator = Validator::make($request->all(), [
      'username' => 'required|exists:users,username',
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

    $user = User::where('username', $request->username)->first();

    // Check if account is active
    if ($user->account_status !== 'active') {
      return response()->json([
        'success' => false,
        'status' => 403,
        'message' => 'Your account has been deactivated',
        'account_status' => $user->account_status,
      ], 403);
    }

    // Generate new OTP first
    $newOtp = rand(100000, 999999);

    // Use DB to avoid updating updated_at
    DB::table('users')
      ->where('id', $user->id)
      ->update([
        'otp_code' => $newOtp,
        'otp_verified_at' => null,
        'otp_code_expired_at' => Carbon::now()->addMinutes(5)->addSeconds(10),
        'updated_at' => DB::raw('updated_at'),
      ]);

    // Get company name from trademarks database
    $companyName = $this->getCompanyName();

    $otpBody = <<<TXT
            Hello {$user->username},

            Your new OTP code is: {$newOtp}

            It is valid for the next 5 minutes.

            ***Please do not reply to this email. This is an automated confirmation that we have received your request for system password reset.***

            This e-mail transmission is intended only for the addressee and may contain confidential information. Confidentiality is not waived if you are not the intended recipient of this e-mail, nor may you use, review, disclose, disseminate or copy any information contained in or attached to it. If you received this e-mail in error please delete it and any attachments and notify us immediately by reply e-mail. {$companyName} does not warrant that any attachments are free from viruses or other defects. You assume all liability for any loss, damage or other consequences which may arise from opening or using the attachments.
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
        'error' => $e->getMessage(),
      ], 500);
    }

    return response()->json([
      'success' => true,
      'status' => 200,
      'message' => 'A new OTP has been sent to your email.',
      'data' => [
        'email_hint' => substr($user->email, 0, 3) . '****' . strstr($user->email, '@'),
      ],
    ], 200);
  }
}