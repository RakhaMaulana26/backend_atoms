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
        $requesterNotes = $this->input('requester_notes');
        $targetNotes = $this->input('target_notes');

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

        // 4. Validate roster days
        $fromRosterDay = RosterDay::with('rosterPeriod')->find($fromRosterDayId);
        $toRosterDay = RosterDay::with('rosterPeriod')->find($toRosterDayId);

        if (!$fromRosterDay || !$toRosterDay) {
            $validator->errors()->add('from_roster_day_id', 'Roster day tidak valid.');
            return;
        }

        // 5. Both rosters must be published
        if ($fromRosterDay->rosterPeriod->status !== 'published') {
            $validator->errors()->add('from_roster_day_id', 'Roster period belum dipublish.');
            return;
        }

        if ($toRosterDay->rosterPeriod->status !== 'published') {
            $validator->errors()->add('to_roster_day_id', 'Roster period belum dipublish.');
            return;
        }

        // 6. Check H-3 rule (minimum 3 days before)
        $minDate = Carbon::now()->addDays(3)->startOfDay();
        
        if (Carbon::parse($fromRosterDay->work_date)->lt($minDate)) {
            $validator->errors()->add('from_roster_day_id', 'Tukar shift harus diajukan minimal H-3 sebelum tanggal shift.');
            return;
        }

        if (Carbon::parse($toRosterDay->work_date)->lt($minDate)) {
            $validator->errors()->add('to_roster_day_id', 'Tanggal target shift harus minimal H-3 dari sekarang.');
            return;
        }

        // 7. Requester must have a shift assignment on from_roster_day with the specified notes
        $requesterAssignment = ShiftAssignment::where('roster_day_id', $fromRosterDayId)
            ->where('employee_id', $requesterEmployee->id)
            ->where('notes', $requesterNotes)
            ->first();

        if (!$requesterAssignment) {
            $validator->errors()->add('requester_notes', 'Anda tidak memiliki shift tersebut pada tanggal yang dipilih.');
            return;
        }

        // 8. Target must have a shift assignment on to_roster_day with the specified notes
        $targetAssignment = ShiftAssignment::where('roster_day_id', $toRosterDayId)
            ->where('employee_id', $targetEmployeeId)
            ->where('notes', $targetNotes)
            ->first();

        if (!$targetAssignment) {
            $validator->errors()->add('target_notes', 'Target employee tidak memiliki shift tersebut pada tanggal yang dipilih.');
            return;
        }

        // 9. Check for duplicate pending requests
        $existingRequest = ShiftRequest::where('status', 'pending')
            ->where(function ($query) use ($requesterEmployee, $targetEmployeeId, $fromRosterDayId, $toRosterDayId, $requesterNotes, $targetNotes) {
                // Same exact request
                $query->where(function ($q) use ($requesterEmployee, $targetEmployeeId, $fromRosterDayId, $toRosterDayId, $requesterNotes, $targetNotes) {
                    $q->where('requester_employee_id', $requesterEmployee->id)
                        ->where('target_employee_id', $targetEmployeeId)
                        ->where('from_roster_day_id', $fromRosterDayId)
                        ->where('to_roster_day_id', $toRosterDayId)
                        ->where('requester_notes', $requesterNotes)
                        ->where('target_notes', $targetNotes);
                });
            })
            ->exists();

        if ($existingRequest) {
            $validator->errors()->add('target_employee_id', 'Permintaan tukar shift yang sama sudah ada dan masih pending.');
            return;
        }

        // 10. Check if requester has another pending request for the same from_roster_day and shift
        $conflictingRequesterRequest = ShiftRequest::where('status', 'pending')
            ->where('requester_employee_id', $requesterEmployee->id)
            ->where('from_roster_day_id', $fromRosterDayId)
            ->where('requester_notes', $requesterNotes)
            ->exists();

        if ($conflictingRequesterRequest) {
            $validator->errors()->add('from_roster_day_id', 'Anda sudah memiliki permintaan tukar shift yang pending untuk shift ini pada tanggal tersebut.');
            return;
        }

        // 11. Check if target has another pending request where their shift is being requested
        $conflictingTargetRequest = ShiftRequest::where('status', 'pending')
            ->where('target_employee_id', $targetEmployeeId)
            ->where('to_roster_day_id', $toRosterDayId)
            ->where('target_notes', $targetNotes)
            ->exists();

        if ($conflictingTargetRequest) {
            $validator->errors()->add('target_employee_id', 'Target employee sudah memiliki permintaan tukar shift yang pending untuk shift tersebut.');
            return;
        }

        // 12. Requester cannot select a target day where they already have a WORKING shift
        // Off days (L, Libur, Cuti, etc.) are allowed - user can pick up shifts on their off days
        // This prevents double shifts on the same day
        // EXCEPTION: Same-day swap is allowed (when from_roster_day_id == to_roster_day_id)
        $offDayNotes = ['l', 'l1', 'l2', 'ct', 'cs', 'dl', 'tb', 'off', 'libur', 'cuti'];
        
        // Only check for double shift if it's NOT a same-day swap
        if ($fromRosterDayId != $toRosterDayId) {
            $requesterHasWorkingShiftOnTargetDay = ShiftAssignment::where('roster_day_id', $toRosterDayId)
                ->where('employee_id', $requesterEmployee->id)
                ->where(function ($q) use ($offDayNotes) {
                    // Only check for WORKING shifts, not off days
                    $q->whereRaw('LOWER(TRIM(notes)) NOT IN (' . implode(',', array_map(fn($s) => "'$s'", $offDayNotes)) . ')')
                      ->whereRaw('LOWER(TRIM(notes)) NOT LIKE \'libur%\'')
                      ->whereRaw('LOWER(TRIM(notes)) NOT LIKE \'cuti%\'')
                      ->whereRaw('LOWER(TRIM(notes)) NOT LIKE \'off%\'');
                })
                ->exists();

            if ($requesterHasWorkingShiftOnTargetDay) {
                $toDate = Carbon::parse($toRosterDay->work_date)->format('d M Y');
                $validator->errors()->add('to_roster_day_id', "Anda sudah memiliki jadwal shift kerja pada tanggal $toDate. Pilih tanggal dimana Anda libur untuk menghindari shift ganda.");
                return;
            }
        }

        // 13. Similarly, target cannot already have a WORKING shift on requester's from_roster_day
        // This prevents target from getting double shifts after swap
        // EXCEPTION: Same-day swap is allowed
        if ($fromRosterDayId != $toRosterDayId) {
            $targetHasWorkingShiftOnFromDay = ShiftAssignment::where('roster_day_id', $fromRosterDayId)
                ->where('employee_id', $targetEmployeeId)
                ->where(function ($q) use ($offDayNotes) {
                    // Only check for WORKING shifts, not off days
                    $q->whereRaw('LOWER(TRIM(notes)) NOT IN (' . implode(',', array_map(fn($s) => "'$s'", $offDayNotes)) . ')')
                      ->whereRaw('LOWER(TRIM(notes)) NOT LIKE \'libur%\'')
                      ->whereRaw('LOWER(TRIM(notes)) NOT LIKE \'cuti%\'')
                      ->whereRaw('LOWER(TRIM(notes)) NOT LIKE \'off%\'');
                })
                ->exists();

            if ($targetHasWorkingShiftOnFromDay) {
                $fromDate = Carbon::parse($fromRosterDay->work_date)->format('d M Y');
                $validator->errors()->add('from_roster_day_id', "Target employee sudah memiliki jadwal shift kerja pada tanggal $fromDate. Tukar shift tidak memungkinkan karena akan menyebabkan shift ganda.");
                return;
            }
        }

        // 14. For same-day swap, requester and target must have DIFFERENT shifts
        if ($fromRosterDayId == $toRosterDayId) {
            if (strtolower(trim($requesterNotes)) === strtolower(trim($targetNotes))) {
                $validator->errors()->add('target_notes', 'Untuk tukar shift di hari yang sama, Anda harus memilih shift yang berbeda.');
                return;
            }
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
}
