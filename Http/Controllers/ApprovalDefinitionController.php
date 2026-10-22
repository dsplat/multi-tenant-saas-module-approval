<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Approval\Http\Controllers\Concerns\ResolvesApprovalActor;
use MultiTenantSaas\Modules\Approval\Http\Resources\ApprovalDefinitionResource;
use MultiTenantSaas\Modules\Approval\Models\ApprovalDefinition;
use MultiTenantSaas\Modules\Approval\Services\ApprovalDefinitionService;

/**
 * 审批定义与版本管理（Console / Operator）
 */
class ApprovalDefinitionController extends BaseController
{
    use ResolvesApprovalActor;

    public function __construct(
        private readonly ApprovalDefinitionService $definitions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->definitions->paginate(
            $this->tenantId(),
            $request->only(['status', 'subject_type', 'key', 'keyword']),
            $this->perPage($request),
        );

        return $this->listResponse($request, $page);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $definition = $this->definitions->find($this->tenantId(), $id);

        return $this->successResponse((new ApprovalDefinitionResource($definition))->resolve($request));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'subject_type' => ['required', 'string', 'max:100'],
            'steps' => ['sometimes', 'array'],
            'settings' => ['sometimes', 'array'],
        ]);

        $definition = $this->definitions->create(
            $this->tenantId(),
            $this->operatorId($request),
            $validated,
        );

        return $this->createdResponse((new ApprovalDefinitionResource($definition))->resolve($request));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'subject_type' => ['sometimes', 'string', 'max:100'],
            'steps' => ['sometimes', 'array'],
            'settings' => ['sometimes', 'array'],
        ]);

        $definition = $this->definitions->update($this->tenantId(), $id, $validated);

        return $this->successResponse((new ApprovalDefinitionResource($definition))->resolve($request));
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $definition = $this->definitions->publish($this->tenantId(), $id);

        return $this->successResponse((new ApprovalDefinitionResource($definition))->resolve($request));
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $definition = $this->definitions->archive($this->tenantId(), $id);

        return $this->successResponse((new ApprovalDefinitionResource($definition))->resolve($request));
    }

    public function createVersion(Request $request, string $id): JsonResponse
    {
        $definition = $this->definitions->createVersion(
            $this->tenantId(),
            $id,
            $this->operatorId($request),
        );

        return $this->createdResponse((new ApprovalDefinitionResource($definition))->resolve($request));
    }

    private function listResponse(Request $request, LengthAwarePaginator $page): JsonResponse
    {
        $items = collect($page->items())
            ->map(fn (ApprovalDefinition $definition): array => (new ApprovalDefinitionResource($definition))->resolve($request))
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                'data' => $items,
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    private function perPage(Request $request): ?int
    {
        return $request->has('per_page') ? max(1, (int) $request->input('per_page')) : null;
    }
}
