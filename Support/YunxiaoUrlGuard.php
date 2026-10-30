<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Yunxiao\Support;

use MultiTenantSaas\Modules\Yunxiao\Exceptions\YunxiaoConfigurationException;

/**
 * 云效 base_url SSRF 守卫
 *
 * 默认只允许 https + 云效 Region host（`*.devops.aliyuncs.com`）。
 * 拒绝 localhost、私网/保留地址、云元数据地址与 URL 内嵌凭据。
 * 测试可通过 `yunxiao.security.allow_private_hosts=true` 放行私有 host，
 * 但仍要求 https（除非同时打开 allow_http）。
 */
final class YunxiaoUrlGuard
{
    public function assertSafe(?string $baseUrl): string
    {
        $url = trim((string) $baseUrl);

        if ($url === '') {
            throw YunxiaoConfigurationException::unsafeBaseUrl('base_url 不能为空');
        }

        if (str_contains($url, '@')) {
            throw YunxiaoConfigurationException::unsafeBaseUrl('URL 不得内嵌凭据');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw YunxiaoConfigurationException::unsafeBaseUrl('URL 解析失败');
        }

        $scheme = strtolower($parts['scheme']);
        $allowHttp = (bool) config('yunxiao.security.allow_http', false);

        if ($scheme !== 'https' && ! ($allowHttp && $scheme === 'http')) {
            throw YunxiaoConfigurationException::unsafeBaseUrl('仅允许 https');
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if ($host === '') {
            throw YunxiaoConfigurationException::unsafeBaseUrl('host 不能为空');
        }

        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            throw YunxiaoConfigurationException::unsafeBaseUrl('base_url 只允许指向 host，不允许携带路径');
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw YunxiaoConfigurationException::unsafeBaseUrl('base_url 不允许携带查询串或片段');
        }

        $allowPrivate = (bool) config('yunxiao.security.allow_private_hosts', false);
        $blocked = array_map('strtolower', (array) config('yunxiao.security.blocked_hosts', []));

        if (in_array($host, $blocked, true) || str_ends_with($host, '.localhost')) {
            throw YunxiaoConfigurationException::unsafeBaseUrl('拒绝 localhost/元数据地址');
        }

        if (! $allowPrivate) {
            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                $isPublic = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

                if (! $isPublic) {
                    throw YunxiaoConfigurationException::unsafeBaseUrl('拒绝私网/保留 IP');
                }
            }

            if (str_ends_with($host, '.local') || str_ends_with($host, '.internal') || $host === 'metadata') {
                throw YunxiaoConfigurationException::unsafeBaseUrl('拒绝内网域名');
            }

            $suffixes = array_values(array_filter(array_map(
                static fn ($suffix): string => strtolower(ltrim(trim((string) $suffix), '.')),
                (array) config('yunxiao.security.allowed_host_suffixes', []),
            )));

            if ($suffixes !== []) {
                $allowed = false;
                foreach ($suffixes as $suffix) {
                    if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                        $allowed = true;
                        break;
                    }
                }

                if (! $allowed) {
                    throw YunxiaoConfigurationException::unsafeBaseUrl('host 不属于允许的云效 Region 域名');
                }
            }
        }

        return rtrim($url, '/');
    }
}
