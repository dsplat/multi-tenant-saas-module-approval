<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Services\Resolvers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Modules\Approval\Contracts\ApproverResolverContract;
use MultiTenantSaas\Modules\Approval\Models\ApprovalInstance;

/**
 * 角色审批人解析器
 *
 * 步骤配置：{"resolver":"role","config":{"role":"tenant_admin"}} 或 {"role_id":3}
 * 按租户内 operator_tenants.role_id / roles.name 解析。
 */
final class RoleApproverResolver implements ApproverResolverContract
{
    public function key(): string
    {
        return 'role';
    }

    public function resolve(int $tenantId, array $step, array $subjectSnapshot, ?ApprovalInstance $instance = null): array
    {
        if (! Schema::hasTable('operator_tenants') || ! Schema::hasTable('roles')) {
            return [];
        }

        $config = (array) ($step['config'] ?? []);
        $roleId = $config['role_id'] ?? null;
        $roleName = $config['role'] ?? null;

        $query = DB::table('operator_tenants')
            ->where('operator_tenants.tenant_id', $tenantId)
            ->where('operator_tenants.is_active', true);

        if ($roleId !== null && $roleId !== '') {
            $query->where('operator_tenants.role_id', (int) $roleId);
        } elseif (is_string($roleName) && $roleName !== '') {
            $query->join('roles', 'roles.role_id', '=', 'operator_tenants.role_id')
                ->where('roles.name', $roleName)
                ->where(function ($builder) use ($tenantId): void {
                    $builder->whereNull('roles.tenant_id')->orWhere('roles.tenant_id', $tenantId);
                });
        } else {
            return [];
        }

        return $query->distinct()->pluck('operator_tenants.operator_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
