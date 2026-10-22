<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Approval\Http\Controllers\ApprovalController;

/*
|--------------------------------------------------------------------------
| 审批中心 — 公开路由
|--------------------------------------------------------------------------
| 无需认证：基类仅附加 api group，前缀 api/v1。适合回调/Webhook/前台只读接口，注意不要在此暴露写操作。
|
| 本文件按存在性被基类加载；不需要这个路由面时，直接删除本文件即可。
|
| 示例（取消注释即生效）：
| Route::prefix('approval')->group(function () {
|     Route::get('/', [ApprovalController::class, 'index'])
|         ->middleware('rbac.permission:approval.view');
| });
*/
