<?php

namespace App\Http\Controllers\API\Letters;

use App\Http\Controllers\Controller;
use App\Models\BoardedOutLetter;
use App\Models\HospitalLetter;
use App\Models\LetterPrintEvent;
use App\Models\ReferralLetter;
use App\Models\Referral;
use App\Services\Letters\LetterDocumentService;
use App\Services\TransferReferralService;
use Illuminate\Http\Request;

class LetterDocumentController extends Controller
{
    public function __construct(private readonly LetterDocumentService $documents)
    {
        $this->middleware('auth:sanctum');
    }

    public function referralPdf(Request $request, int $referralId)
    {
        if (!$this->canViewReferralLetter()) {
            return $this->forbidden();
        }

        $letter = $this->documents->findReferralLetter($referralId);

        if (!$letter) {
            if (Referral::whereKey($referralId)->whereNotNull('parent_referral_id')->exists()) {
                return $this->transferLetterMissing();
            }
            return response()->json(['message' => 'Referral letter not found', 'statusCode' => 404], 404);
        }

        // Referral type is authoritative: REFTYPE2 is the ABROAD template;
        // every other referral type must use the Kiswahili template.
        $language = $this->documents->defaultReferralLanguage($letter);

        return $this->pdfResponse(
            $this->documents->renderReferral($letter, $language),
            $this->documents->filename('referral', $letter, $language),
        );
    }

    public function markReferralPrinted(Request $request, int $referralId)
    {
        if (!$this->canViewReferralLetter()) {
            return $this->forbidden();
        }

        $letter = $this->documents->findReferralLetter($referralId);

        if (!$letter) {
            if (Referral::whereKey($referralId)->whereNotNull('parent_referral_id')->exists()) {
                return $this->transferLetterMissing();
            }
            return response()->json(['message' => 'Referral letter not found', 'statusCode' => 404], 404);
        }

        $language = $this->documents->defaultReferralLanguage($letter);
        $event = $this->documents->recordPrint($letter, 'referral', $language, $request);

        return response()->json([
            'message' => 'Referral letter print recorded successfully',
            'data' => $this->printStatus($letter, $event),
            'statusCode' => 200,
        ]);
    }

    public function followUpPdf(Request $request, int $letterId)
    {
        if (!$this->canViewFollowUpLetter()) {
            return $this->forbidden();
        }

        $letter = $this->documents->findFollowUpLetter($letterId);

        if (!$letter) {
            return response()->json(['message' => 'Follow-up letter not found', 'statusCode' => 404], 404);
        }

        if ($letter->outcome === 'Transferred') {
            if (! $this->canViewReferralLetter()) return $this->forbidden();
            $transfer = app(TransferReferralService::class)->transferredReferral($letter);
            if (! $transfer) return $this->transferNeedsReview();
            return $this->referralPdf($request, $transfer->getKey());
        }

        $language = $this->documents->defaultFollowUpLanguage($letter);

        return $this->pdfResponse(
            $this->documents->renderFollowUp($letter, $language),
            $this->documents->filename('follow_up', $letter, $language),
        );
    }

    public function markFollowUpPrinted(Request $request, int $letterId)
    {
        if (!$this->canViewFollowUpLetter()) {
            return $this->forbidden();
        }

        $letter = $this->documents->findFollowUpLetter($letterId);

        if (!$letter) {
            return response()->json(['message' => 'Follow-up letter not found', 'statusCode' => 404], 404);
        }

        if ($letter->outcome === 'Transferred') {
            if (! $this->canViewReferralLetter()) return $this->forbidden();
            $transfer = app(TransferReferralService::class)->transferredReferral($letter);
            if (! $transfer) return $this->transferNeedsReview();
            return $this->markReferralPrinted($request, $transfer->getKey());
        }

        $language = $this->documents->defaultFollowUpLanguage($letter);
        $event = $this->documents->recordPrint($letter, 'follow_up', $language, $request);

        return response()->json([
            'message' => 'Follow-up letter print recorded successfully',
            'data' => $this->printStatus($letter, $event),
            'statusCode' => 200,
        ]);
    }

    public function boardedOutPdf(Request $request, int $patientHistoryId)
    {
        if (!$this->canViewReferralLetter()) {
            return $this->forbidden();
        }

        $letter = $this->documents->findBoardedOutLetter($patientHistoryId);

        if (!$letter) {
            return response()->json(['message' => 'Boarded-out letter not found', 'statusCode' => 404], 404);
        }

        // Boarded-out letters are internal Ministry correspondence.
        $language = LetterDocumentService::LANGUAGE_SWAHILI;

        return $this->pdfResponse(
            $this->documents->renderBoardedOut($letter, $language),
            $this->documents->filename('boarded_out', $letter, $language),
        );
    }

    public function markBoardedOutPrinted(Request $request, int $patientHistoryId)
    {
        if (!$this->canViewReferralLetter()) {
            return $this->forbidden();
        }

        $letter = $this->documents->findBoardedOutLetter($patientHistoryId);

        if (!$letter) {
            return response()->json(['message' => 'Boarded-out letter not found', 'statusCode' => 404], 404);
        }

        $language = LetterDocumentService::LANGUAGE_SWAHILI;
        $event = $this->documents->recordPrint($letter, 'boarded_out', $language, $request);

        return response()->json([
            'message' => 'Boarded-out letter print recorded successfully',
            'data' => $this->printStatus($letter, $event),
            'statusCode' => 200,
        ]);
    }

    public function printHistory(Request $request)
    {
        if (!$this->canViewPrintHistory()) {
            return $this->forbidden();
        }

        $events = LetterPrintEvent::with('printedBy:id,first_name,middle_name,last_name,email')
            ->when($request->filled('letter_type'), fn ($query) => $query->where('letter_type', $request->string('letter_type')->toString()))
            ->when($request->filled('letter_id'), fn ($query) => $query->where('letter_id', $request->integer('letter_id')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('printed_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('printed_at', '<=', $request->date('to')))
            ->latest('printed_at')
            ->paginate(min($request->integer('per_page', 50), 100));

        return response()->json([
            'data' => $events,
            'statusCode' => 200,
        ]);
    }

    private function pdfResponse(string $pdf, string $filename)
    {
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    private function printStatus(ReferralLetter|HospitalLetter|BoardedOutLetter $letter, LetterPrintEvent $event): array
    {
        return [
            'letter_type' => $event->letter_type,
            'letter_id' => $event->letter_id,
            'is_printed' => (bool) $letter->is_printed,
            'printed_at' => $letter->printed_at,
            'printed_by' => $letter->printed_by,
            'print_count' => (int) $letter->print_count,
            'last_printed_language' => $letter->last_printed_language,
            'event_id' => $event->getKey(),
        ];
    }

    private function canViewReferralLetter(): bool
    {
        $user = auth()->user();

        return $user && ($user->can('View ReferralLetter') || $user->hasAnyRole(['ROLE ADMIN', 'ROLE SUPER ADMIN', 'ROLE SUPERADMIN', 'ROLE DG', 'ROLE DIRECTOR GENERAL']));
    }

    private function canViewFollowUpLetter(): bool
    {
        $user = auth()->user();

        return $user && ($user->can('View Hospital Letter') || $user->can('View FollowUp') || $user->hasAnyRole(['ROLE ADMIN', 'ROLE SUPER ADMIN', 'ROLE SUPERADMIN', 'ROLE DG', 'ROLE DIRECTOR GENERAL']));
    }

    private function canViewPrintHistory(): bool
    {
        $user = auth()->user();

        return $user && $user->hasAnyRole(['ROLE ADMIN', 'ROLE SUPER ADMIN', 'ROLE SUPERADMIN', 'ROLE DG', 'ROLE DIRECTOR GENERAL']);
    }

    private function forbidden()
    {
        return response()->json(['message' => 'Forbidden', 'statusCode' => 403], 403);
    }

    private function transferNeedsReview()
    {
        return response()->json([
            'message' => 'This transfer needs a verified destination referral link before its letter can be printed. Please ask an administrator to review the transfer record.',
            'statusCode' => 422,
        ], 422);
    }

    private function transferLetterMissing()
    {
        return response()->json([
            'message' => 'This existing transfer has no prepared referral letter. Please ask an administrator to run the verified transfer-letter repair and review any unresolved case links.',
            'statusCode' => 422,
        ], 422);
    }
}
