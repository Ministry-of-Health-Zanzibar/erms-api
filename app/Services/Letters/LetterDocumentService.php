<?php

namespace App\Services\Letters;

use App\Models\BoardedOutLetter;
use App\Models\HospitalLetter;
use App\Models\LetterPrintEvent;
use App\Models\ReferralLetter;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LetterDocumentService
{
    public const LANGUAGE_ENGLISH = 'en';
    public const LANGUAGE_SWAHILI = 'sw';

    public function __construct(private readonly LetterBrandingService $branding)
    {
    }

    public function findReferralLetter(int $referralId): ?ReferralLetter
    {
        return ReferralLetter::with([
            'referral.patient',
            'referral.hospital.referralType',
            'referral.referralFlights',
        ])
            ->where('referral_id', $referralId)
            ->latest('referral_letter_id')
            ->first();
    }

    public function findFollowUpLetter(int $letterId): ?HospitalLetter
    {
        return HospitalLetter::with([
            'referral.patient',
            'referral.hospital.referralType',
            'transferredReferral.referralLetters',
        ])->find($letterId);
    }

    public function findBoardedOutLetter(int $patientHistoryId): ?BoardedOutLetter
    {
        return BoardedOutLetter::with([
            'patientHistory.patient.patientList',
        ])
            ->where('patient_histories_id', $patientHistoryId)
            ->latest('id')
            ->first();
    }

    public function normalizeLanguage(?string $language, string $default = self::LANGUAGE_SWAHILI): string
    {
        $language = strtolower(trim((string) $language));

        return in_array($language, [self::LANGUAGE_ENGLISH, self::LANGUAGE_SWAHILI], true)
            ? $language
            : $default;
    }

    public function defaultReferralLanguage(ReferralLetter $letter): string
    {
        return $this->defaultLanguageForReferral($letter->referral);
    }

    public function defaultFollowUpLanguage(HospitalLetter $letter): string
    {
        return $this->defaultLanguageForReferral($letter->referral);
    }

    public function defaultLanguageForReferral(?object $referral): string
    {
        $referralTypeCode = strtoupper(trim((string) data_get(
            $referral,
            'hospital.referralType.referral_type_code',
        )));

        return $referralTypeCode === 'REFTYPE2'
            ? self::LANGUAGE_ENGLISH
            : self::LANGUAGE_SWAHILI;
    }

    public function renderReferral(ReferralLetter $letter, string $language): string
    {
        $referral = $letter->referral;

        $patientAge = $this->patientAge($referral?->patient?->date_of_birth);
        $branding = $this->branding->effectiveAssets();

        return $this->renderPdf('letters.referral', [
            'letter' => $letter,
            'referral' => $referral,
            'language' => $language,
            'logoData' => $this->assetDataUri('smz.png'),
            'signatureData' => $branding['signatureData'],
            'stampData' => $branding['stampData'],
            'flight' => $referral?->referralFlights?->first(),
            'patientAge' => $patientAge,
            'ageLabel' => $this->ageLabel($patientAge, $language),
            // Keep the English reference identical to the existing frontend
            // template, which uses its legacy `reference_no` field and falls
            // back to this official reference when that field is absent.
            'reference' => data_get($letter, 'reference_no') ?: 'AB.25/295/02',
            'email' => 'info@mohz.go.tz',
            'dgEmail' => 'dg@mohz.go.tz',
            'permanentSecretary' => 'ps@mohz.go.tz',
            'startDate' => $this->formatDate($letter->start_date),
        ]);
    }

    public function renderFollowUp(HospitalLetter $letter, string $language): string
    {
        $referral = $letter->referral;

        $patientAge = $this->patientAge($referral?->patient?->date_of_birth);
        $branding = $this->branding->effectiveAssets();

        return $this->renderPdf('letters.follow-up', [
            'letter' => $letter,
            'referral' => $referral,
            'language' => $language,
            'logoData' => $this->assetDataUri('smz.png'),
            'signatureData' => $branding['signatureData'],
            'stampData' => $branding['stampData'],
            'patientAge' => $patientAge,
            'ageLabel' => $this->ageLabel($patientAge, $language),
            'email' => 'info@mohz.go.tz',
            'dgEmail' => 'dg@mohz.go.tz',
            'permanentSecretary' => 'ps@mohz.go.tz',
            'referenceDate' => $this->formatDate($letter->next_appointment_date, 'd-m-Y'),
        ]);
    }

    public function renderBoardedOut(BoardedOutLetter $letter, string $language): string
    {
        $history = $letter->patientHistory;
        $patient = $history?->patient;
        $boardDate = $patient?->patientList?->first()?->board_date;
        $branding = $this->branding->effectiveAssets();

        return $this->renderPdf('letters.boarded-out', [
            'letter' => $letter,
            'history' => $history,
            'patient' => $patient,
            'language' => $language,
            'logoData' => $this->assetDataUri('smz.png'),
            'signatureData' => $branding['signatureData'],
            'stampData' => $branding['stampData'],
            'boardDate' => $this->formatDate($boardDate, 'd/m/Y'),
            'email' => 'info@mohz.go.tz',
            'dgEmail' => 'dg@mohz.go.tz',
            'permanentSecretary' => 'ps@mohz.go.tz',
        ]);
    }

    private function renderPdf(string $view, array $data): string
    {
        // Render current data before checking the cache. Template, language,
        // patient, hospital, flight, age/date and branding changes all produce
        // a different key; print-audit-only updates do not rebuild the PDF.
        $html = view($view, $data)->render();
        $key = 'letter-pdf:v1:' . hash('sha256', $view . ':' . $data['letter']->getKey()
            . ':' . json_encode(config('dompdf.options', [])) . ':' . $html);

        try {
            $cached = Cache::get($key);
            if (is_string($cached)) {
                return Crypt::decryptString($cached);
            }
        } catch (\Throwable) {
            // Missing/corrupt cache or a changed encryption key: render normally.
        }

        $pdf = Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => false,
                'defaultFont' => 'DejaVu Sans',
            ])->output();

        try {
            // Cache only encrypted bytes in the private server cache. Every
            // request still authenticates and resolves the active letter first.
            Cache::put($key, Crypt::encryptString($pdf), now()->addMinutes(10));
        } catch (\Throwable) {
            // Preview remains available even if caching/encryption is unavailable.
        }

        return $pdf;
    }

    public function recordPrint(Model $letter, string $letterType, string $language, Request $request): LetterPrintEvent
    {
        return DB::transaction(function () use ($letter, $letterType, $language, $request) {
            $printedAt = now();

            $letter->forceFill([
                'is_printed' => true,
                'printed_at' => $printedAt,
                'printed_by' => auth()->id(),
                'print_count' => ((int) $letter->print_count) + 1,
                'last_printed_language' => $language,
            ])->save();

            return LetterPrintEvent::create([
                'letter_type' => $letterType,
                'letter_id' => $letter->getKey(),
                'language' => $language,
                'printed_by' => auth()->id(),
                'printed_at' => $printedAt,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 1000),
            ]);
        });
    }

    public function filename(string $type, Model $letter, string $language): string
    {
        $identifier = $type === 'referral'
            ? ($letter->referral_letter_code ?: $letter->getKey())
            : $letter->getKey();

        $prefix = match ($type) {
            'referral' => 'referral',
            'follow_up' => 'follow-up',
            'boarded_out' => 'boarded-out',
            default => 'letter',
        };

        return sprintf('%s-letter-%s-%s.pdf', $prefix, $identifier, $language);
    }

    public function formatDate(mixed $value, string $format = 'd F, Y'): string
    {
        if (!$value) {
            return 'N/A';
        }

        try {
            return Carbon::parse($value)->format($format);
        } catch (\Throwable) {
            return 'N/A';
        }
    }

    public function patientAge(?string $dateOfBirth): array
    {
        if (!$dateOfBirth) {
            return ['years' => 0, 'months' => 0, 'days' => 0];
        }

        try {
            $diff = Carbon::parse($dateOfBirth)->diff(now());

            return [
                'years' => $diff->y,
                'months' => $diff->m,
                'days' => $diff->d,
            ];
        } catch (\Throwable) {
            return ['years' => 0, 'months' => 0, 'days' => 0];
        }
    }

    public function ageLabel(array $age, string $language): string
    {
        if ($language === self::LANGUAGE_ENGLISH) {
            if ($age['years'] > 0) {
                return $age['years'] . ' ' . ($age['years'] === 1 ? 'year' : 'years');
            }

            if ($age['months'] > 0) {
                return $age['months'] . ' ' . ($age['months'] === 1 ? 'month' : 'months');
            }

            return $age['days'] . ' ' . ($age['days'] === 1 ? 'day' : 'days');
        }

        if ($age['years'] > 0) {
            return 'MIAKA ' . $age['years'];
        }

        if ($age['months'] > 0) {
            return 'MIEZI ' . $age['months'];
        }

        return 'SIKU ' . $age['days'];
    }

    private function assetDataUri(string $filename): string
    {
        $path = public_path('images/letters/' . $filename);

        if (!is_file($path)) {
            return '';
        }

        $mime = mime_content_type($path) ?: 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
    }
}
