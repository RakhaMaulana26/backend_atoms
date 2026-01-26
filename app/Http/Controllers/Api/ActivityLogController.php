<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
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

        // Filter by module if specified
        if ($request->has('module') && !empty($request->module)) {
            $query->where('module', $request->module);
        }

        // Filter by action if specified
        if ($request->has('action') && !empty($request->action)) {
            $query->where('action', $request->action);
        }

        // Filter by user if specified
        if ($request->has('user_id') && !empty($request->user_id)) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by date range
        if ($request->has('date_from') && !empty($request->date_from)) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to') && !empty($request->date_to)) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Search in description
        if ($request->has('search') && !empty($request->search)) {
            $query->where('description', 'LIKE', '%' . $request->search . '%');
        }

        // Paginate results
        $perPage = min($request->get('per_page', 10), 50); // Max 50 per page
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
     * Get activity statistics (optimized with parallel queries)
     */
    public function statistics(): JsonResponse
    {
        // Execute all COUNT queries in parallel using DB::raw
        $result = \DB::select("
            SELECT 
                COUNT(*) as total_activities,
                COUNT(CASE WHEN DATE(created_at) = CURRENT_DATE THEN 1 END) as today_activities,
                COUNT(CASE WHEN created_at >= DATE_TRUNC('week', CURRENT_DATE) 
                       AND created_at < DATE_TRUNC('week', CURRENT_DATE) + INTERVAL '1 week' THEN 1 END) as week_activities,
                COUNT(CASE WHEN EXTRACT(MONTH FROM created_at) = EXTRACT(MONTH FROM CURRENT_DATE)
                       AND EXTRACT(YEAR FROM created_at) = EXTRACT(YEAR FROM CURRENT_DATE) THEN 1 END) as month_activities
            FROM activity_logs
        ")[0];

        $stats = [
            'total_activities' => (int) $result->total_activities,
            'today_activities' => (int) $result->today_activities,
            'week_activities' => (int) $result->week_activities,
            'month_activities' => (int) $result->month_activities,
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
    }
}