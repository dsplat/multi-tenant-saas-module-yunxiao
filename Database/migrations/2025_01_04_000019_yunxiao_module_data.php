<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Contracts\IdGeneratorContract;

/**
 * 云效集成：RBAC 权限注册（数据迁移，与结构迁移成对）
 *
 * 默认权限只保留 yunxiao.view / yunxiao.manage；
 * 旧 common.yunxiao.list/read/create/update/delete 作为能力别名映射到这两个权限。
 * 幂等：权限 name 唯一键守卫；tenant_admin 关联先查后插。
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['name' => 'yunxiao.view', 'display_name' => '查看云效集成'],
        ['name' => 'yunxiao.manage', 'display_name' => '管理云效集成'],
    ];

    public function up(): void
    {
        $this->registerPermissions();
        $this->attachToTenantAdmin();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 'name'))
            ->delete();
    }

    private function registerPermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $idGenerator = app(IdGeneratorContract::class);
        $now = now();

        foreach (self::PERMISSIONS as $permission) {
            if (DB::table('permissions')->where('name', $permission['name'])->exists()) {
                continue;
            }

            DB::table('permissions')->insert([
                'permission_id' => $idGenerator->generate(),
                'name' => $permission['name'],
                'display_name' => $permission['display_name'],
                'group' => 'yunxiao',
                'description' => $permission['display_name'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function attachToTenantAdmin(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions') || ! Schema::hasTable('permissions')) {
            return;
        }

        $role = DB::table('roles')->where('name', 'tenant_admin')->first();

        if (! $role) {
            return;
        }

        $permIds = DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 'name'))
            ->pluck('permission_id')
            ->all();

        foreach ($permIds as $permId) {
            $exists = DB::table('role_permissions')
                ->where('role_id', $role->role_id)
                ->where('permission_id', $permId)
                ->exists();

            if (! $exists) {
                DB::table('role_permissions')->insert([
                    'role_id' => $role->role_id,
                    'permission_id' => $permId,
                ]);
            }
        }
    }
};
