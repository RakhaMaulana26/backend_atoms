<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ActivityLogController extends Controller
{
    /**
     * Get activity logs with pagination and filters
     */
    public function index(Request $request): JsonResponse
    {
        $query = ActivityLog::with('user:id,name,email')
            ->orderBy('created_at', 'desc');

        if ($request->has('module') && !empty($request->module)) {
            $query->where('module', $request->module);
        }

        if ($request->has('action') && !empty($request->action)) {
            $query->where('action', $request->action);
        }

        if ($request->has('user_id') && !empty($request->user_id)) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('date_from') && !empty($request->date_from)) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to') && !empty($request->date_to)) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->has('search') && !empty($request->search)) {
            $query->where('description', 'LIKE', '%' . $request->search . '%');
        }

        $perPage = min($request->get('per_page', 10), 50);
        $activities = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $activities->items(),
            'meta' => [
                'current_page' => $activities->currentPage(),
                'last_page' => $activities->lastPage(),
                'per_page' => $activities->perPage(),
                'total' => $activities->total(),
                'from' => $activities->firstItem(),
                'to' => $activities->lastItem(),
            ],
        ]);
    }

    /**
     * Get recent activities (last 10)
     */
    public function recent(): JsonResponse
    {
        $activities = ActivityLog::with('user:id,name,email')
            ->select(['id', 'user_id', 'action', 'module', 'description', 'created_at'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $activities,
        ]);
    }

    /**
     * Get activity statistics (portable across DB engines)
     */
    public function statistics(): JsonResponse
    {
        try {
            $today = Carbon::today();
            $startOfWeek = Carbon::now()->startOfWeek();
            $endOfWeek = Carbon::now()->endOfWeek();
            $startOfMonth = Carbon::now()->startOfMonth();
            $endOfMonth = Carbon::now()->endOfMonth();

            $stats = [
                'total_activities' => ActivityLog::count(),
                'today_activities' => ActivityLog::whereDate('created_at', $today)->count(),
                'week_activities' => ActivityLog::whereBetween('created_at', [$startOfWeek, $endOfWeek])->count(),
                'month_activities' => ActivityLog::whereBetween('created_at', [$startOfMonth, $endOfMonth])->count(),
                'by_module' => ActivityLog::selectRaw('module, COUNT(*) as count')
                    ->groupBy('module')
                    ->pluck('count', 'module'),
                'by_action' => ActivityLog::selectRaw('action, COUNT(*) as count')
                    ->groupBy('action')
                    ->orderBy('count', 'desc')
                    ->limit(10)
                    ->pluck('count', 'action'),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil statistik aktivitas: ' . $e->getMessage(),
            ], 500);
        }
    }
}