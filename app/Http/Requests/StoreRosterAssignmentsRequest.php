<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRosterAssignmentsRequest extends FormRequest
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
            'shift_assignments.*.shift_id' => 'required|exists:shifts,id',
            'manager_duties' => 'sometimes|array',
            'manager_duties.*.employee_id' => 'required|exists:employees,id',
            'manager_duties.*.duty_type' => 'required|string|in:Manager Teknik,General Manager',
            'manager_duties.*.shift_id' => 'required|exists:shifts,id',
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
            'shift_assignments.*.shift_id.required' => 'Shift ID wajib diisi untuk setiap shift assignment',
            'shift_assignments.*.shift_id.exists' => 'Shift tidak ditemukan',
            'manager_duties.array' => 'Manager duties harus berupa array',
            'manager_duties.*.employee_id.required' => 'Employee ID wajib diisi untuk setiap manager duty',
            'manager_duties.*.employee_id.exists' => 'Employee tidak ditemukan',
            'manager_duties.*.duty_type.required' => 'Duty type wajib diisi',
            'manager_duties.*.duty_type.in' => 'Duty type harus Manager Teknik atau General Manager',
            'manager_duties.*.shift_id.required' => 'Shift ID wajib diisi untuk setiap manager duty',
            'manager_duties.*.shift_id.exists' => 'Shift tidak ditemukan',
        ];
    }

    /**
     * Custom validation to ensure request is not empty
     */
    protected function prepareForValidation(): void
    {
        if (empty($this->all())) {
            abort(400, 'Request body is empty. Check your JSON syntax (remove trailing commas).');
        }
    }
}
