<?php

namespace App\Http\Controllers\API\ReferralLetters;

use App\Http\Controllers\Controller;
use App\Http\Helpers\Helper;
use App\Models\BoardedOutLetter;
use App\Models\PatientHistory;
use App\Models\Referral;
use App\Models\ReferralLetter;
use App\Services\PatientHistoryWorkflowService;
use App\Support\Pagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReferralLettersController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:View ReferralLetter|Create ReferralLetter|View ReferralLetter|Update ReferralLetter|Delete ReferralLetter', ['only' => ['index', 'store', 'show', 'update', 'destroy']]);
    }

    /**
     * Display a listing of the resource.
     */
    /**
     * @OA\Get(
     *     path="/api/referralLetters",
     *     summary="Get all referralLetters",
     *     tags={"referralLetters"},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *
     *         @OA\Header(
     *             header="Cache-Control",
     *             description="Cache control header",
     *
     *             @OA\Schema(type="string", example="no-cache, private")
     *         ),
     *
     *         @OA\Header(
     *             header="Content-Type",
     *             description="Content type header",
     *
     *             @OA\Schema(type="string", example="application/json; charset=UTF-8")
     *         ),
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="object",
     *
     *                     @OA\Property(property="referral_letter_id", type="integer"),
     *                     @OA\Property(property="referral_id", type="integer"),
     *                     @OA\Property(property="referral_letter_code", type="string"),
     *                     @OA\Property(property="letter_text", type="string"),
     *                     @OA\Property(property="is_printed", type="boolean"),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="deleted_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )
     *             ),
     *             @OA\Property(property="statusCode", type="integer", example=200)
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        if (! $user->can('View ReferralLetter')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $letters = ReferralLetter::withTrashed()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = mb_strtolower(trim((string) $request->input('search')));
                $query->where(function ($query) use ($term): void {
                    $query->whereRaw('LOWER(referral_letter_code) LIKE ?', [$term.'%'])
                        ->orWhereRaw('LOWER(letter_text) LIKE ?', [$term.'%']);
                });
            })
            ->when($request->filled('date_from'), function ($query) use ($request): void {
                $query->whereDate('created_at', '>=', $request->input('date_from'));
            })
            ->when($request->filled('date_to'), function ($query) use ($request): void {
                $query->whereDate('created_at', '<=', $request->input('date_to'));
            })
            ->latest('referral_letter_id')
            ->paginate(Pagination::perPage($request, 25));

        return response([
            'data' => $letters->items(),
            'meta' => Pagination::meta($letters),
            'statusCode' => 200,
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    /**
     * @OA\Post(
     *     path="/api/referralLetters",
     *     summary="Create referralLetters",
     *     tags={"referralLetters"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *            @OA\Property(property="referral_id", type="integer"),
     *            @OA\Property(property="hospital_id", type="integer"),
     *            @OA\Property(property="letter_text", type="string"),
     *            @OA\Property(property="status", type="string"),
     *            @OA\Property(property="start_date", type="string", nullable=true),
     *            @OA\Property(property="end_date", type="string", nullable=true),
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *
     *         @OA\Header(
     *             header="Cache-Control",
     *             description="Cache control header",
     *
     *             @OA\Schema(type="string", example="no-cache, private")
     *         ),
     *
     *         @OA\Header(
     *             header="Content-Type",
     *             description="Content type header",
     *
     *             @OA\Schema(type="string", example="application/json; charset=UTF-8")
     *         ),
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="statusCode", type="integer")
     *         )
     *     )
     * )
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        if (! $user->can('Create ReferralLetter')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        // $data = $request->validate([
        //     'referral_id' => ['nullable', 'required_if:status,Confirmed,Cancelled', 'numeric'],
        //     'patient_histories_id' => ['required_if:status,BoardedOut', 'exists:patient_histories,patient_histories_id'],

        //     'hospital_id' => ['required_if:status,Confirmed', 'numeric'],
        //     'letter_text' => ['nullable', 'string'], // BoardedOut may not need it
        //     'status' => ['required', 'in:Confirmed,Cancelled,BoardedOut'],

        //     'start_date' => ['nullable', 'string'],
        //     'end_date' => ['nullable', 'string'],

        //     // 🔥 BoardedOut fields
        //     'receiver' => ['required_if:status,BoardedOut', 'string'],
        //     'reference_number' => ['required_if:status,BoardedOut', 'string'],
        //     'reference_date' => ['required_if:status,BoardedOut', 'date'],
        //     'recommendations' => ['required_if:status,BoardedOut', 'array'],
        // ]);
        $data = $request->validate([

            'referral_id' => [
                'nullable',
                'required_if:status,Confirmed,Cancelled,Confirmed and BoardedOut',
                'numeric',
                'exists:referrals,referral_id',
            ],

            'patient_histories_id' => [
                'required_if:status,BoardedOut,Confirmed and BoardedOut',
                'exists:patient_histories,patient_histories_id',
            ],

            'hospital_id' => [
                'required_if:status,Confirmed,Confirmed and BoardedOut',
                'numeric',
            ],

            'letter_text' => [
                'nullable',
                'string',
            ],

            'status' => [
                'required',
                'in:Confirmed,Cancelled,BoardedOut,Confirmed and BoardedOut',
            ],

            'start_date' => ['nullable', 'string'],
            'end_date' => ['nullable', 'string'],

            // 🔥 BoardedOut fields
            'receiver' => [
                'required_if:status,BoardedOut,Confirmed and BoardedOut',
                'string',
            ],

            'reference_number' => [
                'required_if:status,BoardedOut,Confirmed and BoardedOut',
                'string',
            ],

            'reference_date' => [
                'required_if:status,BoardedOut,Confirmed and BoardedOut',
                'date',
            ],

            'recommendations' => [
                'required_if:status,BoardedOut,Confirmed and BoardedOut',
                'array',
            ],
        ]);

        DB::beginTransaction();

        try {

            if ($data['status'] === 'BoardedOut') {

                $patientHistory = PatientHistory::query()
                    ->lockForUpdate()
                    ->findOrFail($data['patient_histories_id']);
                $workflow = app(PatientHistoryWorkflowService::class);

                /*
                |--------------------------------------------------------------------------
                | RESOLVE THE SELECTED REFERRAL
                |--------------------------------------------------------------------------
                */
                $existingReferral = ! empty($data['referral_id'])
                    ? Referral::query()
                        ->where('referral_id', $data['referral_id'])
                        ->where('patient_id', $patientHistory->patient_id)
                        ->where('patient_histories_id', $patientHistory->patient_histories_id)
                        ->lockForUpdate()
                        ->firstOrFail()
                    : Referral::where('patient_id', $patientHistory->patient_id)
                        ->where('patient_histories_id', $patientHistory->patient_histories_id)
                        ->whereNotIn('status', ['Cancelled'])
                        ->latest()
                        ->lockForUpdate()
                        ->first();

                $existingBoardedOutLetter = BoardedOutLetter::query()
                    ->where('patient_histories_id', $patientHistory->patient_histories_id)
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if ($existingBoardedOutLetter) {
                    DB::rollBack();

                    return response([
                        'message' => 'This case already has a boarded-out record.',
                        'statusCode' => 409,
                    ], 409);
                }

                $fromStatus = $patientHistory->status;
                $beforeReferralIds = $existingReferral
                    ? $workflow->referralTreeSnapshotIds($existingReferral->referral_id)
                    : [];
                $beforeSnapshot = $workflow->snapshot($patientHistory, $beforeReferralIds);

                /*
                |--------------------------------------------------------------------------
                | UPDATE HISTORY
                |--------------------------------------------------------------------------
                */
                $patientHistory->update([
                    'status' => 'boarded_out',
                    'dg_id' => $user->id,
                ]);

                /*
                |--------------------------------------------------------------------------
                | CREATE BOARDED OUT LETTER
                |--------------------------------------------------------------------------
                */
                $boardedOut = BoardedOutLetter::create([
                    'patient_histories_id' => $data['patient_histories_id'],
                    'referral_id' => $existingReferral?->referral_id,
                    'receiver' => $data['receiver'],
                    'reference_number' => $data['reference_number'],
                    'reference_date' => $data['reference_date'],
                    'recommendations' => $data['recommendations'],
                ]);

                /*
                |--------------------------------------------------------------------------
                | IF REFERRAL EXISTS -> UPDATE IT
                |--------------------------------------------------------------------------
                */
                if ($existingReferral) {

                    $existingReferral->update([
                        'status' => 'BoardedOut',
                        'confirmed_by' => $user->id,
                    ]);
                }

                $workflow->record(
                    $patientHistory,
                    'dg_boarded_out_decision',
                    $fromStatus,
                    $patientHistory->status,
                    $beforeSnapshot,
                    [
                        'referral_id' => $existingReferral?->referral_id,
                        'boarded_out_letter_id' => $boardedOut->id,
                    ],
                    $existingReferral
                        ? $workflow->referralTreeSnapshotIds($existingReferral->referral_id)
                        : $beforeReferralIds,
                );

                DB::commit();

                return response([
                    'data' => $boardedOut,
                    'message' => 'Boarded Out decision recorded successfully.',
                    'statusCode' => 201,
                ], 201);
            }

            // 1️⃣ Find referral
            $referralId = $data['referral_id'] ?? null;

            if (! $referralId) {
                DB::rollBack();

                return response([
                    'message' => 'Referral ID missing',
                    'statusCode' => 422,
                ], 422);
            }

            $referral = Referral::query()
                ->lockForUpdate()
                ->findOrFail($referralId);
            $linkedHistory = app(\App\Services\ReferralCaseLinker::class)->requireHistory($referral);
            if (! empty($data['patient_histories_id']) && (int) $data['patient_histories_id'] !== (int) $linkedHistory->patient_histories_id) {
                DB::rollBack();
                return response()->json(['message' => 'The selected history does not belong to this referral case.', 'statusCode' => 422], 422);
            }
            if ($data['status'] === 'Confirmed and BoardedOut') {

                $patientHistory = PatientHistory::query()
                    ->where('patient_histories_id', $data['patient_histories_id'])
                    ->where('patient_id', $referral->patient_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $workflow = app(PatientHistoryWorkflowService::class);

                $existingBoardedOutLetter = BoardedOutLetter::query()
                    ->where('patient_histories_id', $patientHistory->patient_histories_id)
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if ($existingBoardedOutLetter) {
                    DB::rollBack();

                    return response([
                        'message' => 'This case already has a boarded-out record.',
                        'statusCode' => 409,
                    ], 409);
                }

                $fromStatus = $patientHistory->status;
                $beforeReferralIds = $workflow->referralTreeSnapshotIds($referral->referral_id);
                $beforeSnapshot = $workflow->snapshot($patientHistory, $beforeReferralIds);

                /*
                |--------------------------------------------------------------------------
                | UPDATE HISTORY
                |--------------------------------------------------------------------------
                */
                $patientHistory->update([
                    'status' => 'boarded_out',
                    'dg_comments' => $data['letter_text'] ?? null,
                    'dg_id' => $user->id,
                ]);

                $referral->update([
                    'hospital_id' => $data['hospital_id'],
                    'status' => 'BoardedOut',
                    'confirmed_by' => $user->id,
                ]);

                /*
                |--------------------------------------------------------------------------
                | CREATE BOARDED OUT LETTER
                |--------------------------------------------------------------------------
                */
                $boardedOut = BoardedOutLetter::create([
                    'patient_histories_id' => $data['patient_histories_id'],
                    'referral_id' => $referral->referral_id,
                    'receiver' => $data['receiver'],
                    'reference_number' => $data['reference_number'],
                    'reference_date' => $data['reference_date'],
                    'recommendations' => $data['recommendations'],
                ]);

                $referralLetter = ReferralLetter::create([
                    'referral_id' => $referral->referral_id,
                    'letter_text' => $data['letter_text'] ?? null,
                    'start_date' => $data['start_date'] ?? null,
                    'end_date' => $data['end_date'] ?? null,
                    'created_by' => $user->id,
                ]);

                $workflow->record(
                    $patientHistory,
                    'dg_confirmed_and_boarded_out_decision',
                    $fromStatus,
                    $patientHistory->status,
                    $beforeSnapshot,
                    [
                        'referral_id' => $referral->referral_id,
                        'referral_letter_id' => $referralLetter->referral_letter_id,
                        'boarded_out_letter_id' => $boardedOut->id,
                    ],
                    $workflow->referralTreeSnapshotIds($referral->referral_id),
                );

                DB::commit();

                return response([
                    'data' => [
                        'referral_letter' => $referralLetter,
                        'boarded_out_letter' => $boardedOut,
                    ],
                    'message' => 'Boarded Out decision recorded successfully.',
                    'statusCode' => 201,
                ], 201);
            }

            $workflow = app(PatientHistoryWorkflowService::class);
            $patientHistory = PatientHistory::query()
                ->where('patient_id', $referral->patient_id)
                ->where('patient_histories_id', $linkedHistory->patient_histories_id)
                ->where('status', 'approved')
                ->latest('created_at')
                ->lockForUpdate()
                ->firstOrFail();
            $fromStatus = $patientHistory->status;
            $beforeReferralIds = $workflow->referralTreeSnapshotIds($referral->referral_id);
            $beforeSnapshot = $workflow->snapshot($patientHistory, $beforeReferralIds);

            // 2️⃣ Update referral
            if ($data['status'] === 'Confirmed') {
                $referral->update([
                    'hospital_id' => $data['hospital_id'],
                    'status' => 'Confirmed',
                    'confirmed_by' => $user->id,
                ]);
            } else {
                $referral->update([
                    'status' => 'Cancelled',
                    'confirmed_by' => $user->id,
                ]);
            }

            // 3️⃣ Update patient history
            $patientHistory->update([
                'status' => $data['status'] === 'Confirmed' ? 'confirmed' : 'rejected',
                'dg_comments' => $data['letter_text'] ?? null,
                'dg_id' => $user->id,
            ]);

            // 4️⃣ Create referral letter
            $referralLetter = ReferralLetter::create([
                'referral_id' => $referral->referral_id,
                'letter_text' => $data['letter_text'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'created_by' => $user->id,
            ]);

            $workflow->record(
                $patientHistory,
                'dg_referral_decision',
                $fromStatus,
                $patientHistory->status,
                $beforeSnapshot,
                [
                    'referral_id' => $referral->referral_id,
                    'referral_letter_id' => $referralLetter->referral_letter_id,
                    'referral_status' => $referral->status,
                ],
                $workflow->referralTreeSnapshotIds($referral->referral_id),
            );

            DB::commit();

            return response([
                'data' => $referralLetter,
                'message' => 'DG decision recorded successfully.',
                'statusCode' => 201,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();

            return Helper::serverError($e);
        }
    }

    /**
     * Display the specified resource.
     */
    /**
     * @OA\Get(
     *     path="/api/referralLetters/{referralLetters_id}",
     *     summary="Find referral Letters by ID",
     *     tags={"referralLetters"},
     *
     *     @OA\Parameter(
     *         name="referralLetters_id",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="referral_letter_id", type="integer"),
     *                     @OA\Property(property="referral_id", type="integer"),
     *                     @OA\Property(property="referral_letter_code", type="string"),
     *                     @OA\Property(property="letter_text", type="string"),
     *                     @OA\Property(property="is_printed", type="boolean"),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="deleted_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *             ),
     *             @OA\Property(property="statusCode", type="integer", example=200)
     *         )
     *     )
     * )
     */
    public function show(string $id)
    {
        $user = auth()->user();
        if (! $user->can('View ReferralLetter')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $Referral_letter = ReferralLetter::withTrashed()->find($id);

        if (! $Referral_letter) {
            return response([
                'message' => 'Referral letter not found',
                'statusCode' => 404,
            ]);
        } else {
            return response([
                'data' => $Referral_letter,
                'statusCode' => 200,
            ]);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    /**
     * @OA\Put(
     *     path="/api/referralLetters/{referralLetters_id}",
     *     summary="Update referralLetters",
     *     tags={"referralLetters"},
     *
     *      @OA\Parameter(
     *         name="referralLetters_id",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="string")
     *      ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *
     *         @OA\Header(
     *             header="Cache-Control",
     *             description="Cache control header",
     *
     *             @OA\Schema(type="string", example="no-cache, private")
     *         ),
     *
     *         @OA\Header(
     *             header="Content-Type",
     *             description="Content type header",
     *
     *             @OA\Schema(type="string", example="application/json; charset=UTF-8")
     *         ),
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="object",
     *
     *                    @OA\Property(property="referral_id", type="integer"),
     *                    @OA\Property(property="hospital_id", type="integer", nullable=true),
     *                    @OA\Property(property="letter_text", type="string"),
     *                    @OA\Property(property="is_printed", type="boolean"),
     *                    @OA\Property(property="start_date", type="string", nullable=true),
     *                    @OA\Property(property="end_date", type="string", nullable=true),
     *                 )
     *             ),
     *             @OA\Property(property="statusCode", type="integer", example=200)
     *         )
     *     )
     * )
     */
    public function update(Request $request, string $id)
    {
        $user = auth()->user();
        if (! $user->can('Create ReferralLetter')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        // Validate request
        $data = $request->validate([
            'referral_id' => ['required', 'numeric'],
            'hospital_id' => ['nullable', 'numeric'],   // optional hospital_id
            'letter_text' => ['required', 'string'],
            'is_printed' => ['required', 'boolean'],
            'start_date' => ['nullable', 'string'],
            'end_date' => ['nullable', 'string'],
        ]);

        // Find referral letter
        $Referral_letter = ReferralLetter::findOrFail($id);

        // Update referral letter
        $Referral_letter->update([
            'referral_id' => $data['referral_id'],
            'letter_text' => $data['letter_text'],
            'is_printed' => $data['is_printed'],
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'created_by' => $user->id,
        ]);

        // Optionally update hospital_id in the referral if provided
        if (! empty($data['hospital_id'])) {
            $referral = Referral::find($data['referral_id']);
            if ($referral) {
                $referral->update([
                    'hospital_id' => $data['hospital_id'],
                ]);
            }
        }

        return response([
            'data' => $Referral_letter,
            'message' => 'Referral letter updated successfully',
            'statusCode' => 201,
        ], 201);
    }

    /**
     * Remove the specified resource from storage.
     */
    /**
     * @OA\Delete(
     *     path="/api/referralLetters/{referralLetters_id}",
     *     summary="Delete referralLetters",
     *     tags={"referralLetters"},
     *
     *     @OA\Parameter(
     *         name="referralLetters_id",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *      @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *
     *         @OA\Header(
     *             header="Cache-Control",
     *             description="Cache control header",
     *
     *             @OA\Schema(type="string", example="no-cache, private")
     *         ),
     *
     *         @OA\Header(
     *             header="Content-Type",
     *             description="Content type header",
     *
     *             @OA\Schema(type="string", example="application/json; charset=UTF-8")
     *         ),
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="statusCode", type="integer")
     *         )
     *     )
     * )
     */
    public function destroy(string $id)
    {
        $user = auth()->user();
        if (! $user->can('Delete ReferralLetter')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $Referral_letter = ReferralLetter::withTrashed()->find($id);

        if (! $Referral_letter) {
            return response([
                'message' => 'Referral letter not found',
                'statusCode' => 404,
            ]);
        }

        $Referral_letter->delete();

        return response([
            'message' => 'Referral_letter blocked successfully',
            'statusCode' => 200,
        ], 200);
    }

    /**
     * Unblock
     */
    /**
     * @OA\Patch(
     *     path="/api/referralLetters/unBlock/{Referral_letter_id}",
     *     summary="Unblock referralLetters",
     *     tags={"referralLetters"},
     *
     *     @OA\Parameter(
     *         name="referralLetters_id",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *      @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *
     *         @OA\Header(
     *             header="Cache-Control",
     *             description="Cache control header",
     *
     *             @OA\Schema(type="string", example="no-cache, private")
     *         ),
     *
     *         @OA\Header(
     *             header="Content-Type",
     *             description="Content type header",
     *
     *             @OA\Schema(type="string", example="application/json; charset=UTF-8")
     *         ),
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="statusCode", type="integer")
     *         )
     *     )
     * )
     */
    public function unBlockReferralLetter(int $id)
    {

        $Referral_letter = ReferralLetter::withTrashed()->find($id);

        if (! $Referral_letter) {
            return response([
                'message' => 'Referral letter not found',
                'statusCode' => 404,
            ], 404);
        }

        $Referral_letter->restore($id);

        return response([
            'message' => 'Referral_letter unblocked successfully',
            'statusCode' => 200,
        ], 200);
    }

    // Get comment by referral id
    /**
     * @OA\Get(
     *     path="/api/referralLetters/comment/referral/{referralId}",
     *     summary="Get all referral comment by referral id",
     *     tags={"referralLetters"},
     *
     *  @OA\Parameter(
     *         name="referralId",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *
     *         @OA\Header(
     *             header="Cache-Control",
     *             description="Cache control header",
     *
     *             @OA\Schema(type="string", example="no-cache, private")
     *         ),
     *
     *         @OA\Header(
     *             header="Content-Type",
     *             description="Content type header",
     *
     *             @OA\Schema(type="string", example="application/json; charset=UTF-8")
     *         ),
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="object",
     *
     *                     @OA\Property(property="referral_letter_id", type="integer"),
     *                     @OA\Property(property="letter_text", type="string"),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="deleted_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )
     *             ),
     *             @OA\Property(property="statusCode", type="integer", example=200)
     *         )
     *     )
     * )
     */
    public function getReferralCommentByReferralId(int $referralId)
    {
        $user = auth()->user();
        if (! $user->can('View ReferralLetter')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $comment = DB::table('referral_letters')
            ->join('referrals', 'referrals.referral_id', '=', 'referral_letters.referral_id')
            ->select(
                'referral_letters.referral_letter_id',
                'referral_letters.letter_text',
            )
            ->where('referrals.referral_id', '=', $referralId)
            ->first();

        if ($comment) {
            return response([
                'data' => $comment,
                'statusCode' => 200,
            ], 200);
        } else {
            return response([
                'message' => 'No data found',
                'statusCode' => 500,
            ], 500);
        }
    }
}
