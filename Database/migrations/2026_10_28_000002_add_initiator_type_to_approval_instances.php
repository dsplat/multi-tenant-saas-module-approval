<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * approval_instances 显式发起人类型（C2O 采用约定 v0.4 §4.1）
 *
 * 冻结契约：docs/decisions/2026-10-22-approval-user-initiator-and-c2o-penetration.md §4.1 ——
 * 增加显式发起人类型与稳定 ID 的组合语义：`initiated_by` 保留原 ID 语义，
 * 新增 `initiated_by_type`（user|operator|service）区分身份空间。
 *
 * 存量安全默认值（禁止猜成 User）：当前框架 Approval 路由全程 `operator.identify`，
 * `ApprovalService::initiate` 只写 `initiated_by = operatorId`，无 User 入口——
 * 现存行可断言回填为 `operator`，由列 DEFAULT 'operator' 一并完成；若迁移中发现
 * 非 operator 来源的行应阻断并单列人工核实（此处不做任何按值猜类型的数据变换）。
 *
 * 幂等设计（模块迁移可能在既有库上重放）：hasColumn / hasIndex 逐项守卫，重复执行
 * 安全跳过；纯加列 + 索引无数据变换，sqlite / MySQL 同形可跑（不走 MySqlOnlyMigration）。
 */
return new class extends Migration
{
    private const TABLE = 'approval_instances';

    private const INITIATOR_INDEX = 'approval_instances_initiator_index';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $addType = ! Schema::hasColumn(self::TABLE, 'initiated_by_type');
        $addIndex = ! Schema::hasIndex(self::TABLE, self::INITIATOR_INDEX);

        if (! $addType && ! $addIndex) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($addType, $addIndex): void {
            if ($addType) {
                $table->string('initiated_by_type', 20)
                    ->default('operator')
                    ->comment('发起人类型: user/operator/service（存量行回填 operator，C2O v0.4 §4.1）');
            }

            if ($addIndex) {
                // 本人查询（mine）走 (tenant, 发起人类型, 发起人) 定位
                $table->index(['tenant_id', 'initiated_by_type', 'initiated_by'], self::INITIATOR_INDEX);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (Schema::hasIndex(self::TABLE, self::INITIATOR_INDEX)) {
                $table->dropIndex(self::INITIATOR_INDEX);
            }

            if (Schema::hasColumn(self::TABLE, 'initiated_by_type')) {
                $table->dropColumn('initiated_by_type');
            }
        });
    }
};
