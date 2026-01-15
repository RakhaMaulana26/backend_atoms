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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class RosterController extends Controller
{
    /**
     * POST /rosters
     */
    public function store(Request $request)
    {
        $request->validate([
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|min:2024',
            'days' => 'required|array',
            'days.*.date' => 'required|date',
            'days.*.manager_id' => 'required|exists:employees,id',
            'days.*.shifts' => 'required|array',
            'days.*.shifts.pagi' => 'required|array',
            'days.*.shifts.siang' => 'required|array',
            'days.*.shifts.malam' => 'required|array',
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

            // Get shift IDs
            $shifts = Shift::whereIn('name', ['pagi', 'siang', 'malam'])->get()->keyBy('name');

            foreach ($request->days as $dayData) {
                // Create roster day
                $rosterDay = RosterDay::create([
                    'roster_period_id' => $rosterPeriod->id,
                    'work_date' => $dayData['date'],
                ]);

                // Assign manager
                ManagerDuty::create([
                    'roster_day_id' => $rosterDay->id,
                    'employee_id' => $dayData['manager_id'],
                ]);

                // Assign shifts
                foreach ($dayData['shifts'] as $shiftName => $employeeIds) {
                    $shift = $shifts[$shiftName] ?? null;
                    
                    if (!$shift) {
                        throw new \Exception("Shift {$shiftName} not found");
                    }

                    // Validate minimum employees
                    $this->validateShiftAssignments($shiftName, $employeeIds);

                    foreach ($employeeIds as $employeeId) {
                        ShiftAssignment::create([
                            'roster_day_id' => $rosterDay->id,
                            'shift_id' => $shift->id,
                            'employee_id' => $employeeId,
                        ]);
                    }
                }
            }

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'create',
                'module' => 'roster',
                'reference_id' => $rosterPeriod->id,
                'description' => 'Created roster for ' . $request->month . '/' . $request->year,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Roster created successfully',
                'data' => $rosterPeriod->load('rosterDays.shiftAssignments', 'rosterDays.managerDuties'),
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

        return response()->json([
            'data' => $rosterPeriod,
        ]);
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
        ]);
    }

    /**
     * Validate shift assignments
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
}
