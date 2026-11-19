<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 云效工作项导入账本（需求导入云效，module-yunxiao issue #1）
 *
 * 为什么需要独立账本：云效 CreateWorkitem 没有业务唯一键，响应只回 `{id}`。
 * 「本地已发起、远端是否已创建」必须在调用前留痕，否则网络超时/进程崩溃后重试
 * 会重复建需求。唯一键 (tenant_id, yunxiao_project_id, idempotency_key) 是业务幂等键：
 * 同键同 payload 重放、同键异 payload 冲突、failed 可重试、pending 显式 uncertain。
 *
 * 迁移可在既有库幂等重放；只新增表，不改已发布迁移。
 */
return new class extends Migration
{
    private const TABLE = 'yunxiao_workitem_imports';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->unsignedBigInteger('import_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('connection_id');
            $table->unsignedBigInteger('yunxiao_project_id');
            $table->string('idempotency_key', 191)->comment('业务幂等键；缺省由归一化 payload 派生');
            $table->string('payload_hash', 64)->comment('同键异 payload 冲突判定');
            $table->string('status', 20)->default('pending')->comment('pending|created|failed');
            $table->string('external_workitem_id', 100)->nullable();
            $table->string('serial_number', 50)->nullable();
            $table->string('subject', 255)->default('');
            $table->string('workitem_type_id', 100)->nullable();
            $table->string('assigned_to', 100)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 1000)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'yunxiao_project_id', 'idempotency_key'],
                'yx_workitem_imports_key_unique',
            );
            $table->index(['tenant_id', 'status'], 'yx_workitem_imports_status_index');
            $table->index(['tenant_id', 'external_workitem_id'], 'yx_workitem_imports_external_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
