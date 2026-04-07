<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RosterTask;
use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class RosterTaskController extends Controller
{
    /**
     * Shift definitions:
     * - 07-13: Pagi (Morning - 07:00-13:00)
     * - 13-19: Siang (Afternoon - 13:00-19:00)
     * - 19-07: Malam (Evening - 19:00-07:00)
     */
    private const SHIFTS = ['07-13', '13-19', '19-07'];

    private function isManager(User $user)
    {
        return in_array($user->role, [User::ROLE_MANAGER_TEKNIK, User::ROLE_GENERAL_MANAGER, User::ROLE_ADMIN]);
    }

    private function isAdmin(User $user)
    {
        return $user->role === User::ROLE_ADMIN;
    }

    private function normalizeStatus($status)
    {
        if (!$status) {
            return 'pending';
        }

        $status = strtolower(trim($status));

        $mappings = [
            'done' => 'completed',
            'completed' => 'completed',
            'inprogress' => 'in_progress',
            'in_progress' => 'in_progress',
            'in-progress' => 'in_progress',
            'inprogress' => 'in_progress',
            'pending' => 'pending',
            'cancelled' => 'cancelled',
            'canceled' => 'cancelled',
        ];

        return $mappings[$status] ?? 'pending';
    }

    private function normalizeShiftKey($shift)
    {
        if (!$shift) {
            return null;
        }

        $value = strtolower(trim((string)$shift));
        $map = [
            '07-13' => '07-13',
            '13-19' => '13-19',
            '19-07' => '19-07',
            'pagi' => '07-13',
            'siang' => '13-19',
            'malam' => '19-07',
            'evening' => '19-07',
            'morning' => '07-13',
            'afternoon' => '13-19',
        ];

        return $map[$value] ?? null;
    }

    private function normalizeDate($date)
    {
        if (!$date) {
            return null;
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Exception $e) {
            return null;
        }
    }

    private function normalizeAssignedTo($assignedTo)
    {
        if (is_null($assignedTo) || $assignedTo === '') {
            return [];
        }

        if (is_numeric($assignedTo)) {
            return [(int)$assignedTo];
        }

        if (is_string($assignedTo)) {
            $assignedTo = array_filter(array_map('trim', explode(',', $assignedTo)));
        }

        if (is_object($assignedTo)) {
            $assignedTo = [$assignedTo];
        }

        if (!is_array($assignedTo)) {
            return [];
        }

        $ids = collect($assignedTo)->map(function ($item) {
            if (is_numeric($item)) {
                return (int)$item;
            }
            if (is_array($item) && isset($item['id'])) {
                return (int)$item['id'];
            }
            if (is_object($item) && (isset($item->id) || isset($item->value))) {
                return (int)($item->id ?? $item->value);
            }
            return null;
        })->filter()->unique()->values()->all();

        return $ids;
    }

    private function formatTask(RosterTask $task)
    {
        $assignedUsers = [];
        if (is_array($task->assigned_to) && count($task->assigned_to) > 0) {
            $assignedUsers = User::whereIn('id', $task->assigned_to)->get(['id', 'name', 'role'])->map(function ($u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'role' => $u->role,
                ];
            })->toArray();
        }

        $status = $task->status;
        $statusValueMap = [
            'pending' => 'pending',
            'in_progress' => 'inProgress',
            'completed' => 'done',
            'cancelled' => 'cancelled',
        ];

        $status = $statusValueMap[$status] ?? 'pending';

        $statusDisplayMap = [
            'pending' => 'pending',
            'inProgress' => 'inProgress',
            'done' => 'done',
            'cancelled' => 'cancelled',
        ];

        return [
            'id' => $task->id,
            'date' => $task->date ? $task->date->toDateString() : null,
            'shift_key' => $task->shift_key,
            'shift_name' => [
                '07-13' => 'Pagi',
                '13-19' => 'Siang',
                '19-07' => 'Malam',
            ][$task->shift_key] ?? null,
            'role' => $task->role,
            'assigned_to' => is_array($task->assigned_to) ? $task->assigned_to : [],
            'assigned_to_users' => $assignedUsers,
            'title' => $task->title,
            'description' => $task->description,
            'priority' => $task->priority,
            'status' => $status,
            'status_display' => $statusDisplayMap[$status] ?? 'pending',
            'created_by' => $task->created_by ?? null,
            'created_at' => $task->created_at ? $task->created_at->toDateTimeString() : null,
            'updated_at' => $task->updated_at ? $task->updated_at->toDateTimeString() : null,
        ];
    }

    /**
     * Display a listing of the resource.
     * Access control: 
     * - Managers/Admin see all tasks
     * - Employees see only tasks assigned to them + tasks for their role
     */
    public function index(Request $request)
    {
        $query = RosterTask::query();

        if ($request->has('date')) {
            $query->whereDate('date', $request->date);
        }

        if ($request->has('shift')) {
            $query->where('shift_key', $request->shift);
        }

        if ($request->has('shift_key')) {
            $query->where('shift_key', $request->shift_key);
        }

        if ($request->has('role')) {
            $query->where('role', $request->role);
        }

        if ($request->has('assigned_to')) {
            $assignedTo = $request->assigned_to;
            if (is_string($assignedTo)) {
                if (strpos($assignedTo, ',') !== false) {
                    $assignedTo = array_filter(array_map('trim', explode(',', $assignedTo)));
                } else {
                    $assignedTo = [$assignedTo];
                }
            }

            foreach ((array) $assignedTo as $userId) {
                $query->whereJsonContains('assigned_to', (int) $userId);
            }
        }

        if ($request->has('status')) {
            $status = $this->normalizeStatus($request->status);
            $query->where('status', $status);
        }

        $tasks = $query->latest()->paginate($request->get('per_page', 20));

        $data = $tasks->getCollection()->map(fn($task) => $this->formatTask($task));

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $tasks->currentPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
                'last_page' => $tasks->lastPage(),
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     * 
     * ONLY: Admin, Manajer Teknik, General Manager
     * 
     * Shifts:
     * - 07-13: Pagi
     * - 13-19: Siang
     * - 19-07: Malam (Sore)
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        if (!$this->isManager($user)) {
            return response()->json(['message' => 'Hanya admin dan manajer yang bisa membuat task', 'error' => 'UNAUTHORIZED'], 403);
        }

        $payload = $request->all();

        if (empty($payload['shift_key']) && !empty($payload['shift'])) {
            $payload['shift_key'] = $payload['shift'];
        }

        if (!empty($payload['shift_key'])) {
            $normalizedShift = $this->normalizeShiftKey($payload['shift_key']);
            if ($normalizedShift) {
                $payload['shift_key'] = $normalizedShift;
            }
        }

        if (empty($payload['assigned_to']) && !empty($payload['assigned_to_ids'])) {
            $payload['assigned_to'] = $payload['assigned_to_ids'];
        }

        if (isset($payload['status'])) {
            $payload['status'] = $this->normalizeStatus($payload['status']);
        }

        $payload['status'] = $payload['status'] ?? 'pending';

        if (!empty($payload['priority'])) {
            $payload['priority'] = strtolower(trim($payload['priority']));
            if (!in_array($payload['priority'], ['low', 'medium', 'high'])) {
                $payload['priority'] = 'medium';
            }
        } else {
            $payload['priority'] = 'medium';
        }

        if (!empty($payload['date'])) {
            $normalizedDate = $this->normalizeDate($payload['date']);
            if ($normalizedDate) {
                $payload['date'] = $normalizedDate;
            }
        }

        if (isset($payload['assigned_to'])) {
            $payload['assigned_to'] = $this->normalizeAssignedTo($payload['assigned_to']);
        } else {
            $payload['assigned_to'] = [];
        }

        $validator = Validator::make($payload, [
            'date' => 'required|date|date_format:Y-m-d', // allow historical or current date tugas
            'shift_key' => 'required|string|in:07-13,13-19,19-07',
            'role' => 'required|string',
            'assigned_to' => 'sometimes|array',
            'assigned_to.*' => 'integer|exists:users,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'priority' => 'required|in:low,medium,high',
            'status' => 'required|in:pending,in_progress,completed,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
                'payload' => $payload,
            ], 422);
        }

        $payload['created_by'] = $user->id;

        $task = RosterTask::create($payload);

        $shiftNames = ['07-13' => 'Pagi (07.00-13.00)', '13-19' => 'Siang (13.00-19.00)', '19-07' => 'Malam (19.00-07.00)'];
        $shiftName = $shiftNames[$task->shift_key] ?? $task->shift_key;

        foreach ($task->assigned_to as $userId) {
            Notification::create([
                'user_id' => $userId,
                'sender_id' => $user->id,
                'title' => "Task Baru Shift {$shiftName}",
                'message' => "Anda mendapat tugas baru: {$task->title} pada tanggal {$task->date} ({$shiftName})",
                'type' => 'inbox',
                'category' => 'roster',
                'reference_id' => $task->id,
                'data' => json_encode(['type' => 'roster_task', 'task_id' => $task->id, 'shift_key' => $task->shift_key, 'date' => $task->date, 'role' => $task->role]),
                'is_read' => false,
            ]);
        }

        return response()->json(['data' => $this->formatTask($task), 'message' => 'Task berhasil dibuat'], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $task = RosterTask::findOrFail($id);
        return response()->json(['data' => $this->formatTask($task)]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $user = Auth::user();
        $task = RosterTask::findOrFail($id);

        $data = $request->all();

        if (!empty($data['date'])) {
            $normalizedDate = $this->normalizeDate($data['date']);
            if ($normalizedDate) {
                $data['date'] = $normalizedDate;
            }
        }

        if (isset($data['status'])) {
            $data['status'] = $this->normalizeStatus($data['status']);
        }

        if (isset($data['priority'])) {
            $data['priority'] = strtolower(trim($data['priority']));
        }

        if (isset($data['assigned_to'])) {
            $data['assigned_to'] = $this->normalizeAssignedTo($data['assigned_to']);
        }

        if (!empty($data['shift_key'])) {
            $normalizedShift = $this->normalizeShiftKey($data['shift_key']);
            if ($normalizedShift) {
                $data['shift_key'] = $normalizedShift;
            }
        }

        $isStatusUpdateOnly = $request->has('status') && count($data) === 1;

        if ($isStatusUpdateOnly) {
            $validator = Validator::make($data, ['status' => 'required|in:pending,in_progress,completed,cancelled']);
        } else {
            if (!$this->isManager($user)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
            $validator = Validator::make($data, [
                'date' => 'sometimes|date|date_format:Y-m-d',
                'shift_key' => 'sometimes|string|in:07-13,13-19,19-07',
                'role' => 'sometimes|string',
                'assigned_to' => 'sometimes|array',
                'assigned_to.*' => 'integer|exists:users,id',
                'title' => 'sometimes|string|max:255',
                'description' => 'nullable|string|max:1000',
                'priority' => 'sometimes|in:low,medium,high',
                'status' => 'sometimes|in:pending,in_progress,completed,cancelled',
            ]);
        }

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $task->update($data);

        return response()->json(['data' => $this->formatTask($task), 'message' => 'Task updated successfully']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = Auth::user();
        if (!$this->isManager($user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $task = RosterTask::findOrFail($id);
        $task->delete();

        return response()->json([
            'message' => 'Task deleted successfully',
        ]);
    }
}
