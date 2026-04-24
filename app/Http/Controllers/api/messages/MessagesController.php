<?php

namespace App\Http\Controllers\api\messages;

use App\Http\Controllers\Controller;
use App\Helpers\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessagesController extends Controller
{
  protected string $usersConnectionName = 'users_main';

  // -------------------- Get Message Data --------------------
  public function getMessagesData(Request $request)
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

      // Connect to correct school database
      $databaseName = DatabaseManager::generateDatabaseName($schoolCode);
      $schoolDb = DatabaseManager::connect($databaseName);

      $query = $schoolDb->table('messages')
        ->where('user_id', $userId)
        ->where('school_code', $schoolCode);  // ✅ ADD school_code filter

      // Filter by date if needed
      if ($request->has('startDate') && $request->startDate) {
        $query->where('date', '>=', $request->startDate);
      }
      if ($request->has('endDate') && $request->endDate) {
        $query->where('date', '<=', $request->endDate);
      }

      $data = $query
        ->orderByRaw("
            CASE 
                WHEN LOWER(status) = 'unread' THEN 0 
                ELSE 1 
            END,
            created_at DESC,
            id DESC
        ")
        ->get();

      DatabaseManager::disconnect($databaseName);

      return response()->json([
        'success' => true,
        'data' => $data
      ], 200, ['Content-Type' => 'application/json; charset=utf-8']);

    } catch (\Exception $e) {
      return response()->json([
        'success' => false,
        'message' => 'Failed to fetch messages: ' . $e->getMessage()
      ], 500);
    }
  }

  // -------------------- Get Distinct Users from Messages --------------------
  public function getMessagesUsers(Request $request)
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
        ->table('messages as m')
        ->select(
          'm.user_id',
          'm.full_name as fullname',
          DB::raw('COUNT(*) as message_count')
        )
        ->where('m.user_id', $currentUserId)
        ->where('m.school_code', $schoolCode)  // ✅ ADD school_code filter
        ->whereNotNull('m.full_name')
        ->groupBy('m.user_id', 'm.full_name')
        ->orderBy('m.full_name', 'ASC');

      $messageDetails = $query->get();

      DatabaseManager::disconnect($databaseName);

      if ($messageDetails->isEmpty()) {
        return response()->json([
          'success' => true,
          'data' => []
        ]);
      }

      $result = [];
      foreach ($messageDetails as $record) {
        $result[] = [
          'user_id' => $record->user_id,
          'fullname' => $record->fullname,
          'message_count' => $record->message_count
        ];
      }

      return response()->json([
        'success' => true,
        'data' => $result
      ]);

    } catch (\Exception $e) {
      return response()->json([
        'success' => false,
        'message' => 'Failed to fetch message users: ' . $e->getMessage()
      ], 500);
    }
  }

  // -------------------- Get Unread Count --------------------
  public function getUnreadCount(Request $request)
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

      $databaseName = DatabaseManager::generateDatabaseName($schoolCode);
      $schoolDb = DatabaseManager::connect($databaseName);

      $unreadCount = $schoolDb->table('messages')
        ->where('status', 'unread')
        ->where('user_id', $userId)
        ->where('school_code', $schoolCode)  // ✅ ADD school_code filter
        ->count();

      DatabaseManager::disconnect($databaseName);

      return response()->json([
        'success' => true,
        'data' => [
          'unread_count' => $unreadCount
        ]
      ]);
    } catch (\Exception $e) {
      return response()->json([
        'success' => false,
        'message' => 'Failed to fetch unread count: ' . $e->getMessage()
      ], 500);
    }
  }

  // -------------------- Mark Message as Read --------------------
  public function markMessageAsRead($recordId)
  {
    return $this->markAsRead($recordId);
  }

  public function markAllMessagesAsRead()
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
        ->table('messages')
        ->where('user_id', $userId)
        ->where('school_code', $schoolCode);  // ✅ ADD school_code filter

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
          'message' => $updated ? 'Message marked as read' : 'Message not found or not owned by user'
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
          'message' => "$updated messages marked as read for user $userId"
        ]);
      }

    } catch (\Exception $e) {
      return response()->json([
        'success' => false,
        'message' => "Failed to mark message(s) as read: " . $e->getMessage()
      ], 500);
    }
  }
}