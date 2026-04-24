<?php

namespace App\Http\Controllers\api\student_records;

use App\Http\Controllers\Controller;
use App\Helpers\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StudentProfileController extends Controller
{
  public function getStudentProfile(Request $request)
  {
    try {
      $user = Auth::user();

      if (!$user) {
        return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
      }

      $userId = $user->user_id;
      // ✅ Get school_code from authenticated user and convert to uppercase
      $schoolCode = strtoupper($user->school_code ?? '');

      if (!$schoolCode) {
        return response()->json(['success' => false, 'message' => 'School code not found'], 400);
      }

      // Get user data from users_main
      $userData = DB::connection('users_main')
        ->table('users')
        ->where('user_id', $userId)
        ->where('school_code', $schoolCode)
        ->first();

      if (!$userData) {
        return response()->json([
          'success' => false,
          'message' => 'User not found for this school.',
        ], 404);
      }

      // Connect to school database
      $databaseName = DatabaseManager::generateDatabaseName($schoolCode);
      $schoolDb = DatabaseManager::connect($databaseName);

      // Query student_records
      $student = $schoolDb
        ->table('student_records')
        ->where('user_id', $userId)
        ->where('school_code', $schoolCode)
        ->first();

      DatabaseManager::disconnect($databaseName);

      $profileImage = !empty($student->profile_img) ? '/storage/' . $student->profile_img : '/storage/profile-img/user-avatar.jpg';

      $profileData = [
        'student_id' => $student->student_id ?? '',
        'fullname' => $student->fullname ?? $userData->fullname ?? $userData->username ?? '',
        'nickname' => $student->nickname ?? $userData->nickname ?? '',
        'foreign_name' => $student->foreign_name ?? '',
        'gender' => $student->gender ?? '',
        'course' => $student->course ?? '',
        'level' => $student->level ?? '',
        'section' => $student->section ?? '',
        'email' => $student->email ?? $userData->email ?? '',
        'mobile_number' => $student->mobile_number ?? '',
        'lrn' => $student->lrn ?? '',
        'profile_img' => $profileImage,
        'account_status' => $userData->account_status ?? '',
        'school_code' => $schoolCode,
        'school_name' => $student->school_name ?? '',
        'gs_access_status' => $userData->gs_access_status ?? '',
        'school_level' => $student->school_level ?? '',
      ];

      return response()->json(['success' => true, 'data' => $profileData]);

    } catch (\Exception $e) {
      return response()->json([
        'success' => false,
        'message' => 'Failed to fetch student profile: ' . $e->getMessage()
      ], 500);
    }
  }
}