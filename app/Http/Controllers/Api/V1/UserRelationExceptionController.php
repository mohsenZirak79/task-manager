<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRelationExceptionRequest;
use App\Http\Requests\UpdateUserRelationExceptionRequest;
use App\Http\Resources\UserRelationExceptionResource;
use App\Models\UserRelationException;
use App\Services\UserRelationExceptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserRelationExceptionController extends Controller
{
    public function __construct(private readonly UserRelationExceptionService $service) {}

    public function index(Request $request): JsonResponse
    {
        $p = $this->service->paginate($request->only(['from_user_id', 'to_user_id', 'permission_type', 'per_page']));

        return $this->list($p);
    }

    public function store(StoreUserRelationExceptionRequest $request): JsonResponse
    {
        return $this->ok('استثنای ارتباطی با موفقیت ایجاد شد.', new UserRelationExceptionResource($this->service->create($request->validated())), 201);
    }

    public function show(UserRelationException $userRelationException): JsonResponse
    {
        return $this->ok('جزئیات استثنای ارتباطی', new UserRelationExceptionResource($userRelationException->load(['fromUser', 'toUser'])));
    }

    public function update(UpdateUserRelationExceptionRequest $request, UserRelationException $userRelationException): JsonResponse
    {
        return $this->ok('استثنای ارتباطی با موفقیت بروزرسانی شد.', new UserRelationExceptionResource($this->service->update($userRelationException, $request->validated())));
    }

    public function destroy(UserRelationException $userRelationException): JsonResponse
    {
        $this->service->delete($userRelationException);

        return $this->ok('استثنای ارتباطی با موفقیت حذف شد.');
    }

    private function ok(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    private function list($p): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'لیست استثناهای ارتباطی کاربران', 'data' => UserRelationExceptionResource::collection($p->items()), 'meta' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total()]]);
    }
}
