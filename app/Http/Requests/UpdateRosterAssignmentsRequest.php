<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRosterAssignmentsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'shift_assignments' => 'sometimes|array',
            'shift_assignments.*.employee_id' => 'required|exists:employees,id',
            'shift_assignments.*.notes' => 'required|string|max:50', // Primary identifier (P, S, M, L, etc.)
            'shift_assignments.*.shift_id' => 'nullable|exists:shifts,id', // Optional - auto-resolved from notes
            'manager_duties' => 'sometimes|array',
            'manager_duties.*.employee_id' => 'required|exists:employees,id',
            'manager_duties.*.duty_type' => 'required|string|in:Manager Teknik,General Manager',
            'manager_duties.*.shift_id' => 'nullable|exists:shifts,id', // Optional for manager duties
            'manager_duties.*.notes' => 'nullable|string|max:50', // Can use notes for manager duty too
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
            'shift_assignments.array' => 'Shift assignments harus berupa array',
            'shift_assignments.*.employee_id.required' => 'Employee ID wajib diisi untuk setiap shift assignment',
            'shift_assignments.*.employee_id.exists' => 'Employee tidak ditemukan',
            'shift_assignments.*.notes.required' => 'Kode shift wajib diisi (P, S, M, L, dll)',
            'shift_assignments.*.notes.max' => 'Kode shift maksimal 50 karakter',
            'shift_assignments.*.shift_id.exists' => 'Shift tidak ditemukan',
            'manager_duties.array' => 'Manager duties harus berupa array',
            'manager_duties.*.employee_id.required' => 'Employee ID wajib diisi untuk setiap manager duty',
            'manager_duties.*.employee_id.exists' => 'Employee tidak ditemukan',
            'manager_duties.*.duty_type.required' => 'Duty type wajib diisi',
            'manager_duties.*.duty_type.in' => 'Duty type harus Manager Teknik atau General Manager',
            'manager_duties.*.shift_id.exists' => 'Shift tidak ditemukan',
        ];
    }
}
