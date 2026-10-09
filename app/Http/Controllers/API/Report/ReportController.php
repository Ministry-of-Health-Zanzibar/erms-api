<?php

namespace App\Http\Controllers\API\Report;

use App\Http\Controllers\Controller;
use App\Http\Helpers\Helper;
use App\Models\PatientHistory;
use App\Services\Reports\CaseReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Schema(
 *     schema="Referral",
 *     type="object",
 *
 *     @OA\Property(property="referral_id", type="integer", example=123),
 *     @OA\Property(property="patient", type="object"),
 *     @OA\Property(property="hospital", type="object", nullable=true),
 *     @OA\Property(property="hospitalLetters", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="referralLetters", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="parent", type="object", nullable=true),
 *     @OA\Property(property="children", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="bills", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="confirmedBy", type="object", nullable=true),
 *     @OA\Property(property="creator", type="object"),
 *     @OA\Property(property="diagnoses", type="array", @OA\Items(type="object"))
 * )
 */
class ReportController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    // DASHBOARD ========================================================================//

    public function getOverallCounts()
    {
        $user = auth()->user();

        if (! $user->can('View Referral Dashboard')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        // 1. Total Medical Boards
        $totalMedicalBoards = \App\Models\PatientList::count();

        // 2. Total Patients (excluding soft deleted)
        $totalPatients = \App\Models\Patient::count();

        // 3. Total Referrals (Excluding 'Cancelled' and 'Pending')
        // We use whereNotIn to focus on active/finalized cases
        $totalReferrals = \App\Models\Referral::whereNotIn('status', ['Cancelled', 'Pending', 'Requested'])
            ->whereNull('deleted_at') // Ensure we respect soft deletes
            ->count();

        // 4. Total Hospitals
        // $totalHospitals = \App\Models\Hospital::count();
        $totalHospitals = \App\Models\Hospital::whereHas('referralType', function ($query) {
            $query->whereIn('referral_type_code', ['REFTYPE1', 'REFTYPE2']);
        })->count();

        return response([
            'data' => [
                'total_medical_boards' => $totalMedicalBoards,
                'total_patients' => $totalPatients,
                'total_referrals' => $totalReferrals, // Now filtered
                'total_hospitals' => $totalHospitals,
            ],
            'statusCode' => 200,
        ], 200);
    }

    // DASHBOARD ========================================================================//

    public function referralsReportByReason()
    {
        $user = auth()->user();
        if (! $user->can('View Referral Dashboard')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        try {
            $totalReferralsByKufanyiwaUchunguzi = DB::table('referrals')
                ->join('reasons', 'reasons.reason_id', '=', 'referrals.reason_id')
                ->whereNull('referrals.deleted_at')
                ->where('reasons.referral_reason_name', '=', 'Kufanyiwa uchunguzi')
                ->count();

            $totalReferralsByKupatiwaMatibabu = DB::table('referrals')
                ->join('reasons', 'reasons.reason_id', '=', 'referrals.reason_id')
                ->whereNull('referrals.deleted_at')
                ->where('reasons.referral_reason_name', '=', 'Kupatiwa matibabu')
                ->count();

            $totalReferralsByUchunguziNaMatibabuZaidi = DB::table('referrals')
                ->join('reasons', 'reasons.reason_id', '=', 'referrals.reason_id')
                ->whereNull('referrals.deleted_at')
                ->where('reasons.referral_reason_name', '=', 'Uchunguzi na matibabu zaidi')
                ->count();

            $totalReferralsByUchunguziNaMatibabu = DB::table('referrals')
                ->join('reasons', 'reasons.reason_id', '=', 'referrals.reason_id')
                ->whereNull('referrals.deleted_at')
                ->where('reasons.referral_reason_name', '=', 'Uchunguzi na matibabu')
                ->count();

            $totalReferralsByParsPlanaVitrotomy = DB::table('referrals')
                ->join('reasons', 'reasons.reason_id', '=', 'referrals.reason_id')
                ->whereNull('referrals.deleted_at')
                ->where('reasons.referral_reason_name', '=', 'Pars Plana Vitrotomy')
                ->count();

            return response([
                'totalReferralsByKufanyiwaUchunguzi' => $totalReferralsByKufanyiwaUchunguzi,
                'totalReferralsByKupatiwaMatibabu' => $totalReferralsByKupatiwaMatibabu,
                'totalReferralsByUchunguziNaMatibabuZaidi' => $totalReferralsByUchunguziNaMatibabuZaidi,
                'totalReferralsByUchunguziNaMatibabu' => $totalReferralsByUchunguziNaMatibabu,
                'totalReferralsByParsPlanaVitrotomy' => $totalReferralsByParsPlanaVitrotomy,
            ]);
        } catch (\Throwable $e) {
            return Helper::serverError($e, 'Unable to load dashboard totals.');
        }
    }

    // DASHBOARD ========================================================================//

    // DASHBOARD ========================================================================//

    public function referralsReportByGendr()
    {
        $user = auth()->user();

        if (! $user->can('View Referral Dashboard')) {
            return response(['message' => 'Forbidden', 'statusCode' => 403], 403);
        }

        try {
            $referralsByGender = DB::table('referrals')
                ->join('patients', 'patients.patient_id', '=', 'referrals.patient_id')
                ->whereNull('referrals.deleted_at')
                // Match the PascalCase 'Confirmed' from your migration
                ->whereNotIn('referrals.status', ['Cancelled', 'Pending', 'Requested'])
                ->select(
                    DB::raw('LOWER(patients.gender) as gender'),
                    DB::raw('COUNT(referrals.referral_id) as total')
                )
                ->groupBy(DB::raw('LOWER(patients.gender)'))
                ->get();

            $genderStats = $referralsByGender->pluck('total', 'gender')->toArray();

            // Handle various gender string formats (m, male, f, female)
            $femaleCount = ($genderStats['female'] ?? 0) + ($genderStats['f'] ?? 0);
            $maleCount = ($genderStats['male'] ?? 0) + ($genderStats['m'] ?? 0);

            return response([
                'Male' => $maleCount,
                'Female' => $femaleCount,
                'statusCode' => 200,
            ], 200);

        } catch (\Throwable $e) {
            return Helper::serverError($e, 'Unable to generate the gender report.');
        }
    }

    // DASHBOARD ========================================================================//

    // DASHBOARD ========================================================================//

    public function referralReportByHospital()
    {
        $user = auth()->user();

        if (! $user->can('View Referral Dashboard')) {
            return response(['message' => 'Forbidden', 'statusCode' => 403], 403);
        }

        try {
            // 1. Get counts grouped by name in one query
            $reportData = DB::table('referrals')
                ->join('hospitals', 'hospitals.hospital_id', '=', 'referrals.hospital_id')
                ->whereNull('referrals.deleted_at')
                ->select('hospitals.hospital_name', DB::raw('count(*) as total'))
                ->groupBy('hospitals.hospital_name')
                ->get()
                ->pluck('total', 'hospital_name');

            // 2. Define the exact keys your frontend expects
            // This ensures "SIMS" stays 0 if not found in the query results
            $response = [
                'totalReferralsByLumumba' => $reportData->get('LUMUMBA', 0),
                'totalReferralsByMuhimbiliOrthopaedicInstitute' => $reportData->get('Muhimbili Orthopaedic Institute (MOI)', 0),
                'totalReferralsByJakayaKikweteCardiacInstitute' => $reportData->get('Jakaya Kikwete Cardiac Institute (JKCI)', 0),
                'totalReferralsBySIMS' => $reportData->get('SIMS', 0),
                'totalReferralsByMuhimbiliNationalHospital' => $reportData->get('Muhimbili National Hospital (MNH)', 0),
                'totalReferralsByOceanRoadCancerInstitute' => $reportData->get('Ocean Road Cancer Institute (ORCI)', 0),
                'totalReferralsByKilimanjaroChristianMedicalCentre' => $reportData->get('Kilimanjaro Christian Medical Centre (KCMC)', 0),
                'totalReferralsByMadrasInstituteOfOrthopaedicsAndTraumatology' => $reportData->get('Madras Institute of Orthopaedics and Traumatology (MIOT)', 0),
            ];

            return response($response);

        } catch (\Throwable $e) {
            return Helper::serverError($e, 'Unable to generate the hospital report.');
        }
    }

    // public function getMonthlyMaleAndFemaleReferralReport()
    // {
    //     $user = auth()->user();
    //     if (!$user->can('View Referral Dashboard')) {
    //         return response(['message' => 'Forbidden', 'statusCode' => 403], 403);
    //     }

    //     $data = DB::table('referrals')
    //         ->join('patients', 'referrals.patient_id', '=', 'patients.patient_id')
    //         // Match the exclusion logic used in your overall counts
    //         ->whereNotIn('referrals.status', ['Pending', 'Cancelled', 'Requested'])
    //         ->whereNull('referrals.deleted_at')
    //         ->select(
    //             DB::raw("TO_CHAR(referrals.created_at, 'YYYY-MM') as month"),
    //             // Use LOWER() to ensure 'Male' and 'male' are both counted
    //             DB::raw("SUM(CASE WHEN LOWER(patients.gender) IN ('male', 'm') THEN 1 ELSE 0 END) as male_referrals"),
    //             DB::raw("SUM(CASE WHEN LOWER(patients.gender) IN ('female', 'f') THEN 1 ELSE 0 END) as female_referrals"),
    //             DB::raw('COUNT(referrals.referral_id) as total_referrals')
    //         )
    //         ->groupBy(DB::raw("TO_CHAR(referrals.created_at, 'YYYY-MM')"))
    //         ->orderBy(DB::raw("TO_CHAR(referrals.created_at, 'YYYY-MM')"))
    //         ->get();

    //     return response()->json([
    //         'data' => $data,
    //         'statusCode' => 200,
    //     ]);
    // }
    public function getMonthlyMaleAndFemaleReferralReport()
    {
        $user = auth()->user();

        if (! $user->can('View Referral Dashboard')) {
            return response(['message' => 'Forbidden', 'statusCode' => 403], 403);
        }

        $year = date('Y');

        // 1. Get raw DB aggregation
        $raw = DB::table('referrals')
            ->join('patients', 'referrals.patient_id', '=', 'patients.patient_id')
            ->whereNotIn('referrals.status', ['Pending', 'Cancelled', 'Requested'])
            ->whereNull('referrals.deleted_at')
            ->whereYear('referrals.created_at', $year)
            ->select(
                DB::raw("TO_CHAR(referrals.created_at, 'YYYY-MM') as month"),
                DB::raw("SUM(CASE WHEN LOWER(patients.gender) IN ('male', 'm') THEN 1 ELSE 0 END) as male_referrals"),
                DB::raw("SUM(CASE WHEN LOWER(patients.gender) IN ('female', 'f') THEN 1 ELSE 0 END) as female_referrals"),
                DB::raw('COUNT(referrals.referral_id) as total_referrals')
            )
            ->groupBy(DB::raw("TO_CHAR(referrals.created_at, 'YYYY-MM')"))
            ->orderBy(DB::raw("TO_CHAR(referrals.created_at, 'YYYY-MM')"))
            ->get()
            ->keyBy('month');

        // 2. Build full 12-month structure
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $key = $year.'-'.str_pad($m, 2, '0', STR_PAD_LEFT);

            $months[] = [
                'month' => $key,
                'male_referrals' => $raw[$key]->male_referrals ?? 0,
                'female_referrals' => $raw[$key]->female_referrals ?? 0,
                'total_referrals' => $raw[$key]->total_referrals ?? 0,
            ];
        }

        return response()->json([
            'data' => $months,
            'statusCode' => 200,
        ]);
    }

    // DASHBOARD ========================================================================//

    // PRINTABLE REPORT ========================================================================//

    public function showEverythingByReferralId(int $id)
    {
        $user = auth()->user();

        if (! $user->can('View Referral')) {
            return response()->json([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $referral = \App\Models\Referral::with([
            // =======================
            // Patient & Deep Relations
            // =======================
            'patient' => function ($query) {
                $query->with([
                    'geographicalLocation',
                    'files',
                    'patientList.boardMembers',

                    // Patient histories
                    'patientHistories' => function ($q) {
                        $q->with([
                            'diagnoses',
                            'boardDiagnoses',
                            'reason',
                            'boardReason',
                        ]);
                    },

                    // NEW: Followups
                    'followups',

                    // NEW: Insurances
                    'insurances',
                ]);
            },

            // =======================
            // Referral Relations
            // =======================
            'hospital',
            'hospitalLetters',
            'referralLetters',
            'parent',
            'children',
            'confirmedBy',
            'creator',

            // =======================
            // Bills (FULL TREE)
            // =======================
            'bills' => function ($billQuery) {
                $billQuery->with([
                    // Bill file (PDF / attachment)
                    'billFile',

                    // Bill items
                    'billItems',

                    // Payments with pivot data
                    'payments' => function ($paymentQuery) {
                        $paymentQuery->withPivot([
                            'allocated_amount',
                            'allocation_date',
                            'status',
                        ]);
                    },
                ]);
            },
        ])
            ->where('referral_id', $id)
            ->first();

        if (! $referral) {
            return response()->json([
                'message' => 'Referral not found',
                'statusCode' => 404,
            ], 404);
        }

        $history = $referral->patientHistory()->where('patient_id', $referral->patient_id)
            ->with(['diagnoses', 'boardDiagnoses', 'reason', 'boardReason'])->first();
        $referral->case_history = $history;
        $referral->history_id = $history?->patient_histories_id;
        $referral->case_link_resolved = $history !== null;
        if ($referral->patient) {
            $referral->patient->setRelation('patientHistories', collect($history ? [$history] : []));
        }

        return response()->json([
            'data' => $referral,
            'statusCode' => 200,
        ], 200);
    }

    public function referralReport(int $patientId)
    {
        $user = auth()->user();
        if (! $user->can('View Report')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $referrals = DB::table('referrals')
            ->join('hospitals', 'hospitals.hospital_id', '=', 'referrals.hospital_id')
            ->join('patients', 'patients.patient_id', '=', 'referrals.patient_id')
            ->select(
                'referrals.*',
                'hospitals.*',
                'patients.*',
            )
            ->where('patients.patient_id', '=', $patientId)
            ->get();

        if ($referrals->isEmpty()) {
            return response([
                'message' => 'No data found',
                'statusCode' => 200,
            ], 200);
        } else {
            return response([
                'data' => $referrals,
                'statusCode' => 200,
            ]);
        }

    }

    public function getBillsBetweenDates(Request $request)
    {
        $user = auth()->user();
        if (! $user->can('View Report')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $startDate = $validated['start_date'];
        $endDate = $validated['end_date'];

        // Fetch bills with joined data
        $bills = DB::table('bills')
            ->join('referrals', 'referrals.referral_id', '=', 'bills.referral_id')
            ->join('patients', 'patients.patient_id', '=', 'referrals.patient_id')
            ->join('hospitals', 'hospitals.hospital_id', '=', 'referrals.hospital_id')
            ->whereBetween('bills.sent_date', [$startDate, $endDate])
            ->select(
                'bills.bill_id',
                'bills.amount',
                'bills.sent_date',
                'bills.bill_status',
                'bills.bill_file',
                'patients.patient_id',
                'patients.name as patient_name',
                'patients.date_of_birth',
                'patients.gender',
                'patients.phone',
                'patients.location',
                'patients.job',
                'patients.position',
                'hospitals.hospital_id',
                'hospitals.hospital_name',
                'hospitals.hospital_code',
                'hospitals.hospital_address',
                'hospitals.contact_number',
                'hospitals.hospital_email'
            )
            ->get();

        // Attach payments to each bill
        $result = $bills->map(function ($bill) {
            $payments = DB::table('payments')
                ->where('bill_id', $bill->bill_id)
                ->select('payment_id', 'amount_paid', 'payment_method', 'created_at as payment_date')
                ->get();

            return [
                'bill_id' => $bill->bill_id,
                'amount' => $bill->amount,
                'sent_date' => $bill->sent_date,
                'bill_status' => $bill->bill_status,
                'bill_file' => $bill->bill_file,
                'patient' => [
                    'patient_id' => $bill->patient_id,
                    'name' => $bill->patient_name,
                    'date_of_birth' => $bill->date_of_birth,
                    'gender' => $bill->gender,
                    'phone' => $bill->phone,
                    'location' => $bill->location,
                    'job' => $bill->job,
                    'position' => $bill->position,
                ],
                'hospital' => [
                    'hospital_id' => $bill->hospital_id,
                    'hospital_name' => $bill->hospital_name,
                    'hospital_code' => $bill->hospital_code,
                    'hospital_address' => $bill->hospital_address,
                    'contact_number' => $bill->contact_number,
                    'hospital_email' => $bill->hospital_email,
                ],
                'payments' => $payments,
            ];
        });

        return response([
            'data' => $result,
            'statusCode' => 200,
        ]);
    }

    // public function searchReferralReport(Request $request)
    // {
    //     // Permission check
    //     $user = auth()->user();
    //     if (! $user->can('View Report')) {
    //         return response([
    //             'message' => 'Forbidden',
    //             'statusCode' => 403,
    //         ], 403);
    //     }

    //     $query = DB::table('referrals')
    //         ->join('patients', 'patients.patient_id', '=', 'referrals.patient_id')
    //         ->join('hospitals', 'hospitals.hospital_id', '=', 'referrals.hospital_id')
    //         ->join('reasons', 'reasons.reason_id', '=', 'referrals.reason_id')
    //         ->leftJoin('insurances', 'insurances.patient_id', '=', 'patients.patient_id')
    //         ->leftJoin('referral_letters', 'referral_letters.referral_id', '=', 'referrals.referral_id')

    //         ->select(
    //             'referrals.referral_id',
    //             'referrals.created_at',
    //             'referrals.status as referral_status',

    //             'patients.patient_id',
    //             'patients.name as patient_name',

    //             'hospitals.hospital_name',
    //             'hospitals.hospital_address',

    //             'reasons.referral_reason_name',

    //             'insurances.insurance_provider_name',

    //             // Referral letter dates
    //             'referral_letters.start_date',
    //             'referral_letters.end_date'
    //         )

    //         ->selectRaw("
    //             (
    //                 SELECT STRING_AGG(DISTINCT d.diagnosis_name, ', ')
    //                 FROM patient_histories ph
    //                 JOIN history_diagnosis hd
    //                     ON hd.patient_histories_id = ph.patient_histories_id
    //                 JOIN diagnoses d
    //                     ON d.diagnosis_id = hd.diagnosis_id
    //                 WHERE ph.patient_id = patients.patient_id
    //                     AND hd.added_by = 'medical_board'
    //             ) AS board_diagnoses
    //         ");

    //     // Filters
    //     if ($request->filled('patient_name')) {
    //         $query->where('patients.name', 'ILIKE', '%'.$request->patient_name.'%');
    //     }

    //     if ($request->filled('hospital_name')) {
    //         $query->where('hospitals.hospital_name', 'ILIKE', '%'.$request->hospital_name.'%');
    //     }

    //     if ($request->filled('hospital_address')) {
    //         $query->where('hospitals.hospital_address', 'ILIKE', '%'.$request->hospital_address.'%');
    //     }

    //     if ($request->filled('referral_reason_name')) {
    //         $query->where('reasons.referral_reason_name', 'ILIKE', '%'.$request->referral_reason_name.'%');
    //     }

    //     // Filter by referral letter dates (DG referral period)
    //     if ($request->filled('start_date') && $request->filled('end_date')) {
    //         $query->whereBetween('referral_letters.start_date', [
    //             $request->start_date,
    //             $request->end_date,
    //         ]);
    //     }

    //     $results = $query->get();

    //     foreach ($results as $result) {

    //         $result->board_diagnoses = DB::table('patient_histories as ph')
    //             ->join('history_diagnosis as hd', 'hd.patient_histories_id', '=', 'ph.patient_histories_id')
    //             ->join('diagnoses as d', 'd.diagnosis_id', '=', 'hd.diagnosis_id')
    //             ->where('ph.patient_id', $result->patient_id)
    //             ->where('hd.added_by', 'medical_board')
    //             ->select(
    //                 'd.diagnosis_id',
    //                 'd.diagnosis_code',
    //                 'd.diagnosis_name'
    //             )
    //             ->distinct()
    //             ->get();
    //     }

    //     return response([
    //         'data' => $results,
    //         'statusCode' => 200,
    //     ], 200);
    // }

    public function searchReferralReport(Request $request)
{
    // Permission check
    $user = auth()->user();

    if (! $user->can('View Report')) {
        return response([
            'message' => 'Forbidden',
            'statusCode' => 403,
        ], 403);
    }

    $query = DB::table('referrals as r')

        /*
        |--------------------------------------------------------------------------
        | PATIENT
        |--------------------------------------------------------------------------
        */
        ->join(
            'patients as p',
            'p.patient_id',
            '=',
            'r.patient_id'
        )

        /*
        |--------------------------------------------------------------------------
        | FROM HOSPITAL
        |--------------------------------------------------------------------------
        |
        | The FROM hospital is obtained from the user who created
        | the referral:
        |
        | referrals.created_by
        |        ↓
        | hospital_user.user_id
        |        ↓
        | hospital_user.hospital_id
        |        ↓
        | hospitals.hospital_id
        |
        | Only hospitals with referral_type_id = 3 are FROM hospitals.
        |
        | parent_referral_id is NOT used.
        |
        */
        ->leftJoin(
            'hospital_user as from_hospital_user',
            'from_hospital_user.user_id',
            '=',
            'r.created_by'
        )

        ->leftJoin(
            'hospitals as from_hospital',
            function ($join) {
                $join->on(
                    'from_hospital.hospital_id',
                    '=',
                    'from_hospital_user.hospital_id'
                )
                ->where(
                    'from_hospital.referral_type_id',
                    '=',
                    3
                );
            }
        )

        /*
        |--------------------------------------------------------------------------
        | TO HOSPITAL
        |--------------------------------------------------------------------------
        |
        | The TO hospital comes from referrals.hospital_id.
        |
        | Only hospitals with referral_type_id 1 or 2 are TO hospitals.
        |
        */
        ->leftJoin(
            'hospitals as to_hospital',
            function ($join) {
                $join->on(
                    'to_hospital.hospital_id',
                    '=',
                    'r.hospital_id'
                )
                ->whereIn(
                    'to_hospital.referral_type_id',
                    [1, 2]
                );
            }
        )

        /*
        |--------------------------------------------------------------------------
        | REASON
        |--------------------------------------------------------------------------
        */
        ->join(
            'reasons',
            'reasons.reason_id',
            '=',
            'r.reason_id'
        )

        /*
        |--------------------------------------------------------------------------
        | INSURANCE
        |--------------------------------------------------------------------------
        */
        ->leftJoin(
            'insurances',
            'insurances.patient_id',
            '=',
            'p.patient_id'
        )

        /*
        |--------------------------------------------------------------------------
        | REFERRAL LETTER
        |--------------------------------------------------------------------------
        */
        ->leftJoin(
            'referral_letters as rl',
            'rl.referral_id',
            '=',
            'r.referral_id'
        )

        /*
        |--------------------------------------------------------------------------
        | SELECT
        |--------------------------------------------------------------------------
        */
        ->select(
            'r.referral_id',
            'r.parent_referral_id',
            'r.referral_number',
            'r.created_at',
            'r.status as referral_status',

            'p.patient_id',
            'p.name as patient_name',

            /*
            |--------------------------------------------------------------------------
            | FROM HOSPITAL
            |--------------------------------------------------------------------------
            |
            | These are aliases generated by the query.
            | They are NOT database columns.
            |
            */
            'from_hospital.hospital_id as from_hospital_id',
            'from_hospital.hospital_name as from_hospital_name',
            'from_hospital.hospital_address as from_hospital_address',

            /*
            |--------------------------------------------------------------------------
            | TO HOSPITAL
            |--------------------------------------------------------------------------
            */
            'to_hospital.hospital_id as to_hospital_id',
            'to_hospital.hospital_name as to_hospital_name',
            'to_hospital.hospital_address as to_hospital_address',

            'reasons.referral_reason_name',

            'insurances.insurance_provider_name',

            'rl.start_date',
            'rl.end_date'
        )

        /*
        |--------------------------------------------------------------------------
        | BOARD DIAGNOSES
        |--------------------------------------------------------------------------
        */
        ->selectRaw("
            (
                SELECT STRING_AGG(
                    DISTINCT d.diagnosis_name,
                    ', '
                )
                FROM patient_histories ph
                JOIN history_diagnosis hd
                    ON hd.patient_histories_id = ph.patient_histories_id
                JOIN diagnoses d
                    ON d.diagnosis_id = hd.diagnosis_id
                WHERE ph.patient_id = p.patient_id
                    AND hd.added_by = 'medical_board'
            ) AS board_diagnoses
        ");

    /*
    |--------------------------------------------------------------------------
    | ONLY TO-HOSPITAL REFERRALS
    |--------------------------------------------------------------------------
    |
    | The main referral must point to a hospital whose type is 1 or 2.
    |
    */
    $query->whereIn(
        'to_hospital.referral_type_id',
        [1, 2]
    );

    /*
    |--------------------------------------------------------------------------
    | FILTERS
    |--------------------------------------------------------------------------
    */

    if ($request->filled('patient_name')) {
        $query->where(
            'p.name',
            'ILIKE',
            '%' . $request->patient_name . '%'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FROM HOSPITAL NAME
    |--------------------------------------------------------------------------
    */
    if ($request->filled('from_hospital_name')) {
        $query->where(
            'from_hospital.hospital_name',
            'ILIKE',
            '%' . $request->from_hospital_name . '%'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FROM HOSPITAL ADDRESS
    |--------------------------------------------------------------------------
    */
    if ($request->filled('from_hospital_address')) {
        $query->where(
            'from_hospital.hospital_address',
            'ILIKE',
            '%' . $request->from_hospital_address . '%'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | TO HOSPITAL NAME
    |--------------------------------------------------------------------------
    */
    if ($request->filled('to_hospital_name')) {
        $query->where(
            'to_hospital.hospital_name',
            'ILIKE',
            '%' . $request->to_hospital_name . '%'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | TO HOSPITAL ADDRESS
    |--------------------------------------------------------------------------
    */
    if ($request->filled('to_hospital_address')) {
        $query->where(
            'to_hospital.hospital_address',
            'ILIKE',
            '%' . $request->to_hospital_address . '%'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | REFERRAL REASON
    |--------------------------------------------------------------------------
    */
    if ($request->filled('referral_reason_name')) {
        $query->where(
            'reasons.referral_reason_name',
            'ILIKE',
            '%' . $request->referral_reason_name . '%'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DATE RANGE
    |--------------------------------------------------------------------------
    */
    if (
        $request->filled('start_date') &&
        $request->filled('end_date')
    ) {
        $query->whereBetween(
            'rl.start_date',
            [
                $request->start_date,
                $request->end_date,
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | GET RESULTS
    |--------------------------------------------------------------------------
    */
    $results = $query
        ->orderByDesc('r.created_at')
        ->get();

    /*
    |--------------------------------------------------------------------------
    | BOARD DIAGNOSES DETAILS
    |--------------------------------------------------------------------------
    */
    foreach ($results as $result) {

        $result->board_diagnoses = DB::table(
            'patient_histories as ph'
        )
            ->join(
                'history_diagnosis as hd',
                'hd.patient_histories_id',
                '=',
                'ph.patient_histories_id'
            )
            ->join(
                'diagnoses as d',
                'd.diagnosis_id',
                '=',
                'hd.diagnosis_id'
            )
            ->where(
                'ph.patient_id',
                $result->patient_id
            )
            ->where(
                'hd.added_by',
                'medical_board'
            )
            ->select(
                'd.diagnosis_id',
                'd.diagnosis_code',
                'd.diagnosis_name'
            )
            ->distinct()
            ->get();
    }

    return response([
        'data' => $results,
        'statusCode' => 200,
    ], 200);
}

    public function rangeReport(Request $request)
    {
        // Permission check
        $user = auth()->user();
        if (! $user->can('View Report')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        // Validate request input
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        // Query hospital billing/payment report
        $report = DB::table('hospitals')
            ->join('bill_files', 'hospitals.hospital_id', '=', 'bill_files.hospital_id')
            ->join('bills', 'bill_files.bill_file_id', '=', 'bills.bill_file_id')
            ->leftJoin('bill_payments', 'bills.bill_id', '=', 'bill_payments.bill_id')
            ->leftJoin('payments', 'bill_payments.payment_id', '=', 'payments.payment_id')
            ->select(
                'hospitals.hospital_name',
                DB::raw('COUNT(DISTINCT bills.bill_id) as total_bills'),
                DB::raw("SUM(CASE WHEN bills.bill_status = 'Paid' THEN 1 ELSE 0 END) as paid_bills"),
                DB::raw("SUM(CASE WHEN bills.bill_status = 'Pending' THEN 1 ELSE 0 END) as pending_bills"),
                DB::raw('SUM(bills.total_amount) as total_amount'),
                DB::raw("SUM(CASE WHEN bills.bill_status = 'Paid' THEN bills.total_amount ELSE 0 END) as paid_amount"),
                DB::raw("SUM(CASE WHEN bills.bill_status = 'Pending' THEN bills.total_amount ELSE 0 END) as pending_amount")
            )
            ->whereBetween('bills.bill_period_start', [$startDate, $endDate])
            ->groupBy('hospitals.hospital_name')
            ->orderBy('hospitals.hospital_name')
            ->get();

        return response()->json([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'data' => $report,
        ]);
    }

    public function referralStatusReport()
    {
        // Permission check
        $user = auth()->user();
        if (! $user->can('View Report')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $report = DB::table('referrals')
            ->select(
                DB::raw('COUNT(CASE WHEN status = "Confirmed" THEN 1 END) as confirmed'),
                DB::raw('COUNT(CASE WHEN status = "Cancelled" THEN 1 END) as cancelled'),
                DB::raw('COUNT(CASE WHEN status = "Expired" THEN 1 END) as expired'),
                DB::raw('COUNT(CASE WHEN status = "Closed" THEN 1 END) as closed')
            )
            ->first();

        return response()->json($report);
    }

    public function timelyReport(Request $request)
    {
        // Permission check
        $user = auth()->user();
        if (! $user->can('View Report')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $period = $request->input('period'); // daily, weekly, monthly, yearly

        $query = DB::table('bills');

        switch ($period) {
            case 'daily':
                $query->select(
                    DB::raw('DATE(bill_period_start) as period'),
                    DB::raw('COUNT(bill_id) as total_bills'),
                    DB::raw('SUM(total_amount) as total_amount')
                )->groupBy(DB::raw('DATE(bill_period_start)'));
                break;

            case 'weekly':
                $query->select(
                    DB::raw('YEARWEEK(bill_period_start, 1) as period'),
                    DB::raw('COUNT(bill_id) as total_bills'),
                    DB::raw('SUM(total_amount) as total_amount')
                )->groupBy(DB::raw('YEARWEEK(bill_period_start, 1)'));
                break;

            case 'monthly':
                $query->select(
                    DB::raw('DATE_FORMAT(bill_period_start, "%Y-%m") as period'),
                    DB::raw('COUNT(bill_id) as total_bills'),
                    DB::raw('SUM(total_amount) as total_amount')
                )->groupBy(DB::raw('DATE_FORMAT(bill_period_start, "%Y-%m")'));
                break;

            case 'yearly':
                $query->select(
                    DB::raw('YEAR(bill_period_start) as period'),
                    DB::raw('COUNT(bill_id) as total_bills'),
                    DB::raw('SUM(total_amount) as total_amount')
                )->groupBy(DB::raw('YEAR(bill_period_start)'));
                break;
        }

        $report = $query->get();

        return response()->json($report);
    }

    public function patientsReport()
    {
        // Permission check
        $user = auth()->user();
        if (! $user->can('View Report')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $report = DB::table('patients')
            ->select(
                DB::raw('COUNT(CASE WHEN gender = "Male" THEN 1 END) as male'),
                DB::raw('COUNT(CASE WHEN gender = "Female" THEN 1 END) as female')
            )
            ->first();

        return response()->json($report);
    }

    public function systemOverviewReport()
    {
        $user = auth()->user();

        if (! $user->can('View Referral Dashboard')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        try {

            /**
             * =========================
             * 1. BASIC COUNTS
             * =========================
             */
            $totalPatients = DB::table('patients')->count();

            $totalReferrals = DB::table('referrals')
                ->whereNull('deleted_at')
                ->count();

            /**
             * =========================
             * 2. REFERRAL STATUS BREAKDOWN
             * =========================
             */
            $statusCounts = DB::table('referrals')
                ->selectRaw("
                    COUNT(CASE WHEN status = 'Pending' THEN 1 END) as pending,
                    COUNT(CASE WHEN status = 'Reviewed' THEN 1 END) as reviewed,
                    COUNT(CASE WHEN status = 'Assigned' THEN 1 END) as assigned,
                    COUNT(CASE WHEN status = 'Requested' THEN 1 END) as requested,
                    COUNT(CASE WHEN status = 'Approved' THEN 1 END) as approved,
                    COUNT(CASE WHEN status = 'Confirmed' THEN 1 END) as confirmed,
                    COUNT(CASE WHEN status = 'BoardedOut' THEN 1 END) as boarded_out,
                    COUNT(CASE WHEN status IN ('Cancelled','Rejected') THEN 1 END) as cancelled_rejected
                ")
                ->whereNull('deleted_at')
                ->first();

            /**
             * =========================
             * 3. GENDER BREAKDOWN (REFERRALS)
             * =========================
             */
            $gender = DB::table('referrals')
                ->join('patients', 'patients.patient_id', '=', 'referrals.patient_id')
                ->whereNotNull('referrals.patient_id')
                ->whereNull('referrals.deleted_at')
                ->selectRaw("
                    SUM(CASE WHEN LOWER(patients.gender) IN ('male','m') THEN 1 ELSE 0 END) as male_referrals,
                    SUM(CASE WHEN LOWER(patients.gender) IN ('female','f') THEN 1 ELSE 0 END) as female_referrals
                ")
                ->first();

            /**
             * =========================
             * 4. FOLLOWUPS COUNT
             * =========================
             */
            $followups = DB::table('followups')
                ->distinct('referral_id')
                ->count('referral_id');

            /**
             * =========================
             * 5. REFERRALS BY HOSPITAL (NEW)
             * =========================
             */
            $referralsByHospital = DB::table('referrals')
                ->join('hospitals', 'hospitals.hospital_id', '=', 'referrals.hospital_id')
                ->whereNull('referrals.deleted_at')
                ->selectRaw('hospitals.hospital_name, COUNT(referrals.referral_id) as total')
                ->groupBy('hospitals.hospital_name')
                ->orderByDesc('total')
                ->get();

            /**
             * =========================
             * RESPONSE
             * =========================
             */
            return response()->json([
                'data' => [
                    'total_patients' => $totalPatients,
                    'total_referrals' => $totalReferrals,

                    'referrals_by_status' => [
                        'pending' => (int) $statusCounts->pending,
                        'reviewed' => (int) $statusCounts->reviewed,
                        'assigned' => (int) $statusCounts->assigned,
                        'requested' => (int) $statusCounts->requested,
                        'approved' => (int) $statusCounts->approved,
                        'confirmed' => (int) $statusCounts->confirmed,
                        'boarded_out' => (int) $statusCounts->boarded_out,
                        'cancelled_rejected' => (int) $statusCounts->cancelled_rejected,
                    ],

                    'referrals_by_gender' => [
                        'male' => (int) $gender->male_referrals,
                        'female' => (int) $gender->female_referrals,
                    ],

                    'referrals_with_followups' => $followups,

                    // ✅ ADDED HERE
                    'referrals_by_hospital' => $referralsByHospital,
                ],
                'statusCode' => 200,
            ], 200);

        } catch (\Throwable $e) {
            return Helper::serverError($e, 'Unable to generate the referral report.');
        }
    }

    // PRINTABLE REPORT ========================================================================//

    /**
     * Small dashboard endpoint used by the case-status chart.
     *
     * Keep this separate from the complete workflow report because the chart
     * only needs patient-history counts. Loading referral aggregates here made
     * the first dashboard chart wait for an unrelated query.
     */
    public function caseStatusTracking(Request $request, CaseReport $cases)
    {
        $user = auth()->user();

        if (! $user->can('View Referral Dashboard')) {
            return response()->json([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $filters = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'before_or_equal:today', ...($request->filled('start_date') ? ['after_or_equal:start_date'] : [])],
            'source_hospital_ids' => ['nullable', 'array'],
            'source_hospital_ids.*' => ['integer', 'exists:hospitals,hospital_id'],
            'patient_history_status' => ['nullable', 'in:pending,reviewed,assigned,requested,approved,confirmed,boarded_out,rejected,under_review'],
            'include_archived' => ['sometimes', 'boolean'],
            'refresh' => ['sometimes', 'boolean'],
        ]);
        $cacheKey = 'dashboard.case-status-tracking.v2.'.$user->id.'.'.hash('sha256', json_encode(array_diff_key($filters, ['refresh' => true])));

        try {
            if ($request->boolean('refresh')) {
                Cache::forget($cacheKey);
            }
            $report = Cache::remember(
                $cacheKey,
                now()->addSeconds(15),
                fn (): array => [
                    'medical_history' => $cases->summary($filters, $user),
                    'generated_at' => now()->toIso8601String(),
                    'date_basis' => 'Case submission date',
                    'include_archived' => $request->boolean('include_archived'),
                ]
            );

            return response()->json([
                'data' => $report,
                'statusCode' => 200,
            ]);
        } catch (\Throwable $e) {
            return Helper::serverError($e, 'Failed to load case status tracking.');
        }
    }

    public function workflowStatusReport()
    {
        $user = auth()->user();

        if (! $user->can('View Referral Dashboard')) {
            return response()->json([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        try {

            $report = Cache::remember(
                'dashboard.workflow-status-report.v5',
                now()->addSeconds(15),
                function (): array {
                    // patient_histories.status is now the canonical case
                    // status. Referral and boarded-out records are related
                    // details and are not joined into this case count.
                    $medicalHistory = $this->buildCaseStatusTrackingReport();

                    $referrals = DB::table('referrals')
                        ->whereNull('deleted_at')
                        ->selectRaw("
                            COUNT(*) as total,
                            COUNT(CASE WHEN status='Confirmed' THEN 1 END) as confirmed,
                            COUNT(CASE WHEN status='Cancelled' THEN 1 END) as cancelled,
                            COUNT(CASE WHEN status='Closed' THEN 1 END) as closed,
                            COUNT(CASE WHEN status='Transferred' THEN 1 END) as transferred,
                            COUNT(CASE WHEN status='Death' THEN 1 END) as death,
                            COUNT(CASE WHEN status='Expired' THEN 1 END) as expired,
                            COUNT(CASE WHEN status='BoardedOut' THEN 1 END) as boarded_out
                        ")
                        ->first();

                    return [
                        'medical_history' => $medicalHistory,
                        'referrals' => [
                            'total' => (int) $referrals->total,
                            'statuses' => [
                                ['stage' => 'Confirmed', 'count' => (int) $referrals->confirmed],
                                ['stage' => 'Cancelled', 'count' => (int) $referrals->cancelled],
                                ['stage' => 'Closed', 'count' => (int) $referrals->closed],
                                ['stage' => 'Transferred', 'count' => (int) $referrals->transferred],
                                ['stage' => 'Death', 'count' => (int) $referrals->death],
                                ['stage' => 'Expired', 'count' => (int) $referrals->expired],
                                ['stage' => 'Boarded Out', 'count' => (int) $referrals->boarded_out],
                            ],
                        ],
                    ];
                }
            );

            return response()->json([
                'data' => $report,
                'statusCode' => 200,
            ]);

        } catch (\Throwable $e) {
            return Helper::serverError($e, 'Failed to generate workflow report.');
        }
    }

    private function buildCaseStatusTrackingReport(): array
    {
        $medicalBoardCounts = DB::table('patient_histories')
            ->whereNull('deleted_at')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $patientStatusTracking = collect(PatientHistory::STATUS_MAP)
            ->map(function (array $tracking, string $status) use ($medicalBoardCounts) {
                return [
                    'status' => $status,
                    'stage' => $tracking['stage'],
                    'label' => $tracking['label'],
                    'current_holder' => $tracking['current_holder'],
                    'description' => $tracking['description'],
                    'progress_percentage' => (int) round(($tracking['stage'] / 6) * 100),
                    'count' => (int) ($medicalBoardCounts[$status] ?? 0),
                ];
            })
            ->values();

        return [
            'total' => (int) $medicalBoardCounts->sum(),
            'statuses' => $patientStatusTracking,
        ];
    }
}
