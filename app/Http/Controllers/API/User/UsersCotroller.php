<?php

namespace App\Http\Controllers\API\User;

use App\Http\Controllers\Controller;
use App\Http\Helpers\Helper;
use App\Mail\UserCredentialsMail;
use App\Models\User;
use App\Services\UserBlockService;
use App\Support\SuperAdminAccess;
use App\Support\Pagination;
use DB;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Validator;

class UsersCotroller extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * @OA\Get(
     *     path="/api/userAccounts",
     *     summary="Get a list of userAccountss",
     *     tags={"userAccounts"},
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
     *                     @OA\Property(property="id", type="integer", example=2),
     *                     @OA\Property(property="first_name", type="string", example="ROLE NATIONAL"),
     *                     @OA\Property(property="middle_name", type="string", example="web"),
     *                     @OA\Property(property="last_name", type="string", example="ROLE NATIONAL"),
     *                     @OA\Property(property="email", type="string", example="web"),
     *                     @OA\Property(property="phone_no", type="string", example="ROLE NATIONAL"),
     *                     @OA\Property(property="address", type="string", example="web"),
     *                     @OA\Property(property="gender", type="string", example="ROLE NATIONAL"),
     *                     @OA\Property(property="date_of_birth",type="string",format="date"),
     *                     @OA\Property(property="role_id", type="integer", example=2),
     *                     @OA\Property(property="role_name", type="string", example="web"),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2024-08-28 11:30:25"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2024-08-28 11:30:25")
     *                 )
     *             ),
     *             @OA\Property(property="statusCode", type="integer", example=200)
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        $actor = auth()->user();
        $isAdmin = $actor->hasAnyRole(['ROLE ADMIN', 'ROLE SUPER ADMIN', 'ROLE SUPERADMIN']);
        $isNational = $actor->hasRole('ROLE NATIONAL');
        $isAccountant = $actor->hasRole('ROLE ACCOUNTANT');

        if (! $isAdmin && ! $isNational && ! $isAccountant && ! $actor->can('View User')) {
            return response()->json([
                'message' => 'Unauthorized',
                'statusCode' => 401,
            ], 401);
        }

        $query = DB::table('users')
            ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->leftJoin('hospital_user', 'hospital_user.user_id', '=', 'users.id')
            ->leftJoin('hospitals', 'hospitals.hospital_id', '=', 'hospital_user.hospital_id')
            ->leftJoin('users as blocker', 'blocker.id', '=', 'users.blocked_by')
            ->where('model_has_roles.model_type', User::class)
            ->where('users.id', '!=', 1)
            ->select(
                'users.id',
                'users.first_name',
                'users.middle_name',
                'users.last_name',
                'users.email',
                'users.phone_no',
                'users.address',
                'users.gender',
                'users.date_of_birth',
                'users.deleted_at',
                'users.is_blocked',
                'users.blocked_at',
                'users.blocked_by',
                'users.blocked_reason',
                DB::raw("NULLIF(CONCAT_WS(' ', blocker.first_name, blocker.middle_name, blocker.last_name), '') as blocked_by_name"),
                'roles.name as role_name',
                'roles.id as role_id',
                'hospitals.hospital_name as hospital',
            );

        if ($isAdmin) {
            $query->where('users.created_by', Auth::id());
        } elseif ($isNational) {
            $query->where('users.created_by', Auth::id())
                ->where('roles.name', '!=', 'ROLE NATIONAL')
                ->where('model_has_roles.role_id', '!=', 1);
        } elseif ($isAccountant) {
            $query->where('users.created_by', 2)
                ->where('roles.name', '!=', 'ROLE NATIONAL')
                ->where('model_has_roles.role_id', '!=', 1);
        }

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $term = mb_strtolower($search);
            $query->where(function ($query) use ($term): void {
                $query->whereRaw('LOWER(users.first_name) LIKE ?', [$term.'%'])
                    ->orWhereRaw('LOWER(users.middle_name) LIKE ?', [$term.'%'])
                    ->orWhereRaw('LOWER(users.last_name) LIKE ?', [$term.'%'])
                    ->orWhereRaw('LOWER(users.email) LIKE ?', [$term.'%'])
                    ->orWhereRaw('LOWER(users.phone_no) LIKE ?', [$term.'%']);
            });
        }

        $staffs = $query
            ->orderByDesc('users.id')
            ->paginate(Pagination::perPage($request, 25));

        return response()->json([
            'data' => $staffs->items(),
            'meta' => Pagination::meta($staffs),
            'statusCode' => 200,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/userAccounts",
     *     summary="Store a new userAccounts",
     *     tags={"userAccounts"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="first_name", type="string"),
     *             @OA\Property(property="middle_name", type="string"),
     *             @OA\Property(property="last_name", type="string"),
     *             @OA\Property(property="location_id", type="string"),
     *             @OA\Property(property="role_id", type="string"),
     *             @OA\Property(property="phone_no", type="string"),
     *             @OA\Property(property="date_of_birth", type="date"),
     *             @OA\Property(property="email", type="string"),
     *             @OA\Property(property="gender", type="string"),
     *             @OA\Property(property="password", type="string")
     *         )
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
        if (auth()->user()->hasRole('ROLE ADMIN') || auth()->user()->hasRole('ROLE NATIONAL') || auth()->user()->can('Create User')) {
            $validator = Validator::make($request->all(), [
                'first_name' => ['required', 'string', 'max:100'],
                'middle_name' => ['required', 'string', 'max:100'],
                'last_name' => ['required', 'string', 'max:100'],
                'address' => ['required', 'string', 'max:255'],
                'phone_no' => ['required', 'string', 'max:30'],
                'email' => ['required', 'string', 'email', 'max:255'],
                'gender' => ['required', 'string', 'max:30'],
                'date_of_birth' => ['required', 'date'],
                'role_id' => ['required', 'integer', 'exists:roles,id'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'The submitted user data is invalid.',
                    'errors' => $validator->errors(),
                    'statusCode' => 422,
                ], 422);
            }

            $check_value = DB::select('SELECT u.email FROM users u WHERE u.email = ?', [$request->email]);
            if (count($check_value) == 0) {
                try {
                    $password_plain = Str::password(12);

                    // 2️⃣ Create the user
                    $user = User::create([
                        'first_name' => $request->first_name,
                        'middle_name' => $request->middle_name,
                        'last_name' => $request->last_name,
                        'address' => $request->address,
                        'phone_no' => $request->phone_no,
                        'gender' => $request->gender,
                        'date_of_birth' => date('Y-m-d', strtotime($request->date_of_birth)),
                        'email' => $request->email,
                        'password' => Hash::make($password_plain), // store hashed password
                        'login_status' => '0',
                        'created_by' => Auth::id(),
                    ]);

                    // 3️⃣ Assign role
                    $user->assignRole($request->role_id);

                    // 4️⃣ Give permissions
                    $permissions = DB::table('role_has_permissions')
                        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                        ->select('permissions.id', 'permissions.name')
                        ->where('role_has_permissions.role_id', '=', $request->role_id)
                        ->get();

                    $user->givePermissionTo($permissions);

                    // 5️⃣ Send credentials email
                    Mail::to($user->email)->send(new UserCredentialsMail($user, $password_plain));

                    // 6️⃣ Return success response
                    $successResponse = [
                        'message' => 'User Account Created Successfully and credentials sent via email',
                        'password' => $password_plain,
                        'email' => $request->email,
                        'statusCode' => 201,
                    ];

                    return response()->json($successResponse);

                } catch (Exception $e) {
                    return Helper::serverError($e);
                }

            } else {
                $errorResponse = [
                    'message' => 'Email Already Exists',
                    'statusCode' => 400,
                ];

                return response()->json($errorResponse);
            }

        } else {
            return response()->json(['message' => 'Unauthorized', 'statusCode' => 401]);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/userAccounts/{id}",
     *     summary="Get a specific userAccounts",
     *     tags={"userAccounts"},
     *
     *     @OA\Parameter(
     *         name="Id",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="string")
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
     *                     @OA\Property(property="id", type="integer", example=2),
     *                     @OA\Property(property="first_name", type="string", example="ROLE NATIONAL"),
     *                     @OA\Property(property="middle_name", type="string", example="web"),
     *                     @OA\Property(property="last_name", type="string", example="ROLE NATIONAL"),
     *                     @OA\Property(property="email", type="string", example="web"),
     *                     @OA\Property(property="phone_no", type="string", example="ROLE NATIONAL"),
     *                     @OA\Property(property="address", type="string", example="web"),
     *                     @OA\Property(property="gender", type="string", example="ROLE NATIONAL"),
     *                     @OA\Property(property="date_of_birth",type="string",format="date"),
     *                     @OA\Property(property="role_id", type="integer", example=2),
     *                     @OA\Property(property="role_name", type="string", example="web"),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2024-08-28 11:30:25"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2024-08-28 11:30:25")
     *                 )
     *             ),
     *             @OA\Property(property="statusCode", type="integer", example=200)
     *         )
     *     )
     * )
     */
    public function show(string $id)
    {
        if (auth()->user()->hasRole('ROLE ADMIN') || auth()->user()->hasRole('ROLE NATIONAL') || auth()->user()->can('Delete User')) {
            $staffs = DB::table('users')
                ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->select('users.id', 'users.first_name', 'users.middle_name', 'users.last_name', 'users.email', 'users.phone_no', 'users.address', 'users.gender', 'users.date_of_birth', 'users.deleted_at', 'roles.name as role_name', 'roles.id as role_id')
                ->where('model_has_roles.role_id', '!=', 1)
                ->where('users.id', '=', $id)
                ->get();

            $response = [
                'data' => $staffs,
                'statusCode' => 200,
            ];
        } else {
            return response()
                ->json(['message' => 'Unauthorized', 'statusCode' => 401], 401);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/userAccounts/{id}",
     *     summary="Update a userAccounts",
     *     tags={"userAccounts"},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="first_name", type="string"),
     *             @OA\Property(property="middle_name", type="string"),
     *             @OA\Property(property="last_name", type="string"),
     *             @OA\Property(property="location_id", type="string"),
     *             @OA\Property(property="phone_no", type="string"),
     *             @OA\Property(property="date_of_birth", type="date"),
     *             @OA\Property(property="email", type="string"),
     *             @OA\Property(property="gender", type="string"),
     *             @OA\Property(property="password", type="string")
     *         )
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
    public function update(Request $request, string $id)
    {
        if (auth()->user()->hasRole('ROLE ADMIN') || auth()->user()->hasRole('ROLE NATIONAL') || auth()->user()->can('Update User')) {
            $validator = Validator::make($request->all(), [
                'first_name' => ['required', 'string', 'max:100'],
                'middle_name' => ['required', 'string', 'max:100'],
                'last_name' => ['required', 'string', 'max:100'],
                'address' => ['required', 'string', 'max:255'],
                'phone_no' => ['required', 'string', 'max:30'],
                'gender' => ['required', 'string', 'max:30'],
                'date_of_birth' => ['required', 'date'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'The submitted user data is invalid.',
                    'errors' => $validator->errors(),
                    'statusCode' => 422,
                ], 422);
            }

            try {
                $users = User::findOrFail($id);
                $users->first_name = $request->first_name;
                $users->middle_name = $request->middle_name;
                $users->last_name = $request->last_name;
                $users->address = $request->address;
                $users->gender = $request->gender;
                $users->phone_no = $request->phone_no;
                $users->date_of_birth = $request->date_of_birth;
                $users->update();

                // $users->assignRole($request->roleID);

                $successResponse = [
                    'message' => 'User Account Updated Successfully',
                    'statusCode' => 201,
                ];

                return response()->json($successResponse);
            } catch (Exception $e) {
                return Helper::serverError($e);
            }
        } else {
            return response()
                ->json(['message' => 'Unauthorized', 'statusCode' => 401], 401);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/userAccounts/{id}",
     *     summary="Delete a userAccounts",
     *     tags={"userAccounts"},
     *
     *     @OA\Parameter(
     *         name="id",
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
    public function destroy(Request $request, string $id)
    {
        if (! ctype_digit($id) || (int) $id < 1) {
            return response()->json([
                'message' => 'The user ID must be a positive integer.',
                'statusCode' => 422,
            ], 422);
        }

        if (SuperAdminAccess::allowed(auth()->user(), 'Block User')) {
            try {
                $request->validate([
                    'reason' => ['nullable', 'string', 'max:2000'],
                ]);

                app(UserBlockService::class)->block(
                    (int) $id,
                    auth()->user(),
                    $request->input('reason'),
                );

                return response()->json([
                    'message' => 'User Account Blocked Successfully',
                    'statusCode' => 200,
                ]);
            } catch (Exception $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'statusCode' => $e instanceof \RuntimeException ? 422 : 500,
                ], $e instanceof \RuntimeException ? 422 : 500);
            }
        } else {
            return response()
                ->json(['message' => 'Unauthorized', 'statusCode' => 401], 401);
        }
    }

    public function unBlockUser(string $id)
    {
        if (! ctype_digit($id) || (int) $id < 1) {
            return response()->json([
                'message' => 'The user ID must be a positive integer.',
                'statusCode' => 422,
            ], 422);
        }

        if (SuperAdminAccess::allowed(auth()->user(), 'Unblock User')) {
            try {
                app(UserBlockService::class)->unblock((int) $id, auth()->user());

                return response()->json([
                    'message' => 'User Account Unblocked Successfully',
                    'statusCode' => 200,
                ]);

            } catch (Exception $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'statusCode' => $e instanceof \RuntimeException ? 422 : 500,
                ], $e instanceof \RuntimeException ? 422 : 500);
            }
        } else {
            return response()->json(['message' => 'Unauthorized', 'statusCode' => 401], 401);
        }
    }

    public function getBoardMembers()
    {
        $staffs = User::withTrashed()
            ->whereHas('roles', function ($query) {
                $query->where('name', 'ROLE MEDICAL BOARD MEMBER');
            })
            // Fetch the raw columns first
            ->get(['id', 'first_name', 'middle_name', 'last_name']);

        // Use map to create the full_name string in PHP
        $mappedStaffs = $staffs->map(function ($user) {
            // filter() removes null/empty values, then join() puts a single space between them
            $nameParts = array_filter([
                $user->first_name,
                $user->middle_name,
                $user->last_name,
            ]);

            return [
                'user_id' => $user->id,
                'full_name' => implode(' ', $nameParts),
            ];
        });

        return response()->json([
            'data' => $mappedStaffs,
            'message' => 'Board members retrieved successfully',
            'statusCode' => 200,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/users/{userId}/assign-hospital",
     *     summary="Assign a hospital to a user",
     *     tags={"userAccounts"},
     *
     *     @OA\Parameter(
     *         name="userId",
     *         in="path",
     *         required=true,
     *         description="ID of the user to assign hospital",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(
     *                 property="hospital_id",
     *                 type="integer",
     *                 example=9,
     *                 description="ID of the hospital to assign"
     *             ),
     *             @OA\Property(
     *                 property="role",
     *                 type="string",
     *                 example="doctor",
     *                 description="Role of the user in the hospital"
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Hospital assigned successfully",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="message", type="string", example="Hospital assigned successfully"),
     *             @OA\Property(property="data", type="array",
     *
     *                 @OA\Items(
     *                     type="object",
     *
     *                     @OA\Property(property="hospital_id", type="integer", example=9),
     *                     @OA\Property(property="hospital_name", type="string", example="City Hospital"),
     *                     @OA\Property(property="pivot", type="object",
     *                         @OA\Property(property="role", type="string", example="doctor"),
     *                         @OA\Property(property="assigned_by", type="integer", example=1),
     *                         @OA\Property(property="created_at", type="string", example="2025-12-30 12:00:00"),
     *                         @OA\Property(property="updated_at", type="string", example="2025-12-30 12:00:00")
     *                     )
     *                 )
     *             ),
     *             @OA\Property(property="statusCode", type="integer", example=200)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Unauthorized"),
     *             @OA\Property(property="statusCode", type="integer", example=401)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation Error",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Validation Error"),
     *             @OA\Property(property="errors", type="object"),
     *             @OA\Property(property="statusCode", type="integer", example=422)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Failed to assign hospital",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Failed to assign hospital"),
     *             @OA\Property(property="error", type="string", example="Error message"),
     *             @OA\Property(property="statusCode", type="integer", example=500)
     *         )
     *     )
     * )
     */
    public function assignHospital(Request $request, $userId)
    {
        // Only admins or users with permission can assign hospitals
        if (! auth()->user()->hasRole('ROLE ADMIN') && ! auth()->user()->can('Assign Hospital')) {
            return response()->json([
                'message' => 'Unauthorized',
                'statusCode' => 401,
            ], 401);
        }

        $user = User::findOrFail($userId);

        $validator = Validator::make($request->all(), [
            'hospital_id' => 'required|exists:hospitals,hospital_id',
            'role' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $validator->errors(),
                'statusCode' => 422,
            ], 422);
        }

        try {
            // Attach hospital without removing existing ones
            $user->hospitals()->attach($request->hospital_id, [
                'role' => $request->role ?? 'staff',
                'assigned_by' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'Hospital assigned successfully',
                'data' => $user->hospitals()->get(),
                'statusCode' => 200,
            ]);

        } catch (Exception $e) {
            return Helper::serverError($e, 'Failed to assign hospital');
        }
    }
}
