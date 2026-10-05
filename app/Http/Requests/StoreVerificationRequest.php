<?php

namespace App\Http\Requests;

use App\Enums\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // consumer auth handled by middleware
    }

    public function rules(): array
    {
        return [
            'external_reference' => ['required', 'string', 'max:190'],
            'verification_policy' => ['required', 'string', 'max:100'],
            'expected_document_type' => ['nullable', 'string', Rule::in(array_column(DocumentType::cases(), 'value'))],
            'subject' => ['required', 'array'],
            'subject.type' => ['nullable', 'string', 'max:40'],
            'subject.external_id' => ['required', 'string', 'max:190'],
            'subject.first_name' => ['required', 'string', 'max:120'],
            'subject.middle_names' => ['nullable', 'string', 'max:160'],
            'subject.surname' => ['required', 'string', 'max:120'],
            'subject.date_of_birth' => ['nullable', 'date_format:Y-m-d'],
            'subject.country' => ['nullable', 'string', 'size:2'],
            'subject.national_id_number' => ['nullable', 'string', 'max:60'],
            'subject.passport_number' => ['nullable', 'string', 'max:60'],
            'subject.driver_license_number' => ['nullable', 'string', 'max:60'],
            'subject.gender' => ['nullable', 'string', 'max:20'],
            'documents' => ['nullable', 'array', 'max:6'],
            'documents.*.type' => ['nullable', 'string', Rule::in(array_column(DocumentType::cases(), 'value'))],
            'documents.*.side' => ['nullable', 'string', Rule::in(['front', 'back'])],
            'documents.*.file_base64' => ['required_with:documents', 'string'],
            'callback.url' => ['nullable', 'url', 'starts_with:https://,http://', 'max:512'],
        ];
    }
}
