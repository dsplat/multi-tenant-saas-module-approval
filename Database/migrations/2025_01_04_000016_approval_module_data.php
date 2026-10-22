<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Contracts\IdGeneratorContract;

/**
 * 审批中心：RBAC 权限注册（数据迁移，与结构迁移成对）
 *
 * 幂等设计（模块迁移可能在任何环境的既有库上重放）：
 *   - hasTable 守卫：permissions / roles / role_permissions 表不存在时跳过
 *   - 权限 name 唯一键守卫：已存在的权限复用，不重复插入
 *   - 默认权限集：approval.view / manage / create / decide / export
 *   - 旧脚手架的 approval.update / approval.delete 保留不删（持有旧权限的宿主
 *     通过能力别名 common.approval.update/delete 仍可调用，统一映射 approval.manage）
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['name' => 'approval.view', 'display_name' => '查看审批中心'],
        ['name' => 'approval.manage', 'display_name' => '管理审批定义'],
        ['name' => 'approval.create', 'display_name' => '发起审批'],
        ['name' => 'approval.decide', 'display_name' => '提交审批决策'],
        ['name' => 'approval.export', 'display_name' => '导出审批数据'],
    ];

    public function up(): void
    {
        $this->registerPermissions();
        $this->attachToTenantAdmin();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $names = array_column(self::PERMISSIONS, 'name');

        if (Schema::hasTable('role_permissions')) {
            $ids = DB::table('permissions')->whereIn('name', $names)->pluck('permission_id')->all();

            if ($ids !== []) {
                DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
            }
        }

        DB::table('permissions')->whereIn('name', $names)->delete();
    }

    private function registerPermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $idGenerator = app(IdGeneratorContract::class);
        $now = now();

        foreach (self::PERMISSIONS as $permission) {
            $exists = DB::table('permissions')->where('name', $permission['name'])->exists();

            if ($exists) {
                continue;
            }

            DB::table('permissions')->insert([
                'permission_id' => $idGenerator->generate(),
                'name' => $permission['name'],
                'display_name' => $permission['display_name'],
                'group' => 'approval',
                'description' => $permission['display_name'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * tenant_admin 策略是「全部权限减 denylist」，新模块权限应自动获得。
     * 存量库升级时迁移先于 seeder 跑，此处显式挂接避免 tenant_admin 缺权限。
     */
    private function attachToTenantAdmin(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions') || ! Schema::hasTable('permissions')) {
            return;
        }

        $role = DB::table('roles')->where('name', 'tenant_admin')->first();
        if (! $role) {
            return;
        }

        $permIds = DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 'name'))
            ->pluck('permission_id')
            ->all();

        foreach ($permIds as $permId) {
            $exists = DB::table('role_permissions')
                ->where('role_id', $role->role_id)
                ->where('permission_id', $permId)
                ->exists();

            if (! $exists) {
                DB::table('role_permissions')->insert([
                    'role_id' => $role->role_id,
                    'permission_id' => $permId,
                ]);
            }
        }
    }
};
