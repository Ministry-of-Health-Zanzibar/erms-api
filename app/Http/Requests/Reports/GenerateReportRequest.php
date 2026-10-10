<?php

namespace App\Http\Requests\Reports;

use App\Support\SuperAdminAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && SuperAdminAccess::allowed($this->user(), 'View Report');
    }

    /**
     * Multi-select controls may submit numeric IDs as strings depending on
     * the browser/control adapter. Normalize integer-like values before the
     * validation rules run while preserving invalid values for validation to
     * reject normally.
     */
    protected function prepareForValidation(): void
    {
        $integerFields = [
            'top',
            'age_from',
            'age_to',
            'diagnosis_id',
            'hospital_id',
            'referral_type_id',
            'page',
            'per_page',
            'case_id',
        ];

        $normalized = [];

        foreach ($integerFields as $field) {
            if ($this->exists($field)) {
                $normalized[$field] = $this->normalizeInteger($this->input($field));
            }
        }

        if ($this->exists('hospital_ids')) {
            $hospitalIds = $this->input('hospital_ids');
            $normalized['hospital_ids'] = is_array($hospitalIds)
                ? array_map(fn ($hospitalId) => $this->normalizeInteger($hospitalId), $hospitalIds)
                : $hospitalIds;
        }

        if ($this->exists('source_hospital_ids')) {
            $sourceHospitalIds = $this->input('source_hospital_ids');
            $normalized['source_hospital_ids'] = is_array($sourceHospitalIds)
                ? array_map(fn ($hospitalId) => $this->normalizeInteger($hospitalId), $sourceHospitalIds)
                : $sourceHospitalIds;
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    private function normalizeInteger(mixed $value): mixed
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[+-]?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (int) $value;
        }

        return $value;
    }

    public function rules(): array
    {
        return [
            'report_type' => ['required', 'string', Rule::in(['top_diagnoses', 'referrals_by_hospital', 'case_workflow', 'boarded_out_cases', 'patient_summary', 'case_journey'])],
            'start_date' => ['required', 'date', 'before_or_equal:end_date'],
            'end_date' => ['required', 'date', 'before_or_equal:today'],
            'top' => ['nullable', 'integer', Rule::in([5, 10, 20, 50, 100])],
            'result_limit' => ['nullable', Rule::in(['all', 5, 10, 20, 50, 100, '5', '10', '20', '50', '100'])],
            'group_by' => ['nullable', 'string', Rule::in(['destination', 'month', 'quarter', 'year', 'day', 'gender', 'diagnosis', 'status'])],
            'detail_level' => ['nullable', 'string', Rule::in(['summary', 'breakdown', 'details'])],
            'gender' => ['nullable', 'string', Rule::in(['male', 'female', 'other', 'Male', 'Female', 'Other'])],
            'age_from' => ['nullable', 'integer', 'min:0', 'max:150'],
            'age_to' => ['nullable', 'integer', 'min:0', 'max:150', 'gte:age_from'],
            'age_group' => ['nullable', 'string', Rule::in(['0-4', '5-14', '15-24', '25-34', '35-44', '45-54', '55-64', '65+'])],
            'location_id' => ['nullable', 'string', 'exists:geographical_locations,location_id'],
            'diagnosis_id' => [
                'nullable',
                'integer',
                Rule::exists('diagnoses', 'diagnosis_id')->whereNull('deleted_at'),
            ],
            'hospital_ids' => ['nullable', 'array', 'max:50'],
            'hospital_ids.*' => ['integer', Rule::exists('hospitals', 'hospital_id')->whereNull('deleted_at')],
            'source_hospital_ids' => ['nullable', 'array', 'max:50'],
            'source_hospital_ids.*' => ['integer', Rule::exists('hospitals', 'hospital_id')->whereNull('deleted_at')],
            'hospital_id' => ['nullable', 'integer', Rule::exists('hospitals', 'hospital_id')->whereNull('deleted_at')],
            'referral_status' => ['nullable', 'string', Rule::in(['Pending', 'Confirmed', 'Death', 'Cancelled', 'Transferred', 'Expired', 'Closed', 'Requested', 'BoardedOut'])],
            'referral_type_id' => ['nullable', 'integer', Rule::exists('referral_types', 'referral_type_id')->whereNull('deleted_at')],
            'patient_history_status' => ['nullable', 'string', Rule::in(['pending', 'reviewed', 'assigned', 'requested', 'approved', 'confirmed', 'boarded_out', 'rejected', 'under_review'])],
            'include_archived' => ['sometimes', 'boolean'],
            'patient_search' => ['nullable', 'string', 'max:120'],
            'referral_search' => ['nullable', 'string', 'max:120'],
            'outcome' => ['nullable', 'string', Rule::in(['Follow-up', 'Finished', 'Transferred', 'Death'])],
            'case_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
