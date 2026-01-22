<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\ManagerDuty;
use App\Models\RosterDay;
use App\Models\RosterPeriod;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Helpers\CacheHelper;
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
        // Cache key based on filters
        $cacheKey = 'rosters_' . ($request->month ?? 'all') . '_' . ($request->year ?? 'all');
        
        // Try to get from cache (5 minutes)
        $rosters = Cache::remember($cacheKey, 300, function () use ($request) {
            $query = RosterPeriod::query()
                ->select(['id', 'month', 'year', 'status', 'created_at', 'updated_at']) // Only needed columns
                ->orderBy('year', 'desc')
                ->orderBy('month', 'desc');
                
            // Optional filtering by month/year
            if ($request->has('month')) {
                $query->where('month', $request->month);
            }
            
            if ($request->has('year')) {
                $query->where('year', $request->year);
            }
            
            return $query->limit(12)->get(); // Max 12 months (1 year)
        });
        
        return response()->json($rosters);
    }

    /**
     * POST /rosters
     */
    public function store(Request $request)
    {
        $request->validate([
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|min:2024',
        ]);

        DB::beginTransaction();
        try {
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

            // Auto-generate all days for the month (no shift assignments yet)
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $request->month, $request->year);
            
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $date = sprintf('%04d-%02d-%02d', $request->year, $request->month, $day);
                
                // Create roster day (without manager or shift assignments)
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

            return response()->json([
                'message' => 'Roster template created successfully. You can now assign managers and shifts to each day.',
                'data' => $rosterPeriod->load('rosterDays'),
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
     */
    public function show($id)
    {
        $rosterPeriod = RosterPeriod::with([
            'rosterDays.shiftAssignments.employee.user',
            'rosterDays.shiftAssignments.shift',
            'rosterDays.managerDuties.employee.user',
        ])->findOrFail($id);

        return response()->json($rosterPeriod);
    }

    /**
     * POST /rosters/{id}/publish
     */
    public function publish($id)
    {
        $rosterPeriod = RosterPeriod::findOrFail($id);

        if ($rosterPeriod->isPublished()) {
            return response()->json([
                'message' => 'Roster is already published'
            ], 400);
        }

        // Comprehensive validation before publish
        $validation = $this->validateRosterCompleteness($rosterPeriod);
        
        if (!$validation['is_valid']) {
            return response()->json([
                'message' => 'Roster validation failed. Cannot publish incomplete roster.',
                'validation' => $validation,
            ], 422);
        }

        $rosterPeriod->status = 'published';
        $rosterPeriod->save();

        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => 'publish',
            'module' => 'roster',
            'reference_id' => $rosterPeriod->id,
            'description' => 'Published roster for ' . $rosterPeriod->month . '/' . $rosterPeriod->year,
        ]);

        return response()->json([
            'message' => 'Roster published successfully',
            'data' => $rosterPeriod,
            'validation' => $validation,
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
            ])
            ->firstOrFail();

        return response()->json($rosterDay);
    }

    /**
     * POST /rosters/{roster_id}/days/{day_id}/assignments
     * Assign employees to shifts and managers for a specific day
     */
    public function storeAssignments(Request $request, $rosterId, $dayId)
    {
        // Check if request body is empty
        if (empty($request->all())) {
            return response()->json([
                'message' => 'Request body is empty. Check your JSON syntax (remove trailing commas).',
                'example' => [
                    'shift_assignments' => [
                        ['employee_id' => 1, 'shift_id' => 1]
                    ]
                ]
            ], 400);
        }

        $request->validate([
            'shift_assignments' => 'sometimes|array',
            'shift_assignments.*.employee_id' => 'required|exists:employees,id',
            'shift_assignments.*.shift_id' => 'required|exists:shifts,id',
            'manager_duties' => 'sometimes|array',
            'manager_duties.*.employee_id' => 'required|exists:employees,id',
            'manager_duties.*.duty_type' => 'required|string|in:Manager Teknik,General Manager',
        ]);

        // Debug: Log request data
        \Log::info('Assignment Request', [
            'has_shift_assignments' => $request->has('shift_assignments'),
            'shift_assignments_count' => $request->has('shift_assignments') ? count($request->shift_assignments) : 0,
            'has_manager_duties' => $request->has('manager_duties'),
            'request_all' => $request->all()
        ]);

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
                    
                    // Check if assignment already exists
                    $existing = ShiftAssignment::where('roster_day_id', $dayId)
                        ->where('employee_id', $assignment['employee_id'])
                        ->where('shift_id', $assignment['shift_id'])
                        ->first();

                    if (!$existing) {
                        ShiftAssignment::create([
                            'roster_day_id' => $dayId,
                            'employee_id' => $assignment['employee_id'],
                            'shift_id' => $assignment['shift_id'],
                        ]);
                        $assignmentCount++;
                        
                        // Auto-assign manager duty if employee is Manager Teknik
                        if (in_array($employee->user->role, ['Manager Teknik', 'General Manager'])) {
                            $existingDuty = ManagerDuty::where('roster_day_id', $dayId)
                                ->where('employee_id', $assignment['employee_id'])
                                ->where('duty_type', $employee->user->role)
                                ->first();
                                
                            if (!$existingDuty) {
                                ManagerDuty::create([
                                    'roster_day_id' => $dayId,
                                    'employee_id' => $assignment['employee_id'],
                                    'duty_type' => $employee->user->role,
                                ]);
                            }
                        }
                    } else {
                        $skippedCount++;
                        $shift = Shift::find($assignment['shift_id']);
                        $skippedDetails[] = [
                            'type' => 'shift_assignment',
                            'employee' => $employee->user->name ?? 'Employee #' . $assignment['employee_id'],
                            'shift' => $shift->name ?? 'Shift #' . $assignment['shift_id'],
                            'reason' => 'Already assigned to this shift'
                        ];
                    }
                }
            }

            // Process manager duties (manual override - optional)
            if ($request->has('manager_duties')) {
                foreach ($request->manager_duties as $duty) {
                    // Check if manager duty already exists
                    $existing = ManagerDuty::where('roster_day_id', $dayId)
                        ->where('employee_id', $duty['employee_id'])
                        ->where('duty_type', $duty['duty_type'])
                        ->first();

                    if (!$existing) {
                        ManagerDuty::create([
                            'roster_day_id' => $dayId,
                            'employee_id' => $duty['employee_id'],
                            'duty_type' => $duty['duty_type'],
                        ]);
                        $assignmentCount++;
                    } else {
                        $skippedCount++;
                        $employee = Employee::with('user')->find($duty['employee_id']);
                        $skippedDetails[] = [
                            'type' => 'manager_duty',
                            'employee' => $employee->user->name ?? 'Employee #' . $duty['employee_id'],
                            'duty_type' => $duty['duty_type'],
                            'reason' => 'Already assigned as ' . $duty['duty_type']
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
    public function updateAssignments(Request $request, $rosterId, $dayId)
    {
        $request->validate([
            'shift_assignments' => 'sometimes|array',
            'shift_assignments.*.employee_id' => 'required|exists:employees,id',
            'shift_assignments.*.shift_id' => 'required|exists:shifts,id',
            'manager_duties' => 'sometimes|array',
            'manager_duties.*.employee_id' => 'required|exists:employees,id',
            'manager_duties.*.duty_type' => 'required|string|in:Manager Teknik,General Manager',
        ]);

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
                    ShiftAssignment::create([
                        'roster_day_id' => $dayId,
                        'employee_id' => $assignment['employee_id'],
                        'shift_id' => $assignment['shift_id'],
                    ]);
                    $assignmentCount++;
                }
            }

            // Create new manager duties
            if ($request->has('manager_duties')) {
                foreach ($request->manager_duties as $duty) {
                    ManagerDuty::create([
                        'roster_day_id' => $dayId,
                        'employee_id' => $duty['employee_id'],
                        'duty_type' => $duty['duty_type'],
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
                return $a->employee->employee_type === 'CNS';
            })->count();
            
            $supportCount = $assignments->filter(function($a) {
                return $a->employee->employee_type === 'Support';
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
                'shift_name' => $shift->shift_name,
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

        $cnsCount = $employees->where('employee_type', 'CNS')->count();
        $supportCount = $employees->where('employee_type', 'SUPPORT')->count();

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
     * - Each shift has minimum 4 CNS + 2 Support
     * - Each day has at least 1 Manager Teknik
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

        $rosterDays = $rosterPeriod->days()->with([
            'shiftAssignments.employee',
            'managerDuties.employee'
        ])->get();

        $validation['total_days'] = $rosterDays->count();

        // Get all 3 shifts
        $allShifts = Shift::all();
        if ($allShifts->count() !== 3) {
            $validation['is_valid'] = false;
            $validation['errors'][] = 'System must have exactly 3 shifts configured';
            return $validation;
        }

        foreach ($rosterDays as $day) {
            $dayValidation = [
                'date' => $day->work_date,
                'is_valid' => true,
                'errors' => [],
                'shifts' => [],
                'manager_count' => 0,
            ];

            // Check Manager Teknik
            $managerTeknikCount = $day->managerDuties()
                ->whereHas('employee', function($q) {
                    $q->where('employee_type', Employee::TYPE_MANAGER_TEKNIK);
                })
                ->count();

            $dayValidation['manager_count'] = $managerTeknikCount;

            if ($managerTeknikCount < 1) {
                $dayValidation['is_valid'] = false;
                $dayValidation['errors'][] = 'Missing Manager Teknik (required: minimum 1)';
            }

            // Check each shift
            $shiftAssignments = $day->shiftAssignments->groupBy('shift_id');

            foreach ($allShifts as $shift) {
                $assignments = $shiftAssignments->get($shift->id, collect());
                
                $cnsCount = $assignments->filter(function($a) {
                    return $a->employee->employee_type === Employee::TYPE_CNS;
                })->count();
                
                $supportCount = $assignments->filter(function($a) {
                    return $a->employee->employee_type === Employee::TYPE_SUPPORT;
                })->count();

                $shiftValid = $cnsCount >= 4 && $supportCount >= 2;

                $shiftInfo = [
                    'shift_name' => $shift->shift_name,
                    'cns_count' => $cnsCount,
                    'support_count' => $supportCount,
                    'total_count' => $assignments->count(),
                    'is_valid' => $shiftValid,
                ];

                if (!$shiftValid) {
                    $dayValidation['is_valid'] = false;
                    $message = "Shift {$shift->shift_name}: ";
                    
                    if ($cnsCount < 4) {
                        $message .= "Need 4 CNS (current: {$cnsCount}). ";
                    }
                    if ($supportCount < 2) {
                        $message .= "Need 2 Support (current: {$supportCount}).";
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
}
