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
use Illuminate\Pagination\LengthAwarePaginator;
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

        // Optional filter by roster period for calendar/scope-specific views
        if ($rosterPeriodId) {
            $query->whereHas('fromRosterDay', function ($q) use ($rosterPeriodId) {
                $q->where('roster_period_id', $rosterPeriodId);
            });
        }

        // Manager approver can be role-based manager OR temporary manager on duty (manager_duties)
        $isManagerEmployee = $employee && (
            in_array($user->role, [User::ROLE_MANAGER_TEKNIK, User::ROLE_GENERAL_MANAGER])
            || $this->hasManagerDutyInRosterPeriod($employee->id, $rosterPeriodId)
        );
        $isAdminRole = $user->role === User::ROLE_ADMIN;

        // Build unified visibility set first so only involved parties can see requests.
        $allRequests = $query
            ->orderBy('created_at', 'desc')
            ->get();

        $visibleRequests = $allRequests->filter(function (ShiftRequest $shiftRequest) use ($employee, $user, $isAdminRole, $isManagerEmployee) {
            if ($isAdminRole) {
                return true;
            }

            if (!$employee) {
                return false;
            }

            if ((int) $shiftRequest->requester_employee_id === (int) $employee->id
                || (int) $shiftRequest->target_employee_id === (int) $employee->id) {
                return true;
            }

            if (!$isManagerEmployee) {
                return false;
            }

            $managerApproval = $this->getManagerApprovalContext($shiftRequest, $user, $employee);
            return $managerApproval['is_related_manager'];
        })->values();

        $perPage = (int) $request->get('per_page', 15);
        $currentPage = max((int) $request->get('page', 1), 1);
        $currentPageItems = $visibleRequests->forPage($currentPage, $perPage)->values();

        $requests = new LengthAwarePaginator(
            $currentPageItems,
            $visibleRequests->count(),
            $perPage,
            $currentPage,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );

        // Add current_user_can_approve info for each request using the same approver resolution.
        $requestsWithApprovalInfo = collect($requests->items())->map(function ($request) use ($employee, $user) {
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
            
            // Check if can approve as manager using unified approver logic
            if ($request->status === ShiftRequest::STATUS_PENDING) {
                $managerApproval = $this->getManagerApprovalContext($request, $user, $employee);
                $requestArray['current_user_can_approve_as_manager'] = $managerApproval['can_approve'];

                if ($managerApproval['already_approved']) {
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

        // Admin can view all
        if ($user->role === User::ROLE_ADMIN) {
            $canView = true;
        }

        // Requester and target can view their requests
        if ($employee) {
            if ($shiftRequest->requester_employee_id === $employee->id || 
                $shiftRequest->target_employee_id === $employee->id) {
                $canView = true;
            }
        }

        // Only assigned approver managers can view detail.
        if (!$canView && $employee) {
            $managerApproval = $this->getManagerApprovalContext($shiftRequest, $user, $employee);
            $canView = $managerApproval['is_related_manager'];
        }

        if (!$canView) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $approvers = $this->resolveManagerApprovers($shiftRequest);
        $fromManagerDuty = $approvers['from_manager_duty'];
        $toManagerDuty = $approvers['to_manager_duty'];
        $fromManagerEmployee = $approvers['from_manager_employee_id']
            ? Employee::with('user')->find($approvers['from_manager_employee_id'])
            : null;
        $toManagerEmployee = $approvers['to_manager_employee_id']
            ? Employee::with('user')->find($approvers['to_manager_employee_id'])
            : null;

        return response()->json([
            'data' => $shiftRequest,
            'managers' => [
                'from_manager' => ($fromManagerDuty || $fromManagerEmployee) ? [
                    'id' => $fromManagerDuty?->employee_id ?? $fromManagerEmployee?->id,
                    'name' => $fromManagerDuty?->employee?->user?->name
                        ?? $fromManagerEmployee?->user?->name
                        ?? 'N/A',
                ] : null,
                'to_manager' => ($toManagerDuty || $toManagerEmployee) ? [
                    'id' => $toManagerDuty?->employee_id ?? $toManagerEmployee?->id,
                    'name' => $toManagerDuty?->employee?->user?->name
                        ?? $toManagerEmployee?->user?->name
                        ?? 'N/A',
                ] : null,
                'is_same_manager' => $approvers['from_manager_employee_id'] !== null
                    && $approvers['to_manager_employee_id'] !== null
                    && (int) $approvers['from_manager_employee_id'] === (int) $approvers['to_manager_employee_id'],
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
                'from_manager_id' => null,
                'to_manager_id' => null,
            ]);

            // Load relationships for response
            $shiftRequest->load([
                'requesterEmployee.user',
                'targetEmployee.user',
                'fromRosterDay',
                'toRosterDay',
            ]);

            $approvers = $this->persistManagerApproverIds($shiftRequest);

            // Notify target employee
            $targetEmployee = Employee::findOrFail($validated['target_employee_id']);
            
            // Build detailed message
            $fromDate = Carbon::parse($shiftRequest->fromRosterDay->work_date)->format('d M Y');
            $toDate = Carbon::parse($shiftRequest->toRosterDay->work_date)->format('d M Y');
            $detailedMessage = Auth::user()->name . ' mengajukan tukar lintas tanggal dan meminta persetujuan Anda:\n'
                . '• Shift asal requester: ' . $fromDate . ' (' . $shiftRequest->requester_notes . ')\n'
                . '• Shift tujuan requester: ' . $toDate . ' (' . $shiftRequest->target_notes . ')';
            
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
            $this->notifyManagers($shiftRequest, $approvers);

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

        $approvers = $this->resolveManagerApprovers($shiftRequest);
        $fromManagerDuty = $approvers['from_manager_duty'];
        $toManagerDuty = $approvers['to_manager_duty'];

        $managerApproval = $this->getManagerApprovalContext($shiftRequest, $user, $employee);
        $isFromManager = $managerApproval['is_from_manager'];
        $isToManager = $managerApproval['is_to_manager'];
        $fromManagerEmployeeId = $approvers['from_manager_employee_id'];
        $toManagerEmployeeId = $approvers['to_manager_employee_id'];

        \Log::info('approveByManager check', [
            'employee_id' => $employee->id,
            'isFromManager' => $isFromManager,
            'isToManager' => $isToManager,
            'requester_notes' => $shiftRequest->requester_notes,
            'target_notes' => $shiftRequest->target_notes,
            'from_manager_employee_id' => $fromManagerEmployeeId,
            'to_manager_employee_id' => $toManagerEmployeeId,
        ]);

        if (!$managerApproval['is_related_manager']) {
            return response()->json([
                'message' => 'Anda bukan manager untuk shift yang terlibat dalam permintaan ini'
            ], 403);
        }

        DB::beginTransaction();
        try {
            // Check if same manager handles both shifts
            $isSameManager = $fromManagerEmployeeId !== null
                && $toManagerEmployeeId !== null
                && (int) $fromManagerEmployeeId === (int) $toManagerEmployeeId;

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

        // Manager can reject only if they are one of the two assigned approvers.
        if ($employee) {
            $managerApproval = $this->getManagerApprovalContext($shiftRequest, $user, $employee);
            if ($managerApproval['is_related_manager']) {
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

        if ($exactShiftCandidates->isNotEmpty()) {
            return $exactShiftCandidates
                ->sort(function ($a, $b) {
                    $aFixed = (int) ($a->employee->is_fixed_manager ?? 0);
                    $bFixed = (int) ($b->employee->is_fixed_manager ?? 0);

                    if ($aFixed !== $bFixed) {
                        return $bFixed <=> $aFixed;
                    }

                    return ((int) ($a->employee_id ?? 0)) <=> ((int) ($b->employee_id ?? 0));
                })
                ->first();
        }

        return null;
    }

    /**
     * Resolve exactly two approvers (from manager + to manager) for a swap request.
     */
    private function resolveManagerApprovers(ShiftRequest $shiftRequest): array
    {
        $shiftRequest->loadMissing([
            'requesterEmployee.user',
            'targetEmployee.user',
            'fromRosterDay',
            'toRosterDay',
        ]);

        if ($this->isManagerToManagerSwap($shiftRequest)) {
            $generalManagerUsers = Employee::query()
                ->whereIn('id', $this->getGeneralManagerEmployeeIds())
                ->with('user')
                ->get()
                ->pluck('user')
                ->filter()
                ->keyBy('id')
                ->all();

            return [
                'from_manager_duty' => null,
                'to_manager_duty' => null,
                'from_manager_employee_id' => null,
                'to_manager_employee_id' => null,
                'users_by_id' => $generalManagerUsers,
                'is_manager_to_manager' => true,
            ];
        }

        $fromManagerEmployeeId = $shiftRequest->from_manager_id !== null
            ? (int) $shiftRequest->from_manager_id
            : null;
        $toManagerEmployeeId = $shiftRequest->to_manager_id !== null
            ? (int) $shiftRequest->to_manager_id
            : null;

        $fromManagerDuty = null;
        $toManagerDuty = null;

        if ($fromManagerEmployeeId === null) {
            $fromManagerDuty = $this->resolveManagerDutyForContext(
                (int) $shiftRequest->from_roster_day_id,
                (string) $shiftRequest->requester_notes,
                $shiftRequest->requesterEmployee?->group_number
            );

            if (!$fromManagerDuty) {
                $fromManagerDuty = $this->resolveManagerDutyForContext(
                    (int) $shiftRequest->from_roster_day_id,
                    (string) $shiftRequest->requester_notes,
                    null
                );
            }

            if ($fromManagerDuty?->employee_id) {
                $fromManagerEmployeeId = (int) $fromManagerDuty->employee_id;
            }

            if ($fromManagerEmployeeId === null) {
                $fromManagerEmployeeId = $this->resolveManagerEmployeeIdByGroup($shiftRequest->requesterEmployee?->group_number);
            }
        }

        if ($toManagerEmployeeId === null) {
            $toManagerDuty = $this->resolveManagerDutyForContext(
                (int) $shiftRequest->to_roster_day_id,
                (string) $shiftRequest->target_notes,
                $shiftRequest->targetEmployee?->group_number
            );

            if (!$toManagerDuty) {
                $toManagerDuty = $this->resolveManagerDutyForContext(
                    (int) $shiftRequest->to_roster_day_id,
                    (string) $shiftRequest->target_notes,
                    null
                );
            }

            if ($toManagerDuty?->employee_id) {
                $toManagerEmployeeId = (int) $toManagerDuty->employee_id;
            }

            if ($toManagerEmployeeId === null) {
                $toManagerEmployeeId = $this->resolveManagerEmployeeIdByGroup($shiftRequest->targetEmployee?->group_number);
            }
        }

        $usersById = [];
        $managerEmployeeIds = array_values(array_unique(array_filter([
            $fromManagerEmployeeId,
            $toManagerEmployeeId,
        ])));

        if (!empty($managerEmployeeIds)) {
            $managerEmployees = Employee::query()
                ->whereIn('id', $managerEmployeeIds)
                ->with('user')
                ->get();

            foreach ($managerEmployees as $managerEmployee) {
                if ($managerEmployee->user) {
                    $usersById[$managerEmployee->user->id] = $managerEmployee->user;
                }
            }
        }

        if ($fromManagerDuty?->employee?->user) {
            $usersById[$fromManagerDuty->employee->user_id] = $fromManagerDuty->employee->user;
        }
        if ($toManagerDuty?->employee?->user) {
            $usersById[$toManagerDuty->employee->user_id] = $toManagerDuty->employee->user;
        }

        return [
            'from_manager_duty' => $fromManagerDuty,
            'to_manager_duty' => $toManagerDuty,
            'from_manager_employee_id' => $fromManagerEmployeeId,
            'to_manager_employee_id' => $toManagerEmployeeId,
            'users_by_id' => $usersById,
            'is_manager_to_manager' => false,
        ];
    }

    private function resolveManagerEmployeeIdByGroup(?int $groupNumber): ?int
    {
        if (!$groupNumber) {
            return null;
        }

        $fixedManager = Employee::query()
            ->where('group_number', $groupNumber)
            ->where('is_active', true)
            ->where('is_fixed_manager', true)
            ->whereHas('user', function ($q) {
                $q->whereIn('role', [User::ROLE_MANAGER_TEKNIK, User::ROLE_GENERAL_MANAGER]);
            })
            ->orderBy('id')
            ->first();

        if ($fixedManager) {
            return (int) $fixedManager->id;
        }

        $groupManager = Employee::query()
            ->where('group_number', $groupNumber)
            ->where('is_active', true)
            ->whereHas('user', function ($q) {
                $q->whereIn('role', [User::ROLE_MANAGER_TEKNIK, User::ROLE_GENERAL_MANAGER]);
            })
            ->orderBy('id')
            ->first();

        return $groupManager ? (int) $groupManager->id : null;
    }

    private function persistManagerApproverIds(ShiftRequest $shiftRequest): array
    {
        $approvers = $this->resolveManagerApprovers($shiftRequest);

        if ($approvers['is_manager_to_manager']) {
            return $approvers;
        }

        $fromManagerEmployeeId = $approvers['from_manager_employee_id'];
        $toManagerEmployeeId = $approvers['to_manager_employee_id'];

        $hasChanges = ((int) ($shiftRequest->from_manager_id ?? 0) !== (int) ($fromManagerEmployeeId ?? 0))
            || ((int) ($shiftRequest->to_manager_id ?? 0) !== (int) ($toManagerEmployeeId ?? 0));

        if ($hasChanges) {
            $shiftRequest->from_manager_id = $fromManagerEmployeeId;
            $shiftRequest->to_manager_id = $toManagerEmployeeId;
            $shiftRequest->save();
        }

        return $approvers;
    }

    /**
     * Compute manager-approval context for current user based on unified approver resolution.
     */
    private function getManagerApprovalContext(ShiftRequest $shiftRequest, User $user, ?Employee $employee): array
    {
        if (!$employee) {
            return [
                'is_related_manager' => false,
                'is_from_manager' => false,
                'is_to_manager' => false,
                'can_approve' => false,
                'already_approved' => false,
            ];
        }

        $approvers = $this->resolveManagerApprovers($shiftRequest);

        if ($approvers['is_manager_to_manager']) {
            $isRelated = $user->role === User::ROLE_GENERAL_MANAGER;
            $canApprove = $isRelated
                && $shiftRequest->status === ShiftRequest::STATUS_PENDING
                && (!$shiftRequest->approved_by_from_manager || !$shiftRequest->approved_by_to_manager);
            $alreadyApproved = $isRelated
                && $shiftRequest->approved_by_from_manager
                && $shiftRequest->approved_by_to_manager;

            return [
                'is_related_manager' => $isRelated,
                'is_from_manager' => $isRelated,
                'is_to_manager' => $isRelated,
                'can_approve' => $canApprove,
                'already_approved' => $alreadyApproved,
            ];
        }

        $employeeId = (int) $employee->id;
        $isFromManager = $approvers['from_manager_employee_id'] !== null
            && (int) $approvers['from_manager_employee_id'] === $employeeId;
        $isToManager = $approvers['to_manager_employee_id'] !== null
            && (int) $approvers['to_manager_employee_id'] === $employeeId;
        $isRelatedManager = $isFromManager || $isToManager;

        $canApprove = $shiftRequest->status === ShiftRequest::STATUS_PENDING
            && (($isFromManager && !$shiftRequest->approved_by_from_manager)
                || ($isToManager && !$shiftRequest->approved_by_to_manager));

        $alreadyApproved = ($isFromManager && $shiftRequest->approved_by_from_manager)
            || ($isToManager && $shiftRequest->approved_by_to_manager);

        return [
            'is_related_manager' => $isRelatedManager,
            'is_from_manager' => $isFromManager,
            'is_to_manager' => $isToManager,
            'can_approve' => $canApprove,
            'already_approved' => $alreadyApproved,
        ];
    }

    /**
     * Notify managers about shift request.
     */
    private function notifyManagers(ShiftRequest $shiftRequest, ?array $approvers = null): void
    {
        Log::info('[shift_request][notify_manager] Called', [
            'shift_request_id' => $shiftRequest->id,
            'time' => now()->toDateTimeString(),
        ]);

        $approvers = $approvers ?? $this->resolveManagerApprovers($shiftRequest);
        $managersToNotify = $approvers['users_by_id'];

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

        if (empty($managersToNotify)) {
            Log::error('[shift_request][notify_manager] No managers found at all for shift request', [
                'shift_request_id' => $shiftRequest->id,
                'from_roster_day_id' => $shiftRequest->from_roster_day_id,
                'to_roster_day_id' => $shiftRequest->to_roster_day_id,
                'requester_notes' => $shiftRequest->requester_notes,
                'target_notes' => $shiftRequest->target_notes,
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
        $approvers = $this->resolveManagerApprovers($shiftRequest);
        $managersToNotify = $approvers['users_by_id'];

        if (empty($managersToNotify)) {
            Log::warning('[shift_request][notify_manager_cancel] No approver managers resolved for cancellation notification', [
                'shift_request_id' => $shiftRequest->id,
            ]);
            return;
        }

        foreach ($managersToNotify as $managerUser) {
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
        // Keep roster baseline immutable for rostered-staff view.
        // Completed swap requests are applied as calendar overlays in frontend.

        // Notify both parties
        Notification::create([
            'user_id' => $shiftRequest->requesterEmployee->user_id,
            'title' => 'Tukar Shift Selesai',
            'message' => 'Tukar shift Anda dengan ' . $shiftRequest->targetEmployee->user->name . ' telah berhasil dieksekusi.',
            'category' => 'shift_request',
            'reference_id' => $shiftRequest->id,
        ]);

        Notification::create([
            'user_id' => $shiftRequest->targetEmployee->user_id,
            'title' => 'Tukar Shift Selesai',
            'message' => 'Tukar shift Anda dengan ' . $shiftRequest->requesterEmployee->user->name . ' telah berhasil dieksekusi.',
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
            'from_roster_day_id' => 'sometimes|exists:roster_days,id',
            'requester_notes' => 'sometimes|string|max:50',
            'roster_month' => 'sometimes|integer|min:1|max:12',
            'roster_year' => 'sometimes|integer|min:2000|max:2100',
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

        $fromRosterDayId = $request->integer('from_roster_day_id');
        $requesterNotes = trim((string) $request->input('requester_notes', ''));
        $rosterMonth = $request->integer('roster_month');
        $rosterYear = $request->integer('roster_year');
        $hasSpecificSource = $fromRosterDayId && $requesterNotes !== '';
        $normalizedRequesterNotes = strtolower($requesterNotes);

        if ($hasSpecificSource && $this->isOffDayNotes($normalizedRequesterNotes)) {
            return response()->json([
                'data' => [],
                'count' => 0,
                'message' => 'Shift asal harus shift kerja, bukan libur/cuti/off.',
            ]);
        }

        // Minimum H-3 from now
        $minDate = Carbon::now()->addDays(3)->startOfDay()->toDateString();

        $rosterPeriodFilter = function ($query) use ($rosterMonth, $rosterYear) {
            $query->where('status', 'published');
            if ($rosterMonth) {
                $query->where('month', $rosterMonth);
            }
            if ($rosterYear) {
                $query->where('year', $rosterYear);
            }
        };

        $requesterSources = collect();

        if ($hasSpecificSource) {
            // Requester must actually have the selected source shift on selected date.
            $requesterHasSourceShift = ShiftAssignment::query()
                ->where('employee_id', $currentEmployee->id)
                ->where('roster_day_id', $fromRosterDayId)
                ->whereRaw('LOWER(TRIM(notes)) = ?', [$normalizedRequesterNotes])
                ->whereHas('rosterDay', function ($q) use ($minDate, $rosterPeriodFilter) {
                    $q->where('work_date', '>=', $minDate)
                        ->whereHas('rosterPeriod', $rosterPeriodFilter);
                })
                ->exists();

            if (!$requesterHasSourceShift) {
                return response()->json([
                    'data' => [],
                    'count' => 0,
                    'message' => 'Anda tidak memiliki shift asal tersebut pada tanggal yang dipilih.',
                ]);
            }

            $requesterSources = collect([[
                'roster_day_id' => (int) $fromRosterDayId,
                'normalized_notes' => $normalizedRequesterNotes,
            ]]);
        } else {
            // Preload mode: collect requester's all eligible working shifts (for flexible UI ordering).
            $requesterSources = ShiftAssignment::query()
                ->select('roster_day_id', 'notes')
                ->where('employee_id', $currentEmployee->id)
                ->whereNotNull('notes')
                ->where('notes', '!=', '')
                ->whereHas('rosterDay', function ($q) use ($minDate, $rosterPeriodFilter) {
                    $q->where('work_date', '>=', $minDate)
                        ->whereHas('rosterPeriod', $rosterPeriodFilter);
                })
                ->get()
                ->map(function ($assignment) {
                    return [
                        'roster_day_id' => (int) $assignment->roster_day_id,
                        'normalized_notes' => strtolower(trim((string) $assignment->notes)),
                    ];
                })
                ->filter(function ($item) {
                    return !$this->isOffDayNotes($item['normalized_notes']);
                })
                ->unique(function ($item) {
                    return $item['roster_day_id'] . ':' . $item['normalized_notes'];
                })
                ->values();

            if ($requesterSources->isEmpty()) {
                return response()->json([
                    'data' => [],
                    'count' => 0,
                    'message' => 'Tidak ada shift kerja requester yang memenuhi syarat untuk swap.',
                ]);
            }
        }

        // Build query for partners: same day, different working shift.
        $query = ShiftAssignment::with(['employee.user', 'rosterDay', 'shift'])
            ->where('employee_id', '!=', $currentEmployee->id)
            ->whereNotNull('notes')
            ->where('notes', '!=', '')
            ->whereHas('rosterDay', function ($q) use ($minDate, $rosterPeriodFilter) {
                $q->where('work_date', '>=', $minDate)
                    ->whereHas('rosterPeriod', $rosterPeriodFilter);
            })
            ->whereHas('employee', function ($q) use ($currentEmployee, $allowedSwapGrades) {
                $q->where('employee_type', $currentEmployee->employee_type)
                    ->where('is_active', true)
                    ->whereHas('user', function ($uq) use ($allowedSwapGrades) {
                        $uq->whereIn('grade', $allowedSwapGrades);
                    });
            });

        if ($hasSpecificSource) {
            $query->where('roster_day_id', $fromRosterDayId)
                ->whereRaw('LOWER(TRIM(notes)) != ?', [$normalizedRequesterNotes]);
        } else {
            $query->where(function ($outer) use ($requesterSources) {
                foreach ($requesterSources as $source) {
                    $outer->orWhere(function ($inner) use ($source) {
                        $inner->where('roster_day_id', $source['roster_day_id'])
                            ->whereRaw('LOWER(TRIM(notes)) != ?', [$source['normalized_notes']]);
                    });
                }
            });
        }

        $assignments = $query->get()->map(function ($assignment) {
            $normalizedNotes = strtolower(trim((string) $assignment->notes));

            if ($this->isOffDayNotes($normalizedNotes)) {
                return null;
            }

            // Check if this partner-shift already has a pending request as target.
            $hasPendingRequest = ShiftRequest::query()
                ->where('status', ShiftRequest::STATUS_PENDING)
                ->where('target_employee_id', $assignment->employee_id)
                ->where('to_roster_day_id', $assignment->roster_day_id)
                ->whereRaw('LOWER(TRIM(target_notes)) = ?', [$normalizedNotes])
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
        })->filter()->values();

        // Keep response structure compatible with existing frontend.
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
                            ->where(function ($managerQ) use ($employee) {
                                $managerQ->where('from_manager_id', $employee->id)
                                    ->orWhere(function ($legacyQ) use ($employee) {
                                        $legacyQ->whereNull('from_manager_id')
                                            ->whereHas('fromRosterDay.managerDuties', function ($mq) use ($employee) {
                                                $mq->where('employee_id', $employee->id);
                                            });
                                    });
                            });
                    })
                    // Or needs to_manager approval
                    ->orWhere(function ($subQ) use ($employee) {
                        $subQ->where('approved_by_to_manager', false)
                            ->where(function ($managerQ) use ($employee) {
                                $managerQ->where('to_manager_id', $employee->id)
                                    ->orWhere(function ($legacyQ) use ($employee) {
                                        $legacyQ->whereNull('to_manager_id')
                                            ->whereHas('toRosterDay.managerDuties', function ($mq) use ($employee) {
                                                $mq->where('employee_id', $employee->id);
                                            });
                                    });
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
