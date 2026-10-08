<?php

namespace App\Http\Controllers\API\Patients;

use App\Http\Controllers\Controller;
use App\Http\Helpers\Helper;
use App\Mail\ConversationNotification;
use App\Models\PatientHistory;
use App\Models\PatientHistoryConversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator; // Ensure you have the User model imported
use Illuminate\Support\Str;

/**
 * @OA\Tag(
 *     name="Patient History Conversations",
 *     description="API Endpoints for managing patient history conversations (chat/messaging)"
 * )
 */
class PatientHistoryConversationController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Display conversations for a specific patient history (Query Parameter Entry)
     * Used when hitting: patient-history-conversations?patient_history_id=8
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $patientHistoryId = $request->input('patient_history_id');

        if (! $patientHistoryId) {
            return response()->json(['statusCode' => 422, 'message' => 'patient_history_id is required'], 422);
        }

        // Fetch all root conversations where user is sender OR receiver
        $conversations = PatientHistoryConversation::with([
            'sender:id,first_name,last_name',
            'receiver:id,first_name,last_name',
            'statuses',
            'children.sender:id,first_name,last_name',
            'children.receiver:id,first_name,last_name',
            'children.statuses',
        ])
            ->where('patient_history_id', $patientHistoryId)
            ->whereNull('parent_id')
            ->where(function ($query) use ($user) {
                $query->where('sender_id', $user->id)
                    ->orWhere('receiver_id', $user->id);
            })
            ->latest()
            ->get();

        if ($conversations->isEmpty()) {
            return response()->json([
                'statusCode' => 200,
                'data' => [],
                'message' => 'No conversations found.',
            ], 200);
        }

        // Pull patient profile metadata
        $historyContext = PatientHistory::with(['patient'])->find($patientHistoryId);

        $data = $conversations->map(function ($convo) use ($patientHistoryId, $historyContext) {
            return [
                'patient_history_id' => (int) $patientHistoryId,
                'conversation_id' => $convo->conversation_id,
                'user_id' => $convo->sender->id,
                'sender_full_name' => $convo->sender->first_name.' '.$convo->sender->last_name,
                'receiver_id' => $convo->receiver_id,
                'receiver_full_name' => $convo->receiver
                    ? trim($convo->receiver->first_name.' '.$convo->receiver->last_name)
                    : null,
                'message' => $convo->message,
                'date' => $convo->created_at->diffForHumans(),
                ...$this->messageStatus($convo),

                // --- METADATA INJECTION ---
                'patient_name' => $historyContext && $historyContext->patient ? $historyContext->patient->name : 'Unknown Patient',
                'file_number' => $historyContext && $historyContext->file_number ? $historyContext->file_number : 'N/A',
                'diagnosis' => $historyContext && $historyContext->history_of_presenting_illness
                                        ? Str::limit($historyContext->history_of_presenting_illness, 80)
                                        : 'General Consultation',

                'replies' => $convo->children->map(function ($reply) {
                    return [
                        'conversation_id' => $reply->conversation_id,
                        'user_id' => $reply->sender_id,
                        'sender_full_name' => $reply->sender->first_name.' '.$reply->sender->last_name,
                        'receiver_id' => $reply->receiver_id,
                        'receiver_full_name' => $reply->receiver
                            ? trim($reply->receiver->first_name.' '.$reply->receiver->last_name)
                            : null,
                        'message' => $reply->message,
                        'date' => $reply->created_at->diffForHumans(),
                        ...$this->messageStatus($reply),
                    ];
                }),
            ];
        });

        return response()->json(['statusCode' => 200, 'data' => $data], 200);
    }

    /**
     * Display specific conversation message (Route Parameter Entry)
     * Used when hitting: patient-history-conversations/{id}/individual-chat
     */
    public function show(Request $request, $patientHistoryId)
    {
        $user = auth()->user();

        // Removed the restrictive receiver_id restriction so users can see threads they initiated
        $conversations = PatientHistoryConversation::where('patient_history_id', $patientHistoryId)
            ->whereNull('parent_id')
            ->where(function ($query) use ($user) {
                $query->where('sender_id', $user->id)
                    ->orWhere('receiver_id', $user->id);
            })
            ->with([
                'sender:id,first_name,last_name',
                'children' => function ($query) {
                    $query->with([
                        'sender:id,first_name,last_name',
                        'receiver:id,first_name,last_name',
                        'statuses',
                    ])->oldest();
                },
                'receiver:id,first_name,last_name',
                'statuses',
            ])
            ->latest()
            ->get();

        if ($conversations->isEmpty()) {
            return response()->json([
                'statusCode' => 200,
                'data' => [],
                'message' => 'No conversations found.',
            ], 200);
        }

        $historyContext = PatientHistory::with(['patient'])->find($patientHistoryId);

        $data = $conversations->map(function ($convo) use ($patientHistoryId, $historyContext) {
            return [
                'patient_history_id' => (int) $patientHistoryId,
                'conversation_id' => $convo->conversation_id,
                'user_id' => $convo->sender->id,
                'sender_full_name' => $convo->sender->first_name.' '.$convo->sender->last_name,
                'receiver_id' => $convo->receiver_id,
                'receiver_full_name' => $convo->receiver
                    ? trim($convo->receiver->first_name.' '.$convo->receiver->last_name)
                    : null,
                'message' => $convo->message,
                'date' => $convo->created_at->diffForHumans(),
                ...$this->messageStatus($convo),

                // --- METADATA INJECTION ---
                'patient_name' => $historyContext && $historyContext->patient ? $historyContext->patient->name : 'Unknown Patient',
                'file_number' => $historyContext && $historyContext->file_number ? $historyContext->file_number : 'N/A',
                'diagnosis' => $historyContext && $historyContext->history_of_presenting_illness
                                        ? Str::limit($historyContext->history_of_presenting_illness, 80)
                                        : 'General Consultation',

                'replies' => $convo->children->map(function ($reply) {
                    return [
                        'conversation_id' => $reply->conversation_id,
                        'user_id' => $reply->sender_id,
                        'sender_full_name' => $reply->sender->first_name.' '.$reply->sender->last_name,
                        'receiver_id' => $reply->receiver_id,
                        'receiver_full_name' => $reply->receiver
                            ? trim($reply->receiver->first_name.' '.$reply->receiver->last_name)
                            : null,
                        'message' => $reply->message,
                        'date' => $reply->created_at->diffForHumans(),
                        ...$this->messageStatus($reply),
                    ];
                }),
            ];
        });

        return response()->json(['statusCode' => 200, 'data' => $data], 200);
    }

    /**
     * Store a new conversation message.
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'patient_history_id' => 'required|exists:patient_histories,patient_histories_id',
            'message' => 'required|string|max:65535',
            // Receiver is ONLY required if we are NOT replying (no parent_id)
            'receiver' => 'required_without:parent_id|nullable|in:mkurugenzi,board,hospital,dg',
            'parent_id' => 'nullable|exists:patient_history_conversations,conversation_id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        $parent = null;
        if (! empty($validated['parent_id'])) {
            $parent = PatientHistoryConversation::query()
                ->where('conversation_id', $validated['parent_id'])
                ->where('patient_history_id', $validated['patient_history_id'])
                ->where(function ($query) use ($user) {
                    $query->where('sender_id', $user->id)
                        ->orWhere('receiver_id', $user->id);
                })
                ->first();

            if (! $parent) {
                return response()->json([
                    'statusCode' => 403,
                    'message' => 'You are not allowed to reply to this conversation.',
                ], 403);
            }
        }

        try {
            DB::beginTransaction();

            $resolvedReceiverId = null;

            // CASE 1: This is a REPLY
            if ($parent) {
                // Logic: If I am the original sender, send to the original receiver.
                // If I am the original receiver, send back to the original sender.
                $resolvedReceiverId = ($parent->sender_id === $user->id)
                    ? $parent->receiver_id
                    : $parent->sender_id;

                $receiverName = 'Reply';
            }
            // CASE 2: This is a NEW MESSAGE (Initiating)
            else {
                $patientHistory = PatientHistory::findOrFail($validated['patient_history_id']);
                $patient = $patientHistory->patient;

                $patientListRelation = DB::table('patient_list_patient')
                    ->where('patient_id', $patient->patient_id)
                    ->first();

                $patientList = $patientListRelation
                    ? DB::table('patient_lists')->where('patient_list_id', $patientListRelation->patient_list_id)->first()
                    : null;

                switch ($validated['receiver']) {
                    case 'dg':
                        $resolvedReceiverId = $patientHistory->dg_id;
                        break;
                    case 'mkurugenzi':
                        $resolvedReceiverId = $patientHistory->mkurugenzi_tiba_id;
                        break;
                    case 'board':
                        $resolvedReceiverId = $patientList ? $patientList->created_by : null;
                        break;
                    case 'hospital':
                        $resolvedReceiverId = $patient->created_by;
                        break;
                }

                $receiverName = ucfirst($validated['receiver']);
            }

            if (! $resolvedReceiverId) {
                throw new \Exception('Could not resolve a User ID for the intended receiver.');
            }

            $conversation = PatientHistoryConversation::create([
                'patient_history_id' => $validated['patient_history_id'],
                'sender_id' => $user->id,
                'receiver_id' => $resolvedReceiverId,
                'parent_id' => $validated['parent_id'] ?? null,
                'message' => $validated['message'],
            ]);

            \DB::table('patient_history_conversation_statuses')->insert([
                'conversation_id' => $conversation->conversation_id,
                'user_id' => $resolvedReceiverId,
                'read_at' => null,
                'is_notified' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $recipient = User::find($resolvedReceiverId);

            if ($recipient && $recipient->email) {
                $senderDisplayName = trim($user->first_name.' '.$user->middle_name.' '.$user->last_name);

                // Tunapitisha $conversation, $senderDisplayName, na $recipient object
                Mail::to($recipient->email)->queue(
                    new ConversationNotification($conversation, $senderDisplayName, $recipient)
                );
            }

            DB::commit();

            return response()->json([
                'statusCode' => 201,
                'message_text' => $parent ? 'Reply sent successfully' : 'Message sent to '.$receiverName,
                'data' => [
                    'patient_history_id' => (int) $conversation->patient_history_id,
                    'conversation_id' => $conversation->conversation_id,
                    'user_id' => $user->id,
                    'sender_full_name' => $user->full_name,
                    'message' => $conversation->message,
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return Helper::serverError($e, 'Unable to send the message.');
        }
    }

    /**
     * Update conversation message.
     */
    public function update(Request $request, PatientHistoryConversation $patientHistoryConversation)
    {
        $user = auth()->user();
        if (! $user->can('Update Patient History')) {
            return response()->json([
                'status' => false,
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        // Only allow owner to edit
        if ($patientHistoryConversation->sender_id !== $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'Can only edit own messages',
                'statusCode' => 403,
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'sometimes|required|string|max:65535',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors(),
                'statusCode' => 422,
            ], 422);
        }

        $data = $validator->validated();

        try {

            $patientHistoryConversation->update($data);

            return response()->json([
                'status' => true,
                'data' => $patientHistoryConversation->fresh()->load(['sender', 'receiver']),
                'message' => 'Message updated successfully',
                'statusCode' => 200,
            ]);

        } catch (\Exception $e) {
            Log::error('Conversation update failed: '.$e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Update failed',
                'statusCode' => 500,
            ], 500);
        }
    }

    /**
     * Remove conversation message.
     */
    public function destroy(PatientHistoryConversation $patientHistoryConversation)
    {
        $user = auth()->user();
        if (! $user->can('Delete Patient History')) {
            return response()->json([
                'status' => false,
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        // Soft delete
        $patientHistoryConversation->delete();

        return response()->json([
            'status' => true,
            'message' => 'Message deleted successfully',
            'statusCode' => 200,
        ]);
    }

    /**
     * Get unread conversation counts and recent unread items for the logged-in user.
     */
    public function unreadNotifications()
    {
        $user = auth()->user();

        $unreadItems = DB::table('patient_history_conversation_statuses as s')
            ->join('patient_history_conversations as c', 's.conversation_id', '=', 'c.conversation_id')
            ->join('users as u', 'c.sender_id', '=', 'u.id')
            ->where('s.user_id', $user->id)
            ->whereNull('s.read_at')
            ->select(
                'c.conversation_id',
                'c.patient_history_id',
                'c.message',
                'u.first_name',
                'u.last_name',
                'c.created_at'
            )
            ->latest('c.created_at')
            ->get();

        return response()->json([
            'statusCode' => 200,
            'count' => $unreadItems->count(),
            'notifications' => $unreadItems,
        ], 200);
    }

    /**
     * Mark all conversations for a specific patient history as read for the logged-in user.
     */
    public function markAsRead(Request $request)
    {
        $user = auth()->user();
        $patientHistoryId = $request->input('patient_history_id');

        if (! $patientHistoryId) {
            return response()->json(['message' => 'patient_history_id is required'], 422);
        }

        // Update statuses where the conversation belongs to this patient history and user
        DB::table('patient_history_conversation_statuses as s')
            ->join('patient_history_conversations as c', 's.conversation_id', '=', 'c.conversation_id')
            ->where('s.user_id', $user->id)
            ->where('c.patient_history_id', $patientHistoryId)
            ->whereNull('s.read_at')
            ->update([
                's.read_at' => now(),
                's.updated_at' => now(),
            ]);

        return response()->json([
            'statusCode' => 200,
            'message' => 'Conversations marked as read',
        ], 200);
    }

    private function messageStatus(PatientHistoryConversation $conversation): array
    {
        $status = $conversation->statuses
            ->firstWhere('user_id', $conversation->receiver_id);

        return [
            'delivered' => $status !== null,
            'read_by_recipient' => $status?->read_at !== null,
        ];
    }
}
