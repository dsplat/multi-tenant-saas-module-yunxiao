<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Yunxiao\Http\Controllers\ConnectionController;
use MultiTenantSaas\Modules\Yunxiao\Http\Controllers\ProjectController;
use MultiTenantSaas\Modules\Yunxiao\Http\Controllers\SyncController;
use MultiTenantSaas\Modules\Yunxiao\Http\Controllers\TemplateMapController;
use MultiTenantSaas\Modules\Yunxiao\Http\Controllers\WorkitemController;
use MultiTenantSaas\Modules\Yunxiao\Http\Controllers\WritebackController;

/*
|--------------------------------------------------------------------------
| 云效集成 — 租户 API（api/v1/yunxiao）
|--------------------------------------------------------------------------
| 基类 ModuleServiceProvider 统一附加 api + auth:sanctum + throttle:api
| + tenant.identify + VerifyOperatorTenant + module.enabled。
| 本文件只加 operator.identify（装载执行身份）与逐路由 capability 授权。
| token 只在服务端 Integration credentials，任何响应不经连接模型直出。
*/

Route::prefix('yunxiao')->middleware('operator.identify')->group(function (): void {
    // 连接（脱敏；token 只进不出）
    Route::get('connections', [ConnectionController::class, 'index'])
        ->middleware('capability:common.yunxiao.connection.view');
    Route::post('connections', [ConnectionController::class, 'store'])
        ->middleware('capability:common.yunxiao.connection.manage');
    Route::put('connections/{id}', [ConnectionController::class, 'update'])
        ->middleware('capability:common.yunxiao.connection.manage');
    Route::post('connections/{id}/test', [ConnectionController::class, 'test'])
        ->middleware('capability:common.yunxiao.connection.manage');
    Route::post('connections/{id}/rotate', [ConnectionController::class, 'rotate'])
        ->middleware('capability:common.yunxiao.connection.manage');

    // H1 项目投影
    Route::get('projects', [ProjectController::class, 'index'])
        ->middleware('capability:common.yunxiao.project.view');
    Route::post('projects', [ProjectController::class, 'store'])
        ->middleware('capability:common.yunxiao.project.create');

    // 工作项投影（只读）
    Route::get('workitems', [WorkitemController::class, 'index'])
        ->middleware('capability:common.yunxiao.workitem.view');
    Route::get('workitems/{id}', [WorkitemController::class, 'show'])
        ->middleware('capability:common.yunxiao.workitem.view');
    Route::put('workitems/{id}', [WorkitemController::class, 'update'])
        ->middleware('capability:common.yunxiao.workitem.view');

    // 手动同步
    Route::post('sync', [SyncController::class, 'store'])
        ->middleware('capability:common.yunxiao.workitem.sync');

    // 写回记录与死信重试
    Route::get('writebacks', [WritebackController::class, 'index'])
        ->middleware('capability:common.yunxiao.writeback.view');
    Route::post('writebacks/{id}/retry', [WritebackController::class, 'retry'])
        ->middleware('capability:common.yunxiao.writeback.retry');

    // 模板/状态映射
    Route::get('template-maps', [TemplateMapController::class, 'index'])
        ->middleware('capability:common.yunxiao.template_map.manage');
    Route::post('template-maps', [TemplateMapController::class, 'store'])
        ->middleware('capability:common.yunxiao.template_map.manage');
    Route::put('template-maps/{id?}', [TemplateMapController::class, 'update'])
        ->middleware('capability:common.yunxiao.template_map.manage');
});
