<?php

namespace App\Http\Controllers\api\auth;

use App\Http\Controllers\Controller;
use App\Helpers\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthenticationController extends Controller
{

  public function autoDestroySession(Request $request)
  {
    // Get the token from the request
    $token = $request->bearerToken();

    if (!$token) {
      return response()->json(['status' => 'Deactivated'], 200);
    }

    // Find the token in the database
    $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);

    if (!$accessToken) {
      return response()->json(['status' => 'Deactivated'], 200);
    }

    // Get user from token
    $user = $accessToken->tokenable;

    if (!$user) {
      return response()->json(['status' => 'Deactivated'], 200);
    }

    // Get fresh user data from database
    $freshUser = DB::connection('users_main')
      ->table('users')
      ->where('user_id', $user->user_id)
      ->first();

    if (!$freshUser) {
      return response()->json(['status' => 'Deactivated'], 200);
    }

    // Check account status
    if ($freshUser->account_status === 'active') {
      return response()->json(['status' => 'Active'], 200);
    }

    return response()->json(['status' => 'Deactivated'], 200);
  }

  /**
   * Authenticate user and return JWT token
   * 
   * @param Request $request Contains username and password
   * @return \Illuminate\Http\JsonResponse Login response with token or redirect instructions
   */
  public function login(Request $request)
  {
    // Validate incoming request parameters
    $request->validate([
      'username' => 'required|string',
      'password' => 'required|string',
    ]);

    // Create rate limiting key combining username and IP address
    $rateLimitKey = 'login:' . strtolower($request->username) . '|' . $request->ip();

    // Check if user has exceeded login attempts
    if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
      $retryAfterSeconds = RateLimiter::availableIn($rateLimitKey);
      return response()->json([
        'message' => 'Too many login attempts. Please try again in ' . $retryAfterSeconds . ' seconds.',
      ], 429);
    }

    // Fetch user from the central users_main database
    $userRecord = DB::connection('users_main')
      ->table('users')
      ->where('username', $request->username)
      ->first();

    // If user doesn't exist, increment rate limiter and return error
    if (!$userRecord) {
      RateLimiter::hit($rateLimitKey, 60);
      return response()->json([
        'message' => 'Invalid username or password.',
      ], 401);
    }

    // Password validation with three scenarios: empty, bcrypt hash, or plain text
    $isPasswordValid = false;

    // Scenario 1: Password field is empty/null in database
    if (empty($userRecord->password) || $userRecord->password === null || trim($userRecord->password) === '') {
      if (empty($request->password) || trim($request->password) === '') {
        $isPasswordValid = true;
      }
    }
    // Scenario 2: Password is a bcrypt hash (starts with $2a$, $2b$, or $2y$)
    else if (preg_match('/^\$2[ayb]\$.{56}$/', $userRecord->password)) {
      if (Hash::check($request->password, $userRecord->password)) {
        $isPasswordValid = true;
      }
    }
    // Scenario 3: Password is stored as plain text (legacy support)
    else {
      if ($request->password === $userRecord->password) {
        $isPasswordValid = true;
      }
    }

    // If password validation fails, increment rate limiter and return error
    if (!$isPasswordValid) {
      RateLimiter::hit($rateLimitKey, 60);
      return response()->json([
        'message' => 'Invalid username or password.',
      ], 401);
    }

    // Check if user account is active
    if ($userRecord->account_status !== 'active') {
      return response()->json([
        'message' => 'Your account has been deactivated',
      ], 403);
    }

    // Clear rate limiter on successful authentication attempt
    RateLimiter::clear($rateLimitKey);

    // Initialize variables for student data
    $studentFullName = null;
    $studentNickName = null;
    $detectedSchoolCode = null;

    // First priority: Get school code from user record
    $detectedSchoolCode = $userRecord->school_code ?? null;

    // If no school code in database, extract from username or user_id
    if (!$detectedSchoolCode) {
      // Pattern: schoolcode_username (e.g., wlkae_sagara_kyosuke)
      if (preg_match('/^([a-z]{2,5})_/i', $userRecord->username ?? '', $schoolCodeMatches)) {
        $detectedSchoolCode = strtolower($schoolCodeMatches[1]);
      }
      // Alternative: Extract from beginning of user_id
      else if (preg_match('/^([a-z]{2,5})/i', $userRecord->user_id ?? '', $schoolCodeMatches)) {
        $detectedSchoolCode = strtolower($schoolCodeMatches[1]);
      }
    } else {
      $detectedSchoolCode = strtolower(trim($detectedSchoolCode));
    }

    // If school code is found, fetch student profile from school database
    if ($detectedSchoolCode) {
      try {
        // Generate appropriate database name based on environment
        $targetDatabaseName = DatabaseManager::generateDatabaseName($detectedSchoolCode);

        // Connect to the school-specific database
        $schoolDatabaseConnection = DatabaseManager::connect($targetDatabaseName);

        // Retrieve student record including fullname and nickname
        $studentProfile = $schoolDatabaseConnection
          ->table('student_records')
          ->where('user_id', $userRecord->user_id)
          ->first();

        // If student record exists, extract the data
        if ($studentProfile) {
          $studentFullName = $studentProfile->fullname;
          $studentNickName = $studentProfile->nickname ?? ''; // Use empty string if null
        }

        // Clean up database connection
        DatabaseManager::disconnect($targetDatabaseName);

      } catch (\Exception $databaseException) {
        // Log error but don't fail login - student data is optional
        \Log::error('School database connection failed:', [
          'error' => $databaseException->getMessage(),
          'school_code' => $detectedSchoolCode,
          'user_id' => $userRecord->user_id
        ]);
      }
    }

    // Determine final display name and nickname (student data takes priority)
    $displayFullName = $studentFullName ?? $userRecord->fullname ?? $userRecord->username;
    $displayNickname = $studentNickName ?? $userRecord->nickname ?? '';

    // Prepare user data for response
    $userResponseData = [
      'user_id' => $userRecord->user_id,
      'full_name' => $displayFullName,
      'username' => $userRecord->username,
      'nickname' => $displayNickname,
      'email' => $userRecord->email,
      'name' => $userRecord->name ?? $userRecord->username,
      'account_status' => $userRecord->account_status,
    ];

    // First-time user flow: If email is not verified or empty, redirect to email verification
    if (empty($userRecord->email_verified_at) || trim($userRecord->email_verified_at) === '') {

      return response()->json([
        'success' => true,
        'redirect_to' => '/first-user',
        'message' => 'Please verify your email',
        'user' => $userResponseData,
        'requires_email' => true,
        // 'first_user_token' => $firstUserAuthToken,
      ], 200);
    }

    // Regular user flow: Generate authentication token
    $userModelInstance = User::on('users_main')->find($userRecord->user_id);
    $userModelInstance->tokens()->delete(); // Remove existing tokens
    $authToken = $userModelInstance->createToken('auth_token')->plainTextToken;

    // Update last successful login time
    $currentTimestamp = Carbon::now();
    DB::connection('users_main')
      ->table('users')
      ->where('user_id', $userRecord->user_id)
      ->update([
        'last_successfull_login' => $currentTimestamp,
        'updated_at' => DB::raw('updated_at') // Preserve original timestamp
      ]);

    // Return successful login response with token
    return response()->json([
      'success' => true,
      'redirect_to' => '/',
      'school_code' => $detectedSchoolCode ?? null,
      'message' => 'Login successful.',
      'user' => $userResponseData,
      'token' => $authToken,
      'token_type' => 'Bearer',
      'requires_email' => false,
    ], 200);
  }

  /**
   * Get current authenticated user with fresh data from database
   * 
   * @param Request $request Contains authentication token
   * @return \Illuminate\Http\JsonResponse Current user data or error
   */
  public function getCurrentUser(Request $request)
  {
    $authenticatedUser = $request->user();

    if (!$authenticatedUser) {
      return response()->json([
        'message' => 'User not found'
      ], 401);
    }

    try {
      $usersMainDatabase = DatabaseManager::connect('users_main');
    } catch (\Exception $connectionError) {
      return response()->json([
        'message' => 'Database connection error.'
      ], 500);
    }

    // Fetch fresh user data from database
    $freshUserData = $usersMainDatabase->table('users')
      ->where('user_id', $authenticatedUser->user_id)
      ->first();

    // If user no longer exists in database, log them out
    if (!$freshUserData) {
      $authenticatedUser->tokens()->delete();
      DatabaseManager::disconnect('users_main');
      return response()->json([
        'message' => 'User account no longer exists',
        'logged_out' => true,
      ], 401);
    }

    // If account is not active, log them out
    if ($freshUserData->account_status !== 'active') {
      $authenticatedUser->currentAccessToken()->delete();
      DatabaseManager::disconnect('users_main');
      return response()->json([
        'message' => 'Your account has been deactivated',
        'account_status' => $freshUserData->account_status,
        'logged_out' => true,
      ], 403);
    }

    // Fetch student data for current user as well
    $currentStudentFullName = null;
    $currentStudentNickName = null;

    $currentSchoolCode = $freshUserData->school_code ?? null;

    // Extract school code if not directly available
    if (!$currentSchoolCode) {
      if (preg_match('/^([A-Z]{2,5})/', $freshUserData->user_id ?? '', $codeMatches)) {
        $currentSchoolCode = $codeMatches[1];
      } else if (preg_match('/^([A-Z]{2,5})/', $freshUserData->username ?? '', $codeMatches)) {
        $currentSchoolCode = $codeMatches[1];
      }
    }

    // Fetch student profile if school code is available
    if ($currentSchoolCode) {
      try {
        $schoolDatabase = DatabaseManager::connect($currentSchoolCode);

        $studentRecord = $schoolDatabase
          ->table('student_records')
          ->where('user_id', $freshUserData->user_id)
          ->select('fullname', 'nickname')
          ->first();

        if ($studentRecord) {
          $currentStudentFullName = $studentRecord->fullname;
          $currentStudentNickName = $studentRecord->nickname;
        }

        DatabaseManager::disconnect($currentSchoolCode);
      } catch (\Exception $schoolDbError) {
        // Silently continue - student data is optional
      }
    }

    // Determine final display values
    $finalFullName = $currentStudentFullName ?? $freshUserData->fullname ?? $freshUserData->username;
    $finalNickname = $currentStudentNickName ?? $freshUserData->nickname ?? '';

    DatabaseManager::disconnect('users_main');

    return response()->json([
      'user_id' => $freshUserData->user_id,
      'username' => $freshUserData->username,
      'full_name' => $finalFullName,
      'email' => $freshUserData->email,
      'account_status' => $freshUserData->account_status,
      'nickname' => $finalNickname,
      'name' => $freshUserData->name ?? $freshUserData->username,
    ]);
  }

  /**
   * Update first-time user information (email and optional password)
   * 
   * @param Request $request Contains username, email, password, and first-user token
   * @return \Illuminate\Http\JsonResponse Success or error response
   */
  public function updateFirstUser(Request $request)
  {
    $request->validate([
      'username' => 'required|string|min:3',
      'email' => 'required|email',
      'password' => 'nullable|string|min:8',
      'first_user_token' => 'required|string',
    ]);

    // Get connection to users_main database
    try {
      $usersMainConnection = DatabaseManager::connect('users_main');
    } catch (\Exception $connectionError) {
      return response()->json([
        'success' => false,
        'message' => 'Database connection error.',
      ], 500);
    }

    // Verify token and user combination
    $userToUpdate = $usersMainConnection
      ->table('users')
      ->where('username', $request->username)
      ->where('first_user_token', $request->first_user_token)
      ->first();

    if (!$userToUpdate) {
      DatabaseManager::disconnect('users_main');
      return response()->json([
        'success' => false,
        'message' => 'Invalid or expired session',
      ], 401);
    }

    // Check if token has expired
    if (Carbon::now()->gt($userToUpdate->first_user_token_expiry_at)) {
      DatabaseManager::disconnect('users_main');
      return response()->json([
        'success' => false,
        'message' => 'Session has expired. Please login again.',
      ], 410);
    }

    // Verify email is not already taken by another user
    $emailAlreadyExists = $usersMainConnection
      ->table('users')
      ->where('email', $request->email)
      ->where('user_id', '!=', $userToUpdate->user_id)
      ->exists();

    if ($emailAlreadyExists) {
      DatabaseManager::disconnect('users_main');
      return response()->json([
        'success' => false,
        'message' => 'Invalid email, Please try another email',
        'errors' => ['email' => ['Invalid email, Please try another email']],
      ], 422);
    }

    // Prepare update data
    $updatePayload = [
      'email' => $request->email,
      'terms_policy_date' => Carbon::now(),
      'terms' => "Agreed",
      'usage_policy' => "Agreed",
      'privacy_policy' => "Accepted",
      'last_successfull_login' => Carbon::now(),
      'first_user_token' => null,
      'first_user_token_expiry_at' => null,
      'updated_at' => Carbon::now(),
    ];

    // Include password if provided
    if ($request->password) {
      $updatePayload['password'] = Hash::make($request->password);
      $updatePayload['password_update_by'] = 1;
    }

    // Set creation timestamp if missing
    if (!$userToUpdate->created_at) {
      $updatePayload['created_at'] = Carbon::now();
    }

    // Set email verification timestamp if missing
    if (!$userToUpdate->email_verified_at) {
      $updatePayload['email_verified_at'] = Carbon::now();
    }

    // Execute update
    $usersMainConnection
      ->table('users')
      ->where('user_id', $userToUpdate->user_id)
      ->update($updatePayload);

    // Create authentication token for the user
    $updatedUserModel = User::on('users_main')->find($userToUpdate->user_id);
    $newAuthToken = $updatedUserModel->createToken('auth_token')->plainTextToken;

    // Clean up database connection
    DatabaseManager::disconnect('users_main');

    return response()->json([
      'success' => true,
      'message' => 'User info updated successfully.',
      'token' => $newAuthToken,
    ]);
  }

  /**
   * Validate first-user token for session continuity
   * 
   * @param Request $request Contains username and first-user token
   * @return \Illuminate\Http\JsonResponse Token validity response
   */
  public function validateFirstUserToken(Request $request)
  {
    $validator = Validator::make($request->all(), [
      'username' => 'required|exists:users,username',
      'first_user_token' => 'required|string',
    ]);

    if ($validator->fails()) {
      return response()->json([
        'success' => false,
        'message' => 'Invalid request',
      ], 422);
    }

    // Connect to users_main database
    try {
      $usersMainConnection = DatabaseManager::connect('users_main');
    } catch (\Exception $connectionError) {
      return response()->json([
        'success' => false,
        'message' => 'Database connection error.',
      ], 500);
    }

    // Find user with matching token
    $tokenUser = $usersMainConnection
      ->table('users')
      ->where('username', $request->username)
      ->where('first_user_token', $request->first_user_token)
      ->first();

    if (!$tokenUser) {
      DatabaseManager::disconnect('users_main');
      return response()->json([
        'success' => false,
        'message' => 'Invalid token',
      ], 401);
    }

    // Check token expiration
    if (Carbon::now()->gt($tokenUser->first_user_token_expiry_at)) {
      DatabaseManager::disconnect('users_main');
      return response()->json([
        'success' => false,
        'message' => 'Token has expired',
      ], 410);
    }

    // ✅ FIX: Calculate remaining seconds until expiry
    $remainingSeconds = Carbon::now()->diffInSeconds(
      $tokenUser->first_user_token_expiry_at,
      false // false = returns negative if expired
    );

    // Ensure it's not negative (shouldn't happen due to check above, but safety)
    $remainingSeconds = max(0, $remainingSeconds);

    DatabaseManager::disconnect('users_main');

    return response()->json([
      'success' => true,
      'message' => 'Token is valid',
      'expires_in' => $remainingSeconds, // ✅ ADDED: Actual remaining time
    ]);
  }

  /**
   * Check account status without authentication
   * 
   * @param Request $request Contains username
   * @return \Illuminate\Http\JsonResponse Account status response
   */
  public function checkAccountStatus(Request $request)
  {
    $validator = Validator::make($request->all(), [
      'username' => 'required|exists:users,username',
    ]);

    if ($validator->fails()) {
      return response()->json([
        'success' => false,
        'message' => 'Username not found.',
      ], 404);
    }

    // Connect to users_main database
    try {
      $usersMainConnection = DatabaseManager::connect('users_main');
    } catch (\Exception $connectionError) {
      return response()->json([
        'success' => false,
        'message' => 'Database connection error.',
      ], 500);
    }

    // Fetch user data
    $statusCheckUser = $usersMainConnection->table('users')
      ->where('username', $request->username)
      ->first();

    // If account is not active, delete any existing tokens
    if ($statusCheckUser->account_status !== 'active') {
      $userModel = User::on('users_main')->find($statusCheckUser->user_id);
      if ($userModel) {
        $userModel->tokens()->delete();
      }

      DatabaseManager::disconnect('users_main');
      return response()->json([
        'success' => false,
        'message' => 'Your account has been deactivated',
        'account_status' => $statusCheckUser->account_status,
        'logged_out' => true,
      ], 403);
    }

    DatabaseManager::disconnect('users_main');

    return response()->json([
      'success' => true,
      'message' => 'Account is active.',
      'account_status' => $statusCheckUser->account_status,
    ]);
  }

  /**
   * Logout user by deleting current authentication token
   * 
   * @param Request $request Contains authentication token
   * @return \Illuminate\Http\JsonResponse Logout confirmation
   */
  public function logout(Request $request)
  {
    $request->user()->currentAccessToken()->delete();

    return response()->json([
      'message' => 'Logged out successfully.',
    ]);
  }
}