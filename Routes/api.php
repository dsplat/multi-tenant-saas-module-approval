<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Approval\Http\Controllers\ApprovalDefinitionController;
use MultiTenantSaas\Modules\Approval\Http\Controllers\ApprovalInstanceController;
use MultiTenantSaas\Modules\Approval\Http\Controllers\UserApprovalController;

/*
|--------------------------------------------------------------------------
| 审批中心 — API 路由（租户自助）
|--------------------------------------------------------------------------
| 基类 ModuleServiceProvider 统一附加：api + auth:sanctum + throttle:api
| + tenant.identify + VerifyOperatorTenant，前缀 api/v1。
| 因此这里不写认证中间件，由 operator.identify 装载执行身份，再走 capability 统一授权。
|
| 静态路径（my-pending / stats / export）必须注册在 {id} 之前，避免被实例 ID 吞掉。
*/

Route::prefix(config('approval.route_prefix', ''))->middleware('operator.identify')->group(function () {
    Route::prefix('approval/definitions')->group(function () {
        Route::get('/', [ApprovalDefinitionController::class, 'index'])
            ->middleware('capability:common.approval.definition.view');

        Route::post('/', [ApprovalDefinitionController::class, 'store'])
            ->middleware('capability:common.approval.definition.manage');

        Route::get('{id}', [ApprovalDefinitionController::class, 'show'])
            ->middleware('capability:common.approval.definition.view');

        Route::put('{id}', [ApprovalDefinitionController::class, 'update'])
            ->middleware('capability:common.approval.definition.manage');

        Route::post('{id}/publish', [ApprovalDefinitionController::class, 'publish'])
            ->middleware('capability:common.approval.definition.manage');

        Route::post('{id}/archive', [ApprovalDefinitionController::class, 'archive'])
            ->middleware('capability:common.approval.definition.manage');

        Route::post('{id}/versions', [ApprovalDefinitionController::class, 'createVersion'])
            ->middleware('capability:common.approval.definition.manage');
    });

    Route::prefix('approval/instances')->group(function () {
        Route::get('/', [ApprovalInstanceController::class, 'index'])
            ->middleware('capability:common.approval.instance.view');

        Route::get('my-pending', [ApprovalInstanceController::class, 'myPending'])
            ->middleware('capability:common.approval.instance.view');

        Route::get('stats', [ApprovalInstanceController::class, 'stats'])
            ->middleware('capability:common.approval.instance.view');

        Route::get('export', [ApprovalInstanceController::class, 'export'])
            ->middleware('capability:common.approval.export');

        Route::post('/', [ApprovalInstanceController::class, 'store'])
            ->middleware('capability:common.approval.instance.create');

        Route::get('{id}', [ApprovalInstanceController::class, 'show'])
            ->middleware('capability:common.approval.instance.view');

        Route::get('{id}/timeline', [ApprovalInstanceController::class, 'timeline'])
            ->middleware('capability:common.approval.instance.view');

        Route::post('{id}/withdraw', [ApprovalInstanceController::class, 'withdraw'])
            ->middleware('capability:common.approval.instance.withdraw');

        Route::post('{id}/cancel', [ApprovalInstanceController::class, 'cancel'])
            ->middleware('capability:common.approval.admin');

        Route::post('{id}/decisions', [ApprovalInstanceController::class, 'decide'])
            ->middleware('capability:common.approval.decision.submit');
    });
});

/*
|--------------------------------------------------------------------------
| 审批中心 — C 端接口（User 面，C2O 缺口②）
|--------------------------------------------------------------------------
| 独立前缀 /user/approval/instances + 独立 `user.identify` 组（C2O 采用约定 v0.4
| §4.2）：身份空间边界清晰，与 console 面（上方 approval/instances/...）不同命名
| 空间，withdraw 不会同 method/URI 重复注册。
|
| 授权：user.identify 装载 User 执行身份后走统一能力门（common.approval.self_*，
| self_read 准入 public + 认证等级——门只验身份，撤销参与人保留窗内仍可到控制器
| 取元数据（C2O 缺口③）；写能力 self_withdraw 准入 member）；**不挂
| rbac.permission** —— RBAC 只作用于 Operator，User 不拥有角色（identity-model
| 铁律）。逐记录可见性（本人发起人/撤销保留窗元数据）在控制器裁决；内部快照/
| 决策意见独立授权不出 C 端（S0 §6.3 白名单见 Http/Resources/UserApprovalResource）。
| 无通用建单入口：User 发起由具体领域经 ApprovalService::initiateAs 验证主体
| 操作权后创建（通用建单权限不开）。
*/
Route::prefix('user/approval/instances')->middleware('user.identify')->group(function () {
    // 静态 mine 必须排在 {instanceId} 动态路由之前：注册顺序即路由缓存序列化顺序；
    // {instanceId} 另加 whereNumber 双保险——'mine' 在任何顺序下都不可能被吞成 ID。
    Route::get('/mine', [UserApprovalController::class, 'mine'])
        ->middleware('capability:common.approval.self_read');

    Route::get('/{instanceId}/timeline', [UserApprovalController::class, 'timeline'])
        ->middleware('capability:common.approval.self_read')
        ->whereNumber('instanceId');

    // 撤回（self-scoped）：发起人本人；URI 前缀独立，不与 console 面
    // POST approval/instances/{id}/withdraw 重复注册。
    Route::post('/{instanceId}/withdraw', [UserApprovalController::class, 'withdraw'])
        ->middleware('capability:common.approval.self_withdraw')
        ->whereNumber('instanceId');

    Route::get('/{instanceId}', [UserApprovalController::class, 'show'])
        ->middleware('capability:common.approval.self_read')
        ->whereNumber('instanceId');
});
