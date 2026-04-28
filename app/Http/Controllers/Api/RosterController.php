<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateRosterPeriodRequest;
use App\Http\Requests\StoreRosterAssignmentsRequest;
use App\Http\Requests\UpdateRosterAssignmentsRequest;
use App\Http\Requests\QuickUpdateAssignmentRequest;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\ManagerDuty;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\Notification;
use App\Models\RosterDay;
use App\Models\RosterPeriod;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRequest;
use App\Models\RosterTask;
use App\Helpers\CacheHelper;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class RosterController extends Controller
{
    /**
     * GET /rosters
     */
    public function index(Request $request)
    {
        $query = RosterPeriod::query()
            ->select(['id', 'month', 'year', 'status', 'spreadsheet_url', 'last_synced_at', 'created_at', 'updated_at'])
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc');
            
        if ($request->has('month')) {
            $query->where('month', $request->month);
        }
        
        if ($request->has('year')) {
            $query->where('year', $request->year);
        }
        
        // Limit results to prevent loading too much data
        $rosters = $query->limit(12)->get();
        
        return response()->json($rosters);
    }

    /**
     * Map shift name (or key) to roster_task.shift_key
     */
    private function mapShiftToTaskShiftKey(?string $shiftName): ?string
    {
        if (empty($shiftName)) {
            return null;
        }

        $shiftNameNormalized = strtolower(trim($shiftName));

        return match ($shiftNameNormalized) {
            '07-13', 'pagi' => '07-13',
            '13-19', 'siang' => '13-19',
            '19-07', 'malam' => '19-07',
            default => null,
        };
    }

    /**
     * Auto-generate roster tasks on publish using manager duties mapping.
     */
    private function generateTasksOnPublish(RosterPeriod $rosterPeriod)
    {
        $rosterDayIds = $rosterPeriod->rosterDays->pluck('id')->toArray();

        $managerDuties = ManagerDuty::with(['employee.user', 'shift', 'rosterDay'])
            ->whereIn('roster_day_id', $rosterDayIds)
            ->get();

        foreach ($managerDuties as $duty) {
            $managerUser = $duty->employee?->user;
            $date = $duty->rosterDay?->work_date?->toDateString();
            $shiftKey = $this->mapShiftToTaskShiftKey($duty->shift?->name ?? null);

            if (!$managerUser || !$date || !$shiftKey) {
                continue;
            }

            $title = "Tugas Manager {$duty->duty_type} - {$shiftKey}";
            $description = "Task otomatis dari publish roster ({$date}) untuk shift {$shiftKey} ({$duty->shift?->name}).";

            $exists = RosterTask::whereDate('date', $date)
                ->where('shift_key', $shiftKey)
                ->where('role', $duty->duty_type)
                ->where('title', $title)
                ->whereJsonContains('assigned_to', $managerUser->id)
                ->first();

            if ($exists) {
                continue;
            }

            RosterTask::create([
                'date' => $date,
                'shift_key' => $shiftKey,
                'role' => $duty->duty_type,
                'assigned_to' => [$managerUser->id],
                'title' => $title,
                'description' => $description,
                'priority' => 'medium',
                'status' => 'pending',
                'created_by' => Auth::id(),
            ]);
        }
    }

    /**
     * POST /rosters
     */
    public function store(CreateRosterPeriodRequest $request)
    {
        DB::beginTransaction();
        try {
            $this->ensureDefaultManagerGroupAssignments();

            // Check if roster period already exists
            $existingPeriod = RosterPeriod::where('month', $request->month)
                ->where('year', $request->year)
                ->first();

            if ($existingPeriod) {
                return response()->json([
                    'message' => 'Roster period already exists for this month and year'
                ], 422);
            }

            // Create roster period
            $rosterPeriod = RosterPeriod::create([
                'month' => $request->month,
                'year' => $request->year,
                'status' => 'draft',
            ]);

            // Auto-generate all days for the month with all employees marked as "Libur"
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $request->month, $request->year);
            
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $date = sprintf('%04d-%02d-%02d', $request->year, $request->month, $day);
                
                // Create roster day (no assignments yet - will be assigned manually)
                RosterDay::create([
                    'roster_period_id' => $rosterPeriod->id,
                    'work_date' => $date,
                ]);
            }

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'create',
                'module' => 'roster',
                'reference_id' => $rosterPeriod->id,
                'description' => 'Created roster template for ' . $request->month . '/' . $request->year,
            ]);

            DB::commit();

            // Clear roster cache
            CacheHelper::clearRosterCache();

            // Load roster days for response (minimal - no assignments yet)
            $rosterPeriod->load([
                'rosterDays' => function ($query) {
                    $query->select(['id', 'roster_period_id', 'work_date'])
                        ->orderBy('work_date', 'asc');
                },
            ]);

            // Build optimized roster_days structure
            $rosterDays = $rosterPeriod->rosterDays->map(function ($day) {
                return [
                    'id' => $day->id,
                    'roster_period_id' => $day->roster_period_id,
                    'work_date' => $day->work_date,
                    'shift_assignments' => [],
                    'manager_duties' => [],
                ];
            });

            // Include all employees (optimized - only essential fields)
            $allEmployees = \App\Models\Employee::with('user:id,name,email,grade')
                ->where('is_active', true)
                ->orderBy('employee_type')
                ->orderBy('group_number')
                ->orderBy('user_id')
                ->select(['id', 'user_id', 'employee_type', 'group_number'])
                ->get();

            // Include all shifts (optimized - only essential fields)
            $allShifts = \App\Models\Shift::orderBy('id')
                ->select(['id', 'name', 'start_time', 'end_time'])
                ->get();

            // Build optimized response
            $optimizedRoster = [
                'id' => $rosterPeriod->id,
                'month' => $rosterPeriod->month,
                'year' => $rosterPeriod->year,
                'status' => $rosterPeriod->status,
                'spreadsheet_url' => $rosterPeriod->spreadsheet_url,
                'last_synced_at' => $rosterPeriod->last_synced_at,
                'created_at' => $rosterPeriod->created_at,
                'updated_at' => $rosterPeriod->updated_at,
                'roster_days' => $rosterDays,
            ];

            return response()->json([
                'message' => 'Roster template created successfully. You can now assign employees to shifts.',
                'data' => [
                    'roster_period' => $optimizedRoster,
                    'all_employees' => $allEmployees,
                    'all_shifts' => $allShifts,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create roster',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /rosters/{id}
     * 
     * Returns roster with all relationships in optimized compact format.
     * Shift assignments contain only IDs - frontend joins with all_employees/all_shifts.
     * This reduces payload size by ~90% (from ~600KB to ~50KB).
     * 
     * Response structure:
     * - roster_period: basic roster info with roster_days
     *   - roster_days[].assignments: [{id, employee_id, shift_id, notes}, ...]
     *   - roster_days[].manager_duties: [{id, employee_id, duty_type, shift_id}, ...]
     * - all_employees: reference data for employee lookup
     * - all_shifts: reference data for shift lookup
     */
    public function show($id)
    {
        // Load roster with minimal relationships for compact response
        $rosterPeriod = RosterPeriod::with([
            'rosterDays' => function ($query) {
                $query->orderBy('work_date', 'asc')
                    ->select(['id', 'roster_period_id', 'work_date']);
            },
        ])->findOrFail($id);

        // Load shift assignments as compact data (only IDs)
        $shiftAssignments = ShiftAssignment::whereIn('roster_day_id', $rosterPeriod->rosterDays->pluck('id'))
            ->select(['id', 'roster_day_id', 'employee_id', 'shift_id', 'notes'])
            ->get()
            ->groupBy('roster_day_id');

        // Load manager duties as compact data (only IDs)
        $managerDuties = ManagerDuty::whereIn('roster_day_id', $rosterPeriod->rosterDays->pluck('id'))
            ->select(['id', 'roster_day_id', 'employee_id', 'duty_type', 'shift_id'])
            ->get()
            ->groupBy('roster_day_id');

        // Build optimized roster_days structure
        $rosterDays = $rosterPeriod->rosterDays->map(function ($day) use ($shiftAssignments, $managerDuties) {
            return [
                'id' => $day->id,
                'roster_period_id' => $day->roster_period_id,
                'work_date' => $day->work_date,
                // Compact assignments: only essential fields
                'shift_assignments' => ($shiftAssignments[$day->id] ?? collect())->map(function ($a) {
                    return [
                        'id' => $a->id,
                        'roster_day_id' => $a->roster_day_id,
                        'employee_id' => $a->employee_id,
                        'shift_id' => $a->shift_id,
                        'notes' => $a->notes,
                    ];
                })->values(),
                // Compact manager duties: only essential fields
                'manager_duties' => ($managerDuties[$day->id] ?? collect())->map(function ($m) {
                    return [
                        'id' => $m->id,
                        'roster_day_id' => $m->roster_day_id,
                        'employee_id' => $m->employee_id,
                        'duty_type' => $m->duty_type,
                        'shift_id' => $m->shift_id,
                    ];
                })->values(),
            ];
        });

        // Include all employees (reference data for frontend lookup)
        $allEmployees = \App\Models\Employee::with('user:id,name,email,grade')
            ->where('is_active', true)
            ->orderBy('employee_type')
            ->orderBy('group_number')
            ->orderBy('user_id')
            ->select(['id', 'user_id', 'employee_type', 'group_number'])
            ->get();

        // Include all shifts (reference data for frontend lookup)
        $allShifts = \App\Models\Shift::orderBy('id')
            ->select(['id', 'name', 'start_time', 'end_time'])
            ->get();

        // Build optimized response
        $optimizedRoster = [
            'id' => $rosterPeriod->id,
            'month' => $rosterPeriod->month,
            'year' => $rosterPeriod->year,
            'status' => $rosterPeriod->status,
            'spreadsheet_url' => $rosterPeriod->spreadsheet_url,
            'last_synced_at' => $rosterPeriod->last_synced_at,
            'created_at' => $rosterPeriod->created_at,
            'updated_at' => $rosterPeriod->updated_at,
            'roster_days' => $rosterDays,
        ];

        return response()->json([
            'roster_period' => $optimizedRoster,
            'all_employees' => $allEmployees,
            'all_shifts' => $allShifts,
        ]);
    }

    /**
     * GET /roster/today
     * Frontend category: roster
     * Response:
     * {
     *   date, shift_periods:[{key,name,start,end}], assignments:[{id,user_id,shift_id,shift_key,employee_name,status,assigned_at}]
     * }
     */
    public function today(Request $request)
    {
        $dateInput = $request->get('date', Carbon::now()->toDateString());

        try {
            $date = Carbon::parse($dateInput)->toDateString();
        } catch (\Exception $e) {
            return response()->json(['message' => 'Invalid date format'], 422);
        }

        $shiftPeriods = [
            ['key' => '07-13', 'name' => 'Shift Pagi', 'start' => '07:00', 'end' => '13:00'],
            ['key' => '13-19', 'name' => 'Shift Siang', 'start' => '13:00', 'end' => '19:00'],
            ['key' => '19-07', 'name' => 'Shift Malam', 'start' => '19:00', 'end' => '07:00'],
        ];

        $shiftMap = [
            '07-13' => $shiftPeriods[0],
            '13-19' => $shiftPeriods[1],
            '19-07' => $shiftPeriods[2],
            'pagi' => $shiftPeriods[0],
            'siang' => $shiftPeriods[1],
            'malam' => $shiftPeriods[2],
        ];

        $shiftKey = $request->get('shift');
        if (!$shiftKey) {
            $now = Carbon::now();
            $hour = (int) $now->format('H');

            if ($hour >= 7 && $hour < 13) {
                $shiftKey = '07-13';
            } elseif ($hour >= 13 && $hour < 19) {
                $shiftKey = '13-19';
            } else {
                $shiftKey = '19-07';
            }
        }

        $shiftKey = str_replace(' ', '', strtolower($shiftKey));

        if (isset($shiftMap[$shiftKey])) {
            $selectedShift = $shiftMap[$shiftKey];
        } else {
            return response()->json(['message' => 'Invalid shift key'], 422);
        }

        // Ensure selectedShift has canonical key
        $selectedShift['key'] = $selectedShift['key'];

        $rosterDay = RosterDay::whereDate('work_date', $date)
            ->whereHas('rosterPeriod', function ($q) {
                $q->where('status', 'published');
            })
            ->with(['shiftAssignments.employee.user', 'shiftAssignments.shift'])
            ->first();

        $assignments = [];
        $taskCounter = 0;

        if ($rosterDay) {
            foreach ($rosterDay->shiftAssignments as $assignment) {
                $assignmentShiftKey = $assignment->shift ? strtolower($assignment->shift->name) : null;
                $assignmentShiftKey = $assignmentShiftKey === 'pagi' ? '07-13' : ($assignmentShiftKey === 'siang' ? '13-19' : ($assignmentShiftKey === 'malam' ? '19-07' : null));

                if ($assignmentShiftKey !== $selectedShift['key']) {
                    continue;
                }

                $employee = $assignment->employee;

                $tasks = [];
                $taskCounter++;
                $tasks[] = [
                    'task_id' => $assignment->id * 100 + 1,
                    'title' => "Shift task for {$selectedShift['name']}",
                    'description' => $assignment->notes ? $assignment->notes : "Tugas standar {$selectedShift['name']}",
                    'status' => 'pending',
                ];

                $assignments[] = [
                    'shift_assignment_id' => (int) $assignment->id,
                    'user_id' => $employee ? (int) $employee->user_id : null,
                    'user_name' => $employee && $employee->user ? $employee->user->name : 'Unassigned',
                    'role' => $employee ? ucfirst($employee->employee_type) : 'Unknown',
                    'shift_key' => $selectedShift['key'],
                    'tasks' => $tasks,
                ];
            }
        }

        if (empty($assignments)) {
            // fallback empty assignment row to keep frontend stable
            $assignments = [];
        }

        foreach ($assignments as &$assignment) {
            // any existing tasks can be extended or mutated if required
        }

        $totalTasks = array_reduce($assignments, function ($carry, $assignment) {
            return $carry + count($assignment['tasks']);
        }, 0);

        $summary = [
            'total_assignments' => count($assignments),
            'total_tasks' => $totalTasks,
            'high_priority' => 0,
        ];

        return response()->json([
            'date' => $date,
            'shift_key' => $selectedShift['key'],
            'shift_start' => $selectedShift['start'],
            'shift_end' => $selectedShift['end'],
            'shift_name' => $selectedShift['name'],
            'shift_periods' => $shiftPeriods,
            'assignments' => $assignments,
            'summary' => $summary,
        ]);
    }

    /**
     * GET /roster/tasks
     * Optional: Get tasks grouped by assignment for selected date+shift
     */
    public function tasks(Request $request)
    {
        $date = $request->get('date', Carbon::now()->toDateString());
        $shift = $request->get('shift', '13-19');

        $todayData = $this->today($request);

        // convert response content from JSON string to array
        $payload = json_decode($todayData->getContent(), true);

        $filtered = array_filter($payload['assignments'] ?? [], function ($assignment) use ($shift) {
            return ($assignment['shift_key'] ?? '') === (strtolower($shift) === 'pagi' ? '07-13' : (strtolower($shift) === 'siang' ? '13-19' : (strtolower($shift) === 'malam' ? '19-07' : $shift)));
        });

        return response()->json(array_values($filtered));
    }

    /**
     * POST /rosters/{id}/publish
     * Query params:
     * - skip_validation=1 : Skip completeness validation (force publish)
     */
    public function publish(Request $request, $id)
    {
        $rosterPeriod = RosterPeriod::findOrFail($id);

        if ($rosterPeriod->isPublished()) {
            return response()->json([
                'message' => 'Roster is already published'
            ], 400);
        }

        $skipValidation = $request->query('skip_validation', false) || $request->input('skip_validation', false);
        
        $validation = null;
        
        // Only validate if not skipping
        if (!$skipValidation) {
            $validation = $this->validateRosterCompleteness($rosterPeriod);
            
            if (!$validation['is_valid']) {
                return response()->json([
                    'message' => 'Roster validation failed. Cannot publish incomplete roster. Use skip_validation=1 to force publish.',
                    'validation' => $validation,
                ], 422);
            }
        }

        $rosterPeriod->status = 'published';
        $rosterPeriod->save();

        $this->generateTasksOnPublish($rosterPeriod);

        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => 'publish',
            'module' => 'roster',
            'reference_id' => $rosterPeriod->id,
            'description' => 'Published roster for ' . $rosterPeriod->month . '/' . $rosterPeriod->year . ($skipValidation ? ' (forced)' : ''),
        ]);

        return response()->json([
            'message' => 'Roster published successfully' . ($skipValidation ? ' (validation skipped)' : ''),
            'data' => $rosterPeriod,
            'validation' => $validation,
        ]);
    }

    /**
     * POST /rosters/{id}/unpublish
     * Unpublish a roster (change status back to draft)
     */
    public function unpublish($id)
    {
        $rosterPeriod = RosterPeriod::findOrFail($id);

        if ($rosterPeriod->status === 'draft') {
            return response()->json([
                'message' => 'Roster is already in draft status'
            ], 400);
        }

        $rosterPeriod->status = 'draft';
        $rosterPeriod->save();

        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => 'unpublish',
            'module' => 'roster',
            'reference_id' => $rosterPeriod->id,
            'description' => 'Unpublished roster for ' . $rosterPeriod->month . '/' . $rosterPeriod->year,
        ]);

        return response()->json([
            'message' => 'Roster unpublished successfully',
            'data' => $rosterPeriod,
        ]);
    }

    /**
     * GET /rosters/{id}/validate
     * Preview validation before publish
     */
    public function validateBeforePublish($id)
    {
        $rosterPeriod = RosterPeriod::findOrFail($id);
        
        $validation = $this->validateRosterCompleteness($rosterPeriod);
        
        return response()->json([
            'message' => $validation['is_valid'] 
                ? 'Roster is ready to publish' 
                : 'Roster has validation errors',
            'validation' => $validation,
        ]);
    }

    /**
     * GET /rosters/{roster_id}/days/{day_id}
     * Get detailed roster day information
     */
    public function showDay($rosterId, $dayId)
    {
        $rosterDay = RosterDay::where('roster_period_id', $rosterId)
            ->where('id', $dayId)
            ->with([
                'shiftAssignments.employee.user',
                'shiftAssignments.shift',
                'managerDuties.employee.user',
                'managerDuties.shift',
            ])
            ->firstOrFail();

        return response()->json($rosterDay);
    }

    /**
     * POST /rosters/{roster_id}/days/{day_id}/assignments
     * Assign employees to shifts and managers for a specific day
     */
    public function storeAssignments(StoreRosterAssignmentsRequest $request, $rosterId, $dayId)
    {
        DB::beginTransaction();
        try {
            // Verify roster period exists
            $rosterPeriod = RosterPeriod::findOrFail($rosterId);

            // Verify roster day belongs to this period
            $rosterDay = RosterDay::where('roster_period_id', $rosterId)
                ->where('id', $dayId)
                ->firstOrFail();

            // Check if roster is already published
            if ($rosterPeriod->isPublished()) {
                return response()->json([
                    'message' => 'Cannot modify published roster'
                ], 400);
            }

            $assignmentCount = 0;
            $skippedCount = 0;
            $skippedDetails = [];

            // Process shift assignments
            if ($request->has('shift_assignments')) {
                foreach ($request->shift_assignments as $assignment) {
                    // Get employee with user relationship to check role
                    $employee = Employee::with('user')->findOrFail($assignment['employee_id']);
                    
                    // Notes is the primary identifier
                    $notes = $assignment['notes'];
                    
                    // Auto-resolve shift_id from notes if not provided
                    $shiftId = $assignment['shift_id'] ?? ShiftAssignment::resolveShiftIdFromNotes($notes);
                    
                    // Check if assignment already exists (by employee and notes on this day)
                    $existing = ShiftAssignment::where('roster_day_id', $dayId)
                        ->where('employee_id', $assignment['employee_id'])
                        ->where('notes', $notes)
                        ->first();

                    if (!$existing) {
                        ShiftAssignment::create([
                            'roster_day_id' => $dayId,
                            'employee_id' => $assignment['employee_id'],
                            'shift_id' => $shiftId,
                            'notes' => $notes,
                        ]);
                        $assignmentCount++;
                        
                        // Auto-assign manager duty if employee is Manager Teknik
                        if (in_array($employee->user->role, ['Manager Teknik', 'General Manager'])) {
                            $existingDuty = ManagerDuty::where('roster_day_id', $dayId)
                                ->where('employee_id', $assignment['employee_id'])
                                ->where('duty_type', $employee->user->role)
                                ->first();
                                
                            if (!$existingDuty && $shiftId) {
                                ManagerDuty::create([
                                    'roster_day_id' => $dayId,
                                    'employee_id' => $assignment['employee_id'],
                                    'duty_type' => $employee->user->role,
                                    'shift_id' => $shiftId,
                                ]);
                            }
                        }
                    } else {
                        $skippedCount++;
                        $skippedDetails[] = [
                            'type' => 'shift_assignment',
                            'employee' => $employee->user->name ?? 'Employee #' . $assignment['employee_id'],
                            'shift' => $notes,
                            'reason' => 'Already assigned to this shift'
                        ];
                    }
                }
            }

            // Process manager duties (manual override - optional)
            if ($request->has('manager_duties')) {
                foreach ($request->manager_duties as $duty) {
                    // Auto-resolve shift_id from notes if provided
                    $shiftId = $duty['shift_id'] ?? ($duty['notes'] ? ShiftAssignment::resolveShiftIdFromNotes($duty['notes']) : null);
                    
                    // Check if manager duty already exists
                    $existingQuery = ManagerDuty::where('roster_day_id', $dayId)
                        ->where('employee_id', $duty['employee_id'])
                        ->where('duty_type', $duty['duty_type']);
                    
                    if ($shiftId) {
                        $existingQuery->where('shift_id', $shiftId);
                    }
                    
                    $existing = $existingQuery->first();

                    if (!$existing) {
                        ManagerDuty::create([
                            'roster_day_id' => $dayId,
                            'employee_id' => $duty['employee_id'],
                            'duty_type' => $duty['duty_type'],
                            'shift_id' => $duty['shift_id'],
                        ]);
                        $assignmentCount++;
                    } else {
                        $skippedCount++;
                        $employee = Employee::with('user')->find($duty['employee_id']);
                        $skippedDetails[] = [
                            'type' => 'manager_duty',
                            'employee' => $employee->user->name ?? 'Employee #' . $duty['employee_id'],
                            'duty_type' => $duty['duty_type'],
                            'reason' => 'Already assigned as ' . $duty['duty_type'] . ' for this shift'
                        ];
                    }
                }
            }

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'create',
                'module' => 'roster',
                'reference_id' => $dayId,
                'description' => 'Added ' . $assignmentCount . ' assignments to roster day ' . $rosterDay->work_date,
            ]);

            DB::commit();

            // Reload data with relationships
            $rosterDay->load([
                'shiftAssignments.employee.user',
                'shiftAssignments.shift',
                'managerDuties.employee.user',
                'managerDuties.shift',
            ]);

            // Get validation summary
            $validation = $this->getValidationSummary($rosterDay);

            // Build response message
            $message = '';
            if ($assignmentCount > 0 && $skippedCount > 0) {
                $message = $assignmentCount . ' assignment(s) added successfully, ' . $skippedCount . ' duplicate(s) skipped';
            } elseif ($assignmentCount > 0) {
                $message = $assignmentCount . ' assignment(s) added successfully';
            } elseif ($skippedCount > 0) {
                $message = 'No new assignments added. All ' . $skippedCount . ' assignment(s) already exist';
            } else {
                $message = 'No assignments to process';
            }

            $response = [
                'message' => $message,
                'data' => $rosterDay,
                'validation' => $validation,
                'summary' => [
                    'added' => $assignmentCount,
                    'skipped' => $skippedCount,
                ]
            ];

            if ($skippedCount > 0) {
                $response['skipped_details'] = $skippedDetails;
            }

            return response()->json($response, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to add assignments',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /rosters/{roster_id}/days/{day_id}/assignments
     * Update all assignments for a day (replaces existing)
     */
    public function updateAssignments(UpdateRosterAssignmentsRequest $request, $rosterId, $dayId)
    {
        DB::beginTransaction();
        try {
            // Verify roster period exists
            $rosterPeriod = RosterPeriod::findOrFail($rosterId);

            // Verify roster day belongs to this period
            $rosterDay = RosterDay::where('roster_period_id', $rosterId)
                ->where('id', $dayId)
                ->firstOrFail();

            // Check if roster is already published
            if ($rosterPeriod->isPublished()) {
                return response()->json([
                    'message' => 'Cannot modify published roster'
                ], 400);
            }

            // Delete existing assignments
            ShiftAssignment::where('roster_day_id', $dayId)->delete();
            ManagerDuty::where('roster_day_id', $dayId)->delete();

            $assignmentCount = 0;

            // Create new shift assignments
            if ($request->has('shift_assignments')) {
                foreach ($request->shift_assignments as $assignment) {
                    // Notes is the primary identifier
                    $notes = $assignment['notes'];
                    
                    // Auto-resolve shift_id from notes if not provided
                    $shiftId = $assignment['shift_id'] ?? ShiftAssignment::resolveShiftIdFromNotes($notes);
                    
                    ShiftAssignment::create([
                        'roster_day_id' => $dayId,
                        'employee_id' => $assignment['employee_id'],
                        'shift_id' => $shiftId,
                        'notes' => $notes,
                    ]);
                    $assignmentCount++;
                }
            }

            // Create new manager duties
            if ($request->has('manager_duties')) {
                foreach ($request->manager_duties as $duty) {
                    // Auto-resolve shift_id from notes if provided
                    $shiftId = $duty['shift_id'] ?? ($duty['notes'] ? ShiftAssignment::resolveShiftIdFromNotes($duty['notes']) : null);
                    
                    ManagerDuty::create([
                        'roster_day_id' => $dayId,
                        'employee_id' => $duty['employee_id'],
                        'duty_type' => $duty['duty_type'],
                        'shift_id' => $shiftId,
                    ]);
                    $assignmentCount++;
                }
            }

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'update',
                'module' => 'roster',
                'reference_id' => $dayId,
                'description' => 'Updated assignments for roster day ' . $rosterDay->work_date,
            ]);

            DB::commit();

            // Reload data with relationships
            $rosterDay->load([
                'shiftAssignments.employee.user',
                'shiftAssignments.shift',
                'managerDuties.employee.user',
                'managerDuties.shift',
            ]);

            // Get validation summary
            $validation = $this->getValidationSummary($rosterDay);

            return response()->json([
                'message' => 'Assignments updated successfully',
                'data' => $rosterDay,
                'validation' => $validation,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update assignments',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /rosters/{roster_id}/days/{day_id}/assignments/{assignment_id}
     * Delete a specific shift assignment
     */
    public function deleteAssignment($rosterId, $dayId, $assignmentId)
    {
        DB::beginTransaction();
        try {
            // Verify roster period exists
            $rosterPeriod = RosterPeriod::findOrFail($rosterId);

            // Check if roster is already published
            if ($rosterPeriod->isPublished()) {
                return response()->json([
                    'message' => 'Cannot modify published roster'
                ], 400);
            }

            // Try to find shift assignment first
            $assignment = ShiftAssignment::where('roster_day_id', $dayId)
                ->where('id', $assignmentId)
                ->first();

            if ($assignment) {
                $assignment->delete();
                $type = 'shift assignment';
            } else {
                // Try to find manager duty
                $duty = ManagerDuty::where('roster_day_id', $dayId)
                    ->where('id', $assignmentId)
                    ->firstOrFail();
                $duty->delete();
                $type = 'manager duty';
            }

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'delete',
                'module' => 'roster',
                'reference_id' => $dayId,
                'description' => 'Deleted ' . $type . ' from roster day',
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Assignment deleted successfully',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete assignment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get validation summary for a roster day
     */
    private function getValidationSummary($rosterDay)
    {
        $shiftAssignments = $rosterDay->shiftAssignments;
        
        // Group by shift
        $byShift = $shiftAssignments->groupBy('shift_id');
        
        $summary = [];
        
        // Get all shifts
        $shifts = Shift::all();
        
        foreach ($shifts as $shift) {
            $assignments = $byShift->get($shift->id, collect());
            
            $cnsCount = $assignments->filter(function($a) {
                return $a->employee && strtolower($a->employee->employee_type) === 'cns';
            })->count();
            
            $supportCount = $assignments->filter(function($a) {
                return $a->employee && strtolower($a->employee->employee_type) === 'support';
            })->count();
            
            $isValid = $cnsCount >= 4 && $supportCount >= 2;
            
            $message = '';
            if (!$isValid) {
                $message = "Need at least 4 CNS and 2 Support. ";
                $message .= "Current: {$cnsCount} CNS, {$supportCount} Support.";
            } else {
                $message = "Valid: {$cnsCount} CNS, {$supportCount} Support.";
            }
            
            $summary['shift_' . $shift->id] = [
                'shift_name' => $shift->name,
                'cns_count' => $cnsCount,
                'support_count' => $supportCount,
                'total_count' => $assignments->count(),
                'valid' => $isValid,
                'message' => $message,
            ];
        }
        
        return $summary;
    }

    /**
     * Validate shift assignments (legacy method - kept for compatibility)
     */
    private function validateShiftAssignments($shiftName, $employeeIds)
    {
        $employees = Employee::whereIn('id', $employeeIds)->get();

        $cnsCount = $employees->filter(fn($e) => strtolower($e->employee_type) === 'cns')->count();
        $supportCount = $employees->filter(fn($e) => strtolower($e->employee_type) === 'support')->count();

        if ($cnsCount < 4) {
            throw new \Exception("Shift {$shiftName} must have at least 4 CNS employees");
        }

        if ($supportCount < 2) {
            throw new \Exception("Shift {$shiftName} must have at least 2 SUPPORT employees");
        }
    }

    /**
     * Validate roster completeness before publish
     * Ensures:
     * - All days have assignments
     * - Each day has all 3 shifts filled
     * - Each shift has minimum 4 CNS + 2 Support + 1 Manager Teknik
     */
    private function validateRosterCompleteness($rosterPeriod)
    {
        $validation = [
            'is_valid' => true,
            'total_days' => 0,
            'valid_days' => 0,
            'invalid_days' => [],
            'errors' => [],
        ];

        // Manager Teknik is now part of shift_assignments, not separate table
        $rosterDays = $rosterPeriod->rosterDays()->with([
            'shiftAssignments.employee',
        ])->get();

        $validation['total_days'] = $rosterDays->count();

        // Get only the 3 main operational shifts (Pagi, Siang, Malam)
        // Filter by shift IDs 1, 2, 3 or by name pattern
        $operationalShifts = Shift::whereIn('id', [1, 2, 3])
            ->orWhere(function($q) {
                $q->where('name', 'like', '%pagi%')
                  ->orWhere('name', 'like', '%siang%')
                  ->orWhere('name', 'like', '%malam%')
                  ->orWhere('name', 'like', '%morning%')
                  ->orWhere('name', 'like', '%afternoon%')
                  ->orWhere('name', 'like', '%night%');
            })
            ->take(3)
            ->get();
            
        if ($operationalShifts->count() < 3) {
            $validation['is_valid'] = false;
            $validation['errors'][] = 'System must have at least 3 operational shifts configured (Pagi, Siang, Malam)';
            return $validation;
        }
        
        $allShifts = $operationalShifts;

        foreach ($rosterDays as $day) {
            $dayValidation = [
                'date' => $day->work_date,
                'is_valid' => true,
                'errors' => [],
                'shifts' => [],
                'manager_count' => 0,
            ];

            // Check each shift - Manager Teknik is now part of shift_assignments
            $shiftAssignments = $day->shiftAssignments->groupBy('shift_id');

            foreach ($allShifts as $shift) {
                $assignments = $shiftAssignments->get($shift->id, collect());
                
                $cnsCount = $assignments->filter(function($a) {
                    return $a->employee && strtolower($a->employee->employee_type) === 'cns';
                })->count();
                
                $supportCount = $assignments->filter(function($a) {
                    return $a->employee && strtolower($a->employee->employee_type) === 'support';
                })->count();

                // Manager Teknik is now checked from shift_assignments based on employee_type
                $managerCount = $assignments->filter(function($a) {
                    return $a->employee && strtolower($a->employee->employee_type) === 'manager teknik';
                })->count();
                
                $hasManager = $managerCount >= 1;
                $shiftValid = $cnsCount >= 4 && $supportCount >= 2 && $hasManager;

                $shiftInfo = [
                    'shift_name' => $shift->name,
                    'cns_count' => $cnsCount,
                    'support_count' => $supportCount,
                    'total_count' => $assignments->count(),
                    'has_manager' => $hasManager,
                    'manager_count' => $managerCount,
                    'is_valid' => $shiftValid,
                ];

                // Update day's total manager count
                $dayValidation['manager_count'] += $managerCount;

                if (!$shiftValid) {
                    $dayValidation['is_valid'] = false;
                    $message = "Shift {$shift->name}: ";
                    
                    if ($cnsCount < 4) {
                        $message .= "Need 4 CNS (current: {$cnsCount}). ";
                    }
                    if ($supportCount < 2) {
                        $message .= "Need 2 Support (current: {$supportCount}). ";
                    }
                    if (!$hasManager) {
                        $message .= "Missing Manager Teknik for this shift.";
                    }
                    
                    $dayValidation['errors'][] = $message;
                }

                $dayValidation['shifts'][] = $shiftInfo;
            }

            // If day is invalid, add to invalid_days
            if (!$dayValidation['is_valid']) {
                $validation['is_valid'] = false;
                $validation['invalid_days'][] = $dayValidation;
            } else {
                $validation['valid_days']++;
            }
        }

        // Summary errors
        if (!$validation['is_valid']) {
            $invalidCount = count($validation['invalid_days']);
            $validation['errors'][] = "{$invalidCount} day(s) failed validation";
        }

        return $validation;
    }

    /**
     * PUT /rosters/{id}
     * Update roster period (month/year) - only for draft rosters
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'month' => 'sometimes|integer|min:1|max:12',
            'year' => 'sometimes|integer|min:2020|max:2100',
        ]);

        $rosterPeriod = RosterPeriod::findOrFail($id);

        if ($rosterPeriod->isPublished()) {
            return response()->json([
                'message' => 'Cannot modify published roster'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $newMonth = $request->input('month', $rosterPeriod->month);
            $newYear = $request->input('year', $rosterPeriod->year);

            // Check if new month/year combination already exists (excluding current)
            if ($newMonth !== $rosterPeriod->month || $newYear !== $rosterPeriod->year) {
                $existing = RosterPeriod::where('month', $newMonth)
                    ->where('year', $newYear)
                    ->where('id', '!=', $id)
                    ->first();

                if ($existing) {
                    return response()->json([
                        'message' => 'Roster period already exists for this month and year'
                    ], 422);
                }

                // Update roster period
                $rosterPeriod->month = $newMonth;
                $rosterPeriod->year = $newYear;
                $rosterPeriod->save();

                // Update roster days dates
                $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $newMonth, $newYear);
                $existingDays = $rosterPeriod->rosterDays()->orderBy('work_date')->get();
                
                // Delete extra days if new month has fewer days
                if ($existingDays->count() > $daysInMonth) {
                    $rosterPeriod->rosterDays()
                        ->orderBy('work_date', 'desc')
                        ->take($existingDays->count() - $daysInMonth)
                        ->delete();
                    $existingDays = $rosterPeriod->rosterDays()->orderBy('work_date')->get();
                }
                
                // Update existing days
                foreach ($existingDays as $index => $day) {
                    $dayNum = $index + 1;
                    $newDate = sprintf('%04d-%02d-%02d', $newYear, $newMonth, $dayNum);
                    $day->work_date = $newDate;
                    $day->save();
                }
                
                // Add new days if new month has more days
                for ($day = $existingDays->count() + 1; $day <= $daysInMonth; $day++) {
                    $date = sprintf('%04d-%02d-%02d', $newYear, $newMonth, $day);
                    RosterDay::create([
                        'roster_period_id' => $rosterPeriod->id,
                        'work_date' => $date,
                    ]);
                }
            }

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'update',
                'module' => 'roster',
                'reference_id' => $rosterPeriod->id,
                'description' => 'Updated roster period to ' . $newMonth . '/' . $newYear,
            ]);

            DB::commit();

            CacheHelper::clearRosterCache();

            return response()->json([
                'message' => 'Roster updated successfully',
                'data' => $rosterPeriod->fresh()->load('rosterDays'),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update roster',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /rosters/{id}
     * Delete roster period and all related data
     */
    public function destroy($id)
    {
        $rosterPeriod = RosterPeriod::findOrFail($id);

        if ($rosterPeriod->isPublished()) {
            return response()->json([
                'message' => 'Cannot delete published roster. Please unpublish first or contact administrator.'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $monthYear = $rosterPeriod->month . '/' . $rosterPeriod->year;

            $rosterDayIds = RosterDay::where('roster_period_id', $id)->pluck('id');

            // Identify swap requests tied to this roster period through roster day references.
            $shiftRequestIds = ShiftRequest::withTrashed()
                ->where(function ($query) use ($rosterDayIds) {
                    $query->whereIn('from_roster_day_id', $rosterDayIds)
                        ->orWhereIn('to_roster_day_id', $rosterDayIds);
                })
                ->pluck('id')
                ->unique();

            // Identify leave requests that have approval dates on this roster period.
            $leaveRequestIds = LeaveRequestApproval::whereIn('roster_day_id', $rosterDayIds)
                ->pluck('leave_request_id')
                ->unique();

            if ($shiftRequestIds->isNotEmpty()) {
                Notification::where('category', 'shift_request')
                    ->whereIn('reference_id', $shiftRequestIds)
                    ->delete();

                ShiftRequest::withTrashed()
                    ->whereIn('id', $shiftRequestIds)
                    ->forceDelete();
            }

            if ($leaveRequestIds->isNotEmpty()) {
                Notification::where('category', 'leave_request')
                    ->whereIn('reference_id', $leaveRequestIds)
                    ->delete();

                LeaveRequest::withTrashed()
                    ->whereIn('id', $leaveRequestIds)
                    ->forceDelete();
            }

            // Delete all related data (cascading should handle this, but let's be explicit)
            // Delete shift assignments for all roster days
            ShiftAssignment::whereIn('roster_day_id', function ($query) use ($id) {
                $query->select('id')->from('roster_days')->where('roster_period_id', $id);
            })->delete();

            // Delete manager duties for all roster days
            ManagerDuty::whereIn('roster_day_id', function ($query) use ($id) {
                $query->select('id')->from('roster_days')->where('roster_period_id', $id);
            })->delete();

            // Delete all roster days
            RosterDay::where('roster_period_id', $id)->delete();

            // Delete roster period
            $rosterPeriod->delete();

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'delete',
                'module' => 'roster',
                'reference_id' => $id,
                'description' => 'Deleted roster for ' . $monthYear,
            ]);

            DB::commit();

            CacheHelper::clearRosterCache();

            return response()->json([
                'message' => 'Roster deleted successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete roster',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /rosters/{roster_id}/assignments/quick-update
     * Quick update assignment for one or multiple days
     * Simplified endpoint with cleaner JSON structure
     */
    public function quickUpdateAssignment(QuickUpdateAssignmentRequest $request, $rosterId)
    {
        DB::beginTransaction();
        try {
            // Verify roster period exists
            $rosterPeriod = RosterPeriod::findOrFail($rosterId);

            // Check if roster is already published
            if ($rosterPeriod->isPublished()) {
                return response()->json([
                    'message' => 'Cannot modify published roster'
                ], 400);
            }

            // Notes is the primary identifier
            $notes = $request->notes;
            
            // Auto-resolve shift_id from notes if not provided
            $shiftId = $request->shift_id ?? ShiftAssignment::resolveShiftIdFromNotes($notes);

            $updatedDays = [];

            // Process each date
            foreach ($request->work_dates as $workDate) {
                // Find or create roster day
                $rosterDay = RosterDay::where('roster_period_id', $rosterId)
                    ->where('work_date', $workDate)
                    ->first();

                if (!$rosterDay) {
                    // Create roster day if doesn't exist
                    $rosterDay = RosterDay::create([
                        'roster_period_id' => $rosterId,
                        'work_date' => $workDate,
                    ]);
                }

                // Delete existing assignment for this employee on this day
                ShiftAssignment::where('roster_day_id', $rosterDay->id)
                    ->where('employee_id', $request->employee_id)
                    ->delete();

                // Create new assignment
                $assignment = ShiftAssignment::create([
                    'roster_day_id' => $rosterDay->id,
                    'employee_id' => $request->employee_id,
                    'shift_id' => $shiftId,
                    'notes' => $notes,
                ]);

                // Return compact assignment (frontend will hydrate with cached employee/shift data)
                $updatedDays[] = [
                    'date' => $workDate,
                    'roster_day_id' => $rosterDay->id,
                    'assignment' => [
                        'id' => $assignment->id,
                        'roster_day_id' => $assignment->roster_day_id,
                        'employee_id' => $assignment->employee_id,
                        'shift_id' => $assignment->shift_id,
                        'notes' => $assignment->notes,
                    ],
                ];
            }

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'update',
                'module' => 'roster',
                'reference_id' => $rosterId,
                'description' => 'Quick updated assignment for employee ' . $request->employee_id . ' on ' . count($request->work_dates) . ' day(s)',
            ]);

            DB::commit();

            CacheHelper::clearRosterCache();

            return response()->json([
                'message' => 'Assignment updated successfully',
                'data' => [
                    'roster_id' => $rosterId,
                    'employee_id' => $request->employee_id,
                    'notes' => $notes,
                    'shift_id' => $shiftId,
                    'dates_updated' => count($request->work_dates),
                    'updated_days' => $updatedDays,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update assignment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Batch update assignments for multiple employees and dates
     * POST /api/rosters/:roster_id/assignments/batch-update
     * 
     * @param Request $request
     * @param int $rosterId
     * @return JsonResponse
     */
    public function batchUpdateAssignments(Request $request, $rosterId)
    {
        // Validate request
        $validated = $request->validate([
            'assignments' => 'required|array',
            'assignments.*.employee_id' => 'required|integer|exists:employees,id',
            'assignments.*.work_dates' => 'required|array',
            'assignments.*.work_dates.*' => 'required|date_format:Y-m-d',
            'assignments.*.notes' => 'required|string|max:50', // Primary identifier
            'assignments.*.shift_id' => 'nullable|integer|exists:shifts,id', // Optional
        ]);

        DB::beginTransaction();
        try {
            // Verify roster period exists
            $rosterPeriod = RosterPeriod::findOrFail($rosterId);

            // Check if roster is already published
            if ($rosterPeriod->isPublished()) {
                return response()->json([
                    'message' => 'Cannot modify published roster'
                ], 400);
            }

            $allUpdatedDays = [];
            $totalUpdates = 0;

            // Process each assignment batch
            foreach ($validated['assignments'] as $assignmentData) {
                // Notes is the primary identifier
                $notes = $assignmentData['notes'];
                
                // Auto-resolve shift_id from notes if not provided
                $shiftId = $assignmentData['shift_id'] ?? ShiftAssignment::resolveShiftIdFromNotes($notes);

                // Process each date for this employee
                foreach ($assignmentData['work_dates'] as $workDate) {
                    // Find or create roster day
                    $rosterDay = RosterDay::where('roster_period_id', $rosterId)
                        ->where('work_date', $workDate)
                        ->first();

                    if (!$rosterDay) {
                        // Create roster day if doesn't exist
                        $rosterDay = RosterDay::create([
                            'roster_period_id' => $rosterId,
                            'work_date' => $workDate,
                        ]);
                    }

                    // Delete existing assignment for this employee on this day
                    ShiftAssignment::where('roster_day_id', $rosterDay->id)
                        ->where('employee_id', $assignmentData['employee_id'])
                        ->delete();

                    // Create new assignment
                    $assignment = ShiftAssignment::create([
                        'roster_day_id' => $rosterDay->id,
                        'employee_id' => $assignmentData['employee_id'],
                        'shift_id' => $shiftId,
                        'notes' => $notes,
                    ]);

                    // Return compact assignment (frontend will hydrate with cached employee/shift data)
                    $allUpdatedDays[] = [
                        'employee_id' => $assignmentData['employee_id'],
                        'date' => $workDate,
                        'roster_day_id' => $rosterDay->id,
                        'assignment' => [
                            'id' => $assignment->id,
                            'roster_day_id' => $assignment->roster_day_id,
                            'employee_id' => $assignment->employee_id,
                            'shift_id' => $assignment->shift_id,
                            'notes' => $assignment->notes,
                        ],
                    ];

                    $totalUpdates++;
                }
            }

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'update',
                'module' => 'roster',
                'reference_id' => $rosterId,
                'description' => 'Batch updated ' . $totalUpdates . ' assignment(s) for ' . count($validated['assignments']) . ' employee(s)',
            ]);

            DB::commit();

            CacheHelper::clearRosterCache();

            return response()->json([
                'message' => 'Assignments updated successfully',
                'data' => [
                    'roster_id' => $rosterId,
                    'total_assignments' => count($validated['assignments']),
                    'total_updates' => $totalUpdates,
                    'updated_days' => $allUpdatedDays,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to batch update assignments',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
    * GET /api/roster/auto-assignment?date=YYYY-MM-DD&shift=07-13|13-19|19-07
    * Return array of user objects {id, name, role} that are on duty for selected date+shift.
     */
    public function autoAssignment(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'shift' => 'required|string',
        ]);

        $date = $request->date;
        $shiftKey = $request->shift;

        // Map shift key to shift name
        $shiftNameMap = [
            '07-13' => 'pagi',
            '13-19' => 'siang',
            '19-07' => 'malam',
        ];

        $shiftName = $shiftNameMap[$shiftKey] ?? null;
        if (!$shiftName) {
            return response()->json(['message' => 'Invalid shift key'], 400);
        }

        // Find shift by name
        $shift = Shift::where('name', $shiftName)->first();
        if (!$shift) {
            return response()->json(['message' => 'Shift not found'], 404);
        }

        // Find roster day for the date
        $rosterDay = RosterDay::whereDate('work_date', $date)->first();
        if (!$rosterDay) {
            // No roster day means there is no duty assignment yet.
            return response()->json(['data' => []]);
        }

        // Get employee IDs that are assigned for this shift on this day.
        $assignedEmployeeIds = ShiftAssignment::where('roster_day_id', $rosterDay->id)
            ->where('shift_id', $shift->id)
            ->pluck('employee_id')
            ->toArray();

        if (empty($assignedEmployeeIds)) {
            return response()->json(['data' => []]);
        }

        $onDutyEmployees = Employee::with('user:id,name,role')
            ->where('is_active', true)
            ->whereIn('id', $assignedEmployeeIds)
            ->get()
            ->map(function ($employee) {
                return [
                    'id' => $employee->user->id,
                    'name' => $employee->user->name,
                    'role' => $employee->user->role,
                ];
            })
            ->values();

        return response()->json(['data' => $onDutyEmployees]);
    }

    /**
     * POST /rosters/{id}/groups/assign
     * Assign CNS/Support employee into a group formation number
     */
    public function assignEmployeeToGroup(Request $request, $rosterId)
    {
        $validated = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'group_number' => 'required|integer|min:1|max:5',
            'employee_type' => 'required|string|in:CNS,Support,Manager Teknik',
        ]);

        DB::beginTransaction();
        try {
            $rosterPeriod = RosterPeriod::findOrFail($rosterId);

            if ($rosterPeriod->isPublished()) {
                return response()->json([
                    'message' => 'Cannot modify published roster'
                ], 400);
            }

            $employee = Employee::with('user:id,name,email,grade')
                ->findOrFail($validated['employee_id']);

            if ($employee->employee_type !== $validated['employee_type']) {
                return response()->json([
                    'message' => 'Employee type mismatch. Expected ' . $validated['employee_type'] . ', got ' . $employee->employee_type,
                ], 422);
            }

            if (!in_array($employee->employee_type, ['CNS', 'Support', 'Manager Teknik'])) {
                return response()->json([
                    'message' => 'Only CNS, Support, or Manager Teknik employees can be managed in group formation',
                ], 422);
            }

            $oldGroup = $employee->group_number;
            $swappedManager = null;

            if ($employee->employee_type === 'Manager Teknik') {
                $grade = (int) ($employee->user->grade ?? 0);
                if (!in_array($grade, [13, 14, 15], true)) {
                    return response()->json([
                        'message' => 'Only Manager Teknik grade 13-15 can be assigned to manager groups',
                    ], 422);
                }

                $targetGroupNumber = (int) $validated['group_number'];
                $occupiedByOtherManager = Employee::query()
                    ->where('employee_type', 'Manager Teknik')
                    ->where('group_number', $targetGroupNumber)
                    ->where('id', '!=', $employee->id)
                    ->with('user:id,name,email,grade')
                    ->lockForUpdate()
                    ->first();

                if ($occupiedByOtherManager) {
                    if ((int) ($oldGroup ?? 0) <= 0) {
                        return response()->json([
                            'message' => 'Current manager group is invalid, cannot perform automatic swap.',
                        ], 422);
                    }

                    // Swap groups so no duplicate manager exists in the same group.
                    $occupiedByOtherManager->group_number = (int) $oldGroup;
                    $occupiedByOtherManager->save();
                    $swappedManager = $occupiedByOtherManager;
                }
            }

            $employee->group_number = $validated['group_number'];
            $employee->save();

            $syncedDays = 0;
            $swappedSyncedDays = 0;
            if ($employee->employee_type === 'Manager Teknik') {
                $syncedDays = $this->syncManagerAssignmentsToGroup($rosterPeriod->id, $employee, (int) $validated['group_number']);
                if ($swappedManager) {
                    $swappedSyncedDays = $this->syncManagerAssignmentsToGroup($rosterPeriod->id, $swappedManager, (int) $swappedManager->group_number);
                }
            } else {
                $syncedDays = $this->syncEmployeeAssignmentsToGroup(
                    $rosterPeriod->id,
                    $employee,
                    (int) $validated['group_number']
                );
            }

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'update',
                'module' => 'roster',
                'reference_id' => $rosterId,
                'description' => 'Assigned ' . $employee->user->name . ' (ID: ' . $employee->id . ') to ' . $employee->employee_type . ' group ' . $validated['group_number'] . ' (from group ' . ($oldGroup ?? 0) . ')' . ($swappedManager ? '; swapped with ' . $swappedManager->user->name . ' to group ' . $swappedManager->group_number : '') . (($syncedDays + $swappedSyncedDays) > 0 ? ' and synced ' . ($syncedDays + $swappedSyncedDays) . ' day(s)' : ''),
            ]);

            DB::commit();

            CacheHelper::clearRosterCache();

            return response()->json([
                'message' => $swappedManager
                    ? 'Employee assigned to group successfully with automatic manager swap'
                    : 'Employee assigned to group successfully',
                'data' => [
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->user->name,
                    'employee_type' => $employee->employee_type,
                    'old_group' => $oldGroup,
                    'new_group' => $employee->group_number,
                    'synced_days' => $syncedDays,
                    'swapped_manager' => $swappedManager ? [
                        'employee_id' => $swappedManager->id,
                        'employee_name' => $swappedManager->user->name,
                        'new_group' => $swappedManager->group_number,
                        'synced_days' => $swappedSyncedDays,
                    ] : null,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to assign employee to group',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /rosters/{id}/groups/{employeeId}
     * Remove CNS/Support employee from group formation (set group_number to 0)
     */
    public function removeEmployeeFromGroup($rosterId, $employeeId)
    {
        DB::beginTransaction();
        try {
            $rosterPeriod = RosterPeriod::findOrFail($rosterId);

            if ($rosterPeriod->isPublished()) {
                return response()->json([
                    'message' => 'Cannot modify published roster'
                ], 400);
            }

            $employee = Employee::with('user:id,name,email,grade')
                ->findOrFail($employeeId);

            if (!in_array($employee->employee_type, ['CNS', 'Support'])) {
                return response()->json([
                    'message' => 'Only CNS or Support employees can be removed from group formation',
                ], 422);
            }

            $oldGroup = $employee->group_number;
            $employee->group_number = 0;
            $employee->save();

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'update',
                'module' => 'roster',
                'reference_id' => $rosterId,
                'description' => 'Removed ' . $employee->user->name . ' (ID: ' . $employee->id . ') from ' . $employee->employee_type . ' group formation (from group ' . ($oldGroup ?? 0) . ')',
            ]);

            DB::commit();

            CacheHelper::clearRosterCache();

            return response()->json([
                'message' => 'Employee removed from group successfully',
                'data' => [
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->user->name,
                    'employee_type' => $employee->employee_type,
                    'old_group' => $oldGroup,
                    'new_group' => $employee->group_number,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to remove employee from group',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /rosters/{id}/managers/add
     * Add an employee (grade 13-14) as manager for entire roster period (all days)
     */
    public function addManager(Request $request, $rosterId)
    {
        $validated = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
        ]);

        DB::beginTransaction();
        try {
            // Verify roster period exists
            $rosterPeriod = RosterPeriod::findOrFail($rosterId);

            // Check if roster is already published
            if ($rosterPeriod->isPublished()) {
                return response()->json([
                    'message' => 'Cannot modify published roster'
                ], 400);
            }

            // Verify employee exists and has valid grade (13-14)
            $employee = Employee::with('user:id,name,email,grade')
                ->findOrFail($validated['employee_id']);

            $grade = $employee->user->grade;
            if ($grade !== 13 && $grade !== 14) {
                return response()->json([
                    'message' => 'Only employees with grade 13-14 can be assigned as managers. This employee has grade ' . $grade,
                ], 422);
            }

            // Get all roster days for this period
            $rosterDays = RosterDay::where('roster_period_id', $rosterId)
                ->select(['id', 'work_date'])
                ->get();

            // Add manager duty for all days
            $createdCount = 0;
            foreach ($rosterDays as $day) {
                // Check if already manager for this day
                $existing = ManagerDuty::where('roster_day_id', $day->id)
                    ->where('employee_id', $validated['employee_id'])
                    ->where('duty_type', 'Manager Teknik')
                    ->exists();

                if (!$existing) {
                    // Get default shift (Morning shift - typically the first one)
                    $defaultShift = Shift::whereRaw('LOWER(name) LIKE ?', ['%pagi%'])
                        ->orWhereRaw('LOWER(name) LIKE ?', ['%morning%'])
                        ->first();
                    
                    $shiftId = $defaultShift?->id ?? Shift::first()?->id ?? 1;

                    ManagerDuty::create([
                        'roster_day_id' => $day->id,
                        'employee_id' => $validated['employee_id'],
                        'duty_type' => 'Manager Teknik',
                        'shift_id' => $shiftId,
                    ]);

                    $createdCount++;
                }
            }

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'create',
                'module' => 'roster',
                'reference_id' => $rosterId,
                'description' => 'Added ' . $employee->user->name . ' (ID: ' . $validated['employee_id'] . ') as manager for ' . $createdCount . ' day(s)',
            ]);

            DB::commit();

            CacheHelper::clearRosterCache();

            return response()->json([
                'message' => 'Manager added successfully to ' . $createdCount . ' day(s)',
                'data' => [
                    'employee_id' => $validated['employee_id'],
                    'employee_name' => $employee->user->name,
                    'days_added' => $createdCount,
                    'total_days' => $rosterDays->count(),
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to add manager',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /rosters/{id}/managers/{employeeId}
     * Remove an employee from manager role for entire roster period (all days)
     */
    public function removeManager($rosterId, $employeeId)
    {
        DB::beginTransaction();
        try {
            // Verify roster period exists
            $rosterPeriod = RosterPeriod::findOrFail($rosterId);

            // Check if roster is already published
            if ($rosterPeriod->isPublished()) {
                return response()->json([
                    'message' => 'Cannot modify published roster'
                ], 400);
            }

            // Verify employee exists
            $employee = Employee::with('user:id,name,email,grade')
                ->findOrFail($employeeId);

            // Check if employee is a fixed manager (cannot be removed)
            if ($employee->is_fixed_manager) {
                return response()->json([
                    'message' => 'Cannot remove ' . $employee->user->name . '. This employee is a fixed Manager Teknik and cannot be removed.',
                    'error' => 'FIXED_MANAGER_CANNOT_REMOVE',
                ], 403);
            }

            // Get all roster days for this period
            $rosterDayIds = RosterDay::where('roster_period_id', $rosterId)
                ->pluck('id')
                ->toArray();

            // Count existing manager duties to remove
            $deletedCount = ManagerDuty::whereIn('roster_day_id', $rosterDayIds)
                ->where('employee_id', $employeeId)
                ->where('duty_type', 'Manager Teknik')
                ->count();

            // Delete all manager duties for this employee across all days
            ManagerDuty::whereIn('roster_day_id', $rosterDayIds)
                ->where('employee_id', $employeeId)
                ->where('duty_type', 'Manager Teknik')
                ->delete();

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'delete',
                'module' => 'roster',
                'reference_id' => $rosterId,
                'description' => 'Removed ' . $employee->user->name . ' (ID: ' . $employeeId . ') from manager role for ' . $deletedCount . ' day(s)',
            ]);

            DB::commit();

            CacheHelper::clearRosterCache();

            return response()->json([
                'message' => 'Manager removed successfully from ' . $deletedCount . ' day(s)',
                'data' => [
                    'employee_id' => $employeeId,
                    'employee_name' => $employee->user->name,
                    'days_removed' => $deletedCount,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to remove manager',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Keep Manager Teknik default mapping unique: MT1->1, MT2->2, MT3->3, MT4->4, MT5->5.
     * This guarantees deterministic ordering and grouping on first roster creation.
     */
    private function ensureDefaultManagerGroupAssignments(): void
    {
        $managerDefaultGroups = [
            'Dudik Fahrudin Sukarno' => 1,
            'Andi Wibowo' => 2,
            'Efried Nara Perkasa' => 3,
            'Alam Fahmi' => 4,
            'Netty Septa Cristila' => 5,
        ];

        $managers = Employee::query()
            ->where('employee_type', 'Manager Teknik')
            ->with('user:id,name')
            ->get();

        foreach ($managers as $manager) {
            $managerName = $manager->user?->name;
            if (!$managerName || !array_key_exists($managerName, $managerDefaultGroups)) {
                continue;
            }

            $expectedGroup = $managerDefaultGroups[$managerName];
            if ((int) $manager->group_number !== $expectedGroup) {
                $manager->group_number = $expectedGroup;
                $manager->save();
            }
        }
    }

    /**
     * Copy shift assignments from the existing manager in the target group to the selected manager.
     */
    private function syncManagerAssignmentsToGroup(int $rosterId, Employee $manager, int $targetGroupNumber): int
    {
        $anchorManager = Employee::query()
            ->where('employee_type', 'Manager Teknik')
            ->where('group_number', $targetGroupNumber)
            ->where('id', '!=', $manager->id)
            ->with('user:id,name,email,grade')
            ->first();

        if (!$anchorManager) {
            return 0;
        }

        $rosterDayIds = RosterDay::where('roster_period_id', $rosterId)
            ->pluck('id')
            ->toArray();

        $anchorAssignments = ShiftAssignment::whereIn('roster_day_id', $rosterDayIds)
            ->where('employee_id', $anchorManager->id)
            ->get()
            ->keyBy('roster_day_id');

        if ($anchorAssignments->isEmpty()) {
            return 0;
        }

        $updatedDays = 0;
        foreach ($rosterDayIds as $rosterDayId) {
            $anchorAssignment = $anchorAssignments->get($rosterDayId);
            if (!$anchorAssignment) {
                continue;
            }

            $managerAssignment = ShiftAssignment::where('roster_day_id', $rosterDayId)
                ->where('employee_id', $manager->id)
                ->first();

            if ($managerAssignment) {
                $managerAssignment->update([
                    'shift_id' => $anchorAssignment->shift_id,
                    'notes' => $anchorAssignment->notes,
                    'span_days' => $anchorAssignment->span_days,
                ]);
            } else {
                ShiftAssignment::create([
                    'roster_day_id' => $rosterDayId,
                    'employee_id' => $manager->id,
                    'shift_id' => $anchorAssignment->shift_id,
                    'notes' => $anchorAssignment->notes,
                    'span_days' => $anchorAssignment->span_days,
                ]);
            }

            $updatedDays++;
        }

        return $updatedDays;
    }

    /**
     * Copy shift assignments from an anchor in target group to the selected CNS/Support employee.
     * Priority anchor: same employee_type in same group, fallback to Manager Teknik of that group.
     */
    private function syncEmployeeAssignmentsToGroup(int $rosterId, Employee $employee, int $targetGroupNumber): int
    {
        $rosterDayIds = RosterDay::where('roster_period_id', $rosterId)
            ->pluck('id')
            ->toArray();

        if (empty($rosterDayIds)) {
            return 0;
        }

        $anchorEmployee = Employee::query()
            ->where('employee_type', $employee->employee_type)
            ->where('group_number', $targetGroupNumber)
            ->where('id', '!=', $employee->id)
            ->first();

        if (!$anchorEmployee) {
            $anchorEmployee = Employee::query()
                ->where('employee_type', 'Manager Teknik')
                ->where('group_number', $targetGroupNumber)
                ->first();
        }

        if (!$anchorEmployee) {
            return 0;
        }

        $anchorAssignments = ShiftAssignment::whereIn('roster_day_id', $rosterDayIds)
            ->where('employee_id', $anchorEmployee->id)
            ->get()
            ->keyBy('roster_day_id');

        if ($anchorAssignments->isEmpty()) {
            return 0;
        }

        $updatedDays = 0;
        foreach ($rosterDayIds as $rosterDayId) {
            $anchorAssignment = $anchorAssignments->get($rosterDayId);
            if (!$anchorAssignment) {
                continue;
            }

            $employeeAssignment = ShiftAssignment::where('roster_day_id', $rosterDayId)
                ->where('employee_id', $employee->id)
                ->first();

            if ($employeeAssignment) {
                $employeeAssignment->update([
                    'shift_id' => $anchorAssignment->shift_id,
                    'notes' => $anchorAssignment->notes,
                    'span_days' => $anchorAssignment->span_days,
                ]);
            } else {
                ShiftAssignment::create([
                    'roster_day_id' => $rosterDayId,
                    'employee_id' => $employee->id,
                    'shift_id' => $anchorAssignment->shift_id,
                    'notes' => $anchorAssignment->notes,
                    'span_days' => $anchorAssignment->span_days,
                ]);
            }

            $updatedDays++;
        }

        return $updatedDays;
    }
}
