<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Services;

/**
 * @deprecated 保留旧类名以兼容直接引用；新代码用 ProjexYunxiaoClient。
 *
 * 行为与父类完全一致：按连接 `config.scope`（Region|Central）选择路径前缀，
 * 不再只支持 Region 组织（issue #45）。
 */
class RegionYunxiaoClient extends ProjexYunxiaoClient {}
