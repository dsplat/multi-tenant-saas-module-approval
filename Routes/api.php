<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Approval\Http\Controllers\ApprovalDefinitionController;
use MultiTenantSaas\Modules\Approval\Http\Controllers\ApprovalInstanceController;

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
