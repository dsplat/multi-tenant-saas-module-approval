<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval;

use MultiTenantSaas\Access\ResourceRegistry;
use MultiTenantSaas\Authorization\CapabilityDefinition;
use MultiTenantSaas\Context\ExecutionIdentity;
use MultiTenantSaas\Contracts\AuthorizationCapabilityRegistryContract;
use MultiTenantSaas\Contracts\CapabilityRequirementResolverContract;
use MultiTenantSaas\Contracts\SceneRegistryContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\Tool;
use MultiTenantSaas\Modules\Approval\Console\Commands\ExpireApprovalInstances;
use MultiTenantSaas\Modules\Approval\Contracts\ApprovalServiceContract;
use MultiTenantSaas\Modules\Approval\Services\ApprovalService;
use MultiTenantSaas\Modules\Approval\Services\Resolvers\FixedApproverResolver;
use MultiTenantSaas\Modules\Approval\Services\Resolvers\RoleApproverResolver;
use MultiTenantSaas\Modules\Approval\Services\Resolvers\SubjectOwnerApproverResolver;
use MultiTenantSaas\Modules\Approval\Services\Tools\ApprovalDecisionDraftHandler;
use MultiTenantSaas\Modules\Approval\Services\Tools\ApprovalInstanceSummaryHandler;
use MultiTenantSaas\Modules\Approval\Services\Tools\ApprovalMyPendingHandler;
use MultiTenantSaas\Modules\Approval\Support\ApprovalSubjectRegistry;
use MultiTenantSaas\Modules\Approval\Support\ApproverResolverRegistry;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

/**
 * 审批中心
 *
 * 路由 / 迁移 / 视图 / 翻译由基类 ModuleServiceProvider 按约定自动加载：
 *   Routes/api.php     → api/v1        （api + auth:sanctum + tenant.identify + VerifyOperatorTenant）
 *   Routes/admin.php   → api/v1/admin  （api + auth:sanctum + tenant.permission）
 *   Routes/tenant.php  → api/v1        （api + auth:sanctum + tenant.identify + VerifyOperatorTenant）
 *   Routes/public.php  → api/v1        （api）
 *
 * 不要在此重写 loadModuleRoutes() / loadModuleViews()：基类已按上述约定加载。
 */
class ApprovalServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'approval';

    /**
     * 能力点 → 所需权限（默认权限集 approval.view/manage/create/decide/export）。
     */
    private const CAPABILITY_PERMISSIONS = [
        'definition.view' => 'approval.view',
        'definition.manage' => 'approval.manage',
        'instance.view' => 'approval.view',
        'instance.create' => 'approval.create',
        'instance.withdraw' => 'approval.create',
        'decision.submit' => 'approval.decide',
        'admin' => 'approval.manage',
        'export' => 'approval.export',
    ];

    /**
     * 旧脚手架能力点兼容别名（不静默删除，持有旧权限的宿主仍可调用）。
     */
    private const LEGACY_ALIASES = [
        'list' => 'approval.view',
        'read' => 'approval.view',
        'create' => 'approval.create',
        'update' => 'approval.manage',
        'delete' => 'approval.manage',
    ];

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(ApprovalSubjectRegistry::class);
        $this->app->singleton(ApproverResolverRegistry::class);
        $this->app->singleton(ApprovalServiceContract::class, ApprovalService::class);
    }

    protected function registerModuleCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ExpireApprovalInstances::class,
            ]);
        }
    }

    protected function bootModule(): void
    {
        $this->registerApproverResolvers();
        $this->registerAuthorizationCapabilities();
        $this->registerResources();
        $this->registerTools();
        $this->registerScenes();
    }

    /**
     * 内置审批人解析器：fixed / role / subject_owner。
     * 宿主可注册同 key 解析器覆盖（last-wins）。
     */
    private function registerApproverResolvers(): void
    {
        $registry = $this->app->make(ApproverResolverRegistry::class);
        $registry->register(new FixedApproverResolver);
        $registry->register(new RoleApproverResolver);
        $registry->register(new SubjectOwnerApproverResolver);
    }

    /** 能力定义归模块，permission 映射可由宿主项目收窄。 */
    private function registerAuthorizationCapabilities(): void
    {
        if (! $this->app->bound(AuthorizationCapabilityRegistryContract::class)) {
            return;
        }

        $registry = $this->app->make(AuthorizationCapabilityRegistryContract::class);
        $requirements = $this->app->bound(CapabilityRequirementResolverContract::class)
            ? $this->app->make(CapabilityRequirementResolverContract::class)
            : null;

        foreach (self::CAPABILITY_PERMISSIONS as $capability => $permission) {
            $this->registerCapability($registry, 'common.approval.' . $capability);
        }

        foreach (self::LEGACY_ALIASES as $alias => $permission) {
            $this->registerCapability($registry, 'common.approval.' . $alias);
        }

        // 权限映射保持字面量：AdminConsolePermissionMapTest 静态解析 capability→permission。
        $requirements?->register('common.approval.definition.view', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'approval.view']);
        $requirements?->register('common.approval.definition.manage', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'approval.manage']);
        $requirements?->register('common.approval.instance.view', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'approval.view']);
        $requirements?->register('common.approval.instance.create', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'approval.create']);
        $requirements?->register('common.approval.instance.withdraw', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'approval.create']);
        $requirements?->register('common.approval.decision.submit', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'approval.decide']);
        $requirements?->register('common.approval.admin', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'approval.manage']);
        $requirements?->register('common.approval.export', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'approval.export']);
        $requirements?->register('common.approval.list', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'approval.view']);
        $requirements?->register('common.approval.read', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'approval.view']);
        $requirements?->register('common.approval.create', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'approval.create']);
        $requirements?->register('common.approval.update', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'approval.manage']);
        $requirements?->register('common.approval.delete', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'approval.manage']);
    }

    private function registerCapability(
        AuthorizationCapabilityRegistryContract $registry,
        string $key,
    ): void {
        $registry->register(new CapabilityDefinition(
            key: $key,
            subjectTypes: [ExecutionIdentity::TYPE_OPERATOR],
            targetScope: CapabilityDefinition::TARGET_TENANT,
            admissionPolicy: [ExecutionIdentity::TYPE_OPERATOR => ['member']],
        ));
    }

    /**
     * 数据资源保护声明：审批账本与决策意见均含敏感信息，未知资源 fail-closed。
     */
    private function registerResources(): void
    {
        if (! $this->app->bound(ResourceRegistry::class)) {
            return;
        }

        $registry = $this->app->make(ResourceRegistry::class);

        $registry->register(
            'approval_definitions',
            requiresPolicy: true,
            sensitive: true,
            filterableColumns: [
                'tenant_id', 'definition_id', 'key', 'subject_type', 'version', 'status',
                'created_by', 'published_at', 'archived_at', 'created_at', 'updated_at',
            ],
            returnableColumns: [
                'tenant_id', 'definition_id', 'key', 'name', 'subject_type', 'version',
                'status', 'steps', 'settings', 'created_by', 'published_at', 'archived_at',
                'created_at', 'updated_at',
            ],
            supportedActions: ['read', 'list', 'aggregate'],
        );

        $registry->register(
            'approval_instances',
            requiresPolicy: true,
            sensitive: true,
            filterableColumns: [
                'tenant_id', 'instance_id', 'definition_id', 'definition_key',
                'definition_version', 'subject_type', 'subject_id', 'project_id',
                'status', 'current_step_no', 'is_exempt', 'initiated_by', 'outcome',
                'idempotency_key', 'active_unique_key', 'started_at', 'completed_at',
                'created_at', 'updated_at',
            ],
            returnableColumns: [
                'tenant_id', 'instance_id', 'definition_id', 'definition_key',
                'definition_version', 'subject_type', 'subject_id', 'project_id',
                'subject_snapshot', 'definition_snapshot', 'status', 'current_step_no',
                'is_exempt', 'exemption_reason', 'initiated_by', 'previous_instance_id',
                'outcome', 'started_at', 'completed_at', 'created_at', 'updated_at',
            ],
            supportedActions: ['read', 'list', 'aggregate', 'export'],
        );

        $registry->register(
            'approval_steps',
            requiresPolicy: true,
            sensitive: true,
            filterableColumns: [
                'tenant_id', 'step_id', 'instance_id', 'step_no', 'mode', 'status',
                'due_at', 'activated_at', 'closed_at', 'created_at', 'updated_at',
            ],
            returnableColumns: [
                'tenant_id', 'step_id', 'instance_id', 'step_no', 'name', 'mode',
                'status', 'approvers_snapshot', 'activated_at', 'due_at', 'closed_at',
                'blocked_reason', 'created_at', 'updated_at',
            ],
            supportedActions: ['read', 'list', 'aggregate'],
        );

        $registry->register(
            'approval_decisions',
            requiresPolicy: true,
            sensitive: true,
            filterableColumns: [
                'tenant_id', 'decision_id', 'instance_id', 'step_id', 'operator_id',
                'decision', 'source', 'decided_at',
            ],
            returnableColumns: [
                'tenant_id', 'decision_id', 'instance_id', 'step_id', 'operator_id',
                'decision', 'comment', 'source', 'ip', 'user_agent', 'request_id',
                'decided_at', 'metadata', 'created_at',
            ],
            supportedActions: ['read', 'list', 'aggregate'],
        );
    }

    /**
     * 注册 L1 只读 AI 工具。
     *
     * 三个工具都要求 common.approval.instance.view；意见草稿只返回文本，
     * 绝不自动提交决策（写操作必须经 decision.submit 能力由本人确认）。
     */
    private function registerTools(): void
    {
        if (! $this->app->bound(ToolRegistryContract::class)) {
            return;
        }

        $registry = $this->app->make(ToolRegistryContract::class);

        $registry->register(
            slug: 'approval_my_pending',
            name: '审批中心 我的待办',
            description: '查询当前 operator 本人的审批待办（当前步骤快照包含本人），可按主体类型或定义 key 过滤；只读。',
            handlerClass: ApprovalMyPendingHandler::class,
            schema: [
                'type' => 'object',
                'properties' => [
                    'subject_type' => ['type' => 'string', 'description' => '主体类型过滤（可选）'],
                    'definition_key' => ['type' => 'string', 'description' => '审批定义 key 过滤（可选）'],
                    'page' => ['type' => 'integer', 'description' => '页码，默认 1'],
                    'per_page' => ['type' => 'integer', 'description' => '每页条数，默认 10，最大 50'],
                ],
                'required' => [],
            ],
            category: 'approval',
            risk: Tool::RISK_L1,
            audience: Tool::AUDIENCE_OPERATOR,
            subjectPolicy: Tool::SUBJECT_ACTOR_SELF,
            dataResource: 'approval_instances',
            dataAction: Tool::DATA_ACTION_READ,
            requiredCapability: 'common.approval.instance.view',
        );

        $registry->register(
            slug: 'approval_instance_summary',
            name: '审批中心 实例摘要',
            description: '按 instance_id 查看审批实例摘要：状态、步骤进度与决策记录；他人审批意见正文按权限裁剪，不返回主体敏感属性。',
            handlerClass: ApprovalInstanceSummaryHandler::class,
            schema: [
                'type' => 'object',
                'properties' => [
                    'instance_id' => ['type' => 'string', 'description' => '审批实例 ID'],
                ],
                'required' => ['instance_id'],
            ],
            category: 'approval',
            risk: Tool::RISK_L1,
            audience: Tool::AUDIENCE_OPERATOR,
            subjectPolicy: Tool::SUBJECT_ACTOR_SELF,
            dataResource: 'approval_instances',
            dataAction: Tool::DATA_ACTION_READ,
            requiredCapability: 'common.approval.instance.view',
        );

        $registry->register(
            slug: 'approval_decision_draft',
            name: '审批中心 意见草稿',
            description: '根据审批实例生成通过/驳回意见草稿文本；只生成草稿，不会落库或提交审批，提交必须由审批人本人完成。',
            handlerClass: ApprovalDecisionDraftHandler::class,
            schema: [
                'type' => 'object',
                'properties' => [
                    'instance_id' => ['type' => 'string', 'description' => '审批实例 ID'],
                    'decision' => ['type' => 'string', 'enum' => ['approve', 'reject'], 'description' => '拟审批结论'],
                    'notes' => ['type' => 'string', 'description' => '审批意见要点（可选）'],
                ],
                'required' => ['instance_id'],
            ],
            category: 'approval',
            risk: Tool::RISK_L1,
            audience: Tool::AUDIENCE_OPERATOR,
            subjectPolicy: Tool::SUBJECT_ACTOR_SELF,
            dataResource: 'approval_instances',
            dataAction: Tool::DATA_ACTION_READ,
            requiredCapability: 'common.approval.instance.view',
        );
    }

    /**
     * AI 场景自注册（场景注册表契约：模块注册 = 能力 + 默认调用）。
     */
    private function registerScenes(): void
    {
        if (! $this->app->bound(SceneRegistryContract::class)) {
            return;
        }

        $registry = $this->app->make(SceneRegistryContract::class);

        $registry->registerScene('approval', '审批中心', 'Menu', '审批定义、实例与待办处理', 100);
        $registry->registerCard('approval-manage', 'approval', '审批管理', '查看审批实例与待办', null, null, '/approval');
    }
}
