<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Exceptions;

use MultiTenantSaas\Exceptions\DomainException;

/**
 * 云效连接器领域异常基类
 *
 * 所有异常消息都必须是脱敏摘要：不得包含 token、Authorization 头或原始请求体。
 */
class YunxiaoException extends DomainException {}
