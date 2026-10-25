<?php

namespace App\Http\Controllers\API\User;

use App\Http\Controllers\Controller;
use App\Http\Helpers\Helper;
use App\Models\User;
use Carbon\Carbon;
use DB;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserProfileCotroller extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum')->except([
            'forgotPassword',
            'forgotPasswordReset',
        ]);
        // $this->middleware('permission:Setup Management|Create Upload Types|Create Upload Types|Update Upload Types|Update Upload Types|Delete Upload Types', ['only' => ['index','create','store','update','destroy']]);
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index()
    {
        $login_details = $this->check_login();
        if (count($login_details) == 0) {
            $successResponse = [
                'message' => 'Password already Changed',
                'status' => '201',
            ];

            return response()->json($successResponse);
        } else {
            $errorResponse = [
                'message' => 'Password not Changed, Please change the Password',
                'status' => '400',
            ];

            return response()->json($errorResponse);
        }
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function check_login()
    {
        $user_id = auth()->user()->id;

        $staffs = DB::table('users')
            ->select('users.*')
            ->where('users.login_status', '!=', 1)
            ->where('users.id', '=', $user_id)
            ->get();

        return $staffs; // turn the array into a string
    }

    /**
     * Return the authenticated user's editable profile and read-only access context.
     */
    public function profile()
    {
        $user = auth()->user()->load(['roles', 'hospitals']);

        return response()->json([
            'data' => $this->profileData($user),
            'statusCode' => 200,
        ]);
    }

    /**
     * Update only the authenticated user's personal information.
     * Roles, permissions, hospital assignments, status, and password remain protected.
     */
    public function updateProfile(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:255'],
            'phone_no' => [
                'required',
                'string',
                'max:20',
                Rule::unique('users', 'phone_no')->ignore($user->id),
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'gender' => ['required', 'string', 'max:15'],
            'date_of_birth' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $user->fill($validated);
        $user->save();
        $user->load(['roles', 'hospitals']);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data' => $this->profileData($user),
            'statusCode' => 200,
        ]);
    }

    private function profileData(User $user): array
    {
        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'middle_name' => $user->middle_name,
            'last_name' => $user->last_name,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'phone_no' => $user->phone_no,
            'address' => $user->address,
            'gender' => $user->gender,
            'date_of_birth' => $user->date_of_birth,
            'roles' => $user->getRoleNames()->values()->all(),
            'hospitals' => $user->hospitals->map(function ($hospital): array {
                return [
                    'hospital_id' => $hospital->hospital_id,
                    'hospital_name' => $hospital->hospital_name,
                    'hospital_role' => $hospital->pivot->role ?? null,
                ];
            })->values()->all(),
        ];
    }

    /**
     * @OA\Post(
     *     path="/api/changePassword",
     *     summary="change Password",
     *     tags={"userProfile"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="new_password", type="string"),
     *             @OA\Property(property="password_confirmation", type="string")
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
    public function change_password(Request $request)
    {
        $validated = $request->validate([
            'old_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'different:old_password'],
            'password_confirmation' => ['required', 'same:new_password'],
        ]);

        $id = auth()->user()->id;
        try {
            $users = User::findOrFail($id);
            if (Hash::check($validated['old_password'], $users->password)) {
                $users->password = Hash::make($validated['new_password']);
                $users->login_status = 1;
                $users->save();

                $successResponse = [
                    'message' => 'Change Password Successfuly',
                    'statusCode' => 201,
                ];

                return response()->json($successResponse);
            } else {
                $errorResponse = [
                    'message' => 'Invalid Old Password',
                    'statusCode' => 400,
                ];

                return response()->json($errorResponse);
            }
        } catch (Exception $e) {
            return Helper::serverError($e);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/resetPassword",
     *     summary="Reset a user's password (Admin only)",
     *     tags={"User Management"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="user_id", type="integer", example=1, description="ID of the user to reset password")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Password reset successfully",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="message", type="string", example="Change Password Successfully"),
     *             @OA\Property(property="new_password", type="string", example="12345678"),
     *             @OA\Property(property="statusCode", type="integer", example=201)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="message", type="string", example="Unauthorized"),
     *             @OA\Property(property="statusCode", type="integer", example=401)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="message", type="string", example="Internal Server Error"),
     *             @OA\Property(property="error", type="string", example="Error details"),
     *             @OA\Property(property="statusCode", type="integer", example=500)
     *         )
     *     )
     * )
     */
    public function resetPassword(Request $request)
    {
        if (auth()->user()->hasRole('ROLE ADMIN')) {
            $validated = $request->validate([
                'user_id' => ['required', 'integer', 'exists:users,id'],
            ]);
            $new_password = Str::password(12);

            try {
                $user = User::findOrFail($validated['user_id']);

                // Update password
                $user->password = bcrypt($new_password);
                $user->login_status = 0;
                $user->save();

                // Send email using the HTML template
                Mail::send('emails.password_reset', [
                    'first_name' => $user->first_name,
                    'email' => $user->email,
                    'new_password' => $new_password,
                ], function ($message) use ($user) {
                    $message->to($user->email)
                        ->subject('Your Password Has Been Reset');
                });

                return response()->json([
                    'message' => 'Password reset successfully and sent to user email',
                    'statusCode' => 201,
                ]);

            } catch (Exception $e) {
                return Helper::serverError($e);
            }
        } else {
            return response()->json([
                'message' => 'Unauthorized',
                'statusCode' => 401,
            ]);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/forgot-password",
     *     summary="Request a password reset link (Public)",
     *     tags={"User Management"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="email", type="string", example="user@example.com", description="Registered user email")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Password reset link sent",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="message", type="string", example="Password reset link sent to your email"),
     *             @OA\Property(property="statusCode", type="integer", example=200)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="message", type="string", example="Internal Server Error"),
     *             @OA\Property(property="error", type="string", example="Error details"),
     *             @OA\Property(property="statusCode", type="integer", example=500)
     *         )
     *     )
     * )
     */
    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            $user = User::where('email', $validated['email'])->first();

            if (! $user) {
                return response()->json([
                    'message' => 'If an account exists for that email, a password reset link has been sent.',
                    'statusCode' => 200,
                ]);
            }

            $token = Str::random(64);

            DB::table('password_resets')->updateOrInsert(
                ['email' => $validated['email']],
                [
                    'token' => Hash::make($token),
                    'created_at' => Carbon::now(),
                ]
            );

            $resetLink = rtrim(config('app.frontend_url'), '/')
                .'/auth/reset-password?email='.urlencode($validated['email'])
                .'&token='.urlencode($token);

            // Send email
            Mail::raw(
                "Click this link to reset your password: {$resetLink}",
                function ($message) use ($validated) {
                    $message->to($validated['email'])
                        ->subject('Reset Your Password');
                }
            );

            return response()->json([
                'message' => 'If an account exists for that email, a password reset link has been sent.',
                'statusCode' => 200,
            ]);
        } catch (Exception $e) {
            return Helper::serverError($e);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/reset-forgot-password",
     *     summary="Reset password using token (Public)",
     *     tags={"User Management"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="email", type="string", example="user@example.com"),
     *             @OA\Property(property="token", type="string", example="abcdef123456"),
     *             @OA\Property(property="new_password", type="string", example="newpassword123"),
     *             @OA\Property(property="new_password_confirmation", type="string", example="newpassword123")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Password reset successfully",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="message", type="string", example="Password reset successfully"),
     *             @OA\Property(property="statusCode", type="integer", example=200)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=400,
     *         description="Invalid or expired token",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="message", type="string", example="Invalid or expired token"),
     *             @OA\Property(property="statusCode", type="integer", example=400)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="message", type="string", example="Internal Server Error"),
     *             @OA\Property(property="error", type="string", example="Error details"),
     *             @OA\Property(property="statusCode", type="integer", example=500)
     *         )
     *     )
     * )
     */
    public function forgotPasswordReset(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $record = DB::table('password_resets')
                ->where('email', $validated['email'])
                ->first();

            if (! $record) {
                return response()->json([
                    'message' => 'Invalid reset request',
                    'statusCode' => 400,
                ], 400);
            }

            $expiresAfter = (int) config('auth.passwords.users.expire', 60);
            $isExpired = Carbon::parse($record->created_at)->addMinutes($expiresAfter)->isPast();

            if ($isExpired || ! Hash::check($validated['token'], $record->token)) {
                if ($isExpired) {
                    DB::table('password_resets')->where('email', $validated['email'])->delete();
                }

                return response()->json([
                    'message' => 'Invalid or expired token',
                    'statusCode' => 400,
                ], 400);
            }

            $user = User::where('email', $validated['email'])->first();

            if (! $user) {
                return response()->json([
                    'message' => 'Invalid reset request',
                    'statusCode' => 400,
                ], 400);
            }

            DB::transaction(function () use ($user, $validated) {
                $user->password = Hash::make($validated['new_password']);
                $user->login_status = 1;
                $user->save();

                DB::table('password_resets')->where('email', $validated['email'])->delete();
            });

            return response()->json([
                'message' => 'Password reset successfully',
                'statusCode' => 200,
            ]);
        } catch (Exception $e) {
            return Helper::serverError($e);
        }
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function logs_function()
    {
        if (! auth()->user()->hasrole('ROLE ADMIN')) {
            $errorResponse = [
                'message' => 'Permission Denied',
                'statusCode' => 400,
            ];

            return response()->json($errorResponse);
        } else {

            $activity = DB::select('SELECT users.phone_no,users.email,users.first_name,users.middle_name,users.last_name,properties, activity_log.created_at, description,activity_log.subject_type as subject,activity_log.id FROM activity_log INNER JOIN users ON users.id=activity_log.causer_id WHERE users.id != 1  ORDER BY activity_log.id DESC LIMIT 1500');

            $successResponse = [
                'activity' => $activity,
                'statusCode' => 200,
            ];

            return response()->json($successResponse);
        }
    }
}
