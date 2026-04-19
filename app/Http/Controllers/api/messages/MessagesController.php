<?php

namespace App\Http\Controllers\api\messages;

use App\Http\Controllers\Controller;
use App\Helpers\DatabaseManager;
use Illuminate\Http\Request;

class MessagesController extends Controller
{
    protected string $usersConnectionName = 'users_main';
    
    // -------------------- Get Message Data --------------------
    public function getMessagesData(Request $request)
    {
        try {
            // DYNAMIC CONNECTION: DatabaseManager auto-detects school
            $schoolDb = DatabaseManager::connect();
            $query = $schoolDb->table('messages');

            // Get user_id either from request or authenticated user
            $userId = $request->get('user_id') ?? auth()->user()->user_id ?? null;
            if ($userId) {
                $query->where('user_id', $userId);
            }

            // Filter by date if needed
            if ($request->has('startDate') && $request->startDate) {
                $query->where('date', '>=', $request->startDate);
            }
            if ($request->has('endDate') && $request->endDate) {
                $query->where('date', '<=', $request->endDate);
            }

            // ABSOLUTE FIX: Use raw SQL with proper ordering
            // 1. Unread first (case-insensitive)
            // 2. Newest created_at first
            // 3. Newest id as tie-breaker
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

            // Disconnect
            DatabaseManager::disconnect();

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
            // Get authenticated user
            $authUser = auth()->user();
            
            if (!$authUser) {
                return response()->json(['success' => false, 'message' => 'User not authenticated'], 401);
            }

            $currentUserId = $authUser->user_id;

            // DYNAMIC CONNECTION: DatabaseManager auto-detects school
            $schoolDb = DatabaseManager::connect();
            $query = $schoolDb
                ->table('messages as m')
                ->select(
                    'm.user_id',
                    'm.full_name as fullname',
                    \Illuminate\Support\Facades\DB::raw('COUNT(*) as message_count')
                )
                ->where('m.user_id', $currentUserId)
                ->whereNotNull('m.full_name')
                ->groupBy('m.user_id', 'm.full_name')
                ->orderBy('m.full_name', 'ASC');

            // Execute the query
            $messageDetails = $query->get();

            // Disconnect
            DatabaseManager::disconnect();

            if ($messageDetails->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }

            // Format response to match frontend EXACTLY like attendance
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
                'message' => 'Failed to fetch message users: '.$e->getMessage()
            ], 500);
        }
    }

    // -------------------- Get Unread Count --------------------
    public function getUnreadCount(Request $request)
    {
        try {
            // DYNAMIC CONNECTION: DatabaseManager auto-detects school
            $schoolDb = DatabaseManager::connect();
            $query = $schoolDb->table('messages')
                ->where('status', 'unread');

            // Get user_id either from request or authenticated user
            $userId = $request->get('user_id') ?? auth()->user()->user_id ?? null;
            
            // Make sure we filter by user_id if it exists
            if ($userId) {
                $query->where('user_id', $userId);
            } else {
                // If no user_id is provided, return 0 to avoid counting all messages
                // Disconnect
                DatabaseManager::disconnect();
                
                return response()->json([
                    'success' => true,
                    'data' => [
                        'unread_count' => 0
                    ]
                ]);
            }

            $unreadCount = $query->count();

            // Disconnect
            DatabaseManager::disconnect();

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

    // -------------------- Helper --------------------
    protected function markAsRead($recordId = null)
    {
        try {
            // Get user_id either from request or authenticated user
            $userId = request()->get('user_id') ?? auth()->user()->user_id ?? null;

            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => "user_id is required"
                ], 400);
            }

            // DYNAMIC CONNECTION: DatabaseManager auto-detects school
            $schoolDb = DatabaseManager::connect();
            
            // Start base query with user_id filter
            $query = $schoolDb
                ->table('messages')
                ->where('user_id', $userId);   // ← THIS FIXES THE ISSUE

            if ($recordId) {
                // Mark ONLY this user's specific message
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
                    'message' => $updated ? 'Message marked as read' : 'Message not found or not owned by user'
                ]);
            } 
            else {
                // Mark ALL messages for THIS USER only
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