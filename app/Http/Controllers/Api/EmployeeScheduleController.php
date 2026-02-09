<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RosterPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmployeeScheduleController extends Controller
{
    /**
     * GET /employee/my-schedule
     * Get current employee's personal schedule for a specific month/year
     */
    public function getMySchedule(Request $request)
    {
        $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2100',
        ]);

        $employee = Auth::user()->employee;

        if (!$employee) {
            return response()->json([
                'message' => 'You must be an employee to view schedules'
            ], 403);
        }

        $month = $request->input('month');
        $year = $request->input('year');

        // Get published roster for the requested month/year
        $rosterPeriod = RosterPeriod::where('status', 'published')
            ->where('month', $month)
            ->where('year', $year)
            ->with([
                'rosterDays' => function ($query) {
                    $query->orderBy('work_date', 'asc');
                },
                'rosterDays.shiftAssignments' => function ($query) use ($employee) {
                    $query->where('employee_id', $employee->id);
                },
                'rosterDays.shiftAssignments.shift'
            ])
            ->first();

        if (!$rosterPeriod) {
            return response()->json([
                'message' => 'No published roster found for this period',
                'data' => null,
            ], 404);
        }

        // Format schedule data
        $schedule = [];
        foreach ($rosterPeriod->rosterDays as $day) {
            $assignment = $day->shiftAssignments->first();
            
            $schedule[] = [
                'date' => $day->work_date,
                'day_of_week' => date('l', strtotime($day->work_date)), // Monday, Tuesday, etc
                'shift_id' => $assignment ? $assignment->shift_id : null,
                'shift_name' => $assignment ? $assignment->shift->name : 'off',
            ];
        }

        return response()->json([
            'data' => [
                'roster_id' => $rosterPeriod->id,
                'month' => $rosterPeriod->month,
                'year' => $rosterPeriod->year,
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->user->name,
                    'employee_type' => $employee->employee_type,
                ],
                'schedule' => $schedule,
            ],
        ]);
    }

    /**
     * GET /employee/roster/{rosterId}/my-schedule
     * Get current employee's personal schedule for a specific roster
     */
    public function getMyScheduleByRoster(Request $request, $rosterId)
    {
        $employee = Auth::user()->employee;

        if (!$employee) {
            return response()->json([
                'message' => 'You must be an employee to view schedules'
            ], 403);
        }

        // Get roster with employee's assignments only
        $rosterPeriod = RosterPeriod::where('id', $rosterId)
            ->with([
                'rosterDays' => function ($query) {
                    $query->orderBy('work_date', 'asc');
                },
                'rosterDays.shiftAssignments' => function ($query) use ($employee) {
                    $query->where('employee_id', $employee->id);
                },
                'rosterDays.shiftAssignments.shift'
            ])
            ->first();

        if (!$rosterPeriod) {
            return response()->json([
                'message' => 'Roster not found',
            ], 404);
        }

        // Format schedule data
        $schedule = [];
        foreach ($rosterPeriod->rosterDays as $day) {
            $assignment = $day->shiftAssignments->first();
            
            $schedule[] = [
                'date' => $day->work_date,
                'day_of_week' => date('l', strtotime($day->work_date)),
                'shift_id' => $assignment ? $assignment->shift_id : null,
                'shift_name' => $assignment ? $assignment->shift->name : 'off',
            ];
        }

        return response()->json([
            'data' => [
                'roster_id' => $rosterPeriod->id,
                'month' => $rosterPeriod->month,
                'year' => $rosterPeriod->year,
                'status' => $rosterPeriod->status,
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->user->name,
                    'employee_type' => $employee->employee_type,
                ],
                'schedule' => $schedule,
            ],
        ]);
    }
}
