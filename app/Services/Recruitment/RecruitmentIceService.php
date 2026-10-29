<?php

namespace App\Services\Recruitment;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class RecruitmentIceService
{
    private const UNAVAILABLE = 'The video connection service is unavailable. Please try joining again shortly.';

    private const CLOUDFLARE_URLS = [
        'stun:stun.cloudflare.com:3478',
        'turn:turn.cloudflare.com:3478?transport=udp',
        'turn:turn.cloudflare.com:443?transport=udp',
        'turn:turn.cloudflare.com:3478?transport=tcp',
        'turn:turn.cloudflare.com:80?transport=tcp',
        'turns:turn.cloudflare.com:5349?transport=tcp',
        'turns:turn.cloudflare.com:443?transport=tcp',
    ];

    /** @return list<array{urls: string|list<string>, username?: string, credential?: string}> */
    public function servers(CarbonImmutable $availableUntil): array
    {
        $servers = $this->staticServers(config('recruitment.ice_servers', []));
        $keyId = config('recruitment.turn.key_id', '');
        $token = config('recruitment.turn.api_token', '');

        if ($keyId === '' && $token === '') {
            return $servers;
        }
        if (! is_string($keyId) || preg_match('/\A[a-fA-F0-9]{32}\z/', $keyId) !== 1
            || ! is_string($token) || preg_match('/\A[a-zA-Z0-9_-]{64}\z/', $token) !== 1) {
            throw new ServiceUnavailableHttpException(null, self::UNAVAILABLE);
        }

        $ttl = max(60, min(10800, (int) now()->diffInSeconds($availableUntil, false) + 120));

        try {
            $response = Http::acceptJson()->asJson()->withToken($token)
                ->connectTimeout(2)->timeout(5)->withoutRedirecting()
                ->post('https://rtc.live.cloudflare.com/v1/turn/keys/'.$keyId.'/credentials/generate-ice-servers', ['ttl' => $ttl]);
        } catch (ConnectionException|RequestException) {
            throw new ServiceUnavailableHttpException(null, self::UNAVAILABLE);
        }

        if (! $response->created() || strlen($response->body()) > 32768) {
            throw new ServiceUnavailableHttpException(null, self::UNAVAILABLE);
        }

        $body = $response->json();
        $relayServers = $this->cloudflareServers(is_array($body) ? ($body['iceServers'] ?? null) : null, $token);

        return [...$servers, ...$relayServers];
    }

    /** @return list<array{urls: string|list<string>, username?: string, credential?: string}> */
    private function staticServers(#[\SensitiveParameter] mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 8) {
            throw new ServiceUnavailableHttpException(null, self::UNAVAILABLE);
        }

        $servers = [];
        foreach ($value as $server) {
            if (! is_array($server) || array_diff(array_keys($server), ['urls', 'username', 'credential', 'credentialType']) !== []) {
                throw new ServiceUnavailableHttpException(null, self::UNAVAILABLE);
            }
            $urls = $this->urls($server['urls'] ?? null);
            $relay = false;
            foreach ($urls as $url) {
                if (! $this->validUrl($url)) {
                    throw new ServiceUnavailableHttpException(null, self::UNAVAILABLE);
                }
                $relay = $relay || str_starts_with($url, 'turn:') || str_starts_with($url, 'turns:');
            }

            $safe = ['urls' => is_string($server['urls']) ? $server['urls'] : $urls];
            if ($relay) {
                $safe += $this->credentials($server);
            } elseif (isset($server['username']) || isset($server['credential'])) {
                throw new ServiceUnavailableHttpException(null, self::UNAVAILABLE);
            }
            if (isset($server['credentialType']) && $server['credentialType'] !== 'password') {
                throw new ServiceUnavailableHttpException(null, self::UNAVAILABLE);
            }
            $servers[] = $safe;
        }

        return $servers;
    }

    /** @return list<array{urls: list<string>, username?: string, credential?: string}> */
    private function cloudflareServers(#[\SensitiveParameter] mixed $value, #[\SensitiveParameter] string $token): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 8) {
            throw new ServiceUnavailableHttpException(null, self::UNAVAILABLE);
        }

        $servers = [];
        $hasRelay = false;
        foreach ($value as $server) {
            if (! is_array($server)) {
                throw new ServiceUnavailableHttpException(null, self::UNAVAILABLE);
            }
            $urls = array_values(array_intersect($this->urls($server['urls'] ?? null), self::CLOUDFLARE_URLS));
            if ($urls === []) {
                continue;
            }
            $safe = ['urls' => $urls];
            if (count(array_filter($urls, static fn (string $url): bool => str_starts_with($url, 'turn'))) > 0) {
                $credentials = $this->credentials($server);
                if (str_contains($credentials['username'], $token) || str_contains($credentials['credential'], $token)) {
                    throw new ServiceUnavailableHttpException(null, self::UNAVAILABLE);
                }
                $safe += $credentials;
                $hasRelay = true;
            }
            $servers[] = $safe;
        }
        if (! $hasRelay) {
            throw new ServiceUnavailableHttpException(null, self::UNAVAILABLE);
        }

        return $servers;
    }

    /** @return list<string> */
    private function urls(#[\SensitiveParameter] mixed $value): array
    {
        $urls = is_string($value) ? [$value] : $value;
        if (! is_array($urls) || ! array_is_list($urls) || count($urls) < 1 || count($urls) > 8) {
            throw new ServiceUnavailableHttpException(null, self::UNAVAILABLE);
        }
        foreach ($urls as $url) {
            if (! is_string($url) || strlen($url) < 1 || strlen($url) > 512) {
                throw new ServiceUnavailableHttpException(null, self::UNAVAILABLE);
            }
        }

        return $urls;
    }

    private function validUrl(string $url): bool
    {
        if (preg_match('/\A(?<scheme>stun|stuns|turn|turns):(?<host>\[[a-fA-F0-9:.]+\]|[a-zA-Z0-9.-]+)(?::(?<port>[0-9]{1,5}))?(?:\?transport=(?<transport>udp|tcp))?\z/', $url, $parts) !== 1) {
            return false;
        }
        $host = $parts['host'];
        if (str_starts_with($host, '[')) {
            if (filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return false;
            }
        } elseif (strlen($host) > 253 || count(array_filter(explode('.', $host), static fn (string $label): bool => preg_match('/\A[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\z/', $label) !== 1)) > 0) {
            return false;
        }

        return (! isset($parts['port']) || $parts['port'] === '' || ((int) $parts['port'] >= 1 && (int) $parts['port'] <= 65535))
            && (! in_array($parts['scheme'], ['stuns', 'turns'], true) || ($parts['transport'] ?? 'tcp') === 'tcp');
    }

    /** @param array<string, mixed> $server
     * @return array{username: string, credential: string}
     */
    private function credentials(#[\SensitiveParameter] array $server): array
    {
        $username = $server['username'] ?? null;
        $credential = $server['credential'] ?? null;
        if (! is_string($username) || strlen($username) < 1 || strlen($username) > 512
            || ! is_string($credential) || strlen($credential) < 1 || strlen($credential) > 1024
            || preg_match('/[\x00-\x20\x7f]/', $username.$credential) === 1) {
            throw new ServiceUnavailableHttpException(null, self::UNAVAILABLE);
        }

        return ['username' => $username, 'credential' => $credential];
    }
}
