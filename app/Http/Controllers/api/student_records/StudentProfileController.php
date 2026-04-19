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
            
            // DYNAMIC CONNECTION: DatabaseManager auto-detects school
            $schoolDb = DatabaseManager::connect();
            $student = $schoolDb
                ->table('student_records')
                ->where('user_id', $userId)
                ->first();
            
            // Disconnect
            DatabaseManager::disconnect();

            if (!$student) {
                return response()->json(['success' => false, 'message' => 'Student record not found'], 404);
            }

            // This stays the same - users_main is still static
            $userData = DB::connection('users_main')
                ->table('users')
                ->where('user_id', $userId)
                ->select('email', 'account_status', 'fullname', 'school_code', 'gs_access_status')
                ->first();

            // TEMPORARY: Hardcode for testing
            // $profileImage = '/storage/profile-img/user-avatar.jpg';
            
            // OR use database value if exists
            $profileImage = !empty($student->profile_img) ? '/storage/' . $student->profile_img : '/storage/profile-img/user-avatar.jpg';

            $profileData = [
                'student_id' => $student->student_id ?? '',
                'fullname' => $student->fullname ?? ($userData->fullname ?? ''),
                'nickname' => $student->nickname ?? '',
                'foreign_name' => $student->foreign_name ?? '',
                'gender' => $student->gender ?? '',
                'course' => $student->course ?? '',
                'level' => $student->level ?? '',
                'section' => $student->section ?? '',
                'email' => $student->email ?? ($userData->email ?? ''),
                'mobile_number' => $student->mobile_number ?? '',
                'lrn' => $student->lrn ?? '',
                'profile_img' => $profileImage,
                'account_status' => $userData->account_status ?? '',
                'school_code' => $userData->school_code ?? '',
                'school_name' => $student->school_name ?? '', // ADD THIS LINE
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