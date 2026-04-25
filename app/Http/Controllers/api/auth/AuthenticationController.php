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
   * Get user email by username (for pre-filling first-user form)
   */
  public function getUserEmailByUsername(Request $request)
  {
    $validator = Validator::make($request->all(), [
      'username' => 'required|string',
      'school_code' => 'required|string',
    ]);

    if ($validator->fails()) {
      return response()->json([
        'success' => false,
        'message' => 'Username and school code are required.',
      ], 422);
    }

    $username = $request->username;
    $schoolCode = strtoupper($request->school_code);

    // ✅ EXACT QUERY: SELECT FROM users_main WITH school_code filter
    $user = DB::connection('users_main')
      ->table('users')
      ->where('username', $username)
      ->where('school_code', $schoolCode)
      ->first();

    if (!$user) {
      return response()->json([
        'success' => false,
        'message' => 'User not found for this school.',
      ], 404);
    }

    // ✅ EXACT QUERY: LEFT JOIN school database based on school_code
    $studentFullname = null;
    $studentSchoolName = null;

    try {
      // Determine which school database to join based on school_code
      // ATHENEUM -> sm_atheneum_dev
      // SVA -> sm_sva_dev
      $databaseName = DatabaseManager::generateDatabaseName($schoolCode);
      $schoolDb = DatabaseManager::connect($databaseName);

      // EXACT LEFT JOIN query
      $studentRecord = $schoolDb
        ->table('student_records')
        ->where('user_id', $user->user_id)
        ->where('school_code', $schoolCode)
        ->first();

      if ($studentRecord) {
        $studentFullname = $studentRecord->fullname;
        $studentSchoolName = $studentRecord->school_name;
      }

      DatabaseManager::disconnect($databaseName);

    } catch (\Exception $e) {
      \Log::error('Failed to fetch student record: ' . $e->getMessage());
    }

    // Return the combined data (prioritize student_records data)
    return response()->json([
      'success' => true,
      'data' => [
        'email' => $user->email ?? '',
        'fullname' => $studentFullname ?? $user->fullname ?? $user->username,
        'school_name' => $studentSchoolName ?? '',
        'has_email' => !empty($user->email),
      ],
    ], 200);
  }

  /**
   * Authenticate user and return JWT token
   */
  public function login(Request $request)
  {
    $request->validate([
      'username' => 'required|string',
      'password' => 'required|string',
    ]);

    $username = $request->username;
    $password = $request->password;

    $rateLimitKey = 'login:' . strtolower($username) . '|' . $request->ip();

    if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
      $retryAfterSeconds = RateLimiter::availableIn($rateLimitKey);
      return response()->json([
        'message' => 'Too many login attempts. Please try again in ' . $retryAfterSeconds . ' seconds.',
      ], 429);
    }

    // Find ALL users with this username
    $users = DB::connection('users_main')
      ->table('users')
      ->where('username', $username)
      ->get();

    if ($users->isEmpty()) {
      RateLimiter::hit($rateLimitKey, 60);
      return response()->json(['message' => 'Invalid username or password.'], 401);
    }

    // Try each user until password matches
    $userRecord = null;
    foreach ($users as $user) {
      $passwordValid = false;

      if (empty($user->password) || $user->password === null || trim($user->password) === '') {
        $passwordValid = empty($password) || trim($password) === '';
      } else if (preg_match('/^\$2[ayb]\$.{56}$/', $user->password)) {
        $passwordValid = Hash::check($password, $user->password);
      } else {
        $passwordValid = ($password === $user->password);
      }

      if ($passwordValid) {
        $userRecord = $user;
        break;
      }
    }

    if (!$userRecord) {
      RateLimiter::hit($rateLimitKey, 60);
      return response()->json(['message' => 'Invalid username or password.'], 401);
    }

    if ($userRecord->account_status !== 'active') {
      return response()->json(['message' => 'Your account has been deactivated'], 403);
    }

    RateLimiter::clear($rateLimitKey);

    $studentFullName = null;
    $studentNickName = null;

    // ✅ FIX: Use uppercase for school_code
    $detectedSchoolCode = strtoupper($userRecord->school_code ?? '');

    if ($detectedSchoolCode) {
      try {
        $targetDatabaseName = DatabaseManager::generateDatabaseName($detectedSchoolCode);
        $schoolDatabaseConnection = DatabaseManager::connect($targetDatabaseName);

        $studentProfile = $schoolDatabaseConnection
          ->table('student_records')
          ->where('user_id', $userRecord->user_id)
          ->where('school_code', $detectedSchoolCode)
          ->first();

        if ($studentProfile) {
          $studentFullName = $studentProfile->fullname;
          $studentNickName = $studentProfile->nickname ?? '';
        }

        DatabaseManager::disconnect($targetDatabaseName);

      } catch (\Exception $databaseException) {
        \Log::error('School database connection failed: ' . $databaseException->getMessage());
      }
    }

    $displayFullName = $studentFullName ?? $userRecord->fullname ?? $userRecord->username;
    $displayNickname = $studentNickName ?? $userRecord->nickname ?? '';

    $userResponseData = [
      'user_id' => $userRecord->user_id,
      'full_name' => $displayFullName,
      'username' => $userRecord->username,
      'nickname' => $displayNickname,
      'email' => $userRecord->email,
      'name' => $userRecord->name ?? $userRecord->username,
      'account_status' => $userRecord->account_status,
      'school_code' => $detectedSchoolCode,
    ];

    // First-time user flow
    if (empty($userRecord->email_verified_at) || trim($userRecord->email_verified_at) === '') {
      return response()->json([
        'success' => true,
        'redirect_to' => '/first-user',
        'message' => 'Please verify your email',
        'user' => $userResponseData,
        'requires_email' => true,
      ], 200);
    }

    // ✅ FIX: Create token for the EXACT user using id
    $userModelInstance = User::on('users_main')
      ->where('id', $userRecord->id)
      ->first();

    if (!$userModelInstance) {
      return response()->json(['message' => 'User not found'], 401);
    }

    $userModelInstance->tokens()->delete();
    $authToken = $userModelInstance->createToken('auth_token')->plainTextToken;

    $currentTimestamp = Carbon::now();
    DB::connection('users_main')
      ->table('users')
      ->where('id', $userRecord->id)
      ->update([
        'last_successfull_login' => $currentTimestamp,
        'updated_at' => DB::raw('updated_at')
      ]);

    return response()->json([
      'success' => true,
      'redirect_to' => '/',
      'school_code' => $detectedSchoolCode,
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

    try {
      $usersMainConnection = DatabaseManager::connect('users_main');
    } catch (\Exception $connectionError) {
      return response()->json([
        'success' => false,
        'message' => 'Database connection error.',
      ], 500);
    }

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

    if (Carbon::now()->gt($userToUpdate->first_user_token_expiry_at)) {
      DatabaseManager::disconnect('users_main');
      return response()->json([
        'success' => false,
        'message' => 'Session has expired. Please login again.',
      ], 410);
    }

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

    if ($request->password) {
      $updatePayload['password'] = Hash::make($request->password);
      $updatePayload['password_update_by'] = 1;
    }

    if (!$userToUpdate->created_at) {
      $updatePayload['created_at'] = Carbon::now();
    }

    if (!$userToUpdate->email_verified_at) {
      $updatePayload['email_verified_at'] = Carbon::now();
    }

    // ✅ FIXED: Update with BOTH user_id AND school_code
    $usersMainConnection
      ->table('users')
      ->where('user_id', $userToUpdate->user_id)
      ->where('school_code', $userToUpdate->school_code)
      ->update($updatePayload);

    $updatedUserModel = User::on('users_main')->find($userToUpdate->id);

    if (!$updatedUserModel) {
      DatabaseManager::disconnect('users_main');
      return response()->json([
        'success' => false,
        'message' => 'User not found after update.'
      ], 500);
    }

    $newAuthToken = $updatedUserModel->createToken('auth_token')->plainTextToken;

    DatabaseManager::disconnect('users_main');

    return response()->json([
      'success' => true,
      'message' => 'User info updated successfully.',
      'token' => $newAuthToken,
    ], 200);
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