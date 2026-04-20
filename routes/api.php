<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Controllers
use App\Http\Controllers\api\auth\AuthenticationController;
use App\Http\Controllers\api\trademarks\TrademarksController;
use App\Http\Controllers\api\auth\EmailController;
use App\Http\Controllers\api\auth\PasswordController;
use App\Http\Controllers\api\student_records\StudentProfileController;
use App\Http\Controllers\api\attendance\AttendanceController;
use App\Http\Controllers\api\messages\MessagesController;
use App\Http\Controllers\api\auth\OtpController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ----------------------------
// PUBLIC ROUTES
// ----------------------------

// Login
Route::post('/login', [AuthenticationController::class, 'login']);
Route::post('/auto-destroy-session', [AuthenticationController::class, 'autoDestroySession']);

// First user setup
Route::post('/update-first-user', [AuthenticationController::class, 'updateFirstUser']);
Route::post('/validate-first-user-token', [AuthenticationController::class, 'validateFirstUserToken']); // NEW

// OTP verify & resend
Route::post('/verify-otp', [OtpController::class, 'verifyOtp']);
Route::post('/resend-otp', [OtpController::class, 'resendOtp']);
Route::get('/otp-session', [OtpController::class, 'otpSession']);
Route::post('/send-otp-first-user', [OtpController::class, 'sendOtpFirstUser']);
Route::get('/get-user-email', [AuthenticationController::class, 'getUserEmailByUsername']);

// Reset password (LEVEL 1 & LEVEL 2)
Route::post('/reset-password-request-lvl-one', [EmailController::class, 'resetPasswordRequestLvlOne']);
Route::post('/reset-password-request-lvl-two', [EmailController::class, 'resetPasswordRequestLvlTwo']);

// Updating Password
Route::post('/reset-password-update', [PasswordController::class, 'resetPasswordUpdate']);
Route::get('/validate-reset-link', [PasswordController::class, 'validateResetLink']);

Route::post('/check-account-status', [AuthenticationController::class, 'checkAccountStatus']);

Route::post('/trademarks', [TrademarksController::class, 'getTrademarksInfo']);

// ----------------------------
// PROTECTED ROUTES
// ----------------------------
Route::middleware('auth:sanctum')->group(function () {

  // Get user - CHANGED to use controller method
  Route::get('/user', [AuthenticationController::class, 'getCurrentUser']);

  // Update email
  Route::post('/update-email', [EmailController::class, 'updateEmail']);

  // Update password (Authenticated users)
  Route::post('/update-password', [PasswordController::class, 'updatePassword']);

  // Student Profile
  Route::get('/student-profile', [StudentProfileController::class, 'getStudentProfile']);

  // Logout
  Route::post('/logout', [AuthenticationController::class, 'logout']);

  // Attendance Routes
  Route::prefix('attendance')->group(function () {
    Route::get('/', [AttendanceController::class, 'attendance']);
    Route::get('/students', [AttendanceController::class, 'getAttendanceUsers']);
    Route::patch('/{recordId}/read', [AttendanceController::class, 'markAttendanceAsRead']);
    Route::patch('/read-all', [AttendanceController::class, 'markAllAttendanceAsRead']);
  });

  // Messages Routes
  Route::prefix('messages')->group(function () {
    Route::get('/', [MessagesController::class, 'getMessagesData']);
    Route::get('/students', [MessagesController::class, 'getMessagesUsers']);
    Route::get('/unread-count', [MessagesController::class, 'getUnreadCount']);
    Route::put('/{recordId}/read', [MessagesController::class, 'markMessageAsRead']);
    Route::put('/read-all', [MessagesController::class, 'markAllMessagesAsRead']);
  });
});