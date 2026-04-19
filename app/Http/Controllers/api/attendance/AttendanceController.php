<?php

namespace App\Http\Controllers\api\attendance;

use App\Http\Controllers\Controller;
use App\Helpers\DatabaseManager;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    protected string $usersConnectionName = 'users_main'; // KEEP THIS
    
    // -------------------- Get Attendance Data --------------------
    public function attendance(Request $request)
    {
        try {
            // Get user_id from authenticated user
            $userId = auth()->user()->user_id;
            
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            // DYNAMIC CONNECTION: DatabaseManager auto-detects school
            $schoolDb = DatabaseManager::connect();
            $query = $schoolDb
                ->table('attendance_records as a')
                ->join('student_records as s', 'a.user_id', '=', 's.user_id')
                ->select(
                    'a.*',
                    'a.full_name as student_name',
                    's.nickname as student_nickname'
                )
                ->where('a.user_id', $userId);

            // Filter by dates
            if ($request->has('startDate') && $request->startDate) {
                $query->where('a.date', '>=', $request->startDate);
            }
            if ($request->has('endDate') && $request->endDate) {
                $query->where('a.date', '<=', $request->endDate);
            }

            // Order by id DESC
            $data = $query
                    ->orderBy('a.id', 'desc')
                    ->orderBy('a.created_at', 'desc')
                    ->get();

            // Disconnect
            DatabaseManager::disconnect();

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch attendance data: '.$e->getMessage()
            ], 500);
        }
    }

    // -------------------- Get Distinct Users from Attendance Records --------------------
    public function getAttendanceUsers(Request $request)
    {
        try {
            // Get authenticated user
            $authUser = auth()->user();
            
            if (!$authUser) {
                return response()->json(['success' => false, 'message' => 'User not authenticated'], 401);
            }

            $currentUserId = $authUser->user_id;

            // DYNAMIC CONNECTION: DatabaseManager auto-detects school
            $schoolDb = DatabaseManager::connect();
            $query = $schoolDb
                ->table('attendance_records as ar')
                ->join('student_records as s', 'ar.user_id', '=', 's.user_id')
                ->select(
                    'ar.user_id',
                    's.fullname as username',
                    's.fullname as account_owner',
                    'ar.full_name as person_name',
                    \Illuminate\Support\Facades\DB::raw('COUNT(*) as times_recorded'),
                    \Illuminate\Support\Facades\DB::raw('MIN(ar.date) as first_date'),
                    \Illuminate\Support\Facades\DB::raw('MAX(ar.date) as last_date'),
                    \Illuminate\Support\Facades\DB::raw("GROUP_CONCAT(DISTINCT DATE(ar.date) ORDER BY ar.date SEPARATOR ', ') as dates_list")
                )
                ->where('ar.user_id', $currentUserId)
                ->groupBy('ar.user_id', 's.fullname', 'ar.full_name')
                ->orderBy('s.fullname', 'ASC');

            // Execute the query
            $attendanceDetails = $query->get();

            // Disconnect
            DatabaseManager::disconnect();

            if ($attendanceDetails->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }

            // Format response to match frontend EXACTLY
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
                'message' => 'Failed to fetch attendance users: '.$e->getMessage()
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

    // -------------------- Helper (Same structure as MessagesController) --------------------
    protected function markAsRead($recordId = null)
    {
        try {
            // Get user_id from auth
            $userId = auth()->user()->user_id;

            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => "user_id is required"
                ], 400);
            }

            // DYNAMIC CONNECTION: DatabaseManager auto-detects school
            $schoolDb = DatabaseManager::connect();
            $query = $schoolDb
                ->table('attendance_records')
                ->where('user_id', $userId);

            if ($recordId) {
                // Mark only this user's specific record
                $updated = $query
                    ->where('id', $recordId)
                    ->update([
                        'status' => 'read',
                        'updated_at' => now()
                    ]);

                // Disconnect
                DatabaseManager::disconnect();

                return response()->json([
                    'success' => (bool)$updated,
                    'message' => $updated
                        ? 'Attendance record marked as read'
                        : 'Record not found or not owned by user'
                ]);
            } 
            else {
                // Mark ALL unread records for THIS USER only
                $updated = $query
                    ->where('status', 'unread')
                    ->update([
                        'status' => 'read',
                        'updated_at' => now()
                    ]);

                // Disconnect
                DatabaseManager::disconnect();

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