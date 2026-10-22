<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Approval\Contracts\ApprovalServiceContract;
use MultiTenantSaas\Modules\Approval\Http\Controllers\Concerns\ResolvesApprovalActor;
use MultiTenantSaas\Modules\Approval\Http\Resources\ApprovalDecisionResource;
use MultiTenantSaas\Modules\Approval\Http\Resources\ApprovalInstanceResource;
use MultiTenantSaas\Modules\Approval\Models\ApprovalDecision;
use MultiTenantSaas\Modules\Approval\Models\ApprovalInstance;
use MultiTenantSaas\Modules\Approval\Services\ApprovalQueryService;

/**
 * 审批实例：发起、查询、时间线、决策、撤回、取消、统计与导出
 */
class ApprovalInstanceController extends BaseController
{
    use ResolvesApprovalActor;

    public function __construct(
        private readonly ApprovalServiceContract $approvals,
        private readonly ApprovalQueryService $queries,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->queries->paginateInstances(
            $this->tenantId(),
            $this->filters($request),
            $this->perPage($request),
        );

        return $this->listResponse($request, $page);
    }

    public function myPending(Request $request): JsonResponse
    {
        $page = $this->queries->pendingFor(
            $this->tenantId(),
            $this->operatorId($request),
            $this->filters($request),
            $this->perPage($request),
        );

        $page->getCollection()->load('steps');

        return $this->listResponse($request, $page);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'definition_id' => ['nullable'],
            'definition_key' => ['nullable', 'string', 'max:100'],
            'definition_version' => ['nullable', 'integer', 'min:1'],
            'subject_type' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['required', 'string', 'max:100'],
            'project_id' => ['nullable'],
            'idempotency_key' => ['nullable', 'string', 'max:191'],
            'previous_instance_id' => ['nullable'],
            'exempt' => ['sometimes', 'boolean'],
            'is_exempt' => ['sometimes', 'boolean'],
            'exemption_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $tenantId = $this->tenantId();
        $operatorId = $this->operatorId($request);

        $isExempt = (bool) ($validated['exempt'] ?? $validated['is_exempt'] ?? false);

        $instance = $isExempt
            ? $this->approvals->recordExemption($tenantId, $operatorId, $validated)
            : $this->approvals->initiate($tenantId, $operatorId, $validated);

        $instance->load(['steps', 'decisions']);

        return $this->createdResponse((new ApprovalInstanceResource($instance))->resolve($request));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $instance = $this->approvals->find($this->tenantId(), $id);
        $instance->load(['steps', 'decisions']);

        return $this->successResponse((new ApprovalInstanceResource($instance))->resolve($request));
    }

    public function timeline(Request $request, string $id): JsonResponse
    {
        return $this->successResponse($this->queries->timeline($this->tenantId(), $id));
    }

    public function withdraw(Request $request, string $id): JsonResponse
    {
        $instance = $this->approvals->withdraw(
            $this->tenantId(),
            $this->operatorId($request),
            $id,
        );

        $instance->load(['steps', 'decisions']);

        return $this->successResponse((new ApprovalInstanceResource($instance))->resolve($request));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $instance = $this->approvals->cancel(
            $this->tenantId(),
            $this->operatorId($request),
            $id,
            (string) $validated['reason'],
        );

        $instance->load(['steps', 'decisions']);

        return $this->successResponse((new ApprovalInstanceResource($instance))->resolve($request));
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:approve,reject'],
            'comment' => ['nullable', 'string'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $validated['source'] = ApprovalDecision::SOURCE_API;
        $validated['ip'] = $request->ip();
        $validated['user_agent'] = substr((string) $request->userAgent(), 0, 255);
        $validated['request_id'] = $request->header('X-Request-Id');

        $decision = $this->approvals->decide(
            $this->tenantId(),
            $this->operatorId($request),
            $id,
            $validated,
        );

        return $this->createdResponse((new ApprovalDecisionResource($decision))->resolve($request));
    }

    public function stats(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $filters['group_by'] = $request->query('group_by');

        return $this->successResponse($this->queries->stats($this->tenantId(), $filters));
    }

    public function export(Request $request): JsonResponse
    {
        $result = $this->queries->exportRows($this->tenantId(), $this->filters($request));

        return $this->successResponse([
            'rows' => $result['rows'],
            'total' => $result['total'],
            'truncated' => $result['truncated'],
            'masked' => true,
            'generated_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->only([
            'status', 'subject_type', 'subject_id', 'definition_key', 'definition_id',
            'definition_version', 'project_id', 'initiated_by', 'outcome', 'is_exempt',
            'participant_id', 'keyword', 'from', 'to',
        ]);
    }

    private function listResponse(Request $request, LengthAwarePaginator $page): JsonResponse
    {
        $items = collect($page->items())
            ->map(fn (ApprovalInstance $instance): array => (new ApprovalInstanceResource($instance))->resolve($request))
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
