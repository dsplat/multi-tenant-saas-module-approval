<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Approval\Http\Controllers\ApprovalController;

/*
|--------------------------------------------------------------------------
| 审批中心 — 系统管理路由
|--------------------------------------------------------------------------
| 供平台运营跨租户使用：基类附加 api + auth:sanctum + throttle:api + tenant.permission（scope=platform 门禁），前缀 api/v1/admin。此面权限点必须用 platform.* 前缀，与租户面同名权限语义分离，否则 tenant_admin 可凭租户权限打进 admin 面。
|
| 本文件按存在性被基类加载；不需要这个路由面时，直接删除本文件即可。
|
| 示例（取消注释即生效）：
| Route::prefix('approval')->group(function () {
|     Route::get('/', [ApprovalController::class, 'index'])
|         ->middleware('rbac.permission:approval.view');
| });
*/
