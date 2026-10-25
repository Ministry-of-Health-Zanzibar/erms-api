<?php

namespace App\Http\Controllers\API\Hospitals;

use App\Http\Controllers\Controller;
use App\Models\Hospital;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Support\HospitalDoctorAccess;

class HospitalController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }
    /**
     * Display a listing of the resource.
     */
    /**
     * @OA\Get(
     *     path="/api/hospitals",
     *     summary="Get all hospitals",
     *     tags={"hospitals"},
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\Header(
     *             header="Cache-Control",
     *             description="Cache control header",
     *             @OA\Schema(type="string", example="no-cache, private")
     *         ),
     *         @OA\Header(
     *             header="Content-Type",
     *             description="Content type header",
     *             @OA\Schema(type="string", example="application/json; charset=UTF-8")
     *         ),
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="hospital_id", type="integer"),
     *                     @OA\Property(property="hospital_code", type="string"),
     *                     @OA\Property(property="hospital_name", type="string"),
     *                     @OA\Property(property="hospital_address", type="string"),
     *                     @OA\Property(property="contact_number", type="string"),
     *                     @OA\Property(property="hospital_email", type="string"),
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
    public function index()
    {
        $user = auth()->user();
        if (!$user->can('View Hospital')
            && !$user->can('View Report')
            && !app(HospitalDoctorAccess::class)->isHospitalDoctor($user)) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        $hospitals = auth()->user()->hasRole('ROLE ADMIN') ? Hospital::withTrashed()->get() : Hospital::all();

        if ($hospitals) {
            return response([
                'data' => $hospitals,
                'statusCode' => 200,
            ], 200);
        } else {
            return response([
                'message' => 'No data found',
                'statusCode' => 200,
            ], 200);
        }
    }

    public function getInternalReferralHospitals()
    {
        $user = auth()->user();
        if (!$user->can('View Hospital') && !app(HospitalDoctorAccess::class)->isHospitalDoctor($user)) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        $hospitals = Hospital::with(['referralType'])->where('referral_type_id',3)->get();

        if ($hospitals) {
            return response([
                'data' => $hospitals,
                'statusCode' => 200,
            ], 200);
        } else {
            return response([
                'message' => 'No data found',
                'statusCode' => 200,
            ], 200);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    /**
     * @OA\Post(
     *     path="/api/hospitals",
     *     summary="Create hospitals",
     *     tags={"hospitals"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="hospital_name", type="string"),
     *                     @OA\Property(property="hospital_address", type="string"),
     *                     @OA\Property(property="contact_number", type="string"),
     *                     @OA\Property(property="hospital_email", type="string"),
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\Header(
     *             header="Cache-Control",
     *             description="Cache control header",
     *             @OA\Schema(type="string", example="no-cache, private")
     *         ),
     *         @OA\Header(
     *             header="Content-Type",
     *             description="Content type header",
     *             @OA\Schema(type="string", example="application/json; charset=UTF-8")
     *         ),
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="statusCode", type="integer")
     *         )
     *     )
     * )
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        if (!$user->can('Create Hospital')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        $data = $request->validate([
            'hospital_name' => ['required', 'string'],
            'hospital_address' => ['nullable', 'string'],
            'contact_number' => ['nullable', 'string'],
            'hospital_email' => ['nullable', 'email'],
            'referral_type_id' => ['nullable', 'exists:referral_types,referral_type_id'],
        ]);


        // Create hospital
        $hospital = Hospital::create([
            'hospital_name' => $data['hospital_name'],
            'hospital_address' => $data['hospital_address'],
            'contact_number' => $data['contact_number'],
            'hospital_email' => $data['hospital_email'] ?? null,
            'referral_type_id' => $data['referral_type_id'] ?? null,
            'created_by' => Auth::id(),
        ]);

        if ($hospital) {
            return response([
                'data' => $hospital,
                'statusCode' => 201,
            ], 201);
        } else {
            return response([
                'message' => 'Internal server error',
                'statusCode' => 500,
            ], 500);
        }
    }



    /**
     * Display the specified resource.
     */
    /**
     * @OA\Get(
     *     path="/api/hospitals/{id}",
     *     summary="Find hospital by ID",
     *     tags={"hospitals"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="hospital_id", type="integer", example=1),
     *                 @OA\Property(property="hospital_code", type="string", example="HOSP001"),
     *                 @OA\Property(property="hospital_name", type="string", example="LUMUMBA"),
     *                 @OA\Property(property="hospital_address", type="string", example="Zanzibar"),
     *                 @OA\Property(property="contact_number", type="string", example="000 000 000"),
     *                 @OA\Property(property="hospital_email", type="string", example="hospital@gmail.com"),
     *                 @OA\Property(property="created_by", type="integer", example=1),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-10T10:44:31.000000Z"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-10T10:44:31.000000Z"),
     *                 @OA\Property(property="deleted_at", type="string", format="date-time", nullable=true, example=null)
     *             ),
     *             @OA\Property(property="statusCode", type="integer", example=200)
     *         )
     *     )
     * )
     */
    public function show($id)
    {
        $id = (int) $id;

        $user = auth()->user();
        if (!$user->can('View Hospital')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        $hospital = Hospital::withTrashed()->find($id);

        if (!$hospital) {
            return response([
                'message' => 'Hospital not found',
                'statusCode' => 404,
            ], 404);
        }

        return response([
            'data' => $hospital,
            'statusCode' => 200,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    /**
     * @OA\Put(
     *     path="/api/hospitals/{id}",
     *     summary="Update hospital",
     *     tags={"hospitals"},
     *      @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string")
     *      ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\Header(
     *             header="Cache-Control",
     *             description="Cache control header",
     *             @OA\Schema(type="string", example="no-cache, private")
     *         ),
     *         @OA\Header(
     *             header="Content-Type",
     *             description="Content type header",
     *             @OA\Schema(type="string", example="application/json; charset=UTF-8")
     *         ),
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="hospital_name", type="string"),
     *                 @OA\Property(property="hospital_address", type="string" ),
     *                 @OA\Property(property="contact_number", type="string"),
     *                 @OA\Property(property="hospital_email", type="string"),
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
        
        // 1. Permission Check
        if (!$user->can('Update Hospital')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        // 2. Validation
        // Note: I included 'referral_type_id' to keep it consistent with your store method
        $data = $request->validate([
            'hospital_name' => ['required', 'string'],
            'hospital_address' => ['nullable', 'string'],
            'contact_number' => ['nullable', 'string'],
            'hospital_email' => ['nullable', 'email'],
            'referral_type_id' => ['nullable', 'exists:referral_types,referral_type_id'],
        ]);

        // 3. Find the Resource
        $hospital = Hospital::find($id);

        if (!$hospital) {
            return response([
                'message' => 'Hospital not found',
                'statusCode' => 404,
            ], 404);
        }

        // 4. Update the Resource
        $updated = $hospital->update([
            'hospital_name' => $data['hospital_name'],
            'hospital_address' => $data['hospital_address'],
            'contact_number' => $data['contact_number'],
            'hospital_email' => $data['hospital_email'] ?? $hospital->hospital_email,
            'referral_type_id' => $data['referral_type_id'] ?? $hospital->referral_type_id,
            // Usually, for updates, we track 'updated_by', 
            // but keeping Auth::id() here if that's your requirement.
        ]);

        // 5. Response
        if ($updated) {
            return response([
                'data' => $hospital->refresh(), // refresh to get the latest DB state
                'message' => 'Hospital updated successfully',
                'statusCode' => 200,
            ], 200);
        }

        return response([
            'message' => 'Internal server error',
            'statusCode' => 500,
        ], 500);
    }

    /**
     * Remove the specified resource from storage.
     */
    /**
     * @OA\Delete(
     *     path="/api/hospitals/{id}",
     *     summary="Delete hospital",
     *     tags={"hospitals"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *      @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\Header(
     *             header="Cache-Control",
     *             description="Cache control header",
     *             @OA\Schema(type="string", example="no-cache, private")
     *         ),
     *         @OA\Header(
     *             header="Content-Type",
     *             description="Content type header",
     *             @OA\Schema(type="string", example="application/json; charset=UTF-8")
     *         ),
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="statusCode", type="integer")
     *         )
     *     )
     * )
     */
    public function destroy(int $id)
    {
        $user = auth()->user();
        if (!$user->can('Delete Hospital')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        $hospital = Hospital::withTrashed()->find($id);

        if (!$hospital) {
            return response([
                'message' => 'Hospital not found',
                'statusCode' => 404,
            ]);
        }

        $hospital->delete();

        return response([
            'message' => 'Hospital blocked successfully',
            'statusCode' => 200,
        ], 200);

    }


    /**
     * Unblock
     */
    /**
     * @OA\Patch(
     *     path="/api/hospitals/unBlock/{id}",
     *     summary="Unblock hospital",
     *     tags={"hospitals"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *      @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\Header(
     *             header="Cache-Control",
     *             description="Cache control header",
     *             @OA\Schema(type="string", example="no-cache, private")
     *         ),
     *         @OA\Header(
     *             header="Content-Type",
     *             description="Content type header",
     *             @OA\Schema(type="string", example="application/json; charset=UTF-8")
     *         ),
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="statusCode", type="integer")
     *         )
     *     )
     * )
     */
    public function unBlockHospital(int $id)
    {

        $hospital = Hospital::withTrashed()->find($id);

        if (!$hospital) {
            return response([
                'message' => 'Hospital not found',
                'statusCode' => 404,
            ], 404);
        }

        $hospital->restore($id);

        return response([
            'message' => 'Hospital unblocked successfully',
            'statusCode' => 200,
        ], 200);
    }
    /**
    * @OA\Get(
     *     path="/api/reffered-hospitals",
     *     summary="Get all hospitals",
     *     tags={"hospitals"},
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\Header(
     *             header="Cache-Control",
     *             description="Cache control header",
     *             @OA\Schema(type="string", example="no-cache, private")
     *         ),
     *         @OA\Header(
     *             header="Content-Type",
     *             description="Content type header",
     *             @OA\Schema(type="string", example="application/json; charset=UTF-8")
     *         ),
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="hospital_id", type="integer"),
     *                     @OA\Property(property="hospital_code", type="string"),
     *                     @OA\Property(property="hospital_name", type="string"),
     *                     @OA\Property(property="hospital_address", type="string"),
     *                     @OA\Property(property="contact_number", type="string"),
     *                     @OA\Property(property="hospital_email", type="string"),
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
    public function getReferredHospitals()
    {
        $user = auth()->user();
        if (!$user->can('View Hospital')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        $hospitals = Hospital::whereHas('referrals', function($query) {
            // Only confirmed referrals
            $query->where('status', 'Confirmed');
        })
        ->with([
            'referrals' => function($query) {
                // Only confirmed referrals (exclude Cancelled)
                $query->where('status', 'Confirmed');
            },
            'referralType',
        ])
        ->withTrashed()
        ->get([
            'hospital_id',
            'hospital_name',
            'hospital_address',
            'hospital_code',
            'referral_type_id'
        ]);


        if ($hospitals) {
            return response([
                'data' => $hospitals,
                'statusCode' => 200,
            ], 200);
        } else {
            return response([
                'message' => 'No data found',
                'statusCode' => 200,
            ], 200);
        }
    }
}
