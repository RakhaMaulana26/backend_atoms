<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\RosterDay;
use App\Models\ShiftAssignment;
use App\Models\ShiftRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

class StoreShiftRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check() && Auth::user()->employee;
    }

    public function rules(): array
    {
        return [
            'target_employee_id' => 'required|exists:employees,id',
            'from_roster_day_id' => 'required|exists:roster_days,id',
            'to_roster_day_id' => 'required|exists:roster_days,id',
            'requester_notes' => 'required|string|max:50',
            'target_notes' => 'required|string|max:50',
            'reason' => 'sometimes|string|max:1000',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateSwapRequest($validator);
        });
    }

    protected function validateSwapRequest(Validator $validator): void
    {
        $requesterEmployee = Auth::user()->employee;
        $targetEmployeeId = $this->input('target_employee_id');
        $fromRosterDayId = $this->input('from_roster_day_id');
        $toRosterDayId = $this->input('to_roster_day_id');
        $requesterNotes = trim((string) $this->input('requester_notes'));
        $targetNotes = trim((string) $this->input('target_notes'));
        $requesterNotesLower = strtolower($requesterNotes);
        $targetNotesLower = strtolower($targetNotes);

        // 1. Cannot swap with self
        if ($requesterEmployee->id == $targetEmployeeId) {
            $validator->errors()->add('target_employee_id', 'Tidak dapat tukar shift dengan diri sendiri.');
            return;
        }

        // 2. Target employee must exist and be active
        $targetEmployee = Employee::with('user')->find($targetEmployeeId);
        if (!$targetEmployee || !$targetEmployee->is_active) {
            $validator->errors()->add('target_employee_id', 'Target employee tidak aktif atau tidak ditemukan.');
            return;
        }

        // 3a. Validate grade compatibility
        $requesterGrade = Auth::user()->grade !== null ? (int) Auth::user()->grade : null;
        $targetGrade = $targetEmployee->user?->grade !== null ? (int) $targetEmployee->user->grade : null;

        if (!$this->isSwapGradeCompatible($requesterGrade, $targetGrade)) {
            $validator->errors()->add(
                'target_employee_id',
                'Tukar shift hanya dapat dilakukan dengan kelas jabatan yang sama, atau pasangan/grup kelas berikut: 14-13, 12-11, dan grup 8-9-10. Kelas 15 hanya dapat swap dengan kelas yang sama.'
            );
            return;
        }

        // 3. Must have same employee_type (role)
        if ($requesterEmployee->employee_type !== $targetEmployee->employee_type) {
            $validator->errors()->add('target_employee_id', 'Tukar shift hanya dapat dilakukan dengan karyawan dengan role yang sama.');
            return;
        }

        // 4. Single-day swap only: source and destination must be the same day.
        if ((int) $fromRosterDayId !== (int) $toRosterDayId) {
            $validator->errors()->add('to_roster_day_id', 'Pertukaran shift hanya dapat dilakukan pada hari yang sama.');
            return;
        }

        // 5. Validate roster days
        $fromRosterDay = RosterDay::with('rosterPeriod')->find($fromRosterDayId);
        $toRosterDay = RosterDay::with('rosterPeriod')->find($toRosterDayId);

        if (!$fromRosterDay || !$toRosterDay) {
            $validator->errors()->add('from_roster_day_id', 'Roster day tidak valid.');
            return;
        }

        // 6. Both rosters must be published
        if ($fromRosterDay->rosterPeriod->status !== 'published') {
            $validator->errors()->add('from_roster_day_id', 'Roster period belum dipublish.');
            return;
        }

        if ($toRosterDay->rosterPeriod->status !== 'published') {
            $validator->errors()->add('to_roster_day_id', 'Roster period belum dipublish.');
            return;
        }

        // 7. Check H-3 rule (minimum 3 days before)
        $minDate = Carbon::now()->addDays(3)->startOfDay();
        
        if (Carbon::parse($fromRosterDay->work_date)->lt($minDate)) {
            $validator->errors()->add('from_roster_day_id', 'Tukar shift harus diajukan minimal H-3 sebelum tanggal shift.');
            return;
        }

        // to_roster_day_id is the same as from_roster_day_id in this flow.

        // 8. Requester must have a WORKING shift on selected day with requester_notes
        $requesterAssignment = ShiftAssignment::where('roster_day_id', $fromRosterDayId)
            ->where('employee_id', $requesterEmployee->id)
            ->whereRaw('LOWER(TRIM(notes)) = ?', [$requesterNotesLower])
            ->first();

        if (!$requesterAssignment) {
            $validator->errors()->add('requester_notes', 'Anda tidak memiliki shift tersebut pada tanggal yang dipilih.');
            return;
        }

        if ($this->isOffDayNotes($requesterNotesLower)) {
            $validator->errors()->add('requester_notes', 'Shift asal harus shift kerja, bukan libur/cuti/off.');
            return;
        }

        // 9. Requested shift must be different from current shift on the same date.
        if ($requesterNotesLower === $targetNotesLower) {
            $validator->errors()->add('target_notes', 'Shift request harus berbeda dari shift Anda di hari tersebut.');
            return;
        }

        // 10. Target partner must have requested WORKING shift on the same date.
        $targetAssignment = ShiftAssignment::where('roster_day_id', $fromRosterDayId)
            ->where('employee_id', $targetEmployeeId)
            ->whereRaw('LOWER(TRIM(notes)) = ?', [$targetNotesLower])
            ->first();

        if (!$targetAssignment) {
            $validator->errors()->add('target_notes', 'Rekan persetujuan tidak memiliki shift tersebut pada tanggal yang dipilih.');
            return;
        }

        if ($this->isOffDayNotes($targetNotesLower)) {
            $validator->errors()->add('target_notes', 'Rekan persetujuan harus memiliki shift kerja (bukan libur/cuti/off) pada tanggal yang dipilih.');
            return;
        }

        // 11. Check for duplicate pending requests
        $existingRequest = ShiftRequest::where('status', 'pending')
            ->where(function ($query) use ($requesterEmployee, $targetEmployeeId, $fromRosterDayId, $toRosterDayId, $requesterNotesLower, $targetNotesLower) {
                // Same exact request
                $query->where(function ($q) use ($requesterEmployee, $targetEmployeeId, $fromRosterDayId, $toRosterDayId, $requesterNotesLower, $targetNotesLower) {
                    $q->where('requester_employee_id', $requesterEmployee->id)
                        ->where('target_employee_id', $targetEmployeeId)
                        ->where('from_roster_day_id', $fromRosterDayId)
                        ->where('to_roster_day_id', $toRosterDayId)
                        ->whereRaw('LOWER(TRIM(requester_notes)) = ?', [$requesterNotesLower])
                        ->whereRaw('LOWER(TRIM(target_notes)) = ?', [$targetNotesLower]);
                });
            })
            ->exists();

        if ($existingRequest) {
            $validator->errors()->add('target_employee_id', 'Permintaan tukar shift yang sama sudah ada dan masih pending.');
            return;
        }

        // 12. Requester cannot have another pending request that uses the same source shift.
        $conflictingRequesterRequest = ShiftRequest::where('status', 'pending')
            ->where('requester_employee_id', $requesterEmployee->id)
            ->where('from_roster_day_id', $fromRosterDayId)
            ->whereRaw('LOWER(TRIM(requester_notes)) = ?', [$requesterNotesLower])
            ->exists();

        if ($conflictingRequesterRequest) {
            $validator->errors()->add('from_roster_day_id', 'Anda sudah memiliki permintaan tukar shift yang pending untuk shift ini pada tanggal tersebut.');
            return;
        }

        // 13. Target partner cannot be double-booked for the same requested shift in pending requests.
        $conflictingTargetRequest = ShiftRequest::where('status', 'pending')
            ->where('target_employee_id', $targetEmployeeId)
            ->where('to_roster_day_id', $fromRosterDayId)
            ->whereRaw('LOWER(TRIM(target_notes)) = ?', [$targetNotesLower])
            ->exists();

        if ($conflictingTargetRequest) {
            $validator->errors()->add('target_employee_id', 'Rekan persetujuan sudah memiliki permintaan pending untuk shift tujuan yang sama.');
            return;
        }
    }

    public function messages(): array
    {
        return [
            'target_employee_id.required' => 'Target employee harus dipilih.',
            'target_employee_id.exists' => 'Target employee tidak ditemukan.',
            'from_roster_day_id.required' => 'Tanggal shift Anda harus dipilih.',
            'from_roster_day_id.exists' => 'Tanggal shift tidak valid.',
            'to_roster_day_id.required' => 'Tanggal shift target harus dipilih.',
            'to_roster_day_id.exists' => 'Tanggal shift target tidak valid.',
            'requester_notes.required' => 'Shift Anda harus dipilih.',
            'requester_notes.string' => 'Shift harus berupa teks.',
            'requester_notes.max' => 'Shift tidak boleh lebih dari 50 karakter.',
            'target_notes.required' => 'Shift target harus dipilih.',
            'target_notes.string' => 'Shift target harus berupa teks.',
            'target_notes.max' => 'Shift target tidak boleh lebih dari 50 karakter.',
            'reason.max' => 'Alasan tidak boleh lebih dari 1000 karakter.',
        ];
    }

    /**
     * Determine whether requester and target grade are compatible for swap.
     */
    private function isSwapGradeCompatible(?int $requesterGrade, ?int $targetGrade): bool
    {
        if ($requesterGrade === null || $targetGrade === null) {
            return false;
        }

        if ($requesterGrade === $targetGrade) {
            return true;
        }

        $crossGradePairs = [
            14 => [13],
            13 => [14],
            12 => [11],
            11 => [12],
            8 => [9, 10],
            9 => [8, 10],
            10 => [8, 9],
        ];

        return in_array($targetGrade, $crossGradePairs[$requesterGrade] ?? [], true);
    }

    private function isOffDayNotes(string $notesLower): bool
    {
        $offDayNotes = ['l', 'l1', 'l2', 'ct', 'cs', 'dl', 'tb', 'off', 'libur', 'cuti'];

        if (in_array($notesLower, $offDayNotes, true)) {
            return true;
        }

        return str_starts_with($notesLower, 'libur')
            || str_starts_with($notesLower, 'cuti')
            || str_starts_with($notesLower, 'off');
    }
}
