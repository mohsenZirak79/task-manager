<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexListFilterOptionsRequest;
use App\Models\Report;
use App\Models\Task;
use App\Services\ListFilterOptionsService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ListFilterOptionsController extends Controller
{
    public function __construct(private readonly ListFilterOptionsService $options) {}

    public function taskUsers(IndexListFilterOptionsRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Task::class);

        return $this->respond($this->options->taskUsers($request->validated(), $request->user()), true);
    }

    public function reportUsers(IndexListFilterOptionsRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Report::class);

        return $this->respond($this->options->reportUsers($request->validated(), $request->user()), true);
    }

    public function userPositions(IndexListFilterOptionsRequest $request): JsonResponse
    {
        // The route uses users:view, not org_positions:view or user creation permissions.
        return $this->respond($this->options->userPositions($request->validated(), $request->user()), false);
    }

    private function respond(LengthAwarePaginator $page, bool $people): JsonResponse
    {
        $items = $page->getCollection()->map(fn ($row) => $people ? [
            'id' => $row->id, 'full_name' => trim($row->first_name.' '.$row->last_name), 'is_active' => $row->is_active,
        ] : ['id' => $row->id, 'title' => $row->title]);

        return response()->json(['success' => true, 'message' => 'گزینه‌های مجاز فیلتر', 'data' => [
            'items' => $items,
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]]);
    }
}
