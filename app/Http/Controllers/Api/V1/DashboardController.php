<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): JsonResponse
    {
        $filters = $request->validate([
            'projects_page' => ['sometimes', 'integer', 'min:1'],
            'projects_per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'آمار داشبورد',
            'data' => $dashboard->build($request->user(), (int) ($filters['projects_page'] ?? 1), (int) ($filters['projects_per_page'] ?? 15)),
        ]);
    }

    public function projects(Request $request, DashboardService $dashboard): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'success' => true, 'message' => 'آمار پروژه‌ها',
            'data' => $dashboard->projects($request->user(), (int) ($filters['page'] ?? 1), (int) ($filters['per_page'] ?? 15)),
        ]);
    }
}
