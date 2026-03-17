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
            'notes' => 'required|string|max:50', // Primary identifier (P, S, M, L, etc.)
            'shift_id' => 'nullable|exists:shifts,id', // Optional - auto-resolved from notes
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
            'notes.required' => 'Kode shift wajib diisi (P, S, M, L, dll)',
            'notes.string' => 'Kode shift harus berupa string',
            'notes.max' => 'Kode shift maksimal 50 karakter',
        ];
    }
}
