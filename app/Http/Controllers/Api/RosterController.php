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
     * GET /rosters
     */
    public function index(Request $request)
    {
        $query = RosterPeriod::with(['rosterDays'])
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc');
            
        // Optional filtering by month/year
        if ($request->has('month')) {
            $query->where('month', $request->month);
        }
        
        if ($request->has('year')) {
            $query->where('year', $request->year);
        }
        
        $rosters = $query->get();
        
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
