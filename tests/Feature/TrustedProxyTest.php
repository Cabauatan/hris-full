<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    public function test_default_configuration_ignores_forwarded_headers_even_from_loopback(): void
    {
        $this->configureTrustedProxies('');

        $this->requestIdentity('127.0.0.1')->assertOk()->assertExactJson([
            'secure' => false,
            'host' => '192.168.100.51',
            'port' => 8002,
            'ip' => '127.0.0.1',
        ]);
    }

    public function test_opted_in_loopback_recognizes_public_https_host_port_and_client_ip(): void
    {
        $this->configureTrustedProxies('127.0.0.1');

        $this->requestIdentity('127.0.0.1')->assertOk()->assertExactJson([
            'secure' => true,
            'host' => 'hris-test.trycloudflare.com',
            'port' => 443,
            'ip' => '203.0.113.25',
        ]);
    }

    public function test_lan_client_cannot_spoof_forwarded_headers_when_only_loopback_is_trusted(): void
    {
        $this->configureTrustedProxies('127.0.0.1');

        $this->requestIdentity('192.168.100.88')->assertOk()->assertExactJson([
            'secure' => false,
            'host' => '192.168.100.51',
            'port' => 8002,
            'ip' => '192.168.100.88',
        ]);
    }

    #[DataProvider('trustedAddresses')]
    public function test_explicit_ip_and_cidr_entries_are_accepted(string $configuration, string $remoteAddress): void
    {
        $this->configureTrustedProxies($configuration);

        $this->requestIdentity($remoteAddress)->assertOk()->assertExactJson([
            'secure' => true,
            'host' => 'hris-test.trycloudflare.com',
            'port' => 443,
            'ip' => '203.0.113.25',
        ]);
    }

    #[DataProvider('invalidProxyConfigurations')]
    public function test_invalid_configuration_fails_closed_for_the_entire_list(string $configuration): void
    {
        $this->configureTrustedProxies($configuration);

        $this->requestIdentity('127.0.0.1')->assertOk()->assertExactJson([
            'secure' => false,
            'host' => '192.168.100.51',
            'port' => 8002,
            'ip' => '127.0.0.1',
        ]);
    }

    public static function trustedAddresses(): array
    {
        return [
            'trimmed comma-separated IPv4' => [' 192.0.2.1, 127.0.0.1,127.0.0.1 ', '127.0.0.1'],
            'IPv4 CIDR' => ['127.0.0.0/8', '127.0.0.5'],
            'IPv4 single host CIDR' => ['127.0.0.1/32', '127.0.0.1'],
            'IPv6 loopback' => ['::1', '::1'],
            'IPv6 CIDR' => ['2001:db8::/32', '2001:db8::10'],
            'IPv6 single host CIDR' => ['::1/128', '::1'],
        ];
    }

    public static function invalidProxyConfigurations(): array
    {
        return [
            'wildcard' => ['*'],
            'wildcard mixed with a valid address' => ['127.0.0.1,*'],
            'double wildcard' => ['**'],
            'dynamic remote address' => ['REMOTE_ADDR'],
            'hostname mixed with a valid address' => ['127.0.0.1,localhost'],
            'invalid address' => ['127.0.0.999'],
            'IPv4 universal range' => ['0.0.0.0/0'],
            'IPv6 universal range' => ['::/0'],
            'IPv4 prefix too large' => ['127.0.0.1/33'],
            'IPv6 prefix too large' => ['::1/129'],
            'negative prefix' => ['127.0.0.1/-1'],
            'nonnumeric prefix' => ['127.0.0.1/trusted'],
            'empty prefix' => ['127.0.0.1/'],
            'multiple prefixes' => ['127.0.0.1/32/32'],
        ];
    }

    private function configureTrustedProxies(string $value): void
    {
        $originalValue = $_ENV['TRUSTED_PROXIES'] ?? null;
        $_ENV['TRUSTED_PROXIES'] = $value;

        try {
            config(['proxies' => require config_path('proxies.php')]);
        } finally {
            if ($originalValue === null) {
                unset($_ENV['TRUSTED_PROXIES']);
            } else {
                $_ENV['TRUSTED_PROXIES'] = $originalValue;
            }
        }
    }

    private function requestIdentity(string $remoteAddress): TestResponse
    {
        Route::get('/api/testing/trusted-proxy', static fn (Request $request): array => [
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
            'port' => $request->getPort(),
            'ip' => $request->ip(),
        ]);

        return $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
            ->getJson('http://192.168.100.51:8002/api/testing/trusted-proxy', [
                'X-Forwarded-For' => '203.0.113.25',
                'X-Forwarded-Host' => 'hris-test.trycloudflare.com',
                'X-Forwarded-Port' => '443',
                'X-Forwarded-Proto' => 'https',
            ]);
    }
}
