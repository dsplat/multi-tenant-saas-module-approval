<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use MultiTenantSaas\Contracts\ExecutionIdentityContract;
use MultiTenantSaas\Modules\Approval\Models\ApprovalDefinition;
use MultiTenantSaas\Modules\Approval\Models\ApprovalInstance;
use MultiTenantSaas\Modules\Approval\Support\ApprovalStepValidator;
use MultiTenantSaas\Modules\Approval\Support\ApproverResolverRegistry;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 审批定义与版本管理
 *
 * 规则：草稿可改；发布前校验步骤结构；发布后不可改（新版本=复制 + version+1）；
 * 归档仅允许无进行中实例的版本。
 */
class ApprovalDefinitionService
{
    public function __construct(
        private readonly ExecutionIdentityContract $identity,
        private readonly ApprovalStepValidator $validator,
        private readonly ApproverResolverRegistry $resolvers,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $tenantId, array $filters = [], ?int $perPage = null): LengthAwarePaginator
    {
        $this->assertTenant($tenantId);

        return ApprovalDefinition::query()
            ->where('tenant_id', $tenantId)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['subject_type'] ?? null, fn ($query, $type) => $query->where('subject_type', $type))
            ->when($filters['key'] ?? null, fn ($query, $key) => $query->where('key', $key))
            ->when($filters['keyword'] ?? null, fn ($query, $keyword) => $query->where(function ($inner) use ($keyword): void {
                $inner->where('name', 'like', '%' . $keyword . '%')->orWhere('key', 'like', '%' . $keyword . '%');
            }))
            ->orderByDesc('created_at')
            ->orderByDesc('definition_id')
            ->paginate($this->perPage($perPage));
    }

    public function find(int $tenantId, int|string $definitionId): ApprovalDefinition
    {
        $this->assertTenant($tenantId);

        $definition = ApprovalDefinition::query()->find($definitionId);

        if ($definition === null) {
            throw new NotFoundHttpException('审批定义不存在');
        }

        $this->assertOwned($definition, $tenantId);

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $tenantId, int $operatorId, array $data): ApprovalDefinition
    {
        $this->assertTenant($tenantId);

        $key = trim((string) ($data['key'] ?? ''));
        if ($key === '' || strlen($key) > 100 || ! preg_match('/^[A-Za-z0-9_.-]+$/', $key)) {
            throw new UnprocessableEntityHttpException('审批定义 key 非法（1..100 位字母/数字/_.-）');
        }

        $subjectType = trim((string) ($data['subject_type'] ?? ''));
        if ($subjectType === '' || strlen($subjectType) > 100) {
            throw new UnprocessableEntityHttpException('审批定义缺少合法 subject_type');
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new UnprocessableEntityHttpException('审批定义缺少名称');
        }

        $steps = $this->validator->normalize((array) ($data['steps'] ?? []));
        $settings = $this->validator->normalizeSettings((array) ($data['settings'] ?? []));

        $version = (int) ApprovalDefinition::query()->where('key', $key)->max('version') + 1;

        try {
            return ApprovalDefinition::create([
                'tenant_id' => $tenantId,
                'key' => $key,
                'name' => $name,
                'subject_type' => $subjectType,
                'version' => $version,
                'status' => ApprovalDefinition::STATUS_DRAFT,
                'steps' => $steps,
                'settings' => $settings,
                'created_by' => $operatorId,
            ])->refresh();
        } catch (QueryException $exception) {
            throw new ConflictHttpException('同一定义版本已存在，请重试', $exception);
        }
    }

    /**
     * 仅草稿可编辑；已发布定义必须创建新版本。
     *
     * @param  array<string, mixed>  $data
     */
    public function update(int $tenantId, int|string $definitionId, array $data): ApprovalDefinition
    {
        $definition = $this->find($tenantId, $definitionId);

        if (! $definition->isDraft()) {
            throw new ConflictHttpException('已发布或已归档定义不可修改，请创建新版本');
        }

        if (array_key_exists('name', $data) && trim((string) $data['name']) === '') {
            throw new UnprocessableEntityHttpException('审批定义名称不得为空');
        }

        if (array_key_exists('subject_type', $data) && trim((string) $data['subject_type']) === '') {
            throw new UnprocessableEntityHttpException('subject_type 不得为空');
        }

        $definition->fill([
            'name' => array_key_exists('name', $data) ? trim((string) $data['name']) : $definition->name,
            'subject_type' => array_key_exists('subject_type', $data) ? trim((string) $data['subject_type']) : $definition->subject_type,
            'steps' => array_key_exists('steps', $data) ? $this->validator->normalize((array) $data['steps']) : $definition->steps,
            'settings' => array_key_exists('settings', $data) ? $this->validator->normalizeSettings((array) $data['settings']) : $definition->settings,
        ])->save();

        return $definition->refresh();
    }

    public function publish(int $tenantId, int|string $definitionId): ApprovalDefinition
    {
        $definition = $this->find($tenantId, $definitionId);

        if (! $definition->isDraft()) {
            throw new ConflictHttpException('仅草稿定义可发布');
        }

        $this->validator->validateForPublish((array) $definition->steps, $this->resolvers);

        $definition->status = ApprovalDefinition::STATUS_PUBLISHED;
        $definition->published_at = now();
        $definition->save();

        return $definition->refresh();
    }

    public function archive(int $tenantId, int|string $definitionId): ApprovalDefinition
    {
        $definition = $this->find($tenantId, $definitionId);

        if ($definition->status === ApprovalDefinition::STATUS_ARCHIVED) {
            throw new ConflictHttpException('审批定义已归档');
        }

        $hasActiveInstance = ApprovalInstance::query()
            ->where('definition_id', $definition->definition_id)
            ->whereIn('status', ApprovalInstance::ACTIVE_STATUSES)
            ->exists();

        if ($hasActiveInstance) {
            throw new ConflictHttpException('该版本仍有进行中实例，不能归档');
        }

        $definition->status = ApprovalDefinition::STATUS_ARCHIVED;
        $definition->archived_at = now();
        $definition->save();

        return $definition->refresh();
    }

    /**
     * 复制当前版本为 version+1 的草稿。
     */
    public function createVersion(int $tenantId, int|string $definitionId, int $operatorId): ApprovalDefinition
    {
        $source = $this->find($tenantId, $definitionId);

        $version = (int) ApprovalDefinition::query()->where('key', $source->key)->max('version') + 1;

        try {
            return ApprovalDefinition::create([
                'tenant_id' => $tenantId,
                'key' => $source->key,
                'name' => $source->name,
                'subject_type' => $source->subject_type,
                'version' => $version,
                'status' => ApprovalDefinition::STATUS_DRAFT,
                'steps' => $source->steps,
                'settings' => $source->settings,
                'created_by' => $operatorId,
            ])->refresh();
        } catch (QueryException $exception) {
            throw new ConflictHttpException('同一定义版本已存在，请重试', $exception);
        }
    }

    public function latestPublished(int $tenantId, string $key): ?ApprovalDefinition
    {
        $this->assertTenant($tenantId);

        return ApprovalDefinition::query()
            ->where('key', $key)
            ->where('status', ApprovalDefinition::STATUS_PUBLISHED)
            ->orderByDesc('version')
            ->orderByDesc('definition_id')
            ->first();
    }

    /**
     * 发起时定位定义：优先 id，其次 key（可指定 version，缺省取最新已发布版本）。
     */
    public function resolveForInitiation(
        int $tenantId,
        int|string|null $definitionId,
        ?string $definitionKey,
        ?int $version = null,
    ): ApprovalDefinition {
        $this->assertTenant($tenantId);

        if ($definitionId !== null && $definitionId !== '') {
            $definition = $this->find($tenantId, $definitionId);

            if (! $definition->isPublished()) {
                throw new UnprocessableEntityHttpException('审批定义未发布');
            }

            return $definition;
        }

        if ($definitionKey === null || $definitionKey === '') {
            throw new UnprocessableEntityHttpException('缺少 definition_id 或 definition_key');
        }

        $query = ApprovalDefinition::query()
            ->where('key', $definitionKey)
            ->where('status', ApprovalDefinition::STATUS_PUBLISHED);

        if ($version !== null) {
            $query->where('version', $version);
        }

        $definition = $query->orderByDesc('version')->orderByDesc('definition_id')->first();

        if ($definition === null) {
            throw new UnprocessableEntityHttpException('未找到已发布的审批定义');
        }

        return $definition;
    }

    public function assertTenant(int $tenantId): void
    {
        $this->identity->assertTenant((string) $tenantId);
    }

    private function assertOwned(ApprovalDefinition $definition, int $tenantId): void
    {
        if ((int) $definition->tenant_id !== $tenantId) {
            throw new AccessDeniedHttpException('无权访问该审批定义');
        }
    }

    private function perPage(?int $perPage): int
    {
        $default = (int) config('approval.per_page', 20);

        return max(1, min(100, $perPage ?? $default));
    }
}
