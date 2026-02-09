<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\ManagerDuty;
use App\Models\Notification;
use App\Models\ShiftRequest;
use App\Models\ShiftAssignment;
use App\Models\RosterPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ShiftRequestController extends Controller
{
    /**
     * POST /shift-requests
     */
    public function store(Request $request)
    {
        $request->validate([
            'target_employee_id' => 'required|exists:employees,id',
            'from_roster_day_id' => 'required|exists:roster_days,id',
            'to_roster_day_id' => 'required|exists:roster_days,id',
            'shift_id' => 'required|exists:shifts,id',
            'reason' => 'sometimes|string',
        ]);

        $requesterEmployee = Auth::user()->employee;

        if (!$requesterEmployee) {
            return response()->json([
                'message' => 'You must be an employee to request shift changes'
            ], 403);
        }

        DB::beginTransaction();
        try {
            $shiftRequest = ShiftRequest::create([
                'requester_employee_id' => $requesterEmployee->id,
                'target_employee_id' => $request->target_employee_id,
                'from_roster_day_id' => $request->from_roster_day_id,
                'to_roster_day_id' => $request->to_roster_day_id,
                'shift_id' => $request->shift_id,
                'reason' => $request->reason,
                'status' => 'pending',
            ]);

            // Notify target employee
            $targetEmployee = Employee::findOrFail($request->target_employee_id);
            Notification::create([
                'user_id' => $targetEmployee->user_id,
                'title' => 'Permintaan Tukar Shift',
                'message' => Auth::user()->name . ' mengajukan tukar shift dengan Anda',
            ]);

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'create',
                'module' => 'shift_request',
                'reference_id' => $shiftRequest->id,
                'description' => 'Created shift request',
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Shift request created successfully',
                'data' => $shiftRequest,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create shift request',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /shift-requests/{id}/approve-target
     */
    public function approveByTarget($id)
    {
        $shiftRequest = ShiftRequest::findOrFail($id);
        $employee = Auth::user()->employee;

        if (!$employee || $shiftRequest->target_employee_id !== $employee->id) {
            return response()->json([
                'message' => 'You are not authorized to approve this request'
            ], 403);
        }

        if ($shiftRequest->status !== 'pending') {
            return response()->json([
                'message' => 'This request cannot be approved'
            ], 400);
        }

        $shiftRequest->approved_by_target = true;
        $shiftRequest->save();

        // Notify managers
        $this->notifyManagers($shiftRequest);

        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => 'approve_target',
            'module' => 'shift_request',
            'reference_id' => $shiftRequest->id,
            'description' => 'Target approved shift request',
        ]);

        return response()->json([
            'message' => 'Shift request approved',
            'data' => $shiftRequest,
        ]);
    }

    /**
     * POST /shift-requests/{id}/approve-manager
     */
    public function approveByManager($id)
    {
        $shiftRequest = ShiftRequest::with(['fromRosterDay', 'toRosterDay'])->findOrFail($id);
        $employee = Auth::user()->employee;

        if (!$employee || $employee->employee_type !== 'MANAGER') {
            return response()->json([
                'message' => 'Only managers can approve this request'
            ], 403);
        }

        // Check if this manager is responsible for the shift on either day
        $isFromManager = ManagerDuty::where('roster_day_id', $shiftRequest->from_roster_day_id)
            ->where('employee_id', $employee->id)
            ->where('shift_id', $shiftRequest->shift_id)
            ->exists();

        $isToManager = ManagerDuty::where('roster_day_id', $shiftRequest->to_roster_day_id)
            ->where('employee_id', $employee->id)
            ->where('shift_id', $shiftRequest->shift_id)
            ->exists();

        // Fallback: check if manager has any duty on those days (for backwards compatibility)
        if (!$isFromManager && !$isToManager) {
            $isFromManager = ManagerDuty::where('roster_day_id', $shiftRequest->from_roster_day_id)
                ->where('employee_id', $employee->id)
                ->exists();
            $isToManager = ManagerDuty::where('roster_day_id', $shiftRequest->to_roster_day_id)
                ->where('employee_id', $employee->id)
                ->exists();
        }

        if (!$isFromManager && !$isToManager) {
            return response()->json([
                'message' => 'You are not the manager for these days'
            ], 403);
        }

        if ($isFromManager) {
            $shiftRequest->approved_by_from_manager = true;
        }

        if ($isToManager) {
            $shiftRequest->approved_by_to_manager = true;
        }

        // Check if fully approved
        if ($shiftRequest->isFullyApproved()) {
            $shiftRequest->status = 'approved';
            $this->executeShiftSwap($shiftRequest);
        }

        $shiftRequest->save();

        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => 'approve_manager',
            'module' => 'shift_request',
            'reference_id' => $shiftRequest->id,
            'description' => 'Manager approved shift request',
        ]);

        return response()->json([
            'message' => 'Shift request approved by manager',
            'data' => $shiftRequest,
        ]);
    }

    /**
     * POST /shift-requests/{id}/reject
     */
    public function reject($id)
    {
        $shiftRequest = ShiftRequest::findOrFail($id);
        $employee = Auth::user()->employee;

        if (!$employee) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $canReject = false;

        // Target can reject
        if ($shiftRequest->target_employee_id === $employee->id) {
            $canReject = true;
        }

        // Manager can reject
        if ($employee->employee_type === 'MANAGER') {
            $isManager = ManagerDuty::whereIn('roster_day_id', [
                $shiftRequest->from_roster_day_id,
                $shiftRequest->to_roster_day_id,
            ])
                ->where('employee_id', $employee->id)
                ->where(function ($query) use ($shiftRequest) {
                    // Check manager for specific shift or any duty on those days
                    $query->where('shift_id', $shiftRequest->shift_id)
                        ->orWhereNull('shift_id');
                })
                ->exists();

            if ($isManager) {
                $canReject = true;
            }
        }

        if (!$canReject) {
            return response()->json([
                'message' => 'You are not authorized to reject this request'
            ], 403);
        }

        $shiftRequest->status = 'rejected';
        $shiftRequest->save();

        // Notify requester
        Notification::create([
            'user_id' => $shiftRequest->requesterEmployee->user_id,
            'title' => 'Permintaan Ditolak',
            'message' => 'Permintaan tukar shift Anda telah ditolak',
        ]);

        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => 'reject',
            'module' => 'shift_request',
            'reference_id' => $shiftRequest->id,
            'description' => 'Rejected shift request',
        ]);

        return response()->json([
            'message' => 'Shift request rejected',
        ]);
    }

    /**
     * Notify managers about shift request
     */
    private function notifyManagers(ShiftRequest $shiftRequest)
    {
        // Find manager for the specific shift on the from-day
        $fromManager = ManagerDuty::where('roster_day_id', $shiftRequest->from_roster_day_id)
            ->where('shift_id', $shiftRequest->shift_id)
            ->with('employee.user')
            ->first();

        // Find manager for the specific shift on the to-day
        $toManager = ManagerDuty::where('roster_day_id', $shiftRequest->to_roster_day_id)
            ->where('shift_id', $shiftRequest->shift_id)
            ->with('employee.user')
            ->first();

        $managerIds = [];

        if ($fromManager) {
            $managerIds[] = $fromManager->employee->user_id;
        }

        if ($toManager && $toManager->employee->user_id !== ($fromManager->employee->user_id ?? null)) {
            $managerIds[] = $toManager->employee->user_id;
        }

        foreach (array_unique($managerIds) as $managerId) {
            Notification::create([
                'user_id' => $managerId,
                'title' => 'Approval Diperlukan',
                'message' => 'Ada permintaan tukar shift yang memerlukan approval Anda',
            ]);
        }
    }

    /**
     * Execute shift swap
     */
    private function executeShiftSwap(ShiftRequest $shiftRequest)
    {
        // This is a simplified version
        // In production, you would need to handle the actual shift assignments swap
        
        Notification::create([
            'user_id' => $shiftRequest->requesterEmployee->user_id,
            'title' => 'Permintaan Disetujui',
            'message' => 'Permintaan tukar shift Anda telah disetujui',
        ]);

        Notification::create([
            'user_id' => $shiftRequest->targetEmployee->user_id,
            'title' => 'Tukar Shift Disetujui',
            'message' => 'Tukar shift dengan ' . $shiftRequest->requesterEmployee->user->name . ' telah disetujui',
        ]);
    }

    /**
     * GET /shift-requests/my-shifts
     * Get current user's upcoming shifts
     */
    public function getMyShifts(Request $request)
    {
        $employee = Auth::user()->employee;

        if (!$employee) {
            return response()->json([
                'message' => 'You must be an employee to view shifts'
            ], 403);
        }

        // Alternative approach: Get from published rosters directly
        $rosterPeriods = RosterPeriod::where('status', 'published')
            ->with([
                'rosterDays' => function ($query) use ($employee) {
                    $query->where('work_date', '>=', now()->toDateString())
                        ->orderBy('work_date', 'asc');
                },
                'rosterDays.shiftAssignments' => function ($query) use ($employee) {
                    $query->where('employee_id', $employee->id);
                },
                'rosterDays.shiftAssignments.shift'
            ])
            ->get();

        $shifts = [];
        foreach ($rosterPeriods as $period) {
            foreach ($period->rosterDays as $day) {
                foreach ($day->shiftAssignments as $assignment) {
                    $shifts[] = [
                        'roster_day_id' => $day->id,
                        'work_date' => $day->work_date,
                        'shift_id' => $assignment->shift_id,
                        'shift_name' => $assignment->shift->name,
                    ];
                }
            }
        }

        return response()->json([
            'data' => $shifts,
            'count' => count($shifts),
        ]);
    }

    /**
     * GET /shift-requests/available-partners
     * Get employees available for shift swap based on criteria
     */
    public function getAvailablePartners(Request $request)
    {
        $request->validate([
            'roster_day_id' => 'sometimes|exists:roster_days,id',
            'shift_id' => 'sometimes|exists:shifts,id',
            'employee_id' => 'sometimes|exists:employees,id',
        ]);

        $currentEmployee = Auth::user()->employee;

        if (!$currentEmployee) {
            return response()->json([
                'message' => 'You must be an employee'
            ], 403);
        }

        $query = ShiftAssignment::with(['employee.user', 'rosterDay', 'shift'])
            ->where('employee_id', '!=', $currentEmployee->id)
            ->whereHas('rosterDay', function ($q) {
                // Only future dates from published rosters
                $q->where('work_date', '>=', now()->toDateString())
                    ->whereHas('rosterPeriod', function ($query) {
                        $query->where('status', 'published');
                    });
            });

        // Filter by roster day if provided
        if ($request->has('roster_day_id')) {
            $query->where('roster_day_id', $request->roster_day_id);
        }

        // Filter by shift if provided
        if ($request->has('shift_id')) {
            $query->where('shift_id', $request->shift_id);
        }

        // Filter by specific employee if provided
        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        // Filter by same role (CNS with CNS, etc)
        $query->whereHas('employee', function ($q) use ($currentEmployee) {
            $q->where('role', $currentEmployee->role);
        });

        $assignments = $query->get()->map(function ($assignment) {
            return [
                'employee_id' => $assignment->employee_id,
                'employee_name' => $assignment->employee->user->name,
                'employee_role' => $assignment->employee->role,
                'roster_day_id' => $assignment->roster_day_id,
                'work_date' => $assignment->rosterDay->work_date,
                'shift_id' => $assignment->shift_id,
                'shift_name' => $assignment->shift->name,
            ];
        });

        // Group by employee for easier frontend handling
        $grouped = $assignments->groupBy('employee_id')->map(function ($items, $employeeId) {
            $first = $items->first();
            return [
                'employee_id' => $employeeId,
                'employee_name' => $first['employee_name'],
                'employee_role' => $first['employee_role'],
                'available_shifts' => $items->map(function ($item) {
                    return [
                        'roster_day_id' => $item['roster_day_id'],
                        'work_date' => $item['work_date'],
                        'shift_id' => $item['shift_id'],
                        'shift_name' => $item['shift_name'],
                    ];
                })->values()->toArray(),
            ];
        })->values();

        return response()->json([
            'data' => $grouped,
            'count' => $grouped->count(),
        ]);
    }
}
