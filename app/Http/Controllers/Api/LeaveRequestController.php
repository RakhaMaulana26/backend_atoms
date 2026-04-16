<?php

namespace App\Http\Controllers\Api;

use App\Helpers\CacheHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeaveRequestRequest;
use App\Http\Requests\UpdateLeaveRequestStatusRequest;
use App\Mail\LeaveRequestStatusChangedMail;
use App\Mail\LeaveRequestSubmittedMail;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\ManagerDuty;
use App\Models\Notification;
use App\Models\RosterDay;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class LeaveRequestController extends Controller
{
    private function normalizeRole(?string $role): string
    {
        if (!$role) {
            return '';
        }

        $collapsed = preg_replace('/\s+/', ' ', trim($role));
        return mb_strtolower($collapsed ?? '');
    }

    private function isManagerRole(?string $role): bool
    {
        $normalizedRole = $this->normalizeRole($role);
        $managerRoles = [
            $this->normalizeRole(User::ROLE_MANAGER_TEKNIK),
            $this->normalizeRole(User::ROLE_GENERAL_MANAGER),
        ];

        return in_array($normalizedRole, $managerRoles, true);
    }

    private function isManagerTeknikRole(?string $role): bool
    {
        return $this->normalizeRole($role) === $this->normalizeRole(User::ROLE_MANAGER_TEKNIK);
    }

    private function getGeneralManagerEmployeeIds(): array
    {
        static $generalManagerEmployeeIds = null;

        if ($generalManagerEmployeeIds !== null) {
            return $generalManagerEmployeeIds;
        }

        $generalManagerEmployeeIds = Employee::query()
            ->whereHas('user', function ($query) {
                $query->where('role', User::ROLE_GENERAL_MANAGER)
                    ->where('is_active', true);
            })
            ->pluck('id')
            ->toArray();

        return $generalManagerEmployeeIds;
    }

    private function getLeaveRequestRelations(): array
    {
        return [
            'employee.user',
            'approvedByManager.user',
            'approvals.managerEmployee.user',
            'approvals.rosterDay.rosterPeriod',
        ];
    }

    /**
     * GET /leave-requests
     * Get all leave requests (for managers)
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $employee = $user?->employee;

        $query = LeaveRequest::with($this->getLeaveRequestRelations())
            ->orderBy('created_at', 'desc');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('request_type')) {
            $query->where('request_type', $request->request_type);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->dateRange($request->start_date, $request->end_date);
        }

        if ($request->has('employee_id')) {
            $query->byEmployee($request->employee_id);
        }

        $perPage = $request->get('per_page', 15);
        $leaveRequests = $query->paginate($perPage);

        $leaveRequests->setCollection(
            $leaveRequests->getCollection()->map(function (LeaveRequest $leaveRequest) use ($user, $employee) {
                return $this->serializeLeaveRequest($leaveRequest, $user, $employee);
            })
        );

        return response()->json([
            'message' => 'Leave requests retrieved successfully',
            'data' => $leaveRequests,
        ]);
    }

    /**
     * GET /leave-requests/my-requests
     * Get current user's leave requests
     */
    public function myRequests(Request $request)
    {
        $user = Auth::user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json([
                'message' => 'You must be an employee to view leave requests',
            ], 403);
        }

        $query = LeaveRequest::with($this->getLeaveRequestRelations())
            ->where('employee_id', $employee->id)
            ->orderBy('created_at', 'desc');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('request_type')) {
            $query->where('request_type', $request->request_type);
        }

        $perPage = $request->get('per_page', 15);
        $leaveRequests = $query->paginate($perPage);

        $leaveRequests->setCollection(
            $leaveRequests->getCollection()->map(function (LeaveRequest $leaveRequest) use ($user, $employee) {
                return $this->serializeLeaveRequest($leaveRequest, $user, $employee);
            })
        );

        return response()->json([
            'message' => 'Your leave requests retrieved successfully',
            'data' => $leaveRequests,
        ]);
    }

    /**
     * GET /leave-requests/{id}
     * Get leave request detail
     */
    public function show($id)
    {
        $leaveRequest = LeaveRequest::with($this->getLeaveRequestRelations())
            ->findOrFail($id);

        $employee = Auth::user()->employee;
        $user = Auth::user();

        if ($employee && $leaveRequest->employee_id !== $employee->id) {
            if (!$this->isManagerRole($user->role)) {
                return response()->json([
                    'message' => 'Unauthorized to view this leave request',
                ], 403);
            }
        }

        return response()->json([
            'message' => 'Leave request retrieved successfully',
            'data' => $this->serializeLeaveRequest($leaveRequest, $user, $employee),
        ]);
    }

    /**
     * GET /leave-requests/{id}/document
     * View supporting document for a leave request
     */
    public function document($id)
    {
        $leaveRequest = LeaveRequest::with(['employee'])->findOrFail($id);
        $employee = Auth::user()->employee;
        $user = Auth::user();

        if ($employee && $leaveRequest->employee_id !== $employee->id) {
            if (!$this->isManagerRole($user->role)) {
                return response()->json([
                    'message' => 'Unauthorized to view this document',
                ], 403);
            }
        }

        if ($leaveRequest->document_content) {
            $decodedContent = base64_decode($leaveRequest->document_content, true);
            if ($decodedContent === false) {
                return response()->json([
                    'message' => 'Format dokumen tidak valid di database',
                ], 500);
            }

            $mimeType = $leaveRequest->document_mime_type ?: 'application/octet-stream';
            $fileName = $leaveRequest->document_original_name ?: ('leave-document-' . $leaveRequest->id);

            return response($decodedContent, 200, [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline; filename="' . $fileName . '"',
                'Cache-Control' => 'private, max-age=3600',
            ]);
        }

        if (!$leaveRequest->document_path) {
            return response()->json([
                'message' => 'Dokumen pendukung tidak tersedia',
            ], 404);
        }

        if (!Storage::disk('public')->exists($leaveRequest->document_path)) {
            return response()->json([
                'message' => 'Dokumen pendukung tidak ditemukan di server',
            ], 404);
        }

        $absolutePath = Storage::disk('public')->path($leaveRequest->document_path);
        $fileName = basename($leaveRequest->document_path);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $mimeType = match ($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        return response()->file($absolutePath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $fileName . '"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * POST /leave-requests
     * Create new leave request
     */
    public function store(StoreLeaveRequestRequest $request)
    {
        $employee = Auth::user()->employee;

        if (!$employee) {
            return response()->json([
                'message' => 'You must be an employee to create leave requests',
            ], 403);
        }

        DB::beginTransaction();
        try {
            Log::info('[leave_request][create] Incoming leave request', [
                'requester_employee_id' => $employee->id,
                'requester_user_id' => Auth::id(),
                'requester_name' => Auth::user()?->name,
                'requester_email' => Auth::user()?->email,
                'request_type' => $request->request_type,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
            ]);

            $requiresDocument = $request->request_type !== LeaveRequest::TYPE_ANNUAL_LEAVE;

            if ($requiresDocument && !$request->hasFile('document')) {
                return response()->json([
                    'message' => 'Dokumen pendukung wajib di-upload untuk tipe permohonan ini',
                    'errors' => [
                        'document' => ['Dokumen pendukung wajib di-upload untuk tipe permohonan ini'],
                    ],
                ], 422);
            }

            $path = null;
            $documentContent = null;
            $documentMimeType = null;
            $documentOriginalName = null;
            if ($request->hasFile('document')) {
                $file = $request->file('document');
                $rawContent = file_get_contents($file->getRealPath());

                if ($rawContent === false) {
                    throw new \RuntimeException('Failed to read uploaded document content');
                }

                $documentContent = base64_encode($rawContent);
                $documentMimeType = $file->getClientMimeType() ?: $file->getMimeType();
                $documentOriginalName = $file->getClientOriginalName();
            }

            $data = [
                'employee_id' => $employee->id,
                'request_type' => $request->request_type,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'reason' => $request->reason,
                'institution' => $request->institution,
                'education_type' => $request->education_type,
                'program_course' => $request->program_course,
                'document_path' => $path,
                'document_content' => $documentContent,
                'document_mime_type' => $documentMimeType,
                'document_original_name' => $documentOriginalName,
                'status' => LeaveRequest::STATUS_PENDING,
            ];

            if ($request->request_type === LeaveRequest::TYPE_ANNUAL_LEAVE) {
                $subtype = (string) $request->input('annual_leave_subtype', 'cuti_kepentingan');
                $subtypeLabel = match ($subtype) {
                    'cuti_bersalin' => 'Cuti Bersalin',
                    'cuti_tahunan' => 'Cuti Tahunan',
                    default => 'Cuti Kepentingan',
                };

                $existingReason = trim((string) ($request->reason ?? ''));
                $data['reason'] = '[' . $subtypeLabel . '] ' . $existingReason;
            }

            $leaveRequest = LeaveRequest::create($data);

            if ($leaveRequest->hasOverlap($leaveRequest->id)) {
                DB::rollBack();
                return response()->json([
                    'message' => 'You already have an approved leave request that overlaps with this period',
                ], 422);
            }

            $approvalBlueprints = $this->resolveApprovalBlueprints($leaveRequest);
            if (!empty($approvalBlueprints['missing_dates'])) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Manager penanggung jawab belum tersedia untuk semua tanggal cuti.',
                    'errors' => [
                        'dates' => $approvalBlueprints['missing_dates'],
                    ],
                ], 422);
            }

            foreach ($approvalBlueprints['approvals'] as $approvalData) {
                LeaveRequestApproval::create([
                    'leave_request_id' => $leaveRequest->id,
                    'roster_day_id' => $approvalData['roster_day_id'],
                    'work_date' => $approvalData['work_date'],
                    'employee_shift_notes' => $approvalData['employee_shift_notes'],
                    'manager_employee_id' => $approvalData['manager_employee_id'],
                    'status' => LeaveRequestApproval::STATUS_PENDING,
                ]);
            }

            Log::info('[leave_request][create] Approval blueprint resolved', [
                'leave_request_id' => $leaveRequest->id,
                'approvals_count' => count($approvalBlueprints['approvals']),
                'approval_targets' => collect($approvalBlueprints['approvals'])->map(function ($approval) {
                    return [
                        'work_date' => $approval['work_date'] ?? null,
                        'roster_day_id' => $approval['roster_day_id'] ?? null,
                        'manager_employee_id' => $approval['manager_employee_id'] ?? null,
                    ];
                })->values()->all(),
            ]);

            $leaveRequest->load($this->getLeaveRequestRelations());

            $managerEmployeeIds = $leaveRequest->approvals
                ->pluck('manager_employee_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $this->notifyManagers($leaveRequest);

            $formattedStart = Carbon::parse($leaveRequest->start_date)->format('d M Y');
            $formattedEnd = Carbon::parse($leaveRequest->end_date)->format('d M Y');
            Notification::create([
                'user_id' => $leaveRequest->employee->user_id,
                'title' => 'Permohonan Cuti Terkirim #' . $leaveRequest->id,
                'message' => 'Permohonan ' . $leaveRequest->request_type_name . ' Anda (' .
                    $formattedStart . ' - ' . $formattedEnd .
                    ') telah dikirim dan sedang menunggu persetujuan manager sesuai jadwal tiap tanggal.',
                'type' => 'inbox',
                'category' => 'leave_request',
                'data' => json_encode([
                    'leave_request_id' => $leaveRequest->id,
                    'request_type' => $leaveRequest->request_type,
                    'status' => 'pending',
                    'start_date' => Carbon::parse($leaveRequest->start_date)->format('Y-m-d'),
                    'end_date' => Carbon::parse($leaveRequest->end_date)->format('Y-m-d'),
                    'manager_employee_ids' => $managerEmployeeIds,
                ]),
            ]);

            Log::info('[leave_request][notify] Requester notification created', [
                'leave_request_id' => $leaveRequest->id,
                'recipient_user_id' => $leaveRequest->employee->user_id,
                'recipient_employee_id' => $leaveRequest->employee_id,
                'recipient_name' => $leaveRequest->employee?->user?->name,
                'recipient_email' => $leaveRequest->employee?->user?->email,
                'channel' => 'in-app',
            ]);

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'create',
                'module' => 'leave_request',
                'reference_id' => $leaveRequest->id,
                'description' => 'Created leave request - ' . $leaveRequest->request_type_name,
            ]);

            DB::commit();

            Log::info('[leave_request][create] Leave request committed', [
                'leave_request_id' => $leaveRequest->id,
                'requester_user_id' => Auth::id(),
            ]);

            return response()->json([
                'message' => 'Leave request created successfully',
                'data' => $this->serializeLeaveRequest($leaveRequest->fresh($this->getLeaveRequestRelations()), Auth::user(), $employee),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            if (isset($data['document_path']) && Storage::disk('public')->exists($data['document_path'])) {
                Storage::disk('public')->delete($data['document_path']);
            }

            return response()->json([
                'message' => 'Failed to create leave request',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /leave-requests/{id}/update-status
     * Approve or reject leave request (managers only)
     */
    public function updateStatus($id, UpdateLeaveRequestStatusRequest $request)
    {
        $user = Auth::user();
        $employee = $user->employee;

        if (!$this->isManagerRole($user->role)) {
            return response()->json([
                'message' => 'Only managers can approve or reject leave requests',
            ], 403);
        }

        if (!$employee) {
            return response()->json([
                'message' => 'Data employee manager tidak ditemukan',
            ], 403);
        }

        $leaveRequest = LeaveRequest::with($this->getLeaveRequestRelations())->findOrFail($id);

        if (!$leaveRequest->canBeApproved() && $request->status === LeaveRequest::STATUS_APPROVED) {
            return response()->json([
                'message' => 'This leave request cannot be approved',
            ], 400);
        }

        if (!$leaveRequest->canBeRejected() && $request->status === LeaveRequest::STATUS_REJECTED) {
            return response()->json([
                'message' => 'This leave request cannot be rejected',
            ], 400);
        }

        DB::beginTransaction();
        try {
            $approvals = $this->ensurePendingApprovals($leaveRequest);
            $assignedApprovals = $approvals->filter(function (LeaveRequestApproval $approval) use ($employee) {
                return (int) $approval->manager_employee_id === (int) $employee->id;
            })->values();

            $pendingAssignedApprovals = $assignedApprovals->filter(function (LeaveRequestApproval $approval) {
                return $approval->status === LeaveRequestApproval::STATUS_PENDING;
            })->values();

            if ($pendingAssignedApprovals->isEmpty()) {
                if ($assignedApprovals->isNotEmpty()) {
                    return response()->json([
                        'message' => 'Anda sudah memproses semua tanggal cuti yang menjadi tanggung jawab Anda.',
                    ], 400);
                }

                return response()->json([
                    'message' => 'Anda bukan manager penanggung jawab untuk tanggal cuti pada permohonan ini.',
                ], 403);
            }

            $updatedAssignmentsCount = 0;
            $shouldNotifyEmployee = false;
            $responseMessage = 'Leave request status updated successfully';
            $actionType = $request->status === LeaveRequest::STATUS_APPROVED ? 'approve_partial' : 'reject';

            if ($request->status === LeaveRequest::STATUS_APPROVED) {
                if ($leaveRequest->hasOverlap($leaveRequest->id)) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Employee already has an approved leave request that overlaps with this period',
                    ], 422);
                }

                $this->markApprovals($pendingAssignedApprovals, LeaveRequestApproval::STATUS_APPROVED, $request->approval_notes);
                $leaveRequest->refresh()->load($this->getLeaveRequestRelations());

                $hasRemainingApprovals = $leaveRequest->approvals()
                    ->where('status', '!=', LeaveRequestApproval::STATUS_APPROVED)
                    ->exists();

                if (!$hasRemainingApprovals) {
                    $leaveRequest->approve($employee->id, $request->approval_notes);
                    $updatedAssignmentsCount = $this->applyLeaveToSchedule($leaveRequest->fresh());
                    $notificationTitle = 'Permohonan Cuti Disetujui ? #' . $leaveRequest->id;
                    $notificationMessage = 'Permohonan ' . $leaveRequest->request_type_name . ' Anda (' .
                        Carbon::parse($leaveRequest->start_date)->format('d M Y') . ' - ' .
                        Carbon::parse($leaveRequest->end_date)->format('d M Y') .
                        ') telah disetujui oleh seluruh manager yang bertugas.' .
                        ($request->approval_notes ? ' Catatan: ' . $request->approval_notes : '');
                    $shouldNotifyEmployee = true;
                    $responseMessage = 'Semua approval manager untuk permohonan cuti ini sudah lengkap.';
                    $actionType = 'approve';
                } else {
                    $responseMessage = 'Persetujuan manager untuk tanggal yang menjadi tanggung jawab Anda sudah dicatat. Menunggu manager pada tanggal lainnya.';
                }
            } else {
                $this->markApprovals($pendingAssignedApprovals, LeaveRequestApproval::STATUS_REJECTED, $request->approval_notes);
                $leaveRequest->reject($employee->id, $request->approval_notes);
                $notificationTitle = 'Permohonan Cuti Ditolak #' . $leaveRequest->id;
                $notificationMessage = 'Permohonan ' . $leaveRequest->request_type_name . ' Anda (' .
                    Carbon::parse($leaveRequest->start_date)->format('d M Y') . ' - ' .
                    Carbon::parse($leaveRequest->end_date)->format('d M Y') .
                    ') ditolak oleh manager yang bertugas.' .
                    ($request->approval_notes ? ' Alasan: ' . $request->approval_notes : '');
                $shouldNotifyEmployee = true;
                $responseMessage = 'Permohonan cuti ditolak.';
            }

            $leaveRequest->refresh()->load($this->getLeaveRequestRelations());

            if ($shouldNotifyEmployee) {
                $managerEmployeeIds = $leaveRequest->approvals
                    ->pluck('manager_employee_id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                Notification::create([
                    'user_id' => $leaveRequest->employee->user_id,
                    'sender_id' => Auth::id(),
                    'title' => $notificationTitle,
                    'message' => $notificationMessage,
                    'type' => 'inbox',
                    'category' => 'leave_request',
                    'data' => json_encode([
                        'leave_request_id' => $leaveRequest->id,
                        'request_type' => $leaveRequest->request_type,
                        'status' => $leaveRequest->status,
                        'start_date' => Carbon::parse($leaveRequest->start_date)->format('Y-m-d'),
                        'end_date' => Carbon::parse($leaveRequest->end_date)->format('Y-m-d'),
                        'manager_employee_ids' => $managerEmployeeIds,
                    ]),
                ]);

                try {
                    Mail::to($leaveRequest->employee->user->email)->send(
                        new LeaveRequestStatusChangedMail($leaveRequest, $leaveRequest->employee->user->name)
                    );
                } catch (\Exception $e) {
                    Log::error('Failed to send leave request status email to employee: ' . $e->getMessage(), [
                        'employee_email' => $leaveRequest->employee->user->email,
                        'leave_request_id' => $leaveRequest->id,
                    ]);
                }
            }

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => $actionType,
                'module' => 'leave_request',
                'reference_id' => $leaveRequest->id,
                'description' => ucfirst(str_replace('_', ' ', $actionType)) . ' leave request - ' . $leaveRequest->request_type_name,
            ]);

            DB::commit();

            return response()->json([
                'message' => $responseMessage,
                'data' => $this->serializeLeaveRequest($leaveRequest, $user, $employee),
                'schedule_updated_days' => $updatedAssignmentsCount,
            ]);
        } catch (\RuntimeException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update leave request status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /leave-requests/{id}
     * Cancel/delete leave request (only pending requests can be deleted by owner)
     */
    public function destroy($id)
    {
        $leaveRequest = LeaveRequest::findOrFail($id);
        $employee = Auth::user()->employee;
        $user = Auth::user();

        if ($employee && $leaveRequest->employee_id !== $employee->id) {
            if (!$this->isManagerRole($user->role)) {
                return response()->json([
                    'message' => 'Unauthorized to delete this leave request',
                ], 403);
            }
        }

        if ($leaveRequest->status !== LeaveRequest::STATUS_PENDING) {
            return response()->json([
                'message' => 'Only pending leave requests can be deleted',
            ], 400);
        }

        DB::beginTransaction();
        try {
            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'delete',
                'module' => 'leave_request',
                'reference_id' => $leaveRequest->id,
                'description' => 'Deleted leave request - ' . $leaveRequest->request_type_name,
            ]);

            $leaveRequest->delete();

            DB::commit();

            return response()->json([
                'message' => 'Leave request deleted successfully',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete leave request',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /leave-requests/statistics
     * Get leave request statistics for current user or all employees (for managers)
     */
    public function statistics(Request $request)
    {
        $user = Auth::user();
        $employee = $user->employee;
        $isManager = $this->isManagerRole($user->role);

        $query = LeaveRequest::query();

        if (!$isManager && $employee) {
            $query->where('employee_id', $employee->id);
        }

        $year = $request->get('year', date('Y'));
        $query->whereYear('start_date', $year);

        $statistics = [
            'total' => $query->count(),
            'pending' => (clone $query)->pending()->count(),
            'approved' => (clone $query)->approved()->count(),
            'rejected' => (clone $query)->rejected()->count(),
            'by_type' => [
                'doctor_leave' => (clone $query)->byType(LeaveRequest::TYPE_DOCTOR_LEAVE)->count(),
                'annual_leave' => (clone $query)->byType(LeaveRequest::TYPE_ANNUAL_LEAVE)->count(),
                'external_duty' => (clone $query)->byType(LeaveRequest::TYPE_EXTERNAL_DUTY)->count(),
                'educational_assignment' => (clone $query)->byType(LeaveRequest::TYPE_EDUCATIONAL_ASSIGNMENT)->count(),
            ],
        ];

        $approvedRequests = (clone $query)->approved()->get();
        $statistics['total_approved_days'] = $approvedRequests->sum('total_days');

        return response()->json([
            'message' => 'Leave request statistics retrieved successfully',
            'data' => $statistics,
        ]);
    }

    /**
     * GET /leave-requests/approval-preview
     * Preview managers who will approve a leave request for selected date range
     */
    public function approvalPreview(Request $request)
    {
        $employee = Auth::user()->employee;

        if (!$employee) {
            return response()->json([
                'message' => 'You must be an employee to preview leave approvers',
            ], 403);
        }

        $validated = $request->validate([
            'request_type' => 'required|in:' . implode(',', [
                LeaveRequest::TYPE_DOCTOR_LEAVE,
                LeaveRequest::TYPE_ANNUAL_LEAVE,
                LeaveRequest::TYPE_EXTERNAL_DUTY,
                LeaveRequest::TYPE_EDUCATIONAL_ASSIGNMENT,
            ]),
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $previewRequest = new LeaveRequest([
            'employee_id' => $employee->id,
            'request_type' => $validated['request_type'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'status' => LeaveRequest::STATUS_PENDING,
        ]);
        $previewRequest->setRelation('employee', $employee->loadMissing('user'));

        $approvalBlueprints = $this->resolveApprovalBlueprints($previewRequest);

        $approvals = collect($approvalBlueprints['approvals'])->map(function ($approval) {
            $managerEmployee = $approval['manager_employee'] ?? null;
            return [
                'work_date' => $approval['work_date'] ?? null,
                'manager_employee_id' => $approval['manager_employee_id'] ?? null,
                'manager_name' => $managerEmployee?->user?->name,
                'manager_role' => $managerEmployee?->user?->role,
                'employee_shift_notes' => $approval['employee_shift_notes'] ?? null,
            ];
        })->values();

        $uniqueApprovers = $approvals
            ->filter(fn ($approval) => !empty($approval['manager_employee_id']))
            ->unique('manager_employee_id')
            ->values()
            ->map(function ($approval) {
                return [
                    'manager_employee_id' => $approval['manager_employee_id'],
                    'manager_name' => $approval['manager_name'],
                    'manager_role' => $approval['manager_role'],
                ];
            })
            ->values();

        return response()->json([
            'message' => 'Leave approval preview generated successfully',
            'data' => [
                'approvals' => $approvals,
                'unique_approvers' => $uniqueApprovers,
                'missing_dates' => $approvalBlueprints['missing_dates'],
            ],
        ]);
    }

    /**
     * Apply approved leave period to roster assignments
     */
    private function applyLeaveToSchedule(LeaveRequest $leaveRequest): int
    {
        [$shiftName, $assignmentNotes] = $this->getLeaveShiftMapping($leaveRequest);

        $leaveShift = Shift::where('name', $shiftName)->first();
        if (!$leaveShift) {
            throw new \RuntimeException("Shift '{$shiftName}' tidak ditemukan. Silakan hubungi admin.");
        }

        $updatedAssignmentsCount = 0;
        $datePeriod = CarbonPeriod::create($leaveRequest->start_date, $leaveRequest->end_date);

        foreach ($datePeriod as $date) {
            $workDate = $date->format('Y-m-d');
            $rosterDays = RosterDay::whereDate('work_date', $workDate)->get();

            foreach ($rosterDays as $rosterDay) {
                $existingAssignment = ShiftAssignment::withTrashed()
                    ->where('roster_day_id', $rosterDay->id)
                    ->where('employee_id', $leaveRequest->employee_id)
                    ->first();

                if ($existingAssignment) {
                    if ($existingAssignment->trashed()) {
                        $existingAssignment->restore();
                    }

                    $existingAssignment->update([
                        'shift_id' => $leaveShift->id,
                        'notes' => $assignmentNotes,
                        'span_days' => 1,
                    ]);
                } else {
                    ShiftAssignment::create([
                        'roster_day_id' => $rosterDay->id,
                        'employee_id' => $leaveRequest->employee_id,
                        'shift_id' => $leaveShift->id,
                        'notes' => $assignmentNotes,
                        'span_days' => 1,
                    ]);
                }

                $updatedAssignmentsCount++;
            }
        }

        if ($updatedAssignmentsCount > 0) {
            CacheHelper::clearRosterCache();
        }

        return $updatedAssignmentsCount;
    }

    /**
     * Map leave request type to shift and assignment notes
     */
    private function getLeaveShiftMapping(LeaveRequest $leaveRequest): array
    {
        return match ($leaveRequest->request_type) {
            LeaveRequest::TYPE_ANNUAL_LEAVE => ['cuti_tahunan', 'CT - Cuti Tahunan'],
            LeaveRequest::TYPE_DOCTOR_LEAVE => ['cuti_sakit', 'CS - Cuti Sakit'],
            LeaveRequest::TYPE_EXTERNAL_DUTY => ['dinas_luar', 'DL - Dinas Luar'],
            LeaveRequest::TYPE_EDUCATIONAL_ASSIGNMENT => ['dinas_luar', 'TP - Tugas Pendidikan'],
            default => ['cuti_tahunan', 'Cuti'],
        };
    }

    private function getManagerEmployeeIds(): array
    {
        static $managerEmployeeIds = null;

        if ($managerEmployeeIds !== null) {
            return $managerEmployeeIds;
        }

        $managerEmployeeIds = Employee::query()
            ->whereHas('user', function ($query) {
                $query->whereIn('role', [User::ROLE_MANAGER_TEKNIK, User::ROLE_GENERAL_MANAGER])
                    ->where('is_active', true);
            })
            ->pluck('id')
            ->toArray();

        return $managerEmployeeIds;
    }

    private function resolveApprovalBlueprints(LeaveRequest $leaveRequest): array
    {
        $leaveRequest->loadMissing('employee.user');

        $approvalBlueprints = [];
        $missingDates = [];

        foreach (CarbonPeriod::create($leaveRequest->start_date, $leaveRequest->end_date) as $date) {
            $workDate = $date->format('Y-m-d');
            $resolution = $this->resolveManagerAssignmentForDate($leaveRequest, $workDate);

            if (!$resolution['manager_employee']) {
                $missingDates[] = $resolution['message'];
                continue;
            }

            $approvalBlueprints[] = [
                'work_date' => $workDate,
                'roster_day_id' => $resolution['roster_day']?->id,
                'employee_shift_notes' => $resolution['employee_shift_notes'],
                'manager_employee_id' => $resolution['manager_employee']->id,
                'manager_employee' => $resolution['manager_employee'],
            ];
        }

        return [
            'approvals' => $approvalBlueprints,
            'missing_dates' => $missingDates,
        ];
    }

    private function resolveManagerAssignmentForDate(LeaveRequest $leaveRequest, string $workDate): array
    {
        $publishedRosterDay = RosterDay::query()
            ->whereDate('work_date', $workDate)
            ->whereHas('rosterPeriod', function ($query) {
                $query->where('status', 'published');
            })
            ->with('rosterPeriod')
            ->orderByDesc('id')
            ->first();

        if (!$publishedRosterDay) {
            return [
                'roster_day' => null,
                'employee_shift_notes' => null,
                'manager_employee' => null,
                'message' => 'Tanggal ' . Carbon::parse($workDate)->format('d M Y') . ' belum memiliki roster published.',
            ];
        }

        $employeeAssignment = ShiftAssignment::query()
            ->where('roster_day_id', $publishedRosterDay->id)
            ->where('employee_id', $leaveRequest->employee_id)
            ->first();

        $employeeShiftNotes = trim((string) ($employeeAssignment?->notes ?? ''));
        $managerEmployee = null;

        // If the requester is a manager, approval must go to General Manager.
        if ($this->isManagerRole($leaveRequest->employee?->user?->role)) {
            $generalManagerEmployeeIds = $this->getGeneralManagerEmployeeIds();

            if (!empty($generalManagerEmployeeIds)) {
                $generalManagerEmployee = Employee::query()
                    ->with('user')
                    ->whereIn('id', $generalManagerEmployeeIds)
                    ->whereHas('user', function ($query) {
                        $query->where('is_active', true);
                    })
                    ->first();

                if ($generalManagerEmployee) {
                    return [
                        'roster_day' => $publishedRosterDay,
                        'employee_shift_notes' => $employeeShiftNotes !== '' ? $employeeShiftNotes : null,
                        'manager_employee' => $generalManagerEmployee,
                        'message' => null,
                    ];
                }
            }

            return [
                'roster_day' => $publishedRosterDay,
                'employee_shift_notes' => $employeeShiftNotes !== '' ? $employeeShiftNotes : null,
                'manager_employee' => null,
                'message' => 'General Manager tidak ditemukan untuk approval cuti manager.',
            ];
        }

        if ($employeeShiftNotes !== '') {
            $managerAssignment = ShiftAssignment::query()
                ->with('employee.user')
                ->where('roster_day_id', $publishedRosterDay->id)
                ->whereIn('employee_id', $this->getManagerEmployeeIds())
                ->whereRaw('LOWER(TRIM(notes)) = ?', [strtolower($employeeShiftNotes)])
                ->get()
                ->sortBy(function (ShiftAssignment $assignment) {
                    return $this->isManagerTeknikRole($assignment->employee?->user?->role) ? 0 : 1;
                })
                ->first();

            $managerEmployee = $managerAssignment?->employee;
        }

        if (!$managerEmployee) {
            $managerDuty = ManagerDuty::query()
                ->with('employee.user')
                ->where('roster_day_id', $publishedRosterDay->id)
                ->whereHas('employee.user', function ($query) {
                    $query->whereIn('role', [User::ROLE_MANAGER_TEKNIK, User::ROLE_GENERAL_MANAGER])
                        ->where('is_active', true);
                })
                ->get()
                ->sortBy(function (ManagerDuty $duty) {
                    return $this->isManagerTeknikRole($duty->employee?->user?->role) ? 0 : 1;
                })
                ->first();

            $managerEmployee = $managerDuty?->employee;
        }

        if (!$managerEmployee) {
            $reason = $employeeShiftNotes !== ''
                ? 'Tidak ada manager yang bertugas pada shift ' . $employeeShiftNotes
                : 'Karyawan belum memiliki assignment shift pada roster published';

            return [
                'roster_day' => $publishedRosterDay,
                'employee_shift_notes' => $employeeShiftNotes !== '' ? $employeeShiftNotes : null,
                'manager_employee' => null,
                'message' => 'Tanggal ' . Carbon::parse($workDate)->format('d M Y') . ': ' . $reason . '.',
            ];
        }

        return [
            'roster_day' => $publishedRosterDay,
            'employee_shift_notes' => $employeeShiftNotes !== '' ? $employeeShiftNotes : null,
            'manager_employee' => $managerEmployee,
            'message' => null,
        ];
    }

    private function ensurePendingApprovals(LeaveRequest $leaveRequest): Collection
    {
        $leaveRequest->loadMissing($this->getLeaveRequestRelations());

        if ($leaveRequest->approvals->isNotEmpty() || $leaveRequest->status !== LeaveRequest::STATUS_PENDING) {
            return $leaveRequest->approvals->values();
        }

        $approvalBlueprints = $this->resolveApprovalBlueprints($leaveRequest);
        if (!empty($approvalBlueprints['missing_dates'])) {
            throw new \RuntimeException('Manager penanggung jawab belum tersedia: ' . implode(' ', $approvalBlueprints['missing_dates']));
        }

        foreach ($approvalBlueprints['approvals'] as $approvalData) {
            LeaveRequestApproval::create([
                'leave_request_id' => $leaveRequest->id,
                'roster_day_id' => $approvalData['roster_day_id'],
                'work_date' => $approvalData['work_date'],
                'employee_shift_notes' => $approvalData['employee_shift_notes'],
                'manager_employee_id' => $approvalData['manager_employee_id'],
                'status' => LeaveRequestApproval::STATUS_PENDING,
            ]);
        }

        $leaveRequest->refresh()->load($this->getLeaveRequestRelations());

        return $leaveRequest->approvals->values();
    }

    private function markApprovals(Collection $approvals, string $status, ?string $notes): void
    {
        $now = now();

        foreach ($approvals as $approval) {
            $approval->update([
                'status' => $status,
                'approval_notes' => $notes,
                'approved_at' => $now,
            ]);
        }
    }

    private function serializeLeaveRequest(LeaveRequest $leaveRequest, ?User $currentUser = null, ?Employee $currentEmployee = null): array
    {
        $payload = $leaveRequest->toArray();
        unset($payload['approvals']);

        $approvalDates = $this->buildApprovalDatesPayload($leaveRequest, $currentEmployee);
        $approvalSummary = [
            'total_dates' => $approvalDates->count(),
            'approved_dates' => $approvalDates->where('status', LeaveRequestApproval::STATUS_APPROVED)->count(),
            'pending_dates' => $approvalDates->where('status', LeaveRequestApproval::STATUS_PENDING)->count(),
            'rejected_dates' => $approvalDates->where('status', LeaveRequestApproval::STATUS_REJECTED)->count(),
        ];
        $approvalSummary['is_fully_approved'] = $approvalSummary['total_dates'] > 0
            && $approvalSummary['approved_dates'] === $approvalSummary['total_dates'];

        $currentUserPendingDates = $approvalDates
            ->filter(fn(array $approval) => $approval['current_user_can_approve'] === true)
            ->pluck('work_date')
            ->values()
            ->all();

        $payload['approval_dates'] = $approvalDates->values()->all();
        $payload['approval_summary'] = $approvalSummary;
        $payload['current_user_can_approve'] = !empty($currentUserPendingDates);
        $payload['current_user_pending_approval_dates'] = $currentUserPendingDates;
        $payload['current_user_already_approved'] = $approvalDates->contains(function (array $approval) {
            return $approval['current_user_already_approved'] === true;
        });
        $payload['assigned_managers'] = $approvalDates
            ->pluck('manager')
            ->filter()
            ->unique('id')
            ->values()
            ->all();

        return $payload;
    }

    private function buildApprovalDatesPayload(LeaveRequest $leaveRequest, ?Employee $currentEmployee): Collection
    {
        $leaveRequest->loadMissing($this->getLeaveRequestRelations());

        if ($leaveRequest->approvals->isNotEmpty()) {
            return $leaveRequest->approvals->map(function (LeaveRequestApproval $approval) use ($currentEmployee) {
                $isCurrentUserManager = $currentEmployee && (int) $approval->manager_employee_id === (int) $currentEmployee->id;
                $managerUser = $approval->managerEmployee?->user;

                return [
                    'id' => $approval->id,
                    'work_date' => $approval->work_date ? Carbon::parse($approval->work_date)->format('Y-m-d') : null,
                    'roster_day_id' => $approval->roster_day_id,
                    'employee_shift_notes' => $approval->employee_shift_notes,
                    'status' => $approval->status,
                    'status_name' => $this->getApprovalStatusName($approval->status),
                    'approval_notes' => $approval->approval_notes,
                    'approved_at' => $approval->approved_at?->toIso8601String(),
                    'manager_employee_id' => $approval->manager_employee_id,
                    'manager' => $managerUser ? [
                        'id' => $managerUser->id,
                        'employee_id' => $approval->manager_employee_id,
                        'name' => $managerUser->name,
                        'email' => $managerUser->email,
                        'role' => $managerUser->role,
                    ] : null,
                    'current_user_is_assigned_manager' => $isCurrentUserManager,
                    'current_user_can_approve' => $isCurrentUserManager && $approval->status === LeaveRequestApproval::STATUS_PENDING,
                    'current_user_already_approved' => $isCurrentUserManager && $approval->status === LeaveRequestApproval::STATUS_APPROVED,
                    'needs_assignment' => false,
                ];
            });
        }

        if ($leaveRequest->status === LeaveRequest::STATUS_PENDING) {
            $approvalBlueprints = $this->resolveApprovalBlueprints($leaveRequest);

            $pendingApprovals = collect($approvalBlueprints['approvals'])->map(function (array $approval) use ($currentEmployee) {
                $managerUser = $approval['manager_employee']?->user;
                $isCurrentUserManager = $currentEmployee && (int) $approval['manager_employee_id'] === (int) $currentEmployee->id;

                return [
                    'id' => null,
                    'work_date' => $approval['work_date'],
                    'roster_day_id' => $approval['roster_day_id'],
                    'employee_shift_notes' => $approval['employee_shift_notes'],
                    'status' => LeaveRequestApproval::STATUS_PENDING,
                    'status_name' => $this->getApprovalStatusName(LeaveRequestApproval::STATUS_PENDING),
                    'approval_notes' => null,
                    'approved_at' => null,
                    'manager_employee_id' => $approval['manager_employee_id'],
                    'manager' => $managerUser ? [
                        'id' => $managerUser->id,
                        'employee_id' => $approval['manager_employee_id'],
                        'name' => $managerUser->name,
                        'email' => $managerUser->email,
                        'role' => $managerUser->role,
                    ] : null,
                    'current_user_is_assigned_manager' => $isCurrentUserManager,
                    'current_user_can_approve' => $isCurrentUserManager,
                    'current_user_already_approved' => false,
                    'needs_assignment' => false,
                ];
            });

            $missingApprovals = collect($approvalBlueprints['missing_dates'])->map(function (string $message) {
                preg_match('/Tanggal\s(\d{2}\s\w{3}\s\d{4})/', $message, $matches);

                return [
                    'id' => null,
                    'work_date' => null,
                    'roster_day_id' => null,
                    'employee_shift_notes' => null,
                    'status' => LeaveRequestApproval::STATUS_PENDING,
                    'status_name' => 'Manager Belum Tersedia',
                    'approval_notes' => $message,
                    'approved_at' => null,
                    'manager_employee_id' => null,
                    'manager' => null,
                    'current_user_is_assigned_manager' => false,
                    'current_user_can_approve' => false,
                    'current_user_already_approved' => false,
                    'needs_assignment' => true,
                    'label' => $matches[1] ?? $message,
                ];
            });

            return $pendingApprovals->concat($missingApprovals)->values();
        }

        $approvedByUser = $leaveRequest->approvedByManager?->user;

        return collect(CarbonPeriod::create($leaveRequest->start_date, $leaveRequest->end_date))
            ->map(function (Carbon $date) use ($leaveRequest, $approvedByUser, $currentEmployee) {
                $isCurrentUserManager = $currentEmployee && (int) $leaveRequest->approved_by_manager_id === (int) $currentEmployee->id;

                return [
                    'id' => null,
                    'work_date' => $date->format('Y-m-d'),
                    'roster_day_id' => null,
                    'employee_shift_notes' => null,
                    'status' => $leaveRequest->status,
                    'status_name' => $this->getApprovalStatusName($leaveRequest->status),
                    'approval_notes' => $leaveRequest->approval_notes,
                    'approved_at' => $leaveRequest->approved_at?->toIso8601String(),
                    'manager_employee_id' => $leaveRequest->approved_by_manager_id,
                    'manager' => $approvedByUser ? [
                        'id' => $approvedByUser->id,
                        'employee_id' => $leaveRequest->approved_by_manager_id,
                        'name' => $approvedByUser->name,
                        'email' => $approvedByUser->email,
                        'role' => $approvedByUser->role,
                    ] : null,
                    'current_user_is_assigned_manager' => $isCurrentUserManager,
                    'current_user_can_approve' => false,
                    'current_user_already_approved' => $isCurrentUserManager && $leaveRequest->status === LeaveRequest::STATUS_APPROVED,
                    'needs_assignment' => false,
                ];
            })
            ->values();
    }

    private function getApprovalStatusName(string $status): string
    {
        return match ($status) {
            LeaveRequestApproval::STATUS_APPROVED, LeaveRequest::STATUS_APPROVED => 'Disetujui',
            LeaveRequestApproval::STATUS_REJECTED, LeaveRequest::STATUS_REJECTED => 'Ditolak',
            default => 'Menunggu Persetujuan',
        };
    }

    /**
     * Notify managers about new leave request.
     */
    private function notifyManagers(LeaveRequest $leaveRequest): void
    {
        $leaveRequest->loadMissing($this->getLeaveRequestRelations());

        Log::info('[leave_request][notify_manager] Called', [
            'leave_request_id' => $leaveRequest->id,
            'requester_employee_id' => $leaveRequest->employee_id,
            'requester_name' => $leaveRequest->employee?->user?->name,
            'requester_email' => $leaveRequest->employee?->user?->email,
        ]);

        $formattedStartDate = Carbon::parse($leaveRequest->start_date)->format('d M Y');
        $formattedEndDate = Carbon::parse($leaveRequest->end_date)->format('d M Y');
        $isoStartDate = Carbon::parse($leaveRequest->start_date)->format('Y-m-d');
        $isoEndDate = Carbon::parse($leaveRequest->end_date)->format('Y-m-d');

        $managerGroups = $leaveRequest->approvals
            ->filter(fn(LeaveRequestApproval $approval) => $approval->managerEmployee?->user)
            ->groupBy('manager_employee_id');

        foreach ($managerGroups as $approvals) {
            /** @var LeaveRequestApproval $firstApproval */
            $firstApproval = $approvals->first();
            $managerUser = $firstApproval->managerEmployee?->user;

            if (!$managerUser) {
                continue;
            }

            $datesLabel = $approvals
                ->map(fn(LeaveRequestApproval $approval) => Carbon::parse($approval->work_date)->format('d M Y'))
                ->unique()
                ->implode(', ');

            Notification::create([
                'user_id' => $managerUser->id,
                'sender_id' => $leaveRequest->employee->user_id,
                'title' => 'Permohonan Cuti Baru #' . $leaveRequest->id,
                'message' => $leaveRequest->employee->user->name . ' mengajukan permohonan ' .
                    $leaveRequest->request_type_name . ' (' .
                    $formattedStartDate . ' - ' .
                    $formattedEndDate . '). Tanggal yang membutuhkan approval Anda: ' . $datesLabel,
                'type' => 'inbox',
                'category' => 'leave_request',
                'data' => json_encode([
                    'leave_request_id' => $leaveRequest->id,
                    'employee_name' => $leaveRequest->employee->user->name,
                    'request_type' => $leaveRequest->request_type,
                    'start_date' => $isoStartDate,
                    'end_date' => $isoEndDate,
                    'approval_dates' => $approvals->map(fn(LeaveRequestApproval $approval) => $approval->work_date ? Carbon::parse($approval->work_date)->format('Y-m-d') : null)->filter()->values()->all(),
                ]),
            ]);

            Log::info('[leave_request][notify_manager] Manager notification created', [
                'leave_request_id' => $leaveRequest->id,
                'manager_user_id' => $managerUser->id,
                'manager_employee_id' => $firstApproval->manager_employee_id,
                'manager_name' => $managerUser->name,
                'manager_email' => $managerUser->email,
                'manager_role' => $managerUser->role,
                'approval_dates' => $approvals->map(fn(LeaveRequestApproval $approval) => $approval->work_date ? Carbon::parse($approval->work_date)->format('Y-m-d') : null)->filter()->values()->all(),
                'channel' => 'in-app',
            ]);

            try {
                Mail::to($managerUser->email)->send(
                    new LeaveRequestSubmittedMail($leaveRequest, $managerUser->name)
                );

                Log::info('[leave_request][notify_manager] Email sent', [
                    'leave_request_id' => $leaveRequest->id,
                    'manager_user_id' => $managerUser->id,
                    'manager_email' => $managerUser->email,
                    'mail_class' => LeaveRequestSubmittedMail::class,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send leave request email to manager: ' . $e->getMessage(), [
                    'manager_email' => $managerUser->email,
                    'leave_request_id' => $leaveRequest->id,
                ]);
            }
        }

        Log::info('[leave_request][notify_manager] Completed', [
            'leave_request_id' => $leaveRequest->id,
            'managers_notified_count' => $managerGroups->count(),
        ]);
    }
}
