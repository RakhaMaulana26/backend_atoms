<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShiftRequestRequest;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\ManagerDuty;
use App\Models\Notification;
use App\Models\ShiftRequest;
use App\Models\ShiftAssignment;
use App\Models\RosterPeriod;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ShiftRequestController extends Controller
{
    /**
     * GET /shift-requests
     * List all shift requests (with filtering)
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $employee = $user->employee;

        $query = ShiftRequest::with([
            'requesterEmployee.user',
            'targetEmployee.user',
            'fromRosterDay',
            'toRosterDay',
        ]);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Check if current user is a manager employee by role (no hardcoded IDs)
        $isManagerEmployee = $employee && in_array($user->role, [User::ROLE_MANAGER_TEKNIK, User::ROLE_GENERAL_MANAGER]);
        $isAdminRole = in_array($user->role, [User::ROLE_ADMIN, User::ROLE_MANAGER_TEKNIK, User::ROLE_GENERAL_MANAGER]);

        // Filter based on role and type parameter
        if ($isAdminRole && $request->get('type') === 'all') {
            // Admin can see all - no filter applied
        } elseif ($isManagerEmployee) {
            // Manager employee - can see:
            // 1. Requests where they are requester or target
            // 2. Requests where they work the SAME shift as requester on from_roster_day
            // 3. Requests where they work the SAME shift as target on to_roster_day
            $query->where(function ($q) use ($employee, $user) {
                // Requester or target
                $q->where('requester_employee_id', $employee->id)
                    ->orWhere('target_employee_id', $employee->id)
                    // Manager works same shift as requester on from_roster_day
                    ->orWhereExists(function ($subQ) use ($employee) {
                        $subQ->select(DB::raw(1))
                            ->from('shift_assignments')
                            ->whereColumn('shift_assignments.roster_day_id', 'shift_requests.from_roster_day_id')
                            ->whereRaw('LOWER(TRIM(shift_assignments.notes)) = LOWER(TRIM(shift_requests.requester_notes))')
                            ->where('shift_assignments.employee_id', $employee->id);
                    })
                    // Manager works same shift as target on to_roster_day
                    ->orWhereExists(function ($subQ) use ($employee) {
                        $subQ->select(DB::raw(1))
                            ->from('shift_assignments')
                            ->whereColumn('shift_assignments.roster_day_id', 'shift_requests.to_roster_day_id')
                            ->whereRaw('LOWER(TRIM(shift_assignments.notes)) = LOWER(TRIM(shift_requests.target_notes))')
                            ->where('shift_assignments.employee_id', $employee->id);
                    });

                // General Manager can see manager-to-manager requests directly.
                if ($user->role === User::ROLE_GENERAL_MANAGER) {
                    $q->orWhere(function ($subQ) {
                        $subQ->whereHas('requesterEmployee.user', function ($uq) {
                            $uq->where('role', User::ROLE_MANAGER_TEKNIK);
                        })->whereHas('targetEmployee.user', function ($uq) {
                            $uq->where('role', User::ROLE_MANAGER_TEKNIK);
                        });
                    });
                }
            });
        } elseif ($employee) {
            // Regular employee - only show requests where they are requester or target
            $query->forEmployee($employee->id);
        }

        // Sort
        $query->orderBy('created_at', 'desc');

        // Pagination
        $perPage = $request->get('per_page', 15);
        $requests = $query->paginate($perPage);

        // Add current_user_can_approve info for each request
        $requestsWithApprovalInfo = collect($requests->items())->map(function ($request) use ($employee, $isManagerEmployee, $user) {
            $requestArray = $request->toArray();
            
            // Default values
            $requestArray['current_user_can_approve_as_target'] = false;
            $requestArray['current_user_can_approve_as_manager'] = false;
            $requestArray['current_user_already_approved'] = false;
            $requestArray['is_manager_to_manager'] = $this->isManagerToManagerSwap($request);
            
            if (!$employee) return $requestArray;
            
            // Check if can approve as target
            if ($request->target_employee_id === $employee->id && 
                !$request->approved_by_target && 
                $request->status === ShiftRequest::STATUS_PENDING) {
                $requestArray['current_user_can_approve_as_target'] = true;
            }
            
            // Check if already approved as target
            if ($request->target_employee_id === $employee->id && $request->approved_by_target) {
                $requestArray['current_user_already_approved'] = true;
            }
            
            $isManagerToManagerSwap = $requestArray['is_manager_to_manager'];

            // Check if can approve as manager
            if ($isManagerEmployee && $request->status === ShiftRequest::STATUS_PENDING) {
                // Special case: manager-to-manager swap requires General Manager approval.
                if ($isManagerToManagerSwap) {
                    if ($user->role === User::ROLE_GENERAL_MANAGER) {
                        $requestArray['current_user_can_approve_as_manager'] =
                            !$request->approved_by_from_manager || !$request->approved_by_to_manager;

                        if ($request->approved_by_from_manager && $request->approved_by_to_manager) {
                            $requestArray['current_user_already_approved'] = true;
                        }
                    }

                    return $requestArray;
                }

                // Check if current manager works same shift as requester on from_roster_day
                $isFromManager = ShiftAssignment::where('roster_day_id', $request->from_roster_day_id)
                    ->where('employee_id', $employee->id)
                    ->whereRaw('LOWER(TRIM(notes)) = ?', [strtolower(trim($request->requester_notes ?? ''))])
                    ->exists();
                
                // Check if current manager works same shift as target on to_roster_day
                $isToManager = ShiftAssignment::where('roster_day_id', $request->to_roster_day_id)
                    ->where('employee_id', $employee->id)
                    ->whereRaw('LOWER(TRIM(notes)) = ?', [strtolower(trim($request->target_notes ?? ''))])
                    ->exists();
                
                // Can approve if:
                // - Is from_manager AND from_manager hasn't approved yet
                // - OR is to_manager AND to_manager hasn't approved yet
                $canApproveAsFromManager = $isFromManager && !$request->approved_by_from_manager;
                $canApproveAsToManager = $isToManager && !$request->approved_by_to_manager;
                
                if ($canApproveAsFromManager || $canApproveAsToManager) {
                    $requestArray['current_user_can_approve_as_manager'] = true;
                }
                
                // Check if already approved as manager
                if (($isFromManager && $request->approved_by_from_manager) || 
                    ($isToManager && $request->approved_by_to_manager)) {
                    $requestArray['current_user_already_approved'] = true;
                }
            }
            
            return $requestArray;
        });

        return response()->json([
            'data' => $requestsWithApprovalInfo,
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    /**
     * GET /shift-requests/{id}
     * Show single shift request detail
     */
    public function show($id)
    {
        $shiftRequest = ShiftRequest::with([
            'requesterEmployee.user',
            'targetEmployee.user',
            'fromRosterDay.rosterPeriod',
            'toRosterDay.rosterPeriod',
        ])->findOrFail($id);

        $user = Auth::user();
        $employee = $user->employee;

        // Check authorization
        $canView = false;

        // Admin and managers can view all
        if (in_array($user->role, [User::ROLE_ADMIN, User::ROLE_MANAGER_TEKNIK, User::ROLE_GENERAL_MANAGER])) {
            $canView = true;
        }

        // Requester and target can view their requests
        if ($employee) {
            if ($shiftRequest->requester_employee_id === $employee->id || 
                $shiftRequest->target_employee_id === $employee->id) {
                $canView = true;
            }
        }

        if (!$canView) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Add manager info
        $managers = $shiftRequest->getInvolvedManagers();

        return response()->json([
            'data' => $shiftRequest,
            'managers' => [
                'from_manager' => isset($managers['from_manager']) ? [
                    'id' => $managers['from_manager']->employee_id,
                    'name' => $managers['from_manager']->employee->user->name ?? 'N/A',
                ] : null,
                'to_manager' => isset($managers['to_manager']) ? [
                    'id' => $managers['to_manager']->employee_id,
                    'name' => $managers['to_manager']->employee->user->name ?? 'N/A',
                ] : null,
                'is_same_manager' => $shiftRequest->hasSameManager(),
            ],
        ]);
    }

    /**
     * POST /shift-requests
     * Create new shift swap request
     */
    public function store(StoreShiftRequestRequest $request)
    {
        $requesterEmployee = Auth::user()->employee;
        $validated = $request->validated();

        DB::beginTransaction();
        try {
            $shiftRequest = ShiftRequest::create([
                'requester_employee_id' => $requesterEmployee->id,
                'target_employee_id' => $validated['target_employee_id'],
                'from_roster_day_id' => $validated['from_roster_day_id'],
                'to_roster_day_id' => $validated['to_roster_day_id'],
                'requester_notes' => $validated['requester_notes'],
                'target_notes' => $validated['target_notes'],
                'reason' => $validated['reason'] ?? null,
                'status' => ShiftRequest::STATUS_PENDING,
            ]);

            // Load relationships for response
            $shiftRequest->load([
                'requesterEmployee.user',
                'targetEmployee.user',
                'fromRosterDay',
                'toRosterDay',
            ]);

            // Notify target employee
            $targetEmployee = Employee::findOrFail($validated['target_employee_id']);
            
            // Build detailed message
            $fromDate = Carbon::parse($shiftRequest->fromRosterDay->work_date)->format('d M Y');
            $toDate = Carbon::parse($shiftRequest->toRosterDay->work_date)->format('d M Y');
            $detailedMessage = Auth::user()->name . ' mengajukan tukar shift:\n'
                . '• Shift Anda: ' . $fromDate . ' (' . $shiftRequest->requester_notes . ')\n'
                . '• Ditukar dengan: ' . $toDate . ' (' . $shiftRequest->target_notes . ')';
            
            Notification::create([
                'user_id' => $targetEmployee->user_id,
                'sender_id' => Auth::id(),
                'title' => 'Permintaan Tukar Shift',
                'message' => $detailedMessage,
                'category' => 'shift_request',
                'reference_id' => $shiftRequest->id,
            ]);

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'create',
                'module' => 'shift_request',
                'reference_id' => $shiftRequest->id,
                'description' => 'Created shift swap request',
            ]);

            // Notify managers immediately when request is submitted
            $this->notifyManagers($shiftRequest);

            DB::commit();

            return response()->json([
                'message' => 'Permintaan tukar shift berhasil dibuat',
                'data' => $shiftRequest,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal membuat permintaan tukar shift',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /shift-requests/{id}/approve-target
     * Target employee approves the swap request
     */
    public function approveByTarget($id)
    {
        $shiftRequest = ShiftRequest::with(['requesterEmployee.user', 'targetEmployee.user', 'fromRosterDay', 'toRosterDay'])->findOrFail($id);
        $employee = Auth::user()->employee;

        if (!$employee || $shiftRequest->target_employee_id !== $employee->id) {
            return response()->json([
                'message' => 'Anda tidak berhak menyetujui permintaan ini'
            ], 403);
        }

        if ($shiftRequest->status !== ShiftRequest::STATUS_PENDING) {
            return response()->json([
                'message' => 'Permintaan ini tidak dapat disetujui'
            ], 400);
        }

        if ($shiftRequest->approved_by_target) {
            return response()->json([
                'message' => 'Anda sudah menyetujui permintaan ini'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $shiftRequest->approved_by_target = true;
            $shiftRequest->save();

            // Execute swap if all approvals are now complete (any order)
            if ($shiftRequest->isFullyApproved()) {
                $shiftRequest->status = ShiftRequest::STATUS_APPROVED;
                $shiftRequest->save();
                $this->executeShiftSwap($shiftRequest);
                $shiftRequest->swap_executed_at = now();
                $shiftRequest->status = ShiftRequest::STATUS_COMPLETED;
                $shiftRequest->save();
            }

            $isCompleted = $shiftRequest->status === ShiftRequest::STATUS_COMPLETED;

            // Notify requester
            Notification::create([
                'user_id' => $shiftRequest->requesterEmployee->user_id,
                'sender_id' => Auth::id(),
                'title' => $isCompleted ? 'Tukar Shift Selesai' : 'Tukar Shift Disetujui',
                'message' => $shiftRequest->targetEmployee->user->name
                    . ($isCompleted
                        ? ' menyetujui permintaan tukar shift Anda. Pertukaran shift sudah dieksekusi.'
                        : ' menyetujui permintaan tukar shift Anda. Menunggu persetujuan dari pihak lainnya.'),
                'category' => 'shift_request',
                'reference_id' => $shiftRequest->id,
            ]);

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'approve_target',
                'module' => 'shift_request',
                'reference_id' => $shiftRequest->id,
                'description' => 'Target employee approved shift swap request',
            ]);

            DB::commit();

            return response()->json([
                'message' => $isCompleted
                    ? 'Permintaan tukar shift disetujui. Pertukaran shift sudah dieksekusi.'
                    : 'Permintaan tukar shift disetujui. Menunggu persetujuan dari pihak lainnya.',
                'data' => $shiftRequest->fresh(['requesterEmployee.user', 'targetEmployee.user']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal menyetujui permintaan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /shift-requests/{id}/approve-manager
     * Manager approves the swap request
     */
    public function approveByManager($id)
    {
        $shiftRequest = ShiftRequest::with([
            'fromRosterDay',
            'toRosterDay',
            'requesterEmployee.user',
            'targetEmployee.user',
        ])->findOrFail($id);
        
        $user = Auth::user();
        $employee = $user->employee;

        // Must be a manager
        if (!in_array($user->role, [User::ROLE_MANAGER_TEKNIK, User::ROLE_GENERAL_MANAGER])) {
            return response()->json([
                'message' => 'Hanya manager yang dapat menyetujui permintaan ini'
            ], 403);
        }

        if ($shiftRequest->status !== ShiftRequest::STATUS_PENDING) {
            return response()->json([
                'message' => 'Permintaan ini tidak dapat disetujui'
            ], 400);
        }

        if (!$employee) {
            return response()->json([
                'message' => 'Data employee manager tidak ditemukan'
            ], 403);
        }

        $isManagerToManagerSwap = $this->isManagerToManagerSwap($shiftRequest);

        // Special case: manager-to-manager swap requires General Manager approval only.
        if ($isManagerToManagerSwap && $user->role !== User::ROLE_GENERAL_MANAGER) {
            return response()->json([
                'message' => 'Untuk pertukaran antar manager, approval manager hanya dapat dilakukan oleh General Manager.'
            ], 403);
        }

        if ($isManagerToManagerSwap) {
            DB::beginTransaction();
            try {
                $shiftRequest->approved_by_from_manager = true;
                $shiftRequest->approved_by_to_manager = true;
                $shiftRequest->save();

                // Execute swap if all approvals are now complete (target + general manager)
                if ($shiftRequest->isFullyApproved()) {
                    $shiftRequest->status = ShiftRequest::STATUS_APPROVED;
                    $shiftRequest->save();
                    $this->executeShiftSwap($shiftRequest);
                    $shiftRequest->swap_executed_at = now();
                    $shiftRequest->status = ShiftRequest::STATUS_COMPLETED;
                    $shiftRequest->save();
                }

                $isCompleted = $shiftRequest->status === ShiftRequest::STATUS_COMPLETED;

                ActivityLog::create([
                    'user_id' => Auth::id(),
                    'action' => 'approve_manager',
                    'module' => 'shift_request',
                    'reference_id' => $shiftRequest->id,
                    'description' => 'General Manager approved manager-to-manager shift swap request' . ($isCompleted ? ' and swap executed' : ''),
                ]);

                DB::commit();

                return response()->json([
                    'message' => $isCompleted
                        ? 'Permintaan tukar shift disetujui dan sudah dieksekusi'
                        : 'Approval General Manager berhasil. Menunggu persetujuan target manager.',
                    'data' => $shiftRequest->fresh(['requesterEmployee.user', 'targetEmployee.user']),
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Gagal menyetujui permintaan',
                    'error' => $e->getMessage(),
                ], 500);
            }
        }

        // Check if this manager works the SAME shift as requester (from_roster_day)
        $isFromManager = ShiftAssignment::where('roster_day_id', $shiftRequest->from_roster_day_id)
            ->where('employee_id', $employee->id)
            ->whereRaw('LOWER(TRIM(notes)) = ?', [strtolower(trim($shiftRequest->requester_notes ?? ''))])
            ->exists();

        // Check if this manager works the SAME shift as target (to_roster_day)
        $isToManager = ShiftAssignment::where('roster_day_id', $shiftRequest->to_roster_day_id)
            ->where('employee_id', $employee->id)
            ->whereRaw('LOWER(TRIM(notes)) = ?', [strtolower(trim($shiftRequest->target_notes ?? ''))])
            ->exists();

        \Log::info('approveByManager check', [
            'employee_id' => $employee->id,
            'isFromManager' => $isFromManager,
            'isToManager' => $isToManager,
            'requester_notes' => $shiftRequest->requester_notes,
            'target_notes' => $shiftRequest->target_notes,
        ]);

        if (!$isFromManager && !$isToManager) {
            return response()->json([
                'message' => 'Anda bukan manager untuk shift yang terlibat dalam permintaan ini'
            ], 403);
        }

        DB::beginTransaction();
        try {
            // Check if same manager handles both shifts
            $isSameManager = $shiftRequest->hasSameManager();

            if ($isSameManager) {
                // Single manager approval - approve both at once
                $shiftRequest->approved_by_from_manager = true;
                $shiftRequest->approved_by_to_manager = true;
            } else {
                // Different managers - approve only for their shift
                if ($isFromManager && !$shiftRequest->approved_by_from_manager) {
                    $shiftRequest->approved_by_from_manager = true;
                }
                if ($isToManager && !$shiftRequest->approved_by_to_manager) {
                    $shiftRequest->approved_by_to_manager = true;
                }
            }
            $shiftRequest->save();

            // Execute swap if all approvals are now complete (any order)
            if ($shiftRequest->isFullyApproved()) {
                $shiftRequest->status = ShiftRequest::STATUS_APPROVED;
                $shiftRequest->save();
                $this->executeShiftSwap($shiftRequest);
                $shiftRequest->swap_executed_at = now();
                $shiftRequest->status = ShiftRequest::STATUS_COMPLETED;
                $shiftRequest->save();
            }

            $isCompleted = $shiftRequest->status === ShiftRequest::STATUS_COMPLETED;

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'approve_manager',
                'module' => 'shift_request',
                'reference_id' => $shiftRequest->id,
                'description' => 'Manager approved shift swap request' . ($isCompleted ? ' and swap executed' : ''),
            ]);

            DB::commit();

            return response()->json([
                'message' => $isCompleted
                    ? 'Permintaan tukar shift disetujui dan sudah dieksekusi'
                    : 'Approval berhasil. Menunggu persetujuan dari pihak lainnya.',
                'data' => $shiftRequest->fresh(['requesterEmployee.user', 'targetEmployee.user']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal menyetujui permintaan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /shift-requests/{id}/reject
     * Reject the swap request
     */
    public function reject(Request $request, $id)
    {
        $request->validate([
            'reason' => 'sometimes|string|max:500',
        ]);

        $shiftRequest = ShiftRequest::with(['requesterEmployee.user', 'targetEmployee.user'])->findOrFail($id);
        $user = Auth::user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($shiftRequest->status !== ShiftRequest::STATUS_PENDING) {
            return response()->json([
                'message' => 'Permintaan ini tidak dapat ditolak'
            ], 400);
        }

        $canReject = false;
        $rejecterName = $user->name;

        // Target can reject
        if ($shiftRequest->target_employee_id === $employee->id) {
            $canReject = true;
        }

        // Manager can reject - check if manager works the SAME shift
        if (in_array($user->role, [User::ROLE_MANAGER_TEKNIK, User::ROLE_GENERAL_MANAGER])) {
            // Check if this manager works the SAME shift as requester
            $isFromManager = ShiftAssignment::where('roster_day_id', $shiftRequest->from_roster_day_id)
                ->where('employee_id', $employee->id)
                ->whereRaw('LOWER(TRIM(notes)) = ?', [strtolower(trim($shiftRequest->requester_notes ?? ''))])
                ->exists();

            // Check if this manager works the SAME shift as target
            $isToManager = ShiftAssignment::where('roster_day_id', $shiftRequest->to_roster_day_id)
                ->where('employee_id', $employee->id)
                ->whereRaw('LOWER(TRIM(notes)) = ?', [strtolower(trim($shiftRequest->target_notes ?? ''))])
                ->exists();

            // NO fallback - only managers with matching shifts can reject
            if ($isFromManager || $isToManager) {
                $canReject = true;
            }
        }

        if (!$canReject) {
            return response()->json([
                'message' => 'Anda tidak berhak menolak permintaan ini'
            ], 403);
        }

        DB::beginTransaction();
        try {
            $shiftRequest->status = ShiftRequest::STATUS_REJECTED;
            $shiftRequest->rejection_reason = $request->reason;
            $shiftRequest->save();

            // Notify requester
            Notification::create([
                'user_id' => $shiftRequest->requesterEmployee->user_id,
                'sender_id' => Auth::id(),
                'title' => 'Permintaan Ditolak',
                'message' => 'Permintaan tukar shift Anda ditolak oleh ' . $rejecterName 
                    . ($request->reason ? '. Alasan: ' . $request->reason : ''),
                'category' => 'shift_request',
                'reference_id' => $shiftRequest->id,
            ]);

            // If rejected by manager, also notify target
            if ($shiftRequest->target_employee_id !== $employee->id) {
                Notification::create([
                    'user_id' => $shiftRequest->targetEmployee->user_id,
                    'sender_id' => Auth::id(),
                    'title' => 'Tukar Shift Ditolak',
                    'message' => 'Permintaan tukar shift dengan ' . $shiftRequest->requesterEmployee->user->name . ' ditolak oleh manager',
                    'category' => 'shift_request',
                    'reference_id' => $shiftRequest->id,
                ]);
            }

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'reject',
                'module' => 'shift_request',
                'reference_id' => $shiftRequest->id,
                'description' => 'Rejected shift swap request',
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Permintaan tukar shift ditolak',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal menolak permintaan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /shift-requests/{id}/cancel
     * Cancel own request (requester only)
     */
    public function cancel($id)
    {
        $shiftRequest = ShiftRequest::with(['targetEmployee.user'])->findOrFail($id);
        $employee = Auth::user()->employee;

        if (!$employee || $shiftRequest->requester_employee_id !== $employee->id) {
            return response()->json([
                'message' => 'Anda tidak berhak membatalkan permintaan ini'
            ], 403);
        }

        if (!$shiftRequest->canBeCancelled()) {
            return response()->json([
                'message' => 'Permintaan ini tidak dapat dibatalkan'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $shiftRequest->status = ShiftRequest::STATUS_CANCELLED;
            $shiftRequest->cancelled_at = now();
            $shiftRequest->cancelled_by = Auth::id();
            $shiftRequest->save();

            // Notify target
            Notification::create([
                'user_id' => $shiftRequest->targetEmployee->user_id,
                'sender_id' => Auth::id(),
                'title' => 'Permintaan Dibatalkan',
                'message' => Auth::user()->name . ' membatalkan permintaan tukar shift',
                'category' => 'shift_request',
                'reference_id' => $shiftRequest->id,
            ]);

            // Notify involved managers too, so they know this request is no longer actionable
            $this->notifyCancellationToManagers($shiftRequest);

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'cancel',
                'module' => 'shift_request',
                'reference_id' => $shiftRequest->id,
                'description' => 'Cancelled shift swap request',
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Permintaan tukar shift dibatalkan',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal membatalkan permintaan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resolve manager employee IDs from user roles (no hardcoded IDs).
     */
    private function getManagerEmployeeIds(): array
    {
        return Employee::query()
            ->whereHas('user', function ($q) {
                $q->whereIn('role', [User::ROLE_MANAGER_TEKNIK, User::ROLE_GENERAL_MANAGER]);
            })
            ->pluck('id')
            ->toArray();
    }

    /**
     * Resolve general manager employee IDs.
     */
    private function getGeneralManagerEmployeeIds(): array
    {
        return Employee::query()
            ->whereHas('user', function ($q) {
                $q->where('role', User::ROLE_GENERAL_MANAGER);
            })
            ->pluck('id')
            ->toArray();
    }

    /**
     * Detect whether this request is a manager-teknik to manager-teknik swap.
     */
    private function isManagerToManagerSwap(ShiftRequest $shiftRequest): bool
    {
        $requesterRole = $shiftRequest->requesterEmployee?->user?->role;
        $targetRole = $shiftRequest->targetEmployee?->user?->role;

        return $requesterRole === User::ROLE_MANAGER_TEKNIK
            && $targetRole === User::ROLE_MANAGER_TEKNIK;
    }

    /**
     * Notify managers about shift request.
     */
    private function notifyManagers(ShiftRequest $shiftRequest): void
    {
        \Log::info('notifyManagers CALLED', [
            'shift_request_id' => $shiftRequest->id,
            'time' => now()->toDateTimeString(),
        ]);

        $managersToNotify = [];

        // Special case: manager-to-manager swap should be approved by General Manager only.
        if ($this->isManagerToManagerSwap($shiftRequest)) {
            $generalManagerEmployeeIds = $this->getGeneralManagerEmployeeIds();

            if (!empty($generalManagerEmployeeIds)) {
                $generalManagers = Employee::query()
                    ->whereIn('id', $generalManagerEmployeeIds)
                    ->with('user')
                    ->get();

                foreach ($generalManagers as $employee) {
                    if ($employee->user) {
                        $managersToNotify[$employee->user_id] = $employee->user;
                    }
                }
            }

            if (empty($managersToNotify)) {
                \Log::error('No General Manager found for manager-to-manager shift request', [
                    'shift_request_id' => $shiftRequest->id,
                ]);
                return;
            }
        }

        $managerEmployeeIds = $this->getManagerEmployeeIds();

        if (empty($managersToNotify)) {
            // Find manager working on requester's roster_day with the SAME shift (notes)
            $fromManager = ShiftAssignment::where('roster_day_id', $shiftRequest->from_roster_day_id)
                ->whereIn('employee_id', $managerEmployeeIds)
                ->whereRaw('LOWER(TRIM(notes)) = ?', [strtolower(trim($shiftRequest->requester_notes ?? ''))])
                ->with('employee.user')
                ->first();

            if ($fromManager && $fromManager->employee && $fromManager->employee->user) {
                $managersToNotify[$fromManager->employee->user_id] = $fromManager->employee->user;
                \Log::info('Found from_manager', [
                    'employee_id' => $fromManager->employee_id,
                    'user_id' => $fromManager->employee->user_id,
                    'name' => $fromManager->employee->user->name,
                    'notes' => $fromManager->notes,
                ]);
            }

            // Find manager working on target's roster_day with the SAME shift (notes)
            $toManager = ShiftAssignment::where('roster_day_id', $shiftRequest->to_roster_day_id)
                ->whereIn('employee_id', $managerEmployeeIds)
                ->whereRaw('LOWER(TRIM(notes)) = ?', [strtolower(trim($shiftRequest->target_notes ?? ''))])
                ->with('employee.user')
                ->first();

            if ($toManager && $toManager->employee && $toManager->employee->user) {
                // Add only if different from fromManager
                if (!isset($managersToNotify[$toManager->employee->user_id])) {
                    $managersToNotify[$toManager->employee->user_id] = $toManager->employee->user;
                    \Log::info('Found to_manager', [
                        'employee_id' => $toManager->employee_id,
                        'user_id' => $toManager->employee->user_id,
                        'name' => $toManager->employee->user->name,
                        'notes' => $toManager->notes,
                    ]);
                }
            }
        }

        \Log::info('notifyManagers - managers found', [
            'shift_request_id' => $shiftRequest->id,
            'from_roster_day_id' => $shiftRequest->from_roster_day_id,
            'to_roster_day_id' => $shiftRequest->to_roster_day_id,
            'requester_notes' => $shiftRequest->requester_notes,
            'target_notes' => $shiftRequest->target_notes,
            'managers_count' => count($managersToNotify),
        ]);

        // Fallback: if no specific managers found, notify all manager employees by role
        if (empty($managersToNotify)) {
            \Log::warning('No specific shift managers found, notifying all manager employees');
            
            $managerEmployees = Employee::whereIn('id', $managerEmployeeIds)
                ->with('user')
                ->get();

            foreach ($managerEmployees as $employee) {
                if ($employee->user) {
                    $managersToNotify[$employee->user_id] = $employee->user;
                }
            }
        }

        if (empty($managersToNotify)) {
            \Log::error('No managers found at all for shift request', [
                'shift_request_id' => $shiftRequest->id,
            ]);
            return;
        }

        // Build detailed message
        $fromDate = Carbon::parse($shiftRequest->fromRosterDay->work_date)->format('d M Y');
        $toDate = Carbon::parse($shiftRequest->toRosterDay->work_date)->format('d M Y');
        $detailedMessage = "Permintaan tukar shift memerlukan approval:\n"
            . "• " . $shiftRequest->requesterEmployee->user->name . ": " . $fromDate . " (" . $shiftRequest->requester_notes . ")\n"
            . "• " . $shiftRequest->targetEmployee->user->name . ": " . $toDate . " (" . $shiftRequest->target_notes . ")";

        // Send notifications
        foreach ($managersToNotify as $userId => $user) {
            \Log::info('Creating notification for manager', [
                'manager_user_id' => $userId,
                'manager_name' => $user->name,
            ]);

            $notification = Notification::create([
                'user_id' => $userId,
                'sender_id' => Auth::id(),
                'title' => 'Approval Diperlukan',
                'message' => $detailedMessage,
                'category' => 'shift_request',
                'reference_id' => $shiftRequest->id,
            ]);

            \Log::info('Manager notification CREATED', [
                'notification_id' => $notification->id,
                'manager_user_id' => $userId,
                'shift_request_id' => $shiftRequest->id,
            ]);
        }

        \Log::info('notifyManagers COMPLETED', [
            'shift_request_id' => $shiftRequest->id,
            'notifications_created' => count($managersToNotify),
        ]);
    }

    /**
     * Notify managers that request has been cancelled.
     */
    private function notifyCancellationToManagers(ShiftRequest $shiftRequest): void
    {
        $managerEmployeeIds = $this->getManagerEmployeeIds();
        if (empty($managerEmployeeIds)) {
            return;
        }

        $managerUsers = Employee::query()
            ->whereIn('id', $managerEmployeeIds)
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter()
            ->keyBy('id');

        foreach ($managerUsers as $managerUser) {
            Notification::create([
                'user_id' => $managerUser->id,
                'sender_id' => Auth::id(),
                'title' => 'Permintaan Dibatalkan',
                'message' => Auth::user()->name . ' membatalkan permintaan tukar shift. Permintaan ini tidak bisa di-approve atau ditolak lagi.',
                'category' => 'shift_request',
                'reference_id' => $shiftRequest->id,
            ]);
        }
    }

    /**
     * Execute the actual shift swap
     */
    private function executeShiftSwap(ShiftRequest $shiftRequest): void
    {
        // Swap shift payload (shift_id/notes/span_days) between requester and target
        // on each involved date. We keep employee ownership unchanged to avoid
        // empty cells (unassigned days) after swap.
        $rosterDayIds = array_values(array_unique([
            (int) $shiftRequest->from_roster_day_id,
            (int) $shiftRequest->to_roster_day_id,
        ]));

        foreach ($rosterDayIds as $rosterDayId) {
            $requesterAssignment = ShiftAssignment::where('roster_day_id', $rosterDayId)
                ->where('employee_id', $shiftRequest->requester_employee_id)
                ->first();

            $targetAssignment = ShiftAssignment::where('roster_day_id', $rosterDayId)
                ->where('employee_id', $shiftRequest->target_employee_id)
                ->first();

            if (!$requesterAssignment || !$targetAssignment) {
                // If one side has no assignment, skip safely instead of creating blank/duplicate states.
                continue;
            }

            $requesterShiftId = $requesterAssignment->shift_id;
            $requesterNotes = $requesterAssignment->notes;
            $requesterSpanDays = $requesterAssignment->span_days;

            $requesterAssignment->shift_id = $targetAssignment->shift_id;
            $requesterAssignment->notes = $targetAssignment->notes;
            $requesterAssignment->span_days = $targetAssignment->span_days;

            $targetAssignment->shift_id = $requesterShiftId;
            $targetAssignment->notes = $requesterNotes;
            $targetAssignment->span_days = $requesterSpanDays;

            $requesterAssignment->save();
            $targetAssignment->save();
        }

        // Notify both parties
        Notification::create([
            'user_id' => $shiftRequest->requesterEmployee->user_id,
            'title' => 'Tukar Shift Selesai',
            'message' => 'Tukar shift dengan ' . $shiftRequest->targetEmployee->user->name . ' telah berhasil dieksekusi',
            'category' => 'shift_request',
            'reference_id' => $shiftRequest->id,
        ]);

        Notification::create([
            'user_id' => $shiftRequest->targetEmployee->user_id,
            'title' => 'Tukar Shift Selesai',
            'message' => 'Tukar shift dengan ' . $shiftRequest->requesterEmployee->user->name . ' telah berhasil dieksekusi',
            'category' => 'shift_request',
            'reference_id' => $shiftRequest->id,
        ]);
    }

    /**
     * GET /shift-requests/my-shifts
     * Get current user's upcoming shifts that can be swapped
     */
    public function getMyShifts(Request $request)
    {
        $employee = Auth::user()->employee;

        if (!$employee) {
            return response()->json([
                'message' => 'Anda harus menjadi employee untuk melihat shift'
            ], 403);
        }

        // Minimum H-3 from now
        $minDate = Carbon::now()->addDays(3)->startOfDay()->toDateString();

        // Get from published rosters only
        $rosterPeriods = RosterPeriod::where('status', 'published')
            ->with([
                'rosterDays' => function ($query) use ($employee, $minDate) {
                    $query->where('work_date', '>=', $minDate)
                        ->orderBy('work_date', 'asc');
                },
                'rosterDays.shiftAssignments' => function ($query) use ($employee) {
                    $query->where('employee_id', $employee->id);
                },
                'rosterDays.shiftAssignments.shift'
            ])
            ->get();

        $shifts = [];
        // Non-swappable notes (case-insensitive) - these are days off or special status
        $nonSwappableNotes = ['l', 'l1', 'l2', 'ct', 'cs', 'dl', 'tb', 'off', 'libur', 'cuti'];
        
        foreach ($rosterPeriods as $period) {
            foreach ($period->rosterDays as $day) {
                foreach ($day->shiftAssignments as $assignment) {
                    // Check notes field first (primary identifier for status)
                    $notesLower = strtolower(trim($assignment->notes ?? ''));
                    
                    // Filter out non-swappable assignments based on notes
                    if (in_array($notesLower, $nonSwappableNotes) || 
                        str_starts_with($notesLower, 'l') && strlen($notesLower) <= 2 || // L, L1, L2
                        str_contains($notesLower, 'libur') ||
                        str_contains($notesLower, 'cuti') ||
                        str_contains($notesLower, 'off')) {
                        continue;
                    }
                    
                    // Also check shift name as fallback (for backward compatibility)
                    if ($assignment->shift) {
                        $shiftNameLower = strtolower(trim($assignment->shift->name ?? ''));
                        if (in_array($shiftNameLower, $nonSwappableNotes) ||
                            str_contains($shiftNameLower, 'libur') ||
                            str_contains($shiftNameLower, 'cuti') ||
                            str_contains($shiftNameLower, 'lepas')) {
                            continue;
                        }
                    }

                    // Check if there's already a pending request for this shift
                    $hasPendingRequest = ShiftRequest::where('status', ShiftRequest::STATUS_PENDING)
                        ->where('requester_employee_id', $employee->id)
                        ->where('from_roster_day_id', $day->id)
                        ->where('requester_notes', $assignment->notes)
                        ->exists();

                    $shifts[] = [
                        'roster_day_id' => $day->id,
                        'work_date' => $day->work_date->format('Y-m-d'),
                        'shift_id' => $assignment->shift_id,
                        'shift_name' => $assignment->shift->name ?? $assignment->notes,
                        'shift_start' => $assignment->shift->start_time ?? null,
                        'shift_end' => $assignment->shift->end_time ?? null,
                        'notes' => $assignment->notes,
                        'has_pending_request' => $hasPendingRequest,
                        'roster_period_id' => $period->id,
                        'roster_period_name' => $period->name ?? $period->id,
                    ];
                }
            }
        }

        return response()->json([
            'data' => $shifts,
            'count' => count($shifts),
            'debug' => [
                'employee_id' => $employee->id,
                'min_date' => $minDate,
                'published_roster_count' => $rosterPeriods->count(),
            ]
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
            'from_roster_day_id' => 'sometimes|exists:roster_days,id',
            'requester_notes' => 'sometimes|string',
        ]);

        $currentEmployee = Auth::user()->employee;

        if (!$currentEmployee) {
            return response()->json([
                'message' => 'Anda harus menjadi employee'
            ], 403);
        }

        // Minimum H-3 from now
        $minDate = Carbon::now()->addDays(3)->startOfDay()->toDateString();

        // Get requester's selected shift info for filtering
        $fromRosterDayId = $request->input('from_roster_day_id');
        $requesterNotes = $request->input('requester_notes');

        // Off-day notes
        $offDayNotes = ['l', 'l1', 'l2', 'ct', 'cs', 'dl', 'tb', 'off', 'libur', 'cuti'];

        // Get requester's off-day roster_day_ids (days where they have Libur, L, L1, L2, etc.)
        $requesterOffDays = ShiftAssignment::where('employee_id', $currentEmployee->id)
            ->whereHas('rosterDay', function ($q) use ($minDate) {
                $q->where('work_date', '>=', $minDate)
                    ->whereHas('rosterPeriod', function ($query) {
                        $query->where('status', 'published');
                    });
            })
            ->where(function ($q) use ($offDayNotes) {
                $q->whereRaw('LOWER(TRIM(notes)) IN (' . implode(',', array_map(fn($s) => "'$s'", $offDayNotes)) . ')')
                  ->orWhereRaw('LOWER(TRIM(notes)) LIKE \'libur%\'')
                  ->orWhereRaw('LOWER(TRIM(notes)) LIKE \'cuti%\'')
                  ->orWhereRaw('LOWER(TRIM(notes)) LIKE \'off%\'');
            })
            ->pluck('roster_day_id')
            ->toArray();

        // Build query for available partner shifts
        $query = ShiftAssignment::with(['employee.user', 'rosterDay', 'shift'])
            ->where('employee_id', '!=', $currentEmployee->id)
            // Only include assignments with valid notes
            ->whereNotNull('notes')
            ->where('notes', '!=', '')
            // EXCLUDE Libur shifts from being target (user request)
            ->where(function ($q) use ($offDayNotes) {
                $q->whereRaw('LOWER(TRIM(notes)) NOT IN (' . implode(',', array_map(fn($s) => "'$s'", $offDayNotes)) . ')')
                  ->whereRaw('LOWER(TRIM(notes)) NOT LIKE \'libur%\'')
                  ->whereRaw('LOWER(TRIM(notes)) NOT LIKE \'cuti%\'')
                  ->whereRaw('LOWER(TRIM(notes)) NOT LIKE \'off%\'');
            })
            ->whereHas('rosterDay', function ($q) use ($minDate) {
                // Only future dates (H-3) from published rosters
                $q->where('work_date', '>=', $minDate)
                    ->whereHas('rosterPeriod', function ($query) {
                        $query->where('status', 'published');
                    });
            })
            // Filter by: days where requester is off OR same day with different shift
            ->where(function ($q) use ($requesterOffDays, $fromRosterDayId, $requesterNotes) {
                // Allow shifts on days where requester has off-day
                if (!empty($requesterOffDays)) {
                    $q->whereIn('roster_day_id', $requesterOffDays);
                }
                
                // Also allow same day swap with different shift
                if ($fromRosterDayId && $requesterNotes) {
                    $q->orWhere(function ($subQ) use ($fromRosterDayId, $requesterNotes) {
                        $subQ->where('roster_day_id', $fromRosterDayId)
                             ->whereRaw('LOWER(TRIM(notes)) != ?', [strtolower(trim($requesterNotes))]);
                    });
                }
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

        // Filter by same role (employee_type)
        $query->whereHas('employee', function ($q) use ($currentEmployee) {
            $q->where('employee_type', $currentEmployee->employee_type)
                ->where('is_active', true);
        });

        $assignments = $query->get()->filter(function ($assignment) use ($fromRosterDayId, $offDayNotes) {
            if (!$fromRosterDayId) {
                return true;
            }

            $isSameDaySwap = (int) $assignment->roster_day_id === (int) $fromRosterDayId;
            if ($isSameDaySwap) {
                return true;
            }

            // Prevent showing target shifts that would make the target employee
            // hold two working shifts on the requester's source day after swap.
            $targetHasWorkingShiftOnFromDay = ShiftAssignment::where('roster_day_id', $fromRosterDayId)
                ->where('employee_id', $assignment->employee_id)
                ->where(function ($q) use ($offDayNotes) {
                    $q->whereRaw('LOWER(TRIM(notes)) NOT IN (' . implode(',', array_map(fn($s) => "'$s'", $offDayNotes)) . ')')
                      ->whereRaw('LOWER(TRIM(notes)) NOT LIKE \'libur%\'')
                      ->whereRaw('LOWER(TRIM(notes)) NOT LIKE \'cuti%\'')
                      ->whereRaw('LOWER(TRIM(notes)) NOT LIKE \'off%\'');
                })
                ->exists();

            return !$targetHasWorkingShiftOnFromDay;
        })->map(function ($assignment) {
            // Check if this shift already has a pending request targeting it
            $hasPendingRequest = ShiftRequest::where('status', ShiftRequest::STATUS_PENDING)
                ->where('target_employee_id', $assignment->employee_id)
                ->where('to_roster_day_id', $assignment->roster_day_id)
                ->where('target_notes', $assignment->notes)
                ->exists();

            return [
                'employee_id' => $assignment->employee_id,
                'employee_name' => $assignment->employee->user->name,
                'employee_type' => $assignment->employee->employee_type,
                'group_number' => $assignment->employee->group_number,
                'roster_day_id' => $assignment->roster_day_id,
                'work_date' => $assignment->rosterDay->work_date->format('Y-m-d'),
                'shift_id' => $assignment->shift_id,
                'shift_name' => $assignment->shift->name ?? $assignment->notes,
                'notes' => $assignment->notes,
                'has_pending_request' => $hasPendingRequest,
            ];
        });

        // Group by employee for easier frontend handling
        $grouped = $assignments->groupBy('employee_id')->map(function ($items, $employeeId) {
            $first = $items->first();
            return [
                'employee_id' => (int) $employeeId,
                'employee_name' => $first['employee_name'],
                'employee_type' => $first['employee_type'],
                'group_number' => $first['group_number'],
                'available_shifts' => $items->map(function ($item) {
                    return [
                        'roster_day_id' => $item['roster_day_id'],
                        'work_date' => $item['work_date'],
                        'shift_id' => $item['shift_id'],
                        'shift_name' => $item['shift_name'],
                        'notes' => $item['notes'],
                        'has_pending_request' => $item['has_pending_request'],
                    ];
                })->values()->toArray(),
            ];
        })->values();

        return response()->json([
            'data' => $grouped,
            'count' => $grouped->count(),
        ]);
    }

    /**
     * GET /shift-requests/pending-count
     * Get count of pending requests for current user
     */
    public function getPendingCount()
    {
        $user = Auth::user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json(['count' => 0]);
        }

        $counts = [
            'as_target' => 0,
            'as_manager' => 0,
            'my_pending' => 0,
        ];

        // Pending requests where user is target and hasn't approved yet
        $counts['as_target'] = ShiftRequest::where('status', ShiftRequest::STATUS_PENDING)
            ->where('target_employee_id', $employee->id)
            ->where('approved_by_target', false)
            ->count();

        // Pending requests needing manager approval (if user is manager)
        if (in_array($user->role, [User::ROLE_MANAGER_TEKNIK, User::ROLE_GENERAL_MANAGER])) {
            $counts['as_manager'] = ShiftRequest::where('status', ShiftRequest::STATUS_PENDING)
                ->where('approved_by_target', true)
                ->where(function ($q) use ($employee) {
                    // Needs from_manager approval
                    $q->where(function ($subQ) use ($employee) {
                        $subQ->where('approved_by_from_manager', false)
                            ->whereHas('fromRosterDay.managerDuties', function ($mq) use ($employee) {
                                $mq->where('employee_id', $employee->id);
                            });
                    })
                    // Or needs to_manager approval
                    ->orWhere(function ($subQ) use ($employee) {
                        $subQ->where('approved_by_to_manager', false)
                            ->whereHas('toRosterDay.managerDuties', function ($mq) use ($employee) {
                                $mq->where('employee_id', $employee->id);
                            });
                    });
                })
                ->count();
        }

        // My pending requests (as requester)
        $counts['my_pending'] = ShiftRequest::where('status', ShiftRequest::STATUS_PENDING)
            ->where('requester_employee_id', $employee->id)
            ->count();

        return response()->json([
            'counts' => $counts,
            'total' => $counts['as_target'] + $counts['as_manager'],
        ]);
    }

    /**
     * GET /shift-requests/manager-for-shift
     * Get manager on duty for a specific roster day and shift (notes)
     */
    public function getManagerForShift(Request $request)
    {
        $request->validate([
            'roster_day_id' => 'required|exists:roster_days,id',
            'notes' => 'required|string',
        ]);

        $rosterDayId = $request->get('roster_day_id');
        $notes = $request->get('notes');

        // Find manager working on this roster_day with the SAME shift (notes)
        $managerEmployeeIds = $this->getManagerEmployeeIds();
        $manager = ShiftAssignment::where('roster_day_id', $rosterDayId)
            ->whereIn('employee_id', $managerEmployeeIds)
            ->whereRaw('LOWER(TRIM(notes)) = ?', [strtolower(trim($notes ?? ''))])
            ->with('employee.user')
            ->first();

        if ($manager && $manager->employee && $manager->employee->user) {
            return response()->json([
                'data' => [
                    'employee_id' => $manager->employee_id,
                    'user_id' => $manager->employee->user_id,
                    'name' => $manager->employee->user->name,
                    'notes' => $manager->notes,
                ],
            ]);
        }

        // If no manager found for this shift, return null
        return response()->json([
            'data' => null,
            'message' => 'Tidak ada manager yang bertugas pada shift ini',
        ]);
    }
}
