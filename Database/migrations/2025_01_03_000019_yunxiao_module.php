<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 云效集成：结构迁移（仅三张投影/映射表）
 *
 * 连接 / 映射 / 游标 / 同步运行 / 写回全部复用 Integration 标准表，
 * 不建 yunxiao_connections、yunxiao_sync_cursors、yunxiao_workitem_mappings。
 * 旧脚手架表 `yunxiaos` 保留一个发布周期，不在此创建/删除。
 * hasTable 守卫保证模块迁移可在既有库上幂等重放。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('yunxiao_projects')) {
            Schema::create('yunxiao_projects', function (Blueprint $table): void {
                $table->unsignedBigInteger('yunxiao_project_id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('connection_id');
                $table->unsignedBigInteger('project_id')->comment('宿主项目 ID（幂等键）');
                $table->string('external_project_id', 100);
                $table->string('name', 255);
                $table->string('custom_code', 10);
                $table->string('template_id', 100);
                $table->string('scope', 100)->nullable();
                $table->string('status', 20)->default('active')->comment('active|partial|creation_failed');
                $table->timestamp('last_synced_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'external_project_id'], 'yx_projects_external_unique');
                $table->unique(['tenant_id', 'project_id'], 'yx_projects_host_unique');
                $table->index(['tenant_id', 'status'], 'yx_projects_status_index');
            });
        }

        if (! Schema::hasTable('yunxiao_workitems')) {
            Schema::create('yunxiao_workitems', function (Blueprint $table): void {
                $table->unsignedBigInteger('yunxiao_workitem_id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('connection_id');
                $table->unsignedBigInteger('yunxiao_project_id');
                $table->string('external_workitem_id', 100);
                $table->string('serial_number', 50)->default('')->comment('对账键（如 TASK-123）');
                $table->string('workitem_type_id', 100)->nullable();
                $table->string('subject', 255)->default('');
                $table->string('status_id', 100)->nullable()->comment('云效真实状态 ID，原样保存');
                $table->string('status_name', 100)->nullable();
                $table->string('assigned_to', 100)->nullable();
                $table->timestamp('gmt_created')->nullable();
                $table->timestamp('gmt_modified')->nullable();
                $table->timestamp('fetched_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'external_workitem_id'], 'yx_workitems_external_unique');
                $table->index(['tenant_id', 'serial_number'], 'yx_workitems_serial_index');
                $table->index(['tenant_id', 'yunxiao_project_id', 'status_id'], 'yx_workitems_project_status_index');
                $table->index(['tenant_id', 'fetched_at'], 'yx_workitems_fetched_index');
            });
        }

        if (! Schema::hasTable('yunxiao_template_maps')) {
            Schema::create('yunxiao_template_maps', function (Blueprint $table): void {
                $table->unsignedBigInteger('template_map_id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->string('project_type', 100)->comment('宿主项目类型');
                $table->string('template_id', 100);
                $table->json('workitem_type_map')->nullable();
                $table->json('status_map')->nullable();
                $table->boolean('is_default')->default(false);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'project_type'], 'yx_template_maps_type_unique');
                $table->index(['tenant_id', 'template_id'], 'yx_template_maps_template_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('yunxiao_workitems');
        Schema::dropIfExists('yunxiao_projects');
        Schema::dropIfExists('yunxiao_template_maps');
    }
};
