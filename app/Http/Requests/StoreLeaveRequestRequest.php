<?php

namespace App\Http\Requests;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'request_type' => [
                'required',
                Rule::in([
                    LeaveRequest::TYPE_DOCTOR_LEAVE,
                    LeaveRequest::TYPE_ANNUAL_LEAVE,
                    LeaveRequest::TYPE_EXTERNAL_DUTY,
                    LeaveRequest::TYPE_EDUCATIONAL_ASSIGNMENT,
                ]),
            ],
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => [
                'nullable',
                'string',
                'max:1000',
                Rule::requiredIf(function () {
                    return in_array($this->request_type, [
                        LeaveRequest::TYPE_DOCTOR_LEAVE,
                        LeaveRequest::TYPE_ANNUAL_LEAVE,
                    ]);
                }),
            ],
            'annual_leave_subtype' => [
                'nullable',
                Rule::in([
                    'cuti_kepentingan',
                    'cuti_bersalin',
                    'cuti_tahunan',
                ]),
                Rule::requiredIf(function () {
                    return $this->request_type === LeaveRequest::TYPE_ANNUAL_LEAVE;
                }),
            ],
            'institution' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(function () {
                    return in_array($this->request_type, [
                        LeaveRequest::TYPE_EXTERNAL_DUTY,
                        LeaveRequest::TYPE_EDUCATIONAL_ASSIGNMENT,
                    ]);
                }),
            ],
            'education_type' => [
                'nullable',
                'string',
                'max:100',
                Rule::requiredIf(function () {
                    return $this->request_type === LeaveRequest::TYPE_EDUCATIONAL_ASSIGNMENT;
                }),
            ],
            'program_course' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(function () {
                    return $this->request_type === LeaveRequest::TYPE_EDUCATIONAL_ASSIGNMENT;
                }),
            ],
            'document' => [
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:5120',
                Rule::requiredIf(function () {
                    return $this->request_type !== LeaveRequest::TYPE_ANNUAL_LEAVE;
                }),
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'request_type.required' => 'Tipe permohonan wajib diisi',
            'request_type.in' => 'Tipe permohonan tidak valid',
            'start_date.required' => 'Tanggal mulai wajib diisi',
            'start_date.date' => 'Tanggal mulai tidak valid',
            'start_date.after_or_equal' => 'Tanggal mulai harus hari ini atau setelahnya',
            'end_date.required' => 'Tanggal selesai wajib diisi',
            'end_date.date' => 'Tanggal selesai tidak valid',
            'end_date.after_or_equal' => 'Tanggal selesai harus setelah atau sama dengan tanggal mulai',
            'reason.required_if' => 'Alasan wajib diisi untuk cuti tahunan dan cuti dokter',
            'reason.max' => 'Alasan maksimal 1000 karakter',
            'annual_leave_subtype.required_if' => 'Jenis cuti kepentingan wajib dipilih',
            'annual_leave_subtype.in' => 'Jenis cuti kepentingan tidak valid',
            'institution.required_if' => 'Institusi/lokasi penugasan wajib diisi',
            'institution.max' => 'Institusi/lokasi penugasan maksimal 255 karakter',
            'education_type.required_if' => 'Jenis pendidikan wajib diisi untuk tugas pendidikan',
            'education_type.max' => 'Jenis pendidikan maksimal 100 karakter',
            'program_course.required_if' => 'Program/kursus wajib diisi untuk tugas pendidikan',
            'program_course.max' => 'Program/kursus maksimal 255 karakter',
            'document.required' => 'Dokumen pendukung wajib di-upload untuk tipe permohonan ini',
            'document.file' => 'Dokumen harus berupa file',
            'document.mimes' => 'Dokumen harus berformat PDF, JPG, JPEG, atau PNG',
            'document.max' => 'Ukuran dokumen maksimal 5MB',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'request_type' => 'tipe permohonan',
            'annual_leave_subtype' => 'jenis cuti kepentingan',
            'start_date' => 'tanggal mulai',
            'end_date' => 'tanggal selesai',
            'reason' => 'alasan',
            'institution' => 'institusi',
            'education_type' => 'jenis pendidikan',
            'program_course' => 'program/kursus',
            'document' => 'dokumen',
        ];
    }
}
