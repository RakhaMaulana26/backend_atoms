<?php

namespace App\Http\Controllers\Api;

use App\Helpers\CacheHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeaveRequestRequest;
use App\Http\Requests\UpdateLeaveRequestStatusRequest;
use App\Mail\LeaveRequestSubmittedMail;
use App\Mail\LeaveRequestStatusChangedMail;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Notification;
use App\Models\RosterDay;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
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

    /**
     * GET /leave-requests
     * Get all leave requests (for managers)
     */
    public function index(Request $request)
    {
        $query = LeaveRequest::with(['employee.user', 'approvedByManager.user'])
            ->orderBy('created_at', 'desc');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by request type
        if ($request->has('request_type')) {
            $query->where('request_type', $request->request_type);
        }

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->dateRange($request->start_date, $request->end_date);
        }

        // Filter by employee
        if ($request->has('employee_id')) {
            $query->byEmployee($request->employee_id);
        }

        // Pagination
        $perPage = $request->get('per_page', 15);
        $leaveRequests = $query->paginate($perPage);

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
        $employee = Auth::user()->employee;

        if (!$employee) {
            return response()->json([
                'message' => 'You must be an employee to view leave requests',
            ], 403);
        }

        $query = LeaveRequest::with(['approvedByManager.user'])
            ->where('employee_id', $employee->id)
            ->orderBy('created_at', 'desc');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by request type
        if ($request->has('request_type')) {
            $query->where('request_type', $request->request_type);
        }

        // Pagination
        $perPage = $request->get('per_page', 15);
        $leaveRequests = $query->paginate($perPage);

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
        $leaveRequest = LeaveRequest::with(['employee.user', 'approvedByManager.user'])
            ->findOrFail($id);

        $employee = Auth::user()->employee;
        $user = Auth::user();

        // Check authorization - employee can view their own, managers can view all
        if ($employee && $leaveRequest->employee_id !== $employee->id) {
            // Check if user is a manager
            if (!$this->isManagerRole($user->role)) {
                return response()->json([
                    'message' => 'Unauthorized to view this leave request',
                ], 403);
            }
        }

        return response()->json([
            'message' => 'Leave request retrieved successfully',
            'data' => $leaveRequest,
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

        // Employee can only access their own document, managers can access all.
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
            $requiresDocument = $request->request_type !== LeaveRequest::TYPE_ANNUAL_LEAVE;

            // Supporting document is optional for annual leave only.
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

                // Store document in DB as base64 to avoid filesystem dependency.
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

            $leaveRequest = LeaveRequest::create($data);

            // Check for overlapping leave requests
            if ($leaveRequest->hasOverlap($leaveRequest->id)) {
                DB::rollBack();
                return response()->json([
                    'message' => 'You already have an approved leave request that overlaps with this period',
                ], 422);
            }

            // Notify managers
            $this->notifyManagers($leaveRequest);

            // Notify employee (submission confirmation)
            $formattedStart = \Carbon\Carbon::parse($leaveRequest->start_date)->format('d M Y');
            $formattedEnd   = \Carbon\Carbon::parse($leaveRequest->end_date)->format('d M Y');
            Notification::create([
                'user_id'  => $leaveRequest->employee->user_id,
                'title'    => 'Permohonan Cuti Terkirim #' . $leaveRequest->id,
                'message'  => 'Permohonan ' . $leaveRequest->request_type_name . ' Anda (' .
                              $formattedStart . ' - ' . $formattedEnd .
                              ') telah dikirim dan sedang menunggu persetujuan manager.',
                'type'     => 'inbox',
                'category' => 'leave_request',
                'data'     => json_encode([
                    'leave_request_id' => $leaveRequest->id,
                    'request_type'     => $leaveRequest->request_type,
                    'status'           => 'pending',
                    'start_date'       => \Carbon\Carbon::parse($leaveRequest->start_date)->format('Y-m-d'),
                    'end_date'         => \Carbon\Carbon::parse($leaveRequest->end_date)->format('Y-m-d'),
                ]),
            ]);

            // Log activity
            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'create',
                'module' => 'leave_request',
                'reference_id' => $leaveRequest->id,
                'description' => 'Created leave request - ' . $leaveRequest->request_type_name,
            ]);

            DB::commit();

            // Load relationships
            $leaveRequest->load(['employee.user', 'approvedByManager.user']);

            return response()->json([
                'message' => 'Leave request created successfully',
                'data' => $leaveRequest,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            
            // Delete uploaded file if exists
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

        // Check if user is a manager
        if (!$this->isManagerRole($user->role)) {
            return response()->json([
                'message' => 'Only managers can approve or reject leave requests',
            ], 403);
        }

        $leaveRequest = LeaveRequest::with(['employee.user'])->findOrFail($id);

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
            $updatedAssignmentsCount = 0;

            if ($request->status === LeaveRequest::STATUS_APPROVED) {
                // Check for overlapping leave requests before approving
                if ($leaveRequest->hasOverlap($leaveRequest->id)) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Employee already has an approved leave request that overlaps with this period',
                    ], 422);
                }

                $leaveRequest->approve($employee ? $employee->id : null, $request->approval_notes);
                $updatedAssignmentsCount = $this->applyLeaveToSchedule($leaveRequest);
                $notificationTitle   = 'Permohonan Cuti Disetujui ✓ #' . $leaveRequest->id;
                $notificationMessage = 'Permohonan ' . $leaveRequest->request_type_name . ' Anda (' .
                                      \Carbon\Carbon::parse($leaveRequest->start_date)->format('d M Y') . ' - ' .
                                      \Carbon\Carbon::parse($leaveRequest->end_date)->format('d M Y') .
                                      ') telah disetujui oleh manager.' .
                                      ($request->approval_notes ? ' Catatan: ' . $request->approval_notes : '');
                $actionType = 'approve';
            } else {
                $leaveRequest->reject($employee ? $employee->id : null, $request->approval_notes);
                $notificationTitle   = 'Permohonan Cuti Ditolak #' . $leaveRequest->id;
                $notificationMessage = 'Permohonan ' . $leaveRequest->request_type_name . ' Anda (' .
                                      \Carbon\Carbon::parse($leaveRequest->start_date)->format('d M Y') . ' - ' .
                                      \Carbon\Carbon::parse($leaveRequest->end_date)->format('d M Y') .
                                      ') ditolak oleh manager.' .
                                      ($request->approval_notes ? ' Alasan: ' . $request->approval_notes : '');
                $actionType = 'reject';
            }

            // Notify employee
            Notification::create([
                'user_id'  => $leaveRequest->employee->user_id,
                'sender_id' => Auth::id(),
                'title'    => $notificationTitle,
                'message'  => $notificationMessage,
                'type'     => 'inbox',
                'category' => 'leave_request',
                'data'     => json_encode([
                    'leave_request_id' => $leaveRequest->id,
                    'request_type'     => $leaveRequest->request_type,
                    'status'           => $request->status,
                    'start_date'       => \Carbon\Carbon::parse($leaveRequest->start_date)->format('Y-m-d'),
                    'end_date'         => \Carbon\Carbon::parse($leaveRequest->end_date)->format('Y-m-d'),
                ]),
            ]);

            // Send email notification to employee
            try {
                Mail::to($leaveRequest->employee->user->email)->send(
                    new LeaveRequestStatusChangedMail($leaveRequest, $leaveRequest->employee->user->name)
                );
            } catch (\Exception $e) {
                // Log email error but don't fail the request
                Log::error('Failed to send leave request status email to employee: ' . $e->getMessage(), [
                    'employee_email' => $leaveRequest->employee->user->email,
                    'leave_request_id' => $leaveRequest->id,
                ]);
            }

            // Log activity
            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => $actionType,
                'module' => 'leave_request',
                'reference_id' => $leaveRequest->id,
                'description' => ucfirst($actionType) . 'd leave request - ' . $leaveRequest->request_type_name,
            ]);

            DB::commit();

            // Reload relationships
            $leaveRequest->load(['employee.user', 'approvedByManager.user']);

            return response()->json([
                'message' => 'Leave request status updated successfully',
                'data' => $leaveRequest,
                'schedule_updated_days' => $updatedAssignmentsCount,
            ]);
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

        // Only employee who created the request can delete it
        if ($employee && $leaveRequest->employee_id !== $employee->id) {
            // Unless they're a manager
            if (!$this->isManagerRole($user->role)) {
                return response()->json([
                    'message' => 'Unauthorized to delete this leave request',
                ], 403);
            }
        }

        // Only pending requests can be deleted
        if ($leaveRequest->status !== LeaveRequest::STATUS_PENDING) {
            return response()->json([
                'message' => 'Only pending leave requests can be deleted',
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Log activity before deletion
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

        // If not manager, only show own statistics
        if (!$isManager && $employee) {
            $query->where('employee_id', $employee->id);
        }

        // Filter by year
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

        // Calculate total approved leave days
        $approvedRequests = (clone $query)->approved()->get();
        $statistics['total_approved_days'] = $approvedRequests->sum('total_days');

        return response()->json([
            'message' => 'Leave request statistics retrieved successfully',
            'data' => $statistics,
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

    /**
     * Notify managers about new leave request
     * Prioritize managers responsible for the leave period
     */
    private function notifyManagers(LeaveRequest $leaveRequest)
    {
        $managers = collect();
        
        // Try to find managers responsible for the leave period dates
        $responsibleManagers = DB::table('manager_duties')
            ->join('roster_days', 'manager_duties.roster_day_id', '=', 'roster_days.id')
            ->join('employees', 'manager_duties.employee_id', '=', 'employees.id')
            ->join('users', 'employees.user_id', '=', 'users.id')
            ->whereBetween('roster_days.work_date', [
                $leaveRequest->start_date,
                $leaveRequest->end_date
            ])
            ->whereIn('users.role', [User::ROLE_MANAGER_TEKNIK, User::ROLE_GENERAL_MANAGER])
            ->where('users.id', '!=', $leaveRequest->employee->user_id)
            ->where('users.is_active', true)
            ->whereNull('manager_duties.deleted_at')
            ->whereNull('employees.deleted_at')
            ->whereNull('users.deleted_at')
            ->select('users.id', 'users.name', 'users.email')
            ->distinct()
            ->get();

        if ($responsibleManagers->isNotEmpty()) {
            // Use specific managers responsible for those dates
            $managers = $responsibleManagers;
        } else {
            // Fallback: notify all active managers
            $managers = User::whereIn('role', [User::ROLE_MANAGER_TEKNIK, User::ROLE_GENERAL_MANAGER])
                ->where('id', '!=', $leaveRequest->employee->user_id)
                ->where('is_active', true)
                ->select('id', 'name', 'email')
                ->get();
        }

        $managers = $managers->unique('id')->values();

        $formattedStartDate = \Carbon\Carbon::parse($leaveRequest->start_date)->format('d M Y');
        $formattedEndDate = \Carbon\Carbon::parse($leaveRequest->end_date)->format('d M Y');
        $isoStartDate = \Carbon\Carbon::parse($leaveRequest->start_date)->format('Y-m-d');
        $isoEndDate = \Carbon\Carbon::parse($leaveRequest->end_date)->format('Y-m-d');

        foreach ($managers as $manager) {
            // Create notification in database
            Notification::create([
                'user_id'  => $manager->id,
                'sender_id' => $leaveRequest->employee->user_id,
                'title'    => 'Permohonan Cuti Baru #'.$leaveRequest->id,
                'message'  => $leaveRequest->employee->user->name . ' mengajukan permohonan ' . 
                             $leaveRequest->request_type_name . ' (' . 
                             $formattedStartDate . ' - ' . 
                             $formattedEndDate . ')',
                'type'     => 'inbox',
                'category' => 'leave_request',
                'data'     => json_encode([
                    'leave_request_id' => $leaveRequest->id,
                    'employee_name' => $leaveRequest->employee->user->name,
                    'request_type' => $leaveRequest->request_type,
                    'start_date' => $isoStartDate,
                    'end_date' => $isoEndDate,
                ]),
            ]);

            // Send email notification
            try {
                Mail::to($manager->email)->send(
                    new LeaveRequestSubmittedMail($leaveRequest, $manager->name)
                );
            } catch (\Exception $e) {
                // Log email error but don't fail the request
                Log::error('Failed to send leave request email to manager: ' . $e->getMessage(), [
                    'manager_email' => $manager->email,
                    'leave_request_id' => $leaveRequest->id,
                ]);
            }
        }
    }
}
