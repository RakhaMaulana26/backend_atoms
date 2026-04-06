<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShiftRequestRequest;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\ManagerDuty;
use App\Models\Notification;
use App\Models\RosterDay;
use App\Models\Shift;
use App\Models\ShiftRequest;
use App\Models\ShiftAssignment;
use App\Models\RosterPeriod;
use App\Models\User;
use App\Services\ShiftResolverService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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
        $rosterPeriodId = $request->integer('roster_period_id');

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

        // Manager approver can be role-based manager OR temporary manager on duty (manager_duties)
        $isManagerEmployee = $employee && (
            in_array($user->role, [User::ROLE_MANAGER_TEKNIK, User::ROLE_GENERAL_MANAGER])
            || $this->hasManagerDutyInRosterPeriod($employee->id, $rosterPeriodId)
        );
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
                    })
                    // Temporary manager on duty for requester day
                    ->orWhereExists(function ($subQ) use ($employee) {
                        $subQ->select(DB::raw(1))
                            ->from('manager_duties')
                            ->whereColumn('manager_duties.roster_day_id', 'shift_requests.from_roster_day_id')
                            ->where('manager_duties.employee_id', $employee->id);
                    })
                    // Temporary manager on duty for target day
                    ->orWhereExists(function ($subQ) use ($employee) {
                        $subQ->select(DB::raw(1))
                            ->from('manager_duties')
                            ->whereColumn('manager_duties.roster_day_id', 'shift_requests.to_roster_day_id')
                            ->where('manager_duties.employee_id', $employee->id);
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

                $fromManagerDuty = $this->resolveManagerDutyForContext(
                    (int) $request->from_roster_day_id,
                    (string) $request->requester_notes,
                    $request->requesterEmployee?->group_number
                );

                $toManagerDuty = $this->resolveManagerDutyForContext(
                    (int) $request->to_roster_day_id,
                    (string) $request->target_notes,
                    $request->targetEmployee?->group_number
                );

                $isFromManager = $fromManagerDuty && (int) $fromManagerDuty->employee_id === (int) $employee->id;
                $isToManager = $toManagerDuty && (int) $toManagerDuty->employee_id === (int) $employee->id;
                
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

        // Temporary manager assigned on related roster context can also view detail.
        if (!$canView && $employee) {
            $fromManagerDuty = $this->resolveManagerDutyForContext(
                (int) $shiftRequest->from_roster_day_id,
                (string) $shiftRequest->requester_notes,
                $shiftRequest->requesterEmployee?->group_number
            );

            $toManagerDuty = $this->resolveManagerDutyForContext(
                (int) $shiftRequest->to_roster_day_id,
                (string) $shiftRequest->target_notes,
                $shiftRequest->targetEmployee?->group_number
            );

            $canView = (
                ($fromManagerDuty && (int) $fromManagerDuty->employee_id === (int) $employee->id)
                || ($toManagerDuty && (int) $toManagerDuty->employee_id === (int) $employee->id)
            );
        }

        if (!$canView) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $fromManagerDuty = $this->resolveManagerDutyForContext(
            (int) $shiftRequest->from_roster_day_id,
            (string) $shiftRequest->requester_notes,
            $shiftRequest->requesterEmployee?->group_number
        );

        $toManagerDuty = $this->resolveManagerDutyForContext(
            (int) $shiftRequest->to_roster_day_id,
            (string) $shiftRequest->target_notes,
            $shiftRequest->targetEmployee?->group_number
        );

        return response()->json([
            'data' => $shiftRequest,
            'managers' => [
                'from_manager' => $fromManagerDuty ? [
                    'id' => $fromManagerDuty->employee_id,
                    'name' => $fromManagerDuty->employee->user->name ?? 'N/A',
                ] : null,
                'to_manager' => $toManagerDuty ? [
                    'id' => $toManagerDuty->employee_id,
                    'name' => $toManagerDuty->employee->user->name ?? 'N/A',
                ] : null,
                'is_same_manager' => $fromManagerDuty && $toManagerDuty
                    ? ((int) $fromManagerDuty->employee_id === (int) $toManagerDuty->employee_id)
                    : false,
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

        Log::info('[shift_request][create] Incoming swap request', [
            'requester_employee_id' => $requesterEmployee?->id,
            'requester_user_id' => Auth::id(),
            'requester_name' => Auth::user()?->name,
            'requester_email' => Auth::user()?->email,
            'target_employee_id' => $validated['target_employee_id'] ?? null,
            'from_roster_day_id' => $validated['from_roster_day_id'] ?? null,
            'to_roster_day_id' => $validated['to_roster_day_id'] ?? null,
            'requester_notes' => $validated['requester_notes'] ?? null,
            'target_notes' => $validated['target_notes'] ?? null,
        ]);

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

            Log::info('[shift_request][notify] Target notification created', [
                'shift_request_id' => $shiftRequest->id,
                'recipient_user_id' => $targetEmployee->user_id,
                'recipient_employee_id' => $targetEmployee->id,
                'recipient_name' => $targetEmployee->user?->name,
                'recipient_email' => $targetEmployee->user?->email,
                'recipient_role' => $targetEmployee->user?->role,
                'channel' => 'in-app',
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

            Log::info('[shift_request][create] Swap request committed', [
                'shift_request_id' => $shiftRequest->id,
                'requester_user_id' => Auth::id(),
                'target_user_id' => $targetEmployee->user_id,
            ]);

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

        $fromManagerDuty = $this->resolveManagerDutyForContext(
            (int) $shiftRequest->from_roster_day_id,
            (string) $shiftRequest->requester_notes,
            $shiftRequest->requesterEmployee?->group_number
        );

        $toManagerDuty = $this->resolveManagerDutyForContext(
            (int) $shiftRequest->to_roster_day_id,
            (string) $shiftRequest->target_notes,
            $shiftRequest->targetEmployee?->group_number
        );

        $isFromManager = $fromManagerDuty && (int) $fromManagerDuty->employee_id === (int) $employee->id;
        $isToManager = $toManagerDuty && (int) $toManagerDuty->employee_id === (int) $employee->id;

        \Log::info('approveByManager check', [
            'employee_id' => $employee->id,
            'isFromManager' => $isFromManager,
            'isToManager' => $isToManager,
            'requester_notes' => $shiftRequest->requester_notes,
            'target_notes' => $shiftRequest->target_notes,
            'from_manager_employee_id' => $fromManagerDuty?->employee_id,
            'to_manager_employee_id' => $toManagerDuty?->employee_id,
        ]);

        if (!$isFromManager && !$isToManager) {
            return response()->json([
                'message' => 'Anda bukan manager untuk shift yang terlibat dalam permintaan ini'
            ], 403);
        }

        DB::beginTransaction();
        try {
            // Check if same manager handles both shifts
            $isSameManager = $fromManagerDuty
                && $toManagerDuty
                && (int) $fromManagerDuty->employee_id === (int) $toManagerDuty->employee_id;

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

        // Manager can reject if currently assigned as manager duty on involved shift/date
        if ($employee) {
            $requesterShiftId = $shiftRequest->getRequesterShiftId();
            $targetShiftId = $shiftRequest->getTargetShiftId();

            $isFromManager = $requesterShiftId
                ? ManagerDuty::where('roster_day_id', $shiftRequest->from_roster_day_id)
                    ->where('employee_id', $employee->id)
                    ->where('shift_id', $requesterShiftId)
                    ->exists()
                : false;

            $isToManager = $targetShiftId
                ? ManagerDuty::where('roster_day_id', $shiftRequest->to_roster_day_id)
                    ->where('employee_id', $employee->id)
                    ->where('shift_id', $targetShiftId)
                    ->exists()
                : false;

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
        $requesterRole = $this->normalizeRole($shiftRequest->requesterEmployee?->user?->role);
        $targetRole = $this->normalizeRole($shiftRequest->targetEmployee?->user?->role);
        $managerTeknikRole = $this->normalizeRole(User::ROLE_MANAGER_TEKNIK);

        return $requesterRole === $managerTeknikRole
            && $targetRole === $managerTeknikRole;
    }

    private function normalizeRole(?string $role): string
    {
        if (!$role) {
            return '';
        }

        $collapsed = preg_replace('/\s+/', ' ', trim($role));
        return mb_strtolower($collapsed ?? '');
    }

    /**
     * Map shift notes (P, S, M, L, etc.) to shift_id using centralized service
     */
    private function getShiftIdFromNotes(?string $notes): ?int
    {
        return ShiftResolverService::resolveShiftId($notes);
    }

    private function resolveManagerDutyForContext(int $rosterDayId, string $notes, ?int $groupNumber = null): ?ManagerDuty
    {
        $normalizedNotes = strtolower(trim($notes));
        $shiftId = ShiftResolverService::resolveShiftId($notes);

        $byAssignmentSameGroup = ManagerDuty::query()
            ->where('roster_day_id', $rosterDayId)
            ->with(['employee.user'])
            ->whereHas('employee.shiftAssignments', function ($shiftAssignmentQuery) use ($rosterDayId, $normalizedNotes) {
                $shiftAssignmentQuery->where('roster_day_id', $rosterDayId)
                    ->whereRaw('LOWER(TRIM(notes)) = ?', [$normalizedNotes]);
            })
            ->when($groupNumber !== null, function ($q) use ($groupNumber) {
                $q->whereHas('employee', function ($employeeQuery) use ($groupNumber) {
                    $employeeQuery->where('group_number', $groupNumber);
                });
            })
            ->first();

        if ($byAssignmentSameGroup) {
            return $byAssignmentSameGroup;
        }

        $byAssignmentAnyGroup = ManagerDuty::query()
            ->where('roster_day_id', $rosterDayId)
            ->with(['employee.user'])
            ->whereHas('employee.shiftAssignments', function ($shiftAssignmentQuery) use ($rosterDayId, $normalizedNotes) {
                $shiftAssignmentQuery->where('roster_day_id', $rosterDayId)
                    ->whereRaw('LOWER(TRIM(notes)) = ?', [$normalizedNotes]);
            })
            ->first();

        if ($byAssignmentAnyGroup) {
            return $byAssignmentAnyGroup;
        }

        if (!$shiftId) {
            return null;
        }

        $exactShiftCandidates = ManagerDuty::query()
            ->where('roster_day_id', $rosterDayId)
            ->where('shift_id', $shiftId)
            ->with(['employee.user'])
            ->when($groupNumber !== null, function ($q) use ($groupNumber) {
                $q->whereHas('employee', function ($employeeQuery) use ($groupNumber) {
                    $employeeQuery->where('group_number', $groupNumber);
                });
            })
            ->get();

        if ($exactShiftCandidates->count() === 1) {
            return $exactShiftCandidates->first();
        }

        return null;
    }

    /**
     * Notify managers about shift request.
     */
    private function notifyManagers(ShiftRequest $shiftRequest): void
    {
        Log::info('[shift_request][notify_manager] Called', [
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
                Log::error('[shift_request][notify_manager] No General Manager found for manager-to-manager shift request', [
                    'shift_request_id' => $shiftRequest->id,
                ]);
                return;
            }
        }

        if (empty($managersToNotify)) {
            // Source of truth: resolve manager by roster day + shift notes context
            $fromManager = $this->resolveManagerDutyForContext(
                (int) $shiftRequest->from_roster_day_id,
                (string) $shiftRequest->requester_notes,
                $shiftRequest->requesterEmployee?->group_number
            );
            $toManager = $this->resolveManagerDutyForContext(
                (int) $shiftRequest->to_roster_day_id,
                (string) $shiftRequest->target_notes,
                $shiftRequest->targetEmployee?->group_number
            );

            if ($fromManager && $fromManager->employee && $fromManager->employee->user) {
                $managersToNotify[$fromManager->employee->user_id] = $fromManager->employee->user;
                Log::info('[shift_request][notify_manager] Found from_manager', [
                    'employee_id' => $fromManager->employee_id,
                    'user_id' => $fromManager->employee->user_id,
                    'name' => $fromManager->employee->user->name,
                    'email' => $fromManager->employee->user->email,
                ]);
            }

            if ($toManager && $toManager->employee && $toManager->employee->user) {
                // Add only if different from fromManager
                if (!isset($managersToNotify[$toManager->employee->user_id])) {
                    $managersToNotify[$toManager->employee->user_id] = $toManager->employee->user;
                    Log::info('[shift_request][notify_manager] Found to_manager', [
                        'employee_id' => $toManager->employee_id,
                        'user_id' => $toManager->employee->user_id,
                        'name' => $toManager->employee->user->name,
                        'email' => $toManager->employee->user->email,
                    ]);
                }
            }
        }

        Log::info('[shift_request][notify_manager] Candidate managers resolved', [
            'shift_request_id' => $shiftRequest->id,
            'from_roster_day_id' => $shiftRequest->from_roster_day_id,
            'to_roster_day_id' => $shiftRequest->to_roster_day_id,
            'requester_notes' => $shiftRequest->requester_notes,
            'target_notes' => $shiftRequest->target_notes,
            'managers_count' => count($managersToNotify),
            'recipients' => collect($managersToNotify)->map(function ($user) {
                return [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ];
            })->values()->all(),
        ]);

        // Fallback: if no specific managers found, notify all manager employees by role
        if (empty($managersToNotify)) {
            Log::warning('[shift_request][notify_manager] No specific shift managers found, notifying all manager employees');

            $managerEmployeeIds = $this->getManagerEmployeeIds();
            
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
            Log::error('[shift_request][notify_manager] No managers found at all for shift request', [
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
            Log::info('[shift_request][notify_manager] Creating notification for manager', [
                'manager_user_id' => $userId,
                'manager_name' => $user->name,
                'manager_email' => $user->email,
                'manager_role' => $user->role,
            ]);

            $notification = Notification::create([
                'user_id' => $userId,
                'sender_id' => Auth::id(),
                'title' => 'Approval Diperlukan',
                'message' => $detailedMessage,
                'category' => 'shift_request',
                'reference_id' => $shiftRequest->id,
            ]);

            Log::info('[shift_request][notify_manager] Manager notification created', [
                'notification_id' => $notification->id,
                'manager_user_id' => $userId,
                'shift_request_id' => $shiftRequest->id,
                'manager_email' => $user->email,
            ]);
        }

        Log::info('[shift_request][notify_manager] Completed', [
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
        $currentUserGrade = Auth::user()->grade !== null ? (int) Auth::user()->grade : null;
        $allowedSwapGrades = $this->getAllowedSwapGradesForRequester($currentUserGrade);

        if (empty($allowedSwapGrades)) {
            return response()->json([
                'data' => [],
                'count' => 0,
                'message' => 'Kelas jabatan akun Anda belum diatur. Hubungi admin untuk mengisi grade.',
            ]);
        }

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
        $query->whereHas('employee', function ($q) use ($currentEmployee, $allowedSwapGrades) {
            $q->where('employee_type', $currentEmployee->employee_type)
                ->where('is_active', true);

            // Apply grade compatibility filter:
            // - Same grade is always allowed
            // - Special cross-grade pairs: 14<->13, 12<->11
            // - Grade group 8,9,10 can swap with each other
            // - Grade 15 can only swap with the same grade
            if (!empty($allowedSwapGrades)) {
                $q->whereHas('user', function ($uq) use ($allowedSwapGrades) {
                    $uq->whereIn('grade', $allowedSwapGrades);
                });
            }
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
                'grade' => $assignment->employee->user->grade,
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
                'grade' => $first['grade'],
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
     * Get allowed target grades for swap based on requester grade.
     */
    private function getAllowedSwapGradesForRequester(?int $requesterGrade): array
    {
        if ($requesterGrade === null) {
            return [];
        }

        $allowedGrades = [$requesterGrade];
        $crossGradePairs = [
            14 => [13],
            13 => [14],
            12 => [11],
            11 => [12],
            8 => [9, 10],
            9 => [8, 10],
            10 => [8, 9],
        ];

        if (isset($crossGradePairs[$requesterGrade])) {
            $allowedGrades = array_merge($allowedGrades, $crossGradePairs[$requesterGrade]);
        }

        return array_values(array_unique($allowedGrades));
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
     * Priority: 1) Manager duties (temporary), 2) Fixed manager from SAME GROUP, 3) Other managers
     */
    public function getManagerForShift(Request $request)
    {
        $request->validate([
            'roster_day_id' => 'required|exists:roster_days,id',
            'notes' => 'required|string',
        ]);

        $rosterDayId = $request->get('roster_day_id');
        $notes = $request->get('notes');

        // Get authenticated user's group
        $authUser = Auth::user();
        $userEmployee = $authUser->employee;
        $userGroupNumber = $userEmployee ? $userEmployee->group_number : null;

        \Log::info('[getManagerForShift] Starting', [
            'roster_day_id' => $rosterDayId,
            'notes' => $notes,
            'user_group_number' => $userGroupNumber,
            'user_id' => $authUser->id,
        ]);

        // Get the roster day
        $rosterDay = RosterDay::find($rosterDayId);
        if (!$rosterDay) {
            \Log::warning('[getManagerForShift] Roster day not found', ['roster_day_id' => $rosterDayId]);
            return response()->json([
                'data' => null,
                'message' => 'Roster day tidak ditemukan',
            ]);
        }

        // Map notes to shift_id using centralized service
        $shiftId = ShiftResolverService::resolveShiftId($notes);
        if (!$shiftId) {
            \Log::warning('[getManagerForShift] Could not resolve shift_id from notes', ['notes' => $notes]);
            return response()->json([
                'data' => null,
                'message' => 'Shift tidak ditemukan dari notes',
            ]);
        }

        \Log::info('[getManagerForShift] Resolved shift_id', [
            'notes' => $notes,
            'shift_id' => $shiftId,
            'user_group' => $userGroupNumber,
        ]);

        // Priority 1: Check temporary manager from manager_duties.
        // Some imported rosters have manager_duties.shift_id that does not match the actual daily shift,
        // so we use two strategies: exact shift_id first, then fallback via same-day shift_assignments.
        $normalizedNotes = strtolower(trim($notes));

        $managerDutyExactShift = null;

        $managerDutyByAssignment = ManagerDuty::query()
            ->where('roster_day_id', $rosterDayId)
            ->with(['employee.user'])
            ->whereHas('employee.shiftAssignments', function ($shiftAssignmentQuery) use ($rosterDayId, $normalizedNotes) {
                $shiftAssignmentQuery->where('roster_day_id', $rosterDayId)
                    ->whereRaw('LOWER(TRIM(notes)) = ?', [$normalizedNotes]);
            })
            ->when($userGroupNumber !== null, function ($q) use ($userGroupNumber) {
                $q->whereHas('employee', function ($employeeQuery) use ($userGroupNumber) {
                    $employeeQuery->where('group_number', $userGroupNumber);
                });
            })
            ->first();

        if (!$managerDutyByAssignment) {
            $managerDutyByAssignment = ManagerDuty::query()
                ->where('roster_day_id', $rosterDayId)
                ->with(['employee.user'])
                ->whereHas('employee.shiftAssignments', function ($shiftAssignmentQuery) use ($rosterDayId, $normalizedNotes) {
                    $shiftAssignmentQuery->where('roster_day_id', $rosterDayId)
                        ->whereRaw('LOWER(TRIM(notes)) = ?', [$normalizedNotes]);
                })
                ->first();
        }

        // IMPORTANT:
        // Prefer manager_duties + shift_assignments match first.
        // Some imported roster files store manager_duties.shift_id inconsistently,
        // which can cause wrong manager selection if exact shift_id is used blindly.
        if (!$managerDutyByAssignment) {
            $exactShiftCandidates = ManagerDuty::query()
                ->where('roster_day_id', $rosterDayId)
                ->where('shift_id', $shiftId)
                ->with(['employee.user'])
                ->when($userGroupNumber !== null, function ($q) use ($userGroupNumber) {
                    $q->whereHas('employee', function ($employeeQuery) use ($userGroupNumber) {
                        $employeeQuery->where('group_number', $userGroupNumber);
                    });
                })
                ->get();

            // Only allow exact-shift fallback when there is exactly one candidate.
            if ($exactShiftCandidates->count() === 1) {
                $managerDutyExactShift = $exactShiftCandidates->first();
            }
        }

        $managerDuty = $managerDutyByAssignment ?: $managerDutyExactShift;

        if ($managerDuty && $managerDuty->employee && $managerDuty->employee->user) {
            \Log::info('[getManagerForShift] Found temporary manager via manager_duties', [
                'roster_day_id' => $rosterDayId,
                'shift_id' => $shiftId,
                'notes' => $notes,
                'user_group' => $userGroupNumber,
                'match_strategy' => $managerDutyExactShift ? 'manager_duties.shift_id' : 'manager_duties + shift_assignments.notes',
                'employee_id' => $managerDuty->employee_id,
                'employee_name' => $managerDuty->employee->user->name,
                'employee_group' => $managerDuty->employee->group_number,
                'duty_type' => $managerDuty->duty_type,
            ]);

            return response()->json([
                'data' => [
                    'employee_id' => $managerDuty->employee_id,
                    'user_id' => $managerDuty->employee->user_id,
                    'name' => $managerDuty->employee->user->name,
                    'notes' => $notes,
                    'is_temporary' => true,
                    'duty_type' => $managerDuty->duty_type,
                    'group_number' => $managerDuty->employee->group_number,
                ],
            ]);
        }

        \Log::info('[getManagerForShift] No temporary manager found, checking fixed manager', [
            'roster_day_id' => $rosterDayId,
            'shift_id' => $shiftId,
            'user_group' => $userGroupNumber,
        ]);

        // Priority 2a: Check fixed manager from SAME GROUP first
        if ($userGroupNumber !== null) {
            \Log::info('[getManagerForShift] Checking same-group managers', [
                'user_group' => $userGroupNumber,
            ]);

            $sameGroupManager = ShiftAssignment::where('roster_day_id', $rosterDayId)
                ->whereRaw('LOWER(TRIM(notes)) = ?', [strtolower(trim($notes))])
                ->with(['employee.user'])
                ->whereHas('employee', function ($q) use ($userGroupNumber) {
                    $q->where('group_number', $userGroupNumber)
                        ->whereHas('user', function ($userQuery) {
                            $userQuery->whereIn('role', [
                                User::ROLE_MANAGER_TEKNIK,
                                User::ROLE_GENERAL_MANAGER,
                            ]);
                        });
                })
                ->first();

            if ($sameGroupManager && $sameGroupManager->employee && $sameGroupManager->employee->user) {
                \Log::info('[getManagerForShift] Found manager from SAME GROUP via ShiftAssignment', [
                    'roster_day_id' => $rosterDayId,
                    'notes' => $notes,
                    'user_group' => $userGroupNumber,
                    'employee_id' => $sameGroupManager->employee_id,
                    'employee_name' => $sameGroupManager->employee->user->name,
                    'employee_group' => $sameGroupManager->employee->group_number,
                    'employee_type' => $sameGroupManager->employee->employee_type,
                ]);

                return response()->json([
                    'data' => [
                        'employee_id' => $sameGroupManager->employee_id,
                        'user_id' => $sameGroupManager->employee->user_id,
                        'name' => $sameGroupManager->employee->user->name,
                        'notes' => $sameGroupManager->notes,
                        'is_temporary' => false,
                        'employee_type' => $sameGroupManager->employee->employee_type,
                        'group_number' => $sameGroupManager->employee->group_number,
                    ],
                ]);
            }

            \Log::info('[getManagerForShift] No same-group manager found, trying other managers', [
                'user_group' => $userGroupNumber,
            ]);
        }

        // Priority 2b: Fall back to ANY Manager Teknik or General Manager with matching shift
        $managerEmployeeIds = $this->getManagerEmployeeIds();
        $manager = ShiftAssignment::where('roster_day_id', $rosterDayId)
            ->whereIn('employee_id', $managerEmployeeIds)
            ->whereRaw('LOWER(TRIM(notes)) = ?', [strtolower(trim($notes))])
            ->with(['employee.user'])
            ->first();

        if ($manager && $manager->employee && $manager->employee->user) {
            \Log::info('[getManagerForShift] Found manager from ANY group via ShiftAssignment', [
                'roster_day_id' => $rosterDayId,
                'notes' => $notes,
                'user_group' => $userGroupNumber,
                'employee_id' => $manager->employee_id,
                'employee_name' => $manager->employee->user->name,
                'employee_group' => $manager->employee->group_number,
                'employee_type' => $manager->employee->employee_type,
                'is_same_group' => $manager->employee->group_number === $userGroupNumber,
            ]);

            return response()->json([
                'data' => [
                    'employee_id' => $manager->employee_id,
                    'user_id' => $manager->employee->user_id,
                    'name' => $manager->employee->user->name,
                    'notes' => $manager->notes,
                    'is_temporary' => false,
                    'employee_type' => $manager->employee->employee_type,
                    'group_number' => $manager->employee->group_number,
                ],
            ]);
        }

        // If no manager found for this shift, return null
        \Log::warning('[getManagerForShift] No manager found', [
            'roster_day_id' => $rosterDayId,
            'notes' => $notes,
            'shift_id' => $shiftId,
            'user_group' => $userGroupNumber,
        ]);

        return response()->json([
            'data' => null,
            'message' => 'Tidak ada manager yang bertugas pada shift ini',
        ]);
    }

    /**
     * GET /shift-requests/check-manager-status
     * Check if current user is a manager (by role or by temporary duty assignment)
     * Returns has_manager_duties flag for current roster period being viewed
     */
    public function checkManagerStatus(Request $request)
    {
        $user = Auth::user();
        $employee = $user->employee;
        $rosterPeriodId = $request->integer('roster_period_id');

        if (!$employee) {
            return response()->json([
                'data' => [
                    'is_role_manager' => false,
                    'has_manager_duties' => false,
                    'is_manager' => false,
                ],
            ]);
        }

        // Check if manager by role
        $isRoleManager = in_array($user->role, [User::ROLE_MANAGER_TEKNIK, User::ROLE_GENERAL_MANAGER]);

        // Check if has manager duties in requested roster period (if provided)
        $hasManagerDuties = $this->hasManagerDutyInRosterPeriod($employee->id, $rosterPeriodId);

        return response()->json([
            'data' => [
                'is_role_manager' => $isRoleManager,
                'has_manager_duties' => $hasManagerDuties,
                'is_manager' => $isRoleManager || $hasManagerDuties,
                'roster_period_id' => $rosterPeriodId,
            ],
        ]);
    }

    private function hasManagerDutyInRosterPeriod(int $employeeId, ?int $rosterPeriodId): bool
    {
        return ManagerDuty::query()
            ->where('employee_id', $employeeId)
            ->when($rosterPeriodId, function ($query, $periodId) {
                $query->whereHas('rosterDay', function ($dayQuery) use ($periodId) {
                    $dayQuery->where('roster_period_id', $periodId);
                });
            })
            ->exists();
    }
}
