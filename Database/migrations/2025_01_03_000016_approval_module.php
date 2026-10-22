<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 审批中心：建表（结构迁移，与数据迁移成对）
 *
 * 幂等设计（模块迁移可能在任何环境的既有库上重放）：
 *   - hasTable 守卫：下游项目若已手工建过同名表，跳过建表
 *   - 旧脚手架 approvals 表不在本迁移中删除，避免发布包升级时丢数据
 * RBAC 权限注册见同块 approval_module_data 迁移。
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createDefinitions();
        $this->createInstances();
        $this->createSteps();
        $this->createDecisions();
    }

    public function down(): void
    {
        foreach (['approval_decisions', 'approval_steps', 'approval_instances', 'approval_definitions'] as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
    }

    private function createDefinitions(): void
    {
        if (Schema::hasTable('approval_definitions')) {
            return;
        }

        Schema::create('approval_definitions', function (Blueprint $table) {
            $table->unsignedBigInteger('definition_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('key', 100);
            $table->string('name', 255);
            $table->string('subject_type', 100);
            $table->integer('version')->default(1);
            $table->string('status', 20)->default('draft')->comment('draft|published|archived');
            $table->json('steps');
            $table->json('settings')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'key', 'version']);
            $table->index(['tenant_id', 'subject_type', 'status']);
        });
    }

    private function createInstances(): void
    {
        if (Schema::hasTable('approval_instances')) {
            return;
        }

        Schema::create('approval_instances', function (Blueprint $table) {
            $table->unsignedBigInteger('instance_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('definition_id');
            $table->string('definition_key', 100);
            $table->integer('definition_version');
            $table->string('subject_type', 100);
            $table->string('subject_id', 100);
            $table->unsignedBigInteger('project_id')->nullable();
            $table->json('subject_snapshot');
            $table->json('definition_snapshot');
            $table->string('status', 20)->default('pending')
                ->comment('pending|in_progress|approved|rejected|withdrawn|cancelled|expired');
            $table->unsignedInteger('current_step_no')->nullable();
            $table->boolean('is_exempt')->default(false);
            $table->string('exemption_reason', 500)->nullable();
            $table->unsignedBigInteger('initiated_by');
            $table->string('idempotency_key', 191)->nullable();
            $table->unsignedBigInteger('previous_instance_id')->nullable();
            // 进行中实例的非空唯一键；终态置 NULL 释放唯一约束
            $table->string('active_unique_key', 191)->nullable();
            $table->string('outcome', 20)->nullable()
                ->comment('approved|rejected|withdrawn|cancelled|expired|exempt');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'active_unique_key']);
            $table->index(['tenant_id', 'subject_type', 'subject_id']);
            $table->index(['tenant_id', 'status', 'current_step_no']);
            $table->index(['tenant_id', 'definition_id']);
        });
    }

    private function createSteps(): void
    {
        if (Schema::hasTable('approval_steps')) {
            return;
        }

        Schema::create('approval_steps', function (Blueprint $table) {
            $table->unsignedBigInteger('step_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('instance_id');
            $table->unsignedInteger('step_no');
            $table->string('name', 255);
            $table->string('mode', 10)->default('any')->comment('any|all');
            $table->string('status', 20)->default('pending')
                ->comment('pending|active|approved|rejected|skipped|blocked|expired');
            $table->json('approvers_snapshot');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('blocked_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['instance_id', 'step_no']);
            $table->index(['tenant_id', 'status', 'due_at']);
            $table->index(['tenant_id', 'instance_id']);
        });
    }

    private function createDecisions(): void
    {
        if (Schema::hasTable('approval_decisions')) {
            return;
        }

        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->unsignedBigInteger('decision_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('instance_id');
            $table->unsignedBigInteger('step_id');
            $table->unsignedBigInteger('operator_id');
            $table->string('decision', 20)->comment('approve|reject');
            $table->text('comment')->nullable();
            $table->string('source', 20)->default('console')->comment('console|api|system');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamp('decided_at');
            $table->json('metadata')->nullable();
            // append-only：无 updated_at
            $table->timestamp('created_at')->nullable();

            $table->unique(['step_id', 'operator_id']);
            $table->index(['tenant_id', 'operator_id', 'decided_at']);
            $table->index(['tenant_id', 'instance_id', 'step_id']);
        });
    }
};
