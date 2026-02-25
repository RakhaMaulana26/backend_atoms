<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuickUpdateAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => 'required|exists:employees,id',
            'work_dates' => 'required|array|min:1',
            'work_dates.*' => 'required|date_format:Y-m-d',
            'shift' => 'required|string', // Can be shift name or shift_id
            'notes' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'employee_id.required' => 'Employee ID wajib diisi',
            'employee_id.exists' => 'Employee tidak ditemukan',
            'work_dates.required' => 'Tanggal kerja wajib diisi',
            'work_dates.array' => 'Tanggal kerja harus berupa array',
            'work_dates.min' => 'Minimal harus ada 1 tanggal kerja',
            'work_dates.*.date_format' => 'Format tanggal harus Y-m-d (contoh: 2026-02-18)',
            'shift.required' => 'Shift wajib diisi',
            'shift.string' => 'Shift harus berupa string',
            'notes.max' => 'Catatan maksimal 255 karakter',
        ];
    }
}
