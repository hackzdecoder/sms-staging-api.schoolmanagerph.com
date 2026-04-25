<?php

namespace App\Http\Controllers\api\attendance;

use App\Http\Controllers\Controller;
use App\Helpers\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
  protected string $usersConnectionName = 'users_main';

  // -------------------- Get Attendance Data --------------------
  public function attendance(Request $request)
  {
    try {
      $authUser = auth()->user();

      if (!$authUser) {
        return response()->json([
          'success' => false,
          'message' => 'User not authenticated'
        ], 401);
      }

      $userId = $authUser->user_id;
      $schoolCode = $authUser->school_code;

      if (!$schoolCode) {
        return response()->json([
          'success' => false,
          'message' => 'School code not found'
        ], 400);
      }

      // ✅ Connect to correct school database
      $databaseName = DatabaseManager::generateDatabaseName($schoolCode);
      $schoolDb = DatabaseManager::connect($databaseName);

      $query = $schoolDb
        ->table('attendance_records as a')
        ->join('student_records as s', 'a.user_id', '=', 's.user_id')
        ->select(
          'a.*',
          'a.full_name as student_name',
          's.nickname as student_nickname'
        )
        ->where('a.user_id', $userId)
        ->where('a.school_code', $schoolCode);  // ✅ ADD school_code

      // Filter by dates
      if ($request->has('startDate') && $request->startDate) {
        $query->where('a.date', '>=', $request->startDate);
      }
      if ($request->has('endDate') && $request->endDate) {
        $query->where('a.date', '<=', $request->endDate);
      }

      $data = $query
        ->orderBy('a.id', 'desc')
        ->orderBy('a.created_at', 'desc')
        ->get();

      DatabaseManager::disconnect($databaseName);

      return response()->json([
        'success' => true,
        'data' => $data
      ]);

    } catch (\Exception $e) {
      return response()->json([
        'success' => false,
        'message' => 'Failed to fetch attendance data: ' . $e->getMessage()
      ], 500);
    }
  }

  // -------------------- Get Distinct Users from Attendance Records --------------------
  public function getAttendanceUsers(Request $request)
  {
    try {
      $authUser = auth()->user();

      if (!$authUser) {
        return response()->json(['success' => false, 'message' => 'User not authenticated'], 401);
      }

      $currentUserId = $authUser->user_id;
      $schoolCode = $authUser->school_code;

      if (!$schoolCode) {
        return response()->json([
          'success' => false,
          'message' => 'School code not found'
        ], 400);
      }

      $databaseName = DatabaseManager::generateDatabaseName($schoolCode);
      $schoolDb = DatabaseManager::connect($databaseName);

      $query = $schoolDb
        ->table('attendance_records as ar')
        ->join('student_records as s', 'ar.user_id', '=', 's.user_id')
        ->select(
          'ar.user_id',
          's.fullname as username',
          's.fullname as account_owner',
          'ar.full_name as person_name',
          DB::raw('COUNT(*) as times_recorded'),
          DB::raw('MIN(ar.date) as first_date'),
          DB::raw('MAX(ar.date) as last_date'),
          DB::raw("GROUP_CONCAT(DISTINCT DATE(ar.date) ORDER BY ar.date SEPARATOR ', ') as dates_list")
        )
        ->where('ar.user_id', $currentUserId)
        ->where('ar.school_code', $schoolCode)  // ✅ ADD school_code
        ->groupBy('ar.user_id', 's.fullname', 'ar.full_name')
        ->orderBy('s.fullname', 'ASC');

      $attendanceDetails = $query->get();

      DatabaseManager::disconnect($databaseName);

      if ($attendanceDetails->isEmpty()) {
        return response()->json([
          'success' => true,
          'data' => []
        ]);
      }

      $result = [];
      foreach ($attendanceDetails as $record) {
        $result[] = [
          'user_id' => $record->user_id,
          'username' => $record->username,
          'fullname' => $record->person_name,
          'attendance_count' => $record->times_recorded,
          'account_owner' => $record->account_owner,
          'first_date' => $record->first_date,
          'last_date' => $record->last_date,
          'dates_list' => $record->dates_list
        ];
      }

      return response()->json([
        'success' => true,
        'data' => $result
      ]);

    } catch (\Exception $e) {
      return response()->json([
        'success' => false,
        'message' => 'Failed to fetch attendance users: ' . $e->getMessage()
      ], 500);
    }
  }

  // -------------------- Mark Attendance as Read (Single) --------------------
  public function markAttendanceAsRead($recordId)
  {
    return $this->markAsRead($recordId);
  }

  // -------------------- Mark All Attendance as Read --------------------
  public function markAllAttendanceAsRead()
  {
    return $this->markAsRead();
  }

  // -------------------- Helper with school_code filter --------------------
  protected function markAsRead($recordId = null)
  {
    try {
      $authUser = auth()->user();

      if (!$authUser) {
        return response()->json([
          'success' => false,
          'message' => "User not authenticated"
        ], 401);
      }

      $userId = $authUser->user_id;
      $schoolCode = $authUser->school_code;

      if (!$schoolCode) {
        return response()->json([
          'success' => false,
          'message' => "School code not found"
        ], 400);
      }

      $databaseName = DatabaseManager::generateDatabaseName($schoolCode);
      $schoolDb = DatabaseManager::connect($databaseName);

      $query = $schoolDb
        ->table('attendance_records')
        ->where('user_id', $userId)
        ->where('school_code', $schoolCode);  // ✅ ADD school_code

      if ($recordId) {
        $updated = $query
          ->where('id', $recordId)
          ->update([
            'status' => 'read',
            'updated_at' => now()
          ]);

        DatabaseManager::disconnect($databaseName);

        return response()->json([
          'success' => (bool) $updated,
          'message' => $updated
            ? 'Attendance record marked as read'
            : 'Record not found or not owned by user'
        ]);
      } else {
        $updated = $query
          ->where('status', 'unread')
          ->update([
            'status' => 'read',
            'updated_at' => now()
          ]);

        DatabaseManager::disconnect($databaseName);

        return response()->json([
          'success' => true,
          'message' => "$updated attendance records marked as read"
        ]);
      }

    } catch (\Exception $e) {
      return response()->json([
        'success' => false,
        'message' => "Failed to mark attendance as read: " . $e->getMessage()
      ], 500);
    }
  }
}