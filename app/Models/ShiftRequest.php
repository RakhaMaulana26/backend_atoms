<?php

namespace App\Models;

use App\Services\ShiftResolverService;
use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class ShiftRequest extends Model
{
    use HasFactory, SoftDeletes, HasAuditFields;

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'requester_employee_id',
        'target_employee_id',
        'from_roster_day_id',
        'to_roster_day_id',
        'requester_notes',
        'target_notes',
        'reason',
        'status',
        'approved_by_target',
        'from_manager_id',
        'to_manager_id',
        'approved_by_from_manager',
        'approved_by_to_manager',
        'cancelled_at',
        'cancelled_by',
        'rejection_reason',
        'swap_executed_at',
    ];

    protected function casts(): array
    {
        return [
            'approved_by_target' => 'boolean',
            'approved_by_from_manager' => 'boolean',
            'approved_by_to_manager' => 'boolean',
            'cancelled_at' => 'datetime',
            'swap_executed_at' => 'datetime',
        ];
    }

    // =============================
    // RELATIONSHIPS
    // =============================

    public function requesterEmployee()
    {
        return $this->belongsTo(Employee::class, 'requester_employee_id');
    }

    public function targetEmployee()
    {
        return $this->belongsTo(Employee::class, 'target_employee_id');
    }

    public function fromRosterDay()
    {
        return $this->belongsTo(RosterDay::class, 'from_roster_day_id');
    }

    public function toRosterDay()
    {
        return $this->belongsTo(RosterDay::class, 'to_roster_day_id');
    }

    public function fromManagerEmployee()
    {
        return $this->belongsTo(Employee::class, 'from_manager_id');
    }

    public function toManagerEmployee()
    {
        return $this->belongsTo(Employee::class, 'to_manager_id');
    }

    /**
     * Get the requester's shift (dynamically resolved from notes)
     */
    public function getRequesterShiftAttribute(): ?Shift
    {
        $shiftId = $this->getRequesterShiftId();
        return $shiftId ? Shift::find($shiftId) : null;
    }

    /**
     * Get the target's shift (dynamically resolved from notes)
     */
    public function getTargetShiftAttribute(): ?Shift
    {
        $shiftId = $this->getTargetShiftId();
        return $shiftId ? Shift::find($shiftId) : null;
    }

    // Legacy alias for backward compatibility
    public function shift()
    {
        return $this->belongsTo(Shift::class, 'requester_shift_id');
    }

    // =============================
    // HELPER METHODS
    // =============================

    /**
     * Check if all required approvals are obtained
     */
    public function isFullyApproved(): bool
    {
        return $this->approved_by_target 
            && $this->approved_by_from_manager 
            && $this->approved_by_to_manager;
    }

    /**
     * Check if request is still pending
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if request can be cancelled
     */
    public function canBeCancelled(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if both managers are the same person
     * Manager is determined from manager_duties on the involved shift/date
     */
    public function hasSameManager(): bool
    {
        $fromManager = $this->getFromManager();
        $toManager = $this->getToManager();

        if (!$fromManager || !$toManager) {
            return false;
        }

        return $fromManager->employee_id === $toManager->employee_id;
    }

    /**
     * Get the manager employee working the requester's EXACT shift
     */
    public function getFromManager(): ?ManagerDuty
    {
        $requesterShiftId = $this->getRequesterShiftId();
        if (!$requesterShiftId) {
            return null;
        }

        return ManagerDuty::where('roster_day_id', $this->from_roster_day_id)
            ->where('shift_id', $requesterShiftId)
            ->with('employee.user')
            ->first();
    }

    /**
     * Get the manager employee working the target's EXACT shift
     */
    public function getToManager(): ?ManagerDuty
    {
        $targetShiftId = $this->getTargetShiftId();
        if (!$targetShiftId) {
            return null;
        }

        return ManagerDuty::where('roster_day_id', $this->to_roster_day_id)
            ->where('shift_id', $targetShiftId)
            ->with('employee.user')
            ->first();
    }

    /**
     * Get managers involved in this swap request
     */
    public function getInvolvedManagers(): array
    {
        $managers = [];

        $requesterShiftId = $this->getRequesterShiftId();
        $targetShiftId = $this->getTargetShiftId();

        // Debug logging
        \Log::info('getInvolvedManagers called', [
            'shift_request_id' => $this->id,
            'from_roster_day_id' => $this->from_roster_day_id,
            'to_roster_day_id' => $this->to_roster_day_id,
            'requester_notes' => $this->requester_notes,
            'target_notes' => $this->target_notes,
            'requesterShiftId' => $requesterShiftId,
            'targetShiftId' => $targetShiftId,
        ]);

        if ($requesterShiftId) {
            $fromManager = ManagerDuty::where('roster_day_id', $this->from_roster_day_id)
                ->where('shift_id', $requesterShiftId)
                ->with('employee.user')
                ->first();

            \Log::info('From manager query result', [
                'roster_day_id' => $this->from_roster_day_id,
                'shift_id' => $requesterShiftId,
                'found' => $fromManager ? true : false,
                'manager_employee_id' => $fromManager?->employee_id,
            ]);

            if ($fromManager) {
                $managers['from_manager'] = $fromManager;
            }
        }

        if ($targetShiftId) {
            $toManager = ManagerDuty::where('roster_day_id', $this->to_roster_day_id)
                ->where('shift_id', $targetShiftId)
                ->with('employee.user')
                ->first();

            \Log::info('To manager query result', [
                'roster_day_id' => $this->to_roster_day_id,
                'shift_id' => $targetShiftId,
                'found' => $toManager ? true : false,
                'manager_employee_id' => $toManager?->employee_id,
            ]);

            if ($toManager && (!isset($managers['from_manager']) || $toManager->employee_id !== $managers['from_manager']->employee_id)) {
                $managers['to_manager'] = $toManager;
            }
        }

        \Log::info('Final managers', ['count' => count($managers), 'keys' => array_keys($managers)]);

        return $managers;
    }

    /**
     * Get the shift_id for the requester's shift based on notes
     * First checks the assignment, then resolves from notes -> shift mapping
     */
    public function getRequesterShiftId(): ?int
    {
        // First try to get from assignment
        $assignment = ShiftAssignment::where('roster_day_id', $this->from_roster_day_id)
            ->where('employee_id', $this->requester_employee_id)
            ->whereRaw('LOWER(TRIM(notes)) = ?', [strtolower(trim($this->requester_notes ?? ''))])
            ->first();

        if ($assignment?->shift_id) {
            return $assignment->shift_id;
        }

        // Fallback: resolve shift_id from notes
        return $this->resolveShiftIdFromNotes($this->requester_notes);
    }

    /**
     * Get the shift_id for the target's shift based on notes
     * First checks the assignment, then resolves from notes -> shift mapping
     */
    public function getTargetShiftId(): ?int
    {
        // First try to get from assignment
        $assignment = ShiftAssignment::where('roster_day_id', $this->to_roster_day_id)
            ->where('employee_id', $this->target_employee_id)
            ->whereRaw('LOWER(TRIM(notes)) = ?', [strtolower(trim($this->target_notes ?? ''))])
            ->first();

        if ($assignment?->shift_id) {
            return $assignment->shift_id;
        }

        // Fallback: resolve shift_id from notes
        return $this->resolveShiftIdFromNotes($this->target_notes);
    }

    /**
     * Resolve shift_id from notes by looking up Shift table
     * Uses centralized ShiftResolverService for consistency
     */
    private function resolveShiftIdFromNotes(?string $notes): ?int
    {
        return ShiftResolverService::resolveShiftId($notes);
    }

    // =============================
    // SCOPES
    // =============================

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeForEmployee($query, $employeeId)
    {
        return $query->where(function ($q) use ($employeeId) {
            $q->where('requester_employee_id', $employeeId)
                ->orWhere('target_employee_id', $employeeId);
        });
    }

    public function scopeNeedsManagerApproval($query, $employeeId)
    {
        // Manager can approve if they work the SAME shift on either day
        return $query->where('status', self::STATUS_PENDING)
            ->where('approved_by_target', true)
            ->where(function ($q) use ($employeeId) {
                // Manager works same shift as requester on from_roster_day
                $q->whereExists(function ($subQ) use ($employeeId) {
                    $subQ->select(DB::raw(1))
                        ->from('shift_assignments')
                        ->whereColumn('shift_assignments.roster_day_id', 'shift_requests.from_roster_day_id')
                        ->whereColumn('shift_assignments.notes', 'shift_requests.requester_notes')
                        ->where('shift_assignments.employee_id', $employeeId);
                })
                // OR Manager works same shift as target on to_roster_day
                ->orWhereExists(function ($subQ) use ($employeeId) {
                    $subQ->select(DB::raw(1))
                        ->from('shift_assignments')
                        ->whereColumn('shift_assignments.roster_day_id', 'shift_requests.to_roster_day_id')
                        ->whereColumn('shift_assignments.notes', 'shift_requests.target_notes')
                        ->where('shift_assignments.employee_id', $employeeId);
                });
            });
    }
}
