<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use App\Notifications\RealTimeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CommunicationController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * 1. Get All Conversation Messages Between Logged-in User & Partner
     * GET /api/messages/{userId}
     */
   public function getMessages(Request $request, $userId): JsonResponse
{
    $targetUserId = (int) $userId;

    // Is user se jude saare messages: jisme yeh user ya toh sender hai YA receiver hai
    $messages = Message::with(['sender:id,name,email', 'receiver:id,name,email'])
        ->where('sender_id', $targetUserId)
        ->orWhere('receiver_id', $targetUserId)
        ->orderBy('created_at', 'asc')
        ->get();

    return response()->json([
        'success'  => true,
        'user_id'  => $targetUserId,
        'total'    => $messages->count(),
        'messages' => $messages,
        'data'     => $messages,
    ]);
}

    /**
     * 2. Send Message & Broadcast Instantly
     * POST /api/messages/send
     */
    public function sendMessage(Request $request): JsonResponse
{
    // Frontend ke payload ko normalize karein
    $receiverId = $request->input('receiver_id') ?? $request->input('user_id');
    $messageText = $request->input('message') ?? $request->input('body');

    $request->merge([
        'receiver_id' => $receiverId !== null ? (int)$receiverId : null,
        'message'     => $messageText,
    ]);

    $validated = $request->validate([
        'receiver_id' => 'required|integer|exists:users,id',
        'message'     => 'required|string|max:5000',
    ]);

    $senderId = (int) $request->user()->id;
    $targetReceiverId = (int) $validated['receiver_id'];

    // Self-message check
    if ($senderId === $targetReceiverId) {
        return response()->json([
            'success' => false,
            'message' => 'Sender and receiver cannot be the same user.',
            'debug'   => [
                'auth_sender_id' => $senderId,
                'target_receiver_id' => $targetReceiverId,
            ]
        ], 422);
    }

    $message = Message::create([
        'sender_id'   => $senderId,
        'receiver_id' => $targetReceiverId,
        'message'     => $validated['message'],
        'is_read'     => false,
    ]);

    $message->load(['sender:id,name,email', 'receiver:id,name,email']);

    // Live WebSocket broadcast
    broadcast(new MessageSent($message))->toOthers();

    return response()->json([
        'success' => true,
        'message' => $message,
        'data'    => $message,
    ], 201);
}
    /**
     * 3. Send Notification to a User
     * POST /api/notifications/send
     */
    public function sendNotification(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id'    => 'required|integer|exists:users,id',
            'title'      => 'required|string|max:255',
            'body'       => 'required|string|max:1000',
            'action_url' => 'nullable|string',
        ]);

        $targetUser = User::findOrFail($validated['user_id']);
        $targetUser->notify(new RealTimeNotification(
            $validated['title'],
            $validated['body'],
            $validated['action_url'] ?? null
        ));

        return response()->json([
            'success' => true,
            'message' => 'Notification sent successfully',
        ], 200);
    }

    /**
     * 4. Fetch User Notifications
     * GET /api/notifications
     */
    public function getNotifications(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success'       => true,
            'unread_count'  => $user->unreadNotifications()->count(),
            'notifications' => $user->notifications()->take(25)->get(),
        ], 200);
    }

    /**
     * 5. Mark All Notifications As Read
     * POST /api/notifications/mark-read
     */
    public function markNotificationsAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read',
        ], 200);
    }
}
