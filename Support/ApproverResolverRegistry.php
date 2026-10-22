<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Support;

use MultiTenantSaas\Modules\Approval\Contracts\ApproverResolverContract;
use MultiTenantSaas\Modules\Approval\Models\ApprovalInstance;

/**
 * 审批人解析器注册表
 *
 * 内置 fixed / role / subject_owner；宿主可注册自定义解析器（last-wins）。
 * 解析结果为空时调用方必须置步骤 blocked，禁止自动通过。
 */
final class ApproverResolverRegistry
{
    /** @var array<string, ApproverResolverContract> */
    private array $resolvers = [];

    public function register(ApproverResolverContract $resolver): void
    {
        $key = trim($resolver->key());

        if ($key === '') {
            throw new \InvalidArgumentException('审批人解析器键不得为空');
        }

        $this->resolvers[$key] = $resolver;
    }

    public function has(string $key): bool
    {
        return isset($this->resolvers[$key]);
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $subjectSnapshot
     * @return array<int, int>
     */
    public function resolve(string $key, int $tenantId, array $step, array $subjectSnapshot, ?ApprovalInstance $instance = null): array
    {
        $resolver = $this->resolvers[$key] ?? null;

        if ($resolver === null) {
            return [];
        }

        $ids = array_map(static fn ($id): int => (int) $id, $resolver->resolve($tenantId, $step, $subjectSnapshot, $instance));

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        $keys = array_keys($this->resolvers);
        sort($keys);

        return $keys;
    }
}
