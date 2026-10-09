<?php

use App\Http\Controllers\API\BillFiles\BillFileController;
use App\Http\Controllers\API\BillItems\BillItemController;
use App\Http\Controllers\API\BillPayments\BillPaymentController;
use App\Http\Controllers\API\Bills\BillController;
use App\Http\Controllers\API\Followups\FollowupController;
use App\Http\Controllers\API\HospitalLetters\HospitalLetterController;
use App\Http\Controllers\API\Letters\LetterBrandingController;
use App\Http\Controllers\API\Letters\LetterDocumentController;
use App\Http\Controllers\API\Hospitals\HospitalController;
use App\Http\Controllers\API\Insurances\InsuranceController;
use App\Http\Controllers\API\Patients\MedicalBoadController;
use App\Http\Controllers\API\Patients\PatientController;
use App\Http\Controllers\API\Patients\PatientHistoryController;
use App\Http\Controllers\API\Patients\PatientListController;
use App\Http\Controllers\API\Payments\PaymentController;
use App\Http\Controllers\API\Reasons\ReasonController;
use App\Http\Controllers\API\ReferralLetters\ReferralLettersController;
use App\Http\Controllers\API\Referrals\ReferralController;
use App\Http\Controllers\API\Referrals\ReferralFlightController;
use App\Http\Controllers\API\ReferralType\ReferralTypeController;
use App\Http\Controllers\API\Report\ReportController;
use App\Http\Controllers\API\Report\ReportingController;
use App\Http\Controllers\API\Setup\DiagnosisController;
use App\Http\Controllers\API\Treatments\TreatmentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::post('login', [App\Http\Controllers\API\Auth\AuthController::class, 'login'])
    ->middleware('throttle:login');

Route::post('forgot-password', [App\Http\Controllers\API\User\UserProfileCotroller::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('reset-forgot-password', [App\Http\Controllers\API\User\UserProfileCotroller::class, 'forgotPasswordReset'])->middleware('throttle:5,1');

Route::middleware(['auth:sanctum', 'not.blocked'])->group(function () {

    Route::get('checkPassword', [App\Http\Controllers\API\User\UserProfileCotroller::class, 'index'])->name('checkPassword');
    Route::post('changePassword', [App\Http\Controllers\API\User\UserProfileCotroller::class, 'change_password'])->name('changePassword');
    Route::post('resetPassword', [App\Http\Controllers\API\User\UserProfileCotroller::class, 'resetPassword'])->name('resetPassword');
    // Kept as a compatibility alias; both endpoints use the protected,
    // paginated audit-log implementation.
    Route::get('logsFunction', [App\Http\Controllers\API\AuditLogController::class, 'index'])->name('logsFunction');
    Route::get('audit-logs', [App\Http\Controllers\API\AuditLogController::class, 'index']);
    Route::get('audit-logs/{id}', [App\Http\Controllers\API\AuditLogController::class, 'show']);

    Route::resource('uploadTypes', App\Http\Controllers\API\Setup\UploadTypesController::class);
    Route::resource('locations', App\Http\Controllers\API\Setup\GeographicalLocationsController::class);
    Route::resource('identifications', App\Http\Controllers\API\Setup\IdentificationsController::class);
    Route::resource('countries', App\Http\Controllers\API\Setup\CountriesController::class);

    Route::get('userAccounts/board-members', [App\Http\Controllers\API\User\UsersCotroller::class, 'getBoardMembers']);
    Route::get('unBlockUser/{userId}', [App\Http\Controllers\API\User\UsersCotroller::class, 'unBlockUser']);
    Route::resource('userAccounts', App\Http\Controllers\API\User\UsersCotroller::class);
    Route::resource('roles', App\Http\Controllers\API\User\RolesCotroller::class);
    Route::resource('permissions', App\Http\Controllers\API\User\PermissionsCotroller::class);

    // ================================================== RMS RELATED APIs ========================================================= //
    // HOSPITALS
    Route::get('hospitals/reffered-hospitals', [HospitalController::class, 'getReferredHospitals']);
    Route::get('hospitals/internal-referral-hospitals', [HospitalController::class, 'getInternalReferralHospitals']);
    Route::resource('hospitals', HospitalController::class);
    Route::patch('hospitals/unBlock/{hospitalId}', [HospitalController::class, 'unBlockHospital']);

    // REFERRAL TYPE
    Route::resource('referralTypes', ReferralTypeController::class);
    Route::patch('referralTypes/unblock/{referralTypeId}', [ReferralTypeController::class, 'unBlockReferralType']);

    // REFERRAL LETTERS
    Route::resource('referralLetters', ReferralLettersController::class);
    Route::get('referralLetters/comment/referral/{referralId}', [ReferralLettersController::class, 'getReferralCommentByReferralId']);
    Route::patch('referralLetters/unBlock/{referralLettersId}', [ReferralLettersController::class, 'unBlockHospital']);

// PATIENTS APIs
    Route::resource('patients', PatientController::class);
    Route::post('patients/update/{id}', [PatientController::class, 'updatePatient']);
    Route::post('patients/storePatientAndHistory', [PatientController::class, 'storePatientAndHistory']);
    Route::post('patients/register-with-auto-approval', [PatientController::class, 'storePatientAndHistoryAutoApproved']);
    Route::post('patients/updatePatientAndHistory/{patient_id}', [PatientController::class, 'updatePatientAndHistory']);
    Route::get('patients/showForUpdate/{patient_id}', [PatientController::class, 'showForUpdate']);
    Route::get('patientsHistories', [PatientController::class, 'patientsHistories']);
    Route::delete('patients/delete/{id}', [PatientController::class, 'delete']);
    Route::patch('patients/unBlock/{id}', [PatientController::class, 'unBlockPatient']);
    Route::get('patients-withinsurance/{id}', [PatientController::class, 'getAllPatientsWithInsurance']);
    Route::get('patients/for-referral/allowed', [PatientController::class, 'getAllPatients']);
    Route::get('/patients/histories/{id}', [PatientController::class, 'getMedicalHistory']);

    // INSURANCES APIs
    Route::resource('insurances', InsuranceController::class);
    Route::patch('insurances/unBlock/{hospitalId}', [InsuranceController::class, 'unBlockInsuarance']);

    // REFERRAL APIs
    Route::resource('referrals', ReferralController::class);
    Route::get('referralwithbills', [ReferralController::class, 'getReferralwithBills']);
    Route::get('referral/{referral_id}', [ReferralController::class, 'getReferralById']);
    Route::post('referral/action', [ReferralController::class, 'handleAction']);
    Route::post('referrals/confirm-referral-by-id/{referral_id}', [ReferralController::class, 'chooseHospitalAndConfirmReferral']);
    Route::patch('referrals/unBlock/{referralId}', [ReferralController::class, 'unBlockReferral']);
    Route::get('referrals-withbills/{referral_id}', [ReferralController::class, 'getReferralsWithBills']);
    Route::get('referrals-by-hospital/{hospital_id}/{bill_file_id}', [ReferralController::class, 'getReferralsByHospitalId']);
    Route::get('hospital-letters/followup-by-referral-id/{referral_id}', [ReferralController::class, 'getHospitalLettersByReferralId']);

    // RMS REASON APIs
    Route::resource('reasons', ReasonController::class);
    Route::patch('reasons/unBlock/{reasonsId}', [ReasonController::class, 'unBlockReason']);

    // RMS Treatments APIs
    Route::resource('treatments', TreatmentController::class);
    Route::post('treatments/update/{treatmentId}', [TreatmentController::class, 'update']);
    Route::patch('treatments/unBlock/{treatmentId}', [TreatmentController::class, 'unBlockTreatment']);

    // BILLS APIs
    Route::resource('bills', BillController::class);
    Route::post('bills/update/{id}', [BillController::class, 'updateBill']);
    Route::get('bills-by-bill-file/{billFileId}', [BillController::class, 'getBillsByBillFile']);
    Route::get('bills/getPatientBillAndPaymentByBillId/{billId}', [BillController::class, 'getPatientBillAndPaymentByBillId']);
    Route::patch('bills/unBlock/{billId}', [BillController::class, 'unBlockBill']);

    // REPORT APIs
    Route::get('reports/types', [ReportingController::class, 'types']);
    Route::get('reports/filters', [ReportingController::class, 'filters']);
    Route::post('reports/generate', [ReportingController::class, 'generate']);
    Route::post('reports/export/{format}', [ReportingController::class, 'export'])->whereIn('format', ['xlsx', 'pdf', 'docx']);
    Route::get('reports/top-diagnoses', \App\Http\Controllers\API\Report\TopDiagnosesController::class);
    Route::get('reports/referrals/{patientId}', [ReportController::class, 'referralReport']);
    Route::get('reports/caseStatusTracking', [ReportController::class, 'caseStatusTracking']);
    Route::get('reports/workflowStatusReport', [ReportController::class, 'workflowStatusReport']);
    Route::get('reports/referralsByType', [ReportController::class, 'referralReportByReferralType']);
    Route::get('reports/referralsByReason', [ReportController::class, 'referralsReportByReason']);
    Route::get('reports/referralByHospital', [ReportController::class, 'referralReportByHospital']);
    Route::post('reports/getBillsBetweenDates', [ReportController::class, 'getBillsBetweenDates']);
    Route::post('reports/searchReferralReport', [ReportController::class, 'searchReferralReport']);
    Route::get('reports/getMonthlyMaleAndFemaleReferralReport', [ReportController::class, 'getMonthlyMaleAndFemaleReferralReport']);
    // Dasboard Counts
    Route::get('/dashboard/totals', [ReportController::class, 'getOverallCounts']);

    // PAYMENT  API
    Route::resource('payments', PaymentController::class);

    // Patient Lis
    Route::resource('patient-lists', MedicalBoadController::class);
    Route::post('patient-lists/update/{id}', [MedicalBoadController::class, 'updatePatientList']);
    Route::patch('patient-lists/unblock/{id}', [PatientListController::class, 'unBlockParentList']);
    Route::get('patient-lists/body-form/{id}', [PatientListController::class, 'getAllPatientsByPatientListId']);
    Route::post('patient-lists/assign-patients/{id}', [MedicalBoadController::class, 'assignPatientsToList']);

    // Hospital Letters
    Route::resource('hospital-letters', HospitalLetterController::class);
    Route::post('hospital-letters/update/{followup_id}', [HospitalLetterController::class, 'updateHospitalLetter']);

    // Followups
    Route::resource('followups', FollowupController::class);

    // Backend-generated referral and follow-up letters
    Route::prefix('letter-documents')->group(function () {
        Route::get('referrals/{referral_id}/pdf', [LetterDocumentController::class, 'referralPdf']);
        Route::post('referrals/{referral_id}/print', [LetterDocumentController::class, 'markReferralPrinted']);
        Route::get('follow-ups/{letter_id}/pdf', [LetterDocumentController::class, 'followUpPdf']);
        Route::post('follow-ups/{letter_id}/print', [LetterDocumentController::class, 'markFollowUpPrinted']);
        Route::get('boarded-out/{patient_history_id}/pdf', [LetterDocumentController::class, 'boardedOutPdf']);
        Route::post('boarded-out/{patient_history_id}/print', [LetterDocumentController::class, 'markBoardedOutPrinted']);
        Route::get('print-history', [LetterDocumentController::class, 'printHistory']);
    });

    // DG/Super Admin-managed signature and stamp used on generated letters.
    Route::get('letter-branding', [LetterBrandingController::class, 'show']);
    Route::post('letter-branding', [LetterBrandingController::class, 'update']);
    Route::delete('letter-branding', [LetterBrandingController::class, 'reset']);

    // Bill Files
    Route::get('bill-files/summary-by-hospital', [BillFileController::class, 'getBillsByHospitals']); // new
    Route::resource('bill-files', BillFileController::class);
    Route::get('bill-files/hospital/{hospital_id}', [BillFileController::class, 'showByHospital']); // new
    Route::post('bill-files/update/{bill_file_id}', [BillFileController::class, 'updateBillFile']);
    Route::get('bill-files/bill-files-for-payment/payment', [BillFileController::class, 'getBillFilesForPayment']);
    Route::get('bill-files/hospital-bills/hospitals', [BillFileController::class, 'getBillFilesGroupByHospitals']);
    Route::get('bill-files/hospitals/{hospital_id}', [BillFileController::class, 'getBillFilesByHospitalId']);

    // Bill Items
    Route::resource('bill-items', BillItemController::class);
    Route::get('bill-items/by-bill-id/{bill_id}', [BillItemController::class, 'getBillItemsByBillId']);

    // Bill Payments
    Route::resource('bill-payments', BillPaymentController::class);

    // New Report
    Route::post('reports/range', [ReportController::class, 'rangeReport']);
    Route::get('reports/referrals', [ReportController::class, 'referralStatusReport']);
    Route::get('reports/timely', [ReportController::class, 'timelyReport']);
    Route::get('reports/patients', [ReportController::class, 'patientsReport']);

    // referrals by Gender
    Route::get('reports/referralsByGender', [ReportController::class, 'referralsReportByGendr']);
    Route::get('reports/showEverythingByReferralId/{referral_id}', [ReportController::class, 'showEverythingByReferralId']);

    // Diagnoses
    Route::prefix('diagnoses')->group(function () {
        Route::get('/search', [DiagnosisController::class, 'searchDiagnosis']);
        Route::get('/', [DiagnosisController::class, 'index']);
        Route::post('/', [DiagnosisController::class, 'store']);
        Route::get('{uuid}', [DiagnosisController::class, 'show']);
        Route::put('{uuid}', [DiagnosisController::class, 'update']);
        Route::delete('{uuid}', [DiagnosisController::class, 'destroy']);
        Route::post('/restore/{uuid}', [DiagnosisController::class, 'restore']);
        Route::post('/import', [DiagnosisController::class, 'importExcel']);
    });

    // Patient Histories
    Route::prefix('patient-histories')->group(function () {
        Route::get('/', [PatientHistoryController::class, 'index']);
        Route::get('/{id}/workflow-events', [App\Http\Controllers\API\PatientHistoryWorkflowController::class, 'index'])->whereNumber('id');
        Route::get('/{id}', [PatientHistoryController::class, 'show']);
        Route::post('/', [PatientHistoryController::class, 'store']);
        Route::post('/update/{id}', [PatientHistoryController::class, 'update']);
        Route::delete('/{id}', [PatientHistoryController::class, 'destroy']);
        Route::post('/{id}/unblock', [PatientHistoryController::class, 'unblock']);
        Route::post('/update-status/{id}', [PatientHistoryController::class, 'updateStatus']);
        // Route::put('/{id}/medical-board', [PatientHistoryController::class, 'updateByMedicalBoard']);
        Route::post('/{id}/medical-board', [PatientHistoryController::class, 'updateByMedicalBoardWithReferralCreation']);
        Route::post('/{id}/medical-board/update', [PatientHistoryController::class, 'updateByMedicalBoardWithoutReferralCreation']);
        Route::get('/{id}/medical-board', [PatientHistoryController::class, 'getMedicalBoardUpdate']);
        Route::put('/{id}/mkurugenzi-tiba', [PatientHistoryController::class, 'updateByMkurugenzi']);
        Route::get('/{id}/mkurugenzi-comments', [PatientHistoryController::class, 'getMkurugenziComments']);
        Route::get('/allowed-to-assign/patients', [PatientHistoryController::class, 'getPatientToBeAssignedToMedicalBoard']);
    });
    Route::post('patient-history-workflow-events/{eventId}/undo', [App\Http\Controllers\API\PatientHistoryWorkflowController::class, 'undo'])->whereNumber('eventId');

    // Patient History Conversations
    Route::prefix('patient-history-conversations')->group(function () {
        Route::get('/unread', [App\Http\Controllers\API\Patients\PatientHistoryConversationController::class, 'unreadNotifications']);
        Route::post('/mark-read', [App\Http\Controllers\API\Patients\PatientHistoryConversationController::class, 'markAsRead']);
        Route::get('/{patientHistoryId}/individual-chat',
            [App\Http\Controllers\API\Patients\PatientHistoryConversationController::class, 'show']
        );
        Route::get('/', [App\Http\Controllers\API\Patients\PatientHistoryConversationController::class, 'index']);
        Route::post('/', [App\Http\Controllers\API\Patients\PatientHistoryConversationController::class, 'store']);
        Route::get('/{patientHistoryConversation}', [App\Http\Controllers\API\Patients\PatientHistoryConversationController::class, 'show']);
        Route::post('/{patientHistoryConversation}', [App\Http\Controllers\API\Patients\PatientHistoryConversationController::class, 'update']);
        Route::delete('/{patientHistoryConversation}', [App\Http\Controllers\API\Patients\PatientHistoryConversationController::class, 'destroy']);
    });

    Route::get('/analytics/referral-trend', [App\Http\Controllers\API\Charts\AnalyticsController::class, 'referralTrend']);
    Route::get('/other-diagnoses-list', [App\Http\Controllers\API\Charts\AnalyticsController::class, 'otherDiagnosesList']);
    Route::post('/users/{userId}/assign-hospital', [App\Http\Controllers\API\User\UsersCotroller::class, 'assignHospital']);

    Route::get('patients/autocomplete-matibabu-card', [PatientController::class, 'autocompleteMatibabuCards']);
    // The specific endpoint for Matibabu Card eligibility search
    Route::post('patients/search-eligibility', [PatientController::class, 'searchByMatibabu']);

    Route::prefix('referral-flights')->group(function () {

        Route::post('/', [ReferralFlightController::class, 'store']);
        Route::get('/referral/{referralId}', [ReferralFlightController::class, 'showByReferral']);
        Route::get('/{id}', [ReferralFlightController::class, 'show']);
        Route::put('/{id}', [ReferralFlightController::class, 'update']);
        Route::delete('/{id}', [ReferralFlightController::class, 'destroy']);

    });

});
