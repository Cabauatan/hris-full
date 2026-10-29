<?php

namespace Tests\Feature;

use App\Models\RecruitmentInterview;
use App\Services\Recruitment\RecruitmentIceService;
use App\Services\Recruitment\RecruitmentInterviewService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\FormRequestTestCase;

class RecruitmentIceServiceTest extends FormRequestTestCase
{
    private const KEY_ID = '0123456789abcdef0123456789abcdef';

    private const TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private const ENDPOINT = 'https://rtc.live.cloudflare.com/v1/turn/keys/0123456789abcdef0123456789abcdef/credentials/generate-ice-servers';

    private const UNAVAILABLE = 'The video connection service is unavailable. Please try joining again shortly.';

    public function test_static_stun_and_explicit_generic_turn_are_returned_without_calling_a_provider(): void
    {
        $servers = [
            ['urls' => ['stun:stun.cloudflare.com:3478']],
            ['urls' => 'turns:relay.example.test:443?transport=tcp', 'username' => 'fixture-user', 'credential' => 'fixture-password'],
            ['urls' => 'turn:[2001:db8::1]:3478?transport=udp', 'username' => 'fixture-user', 'credential' => 'fixture-password'],
        ];
        config(['recruitment.ice_servers' => $servers, 'recruitment.turn' => ['key_id' => '', 'api_token' => '']]);

        $result = app(RecruitmentIceService::class)->servers(CarbonImmutable::parse('2026-10-29 10:00:00'));

        $this->assertSame($servers, $result);
        Http::assertNothingSent();
    }

    public function test_an_explicit_empty_ice_list_keeps_local_only_calls_and_never_issues_credentials(): void
    {
        config(['recruitment.ice_servers' => [], 'recruitment.turn' => ['key_id' => '', 'api_token' => '']]);

        $result = app(RecruitmentIceService::class)->servers(CarbonImmutable::parse('2026-10-29 10:00:00'));

        $this->assertSame([], $result);
        Http::assertNothingSent();
    }

    public function test_unset_ice_json_uses_free_stun_and_explicit_empty_json_is_preserved(): void
    {
        $default = $this->iceConfiguration(null);
        $empty = $this->iceConfiguration('[]');
        config(['recruitment.ice_servers' => $default, 'recruitment.turn' => ['key_id' => '', 'api_token' => '']]);

        $result = app(RecruitmentIceService::class)->servers(CarbonImmutable::parse('2026-10-29 10:00:00'));

        $this->assertSame([['urls' => ['stun:stun.cloudflare.com:3478']]], $result);
        $this->assertSame([], $empty);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidIceJson')]
    public function test_invalid_ice_json_is_not_coerced_to_an_empty_list_and_returns_safe_503(string $json): void
    {
        config(['recruitment.ice_servers' => $this->iceConfiguration($json), 'recruitment.turn' => ['key_id' => '', 'api_token' => '']]);

        $this->assertUnavailable(fn (): array => app(RecruitmentIceService::class)->servers(CarbonImmutable::parse('2026-10-29 10:00:00')));

        Http::assertNothingSent();
    }

    #[DataProvider('invalidStaticServers')]
    public function test_invalid_static_ice_configuration_returns_safe_503_without_http(mixed $servers): void
    {
        config(['recruitment.ice_servers' => $servers, 'recruitment.turn' => ['key_id' => '', 'api_token' => '']]);

        $this->assertUnavailable(fn (): array => app(RecruitmentIceService::class)->servers(CarbonImmutable::parse('2026-10-29 10:00:00')));

        Http::assertNothingSent();
    }

    #[DataProvider('invalidTurnConfiguration')]
    public function test_partial_or_malformed_dedicated_turn_configuration_returns_safe_503_without_http(mixed $keyId, mixed $token): void
    {
        config(['recruitment.ice_servers' => [], 'recruitment.turn' => ['key_id' => $keyId, 'api_token' => $token]]);

        $this->assertUnavailable(fn (): array => app(RecruitmentIceService::class)->servers(CarbonImmutable::parse('2026-10-29 10:00:00')));

        Http::assertNothingSent();
    }

    public function test_cloudflare_credentials_are_short_lived_and_only_allowlisted_urls_and_fields_reach_the_browser(): void
    {
        $this->travelTo('2026-10-29 09:00:00');
        $this->configureTurn([['urls' => ['stun:stun.cloudflare.com:3478']]]);
        $body = $this->providerBody();
        $body['iceServers'][0]['secret'] = 'provider-only-field';
        $body['iceServers'][1]['urls'][] = 'turn:turn.cloudflare.com:53?transport=udp';
        $body['iceServers'][1]['urls'][] = 'turn:untrusted.example.test:3478?transport=udp';
        $body['iceServers'][1]['apiToken'] = self::TOKEN;
        $body['private'] = self::TOKEN;
        Http::fake([self::ENDPOINT => Http::response($body, 201)]);

        $result = app(RecruitmentIceService::class)->servers(CarbonImmutable::parse('2026-10-29 10:00:00'));

        $this->assertSame([
            ['urls' => ['stun:stun.cloudflare.com:3478']],
            ['urls' => ['stun:stun.cloudflare.com:3478']],
            ['urls' => ['turn:turn.cloudflare.com:3478?transport=udp', 'turns:turn.cloudflare.com:443?transport=tcp'], 'username' => 'short-lived-user', 'credential' => 'short-lived-password'],
        ], $result);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::ENDPOINT
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && $request->data() === ['ttl' => 3720]);
        Http::assertSentCount(1);
    }

    #[DataProvider('credentialWindows')]
    public function test_turn_expiry_is_bounded_to_the_remaining_room_window(string $until, int $expectedTtl): void
    {
        $this->travelTo('2026-10-29 09:00:00');
        $this->configureTurn();
        Http::fake([self::ENDPOINT => Http::response($this->providerBody(), 201)]);

        app(RecruitmentIceService::class)->servers(CarbonImmutable::parse($until));

        Http::assertSent(fn (Request $request): bool => $request->data() === ['ttl' => $expectedTtl]);
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failure_returns_safe_503_without_including_the_response_or_secret(int $status, string $body): void
    {
        $this->travelTo('2026-10-29 09:00:00');
        $this->configureTurn();
        Http::fake([self::ENDPOINT => Http::response($body, $status, ['Location' => 'https://untrusted.example.test/private'])]);

        $this->assertUnavailable(fn (): array => app(RecruitmentIceService::class)->servers(CarbonImmutable::parse('2026-10-29 10:00:00')));

        Http::assertSentCount(1);
    }

    public function test_connection_exception_details_are_discarded_and_not_chained_to_the_safe_503(): void
    {
        $this->configureTurn();
        $attempts = 0;
        Http::fake([self::ENDPOINT => function () use (&$attempts): never {
            $attempts++;
            throw new ConnectionException('Sensitive request details '.self::TOKEN);
        }]);

        $this->assertUnavailable(fn (): array => app(RecruitmentIceService::class)->servers(CarbonImmutable::parse('2026-10-29 10:00:00')));

        $this->assertSame(1, $attempts);
    }

    #[DataProvider('invalidProviderBodies')]
    public function test_malformed_or_unsafe_provider_credentials_return_safe_503(array $body): void
    {
        $this->configureTurn();
        Http::fake([self::ENDPOINT => Http::response($body, 201)]);

        $this->assertUnavailable(fn (): array => app(RecruitmentIceService::class)->servers(CarbonImmutable::parse('2026-10-29 10:00:00')));

        Http::assertSentCount(1);
    }

    public function test_an_unassigned_actor_cannot_cause_the_server_to_issue_turn_credentials(): void
    {
        $this->travelTo('2026-10-29 09:00:00');
        $this->configureTurn();
        $staff = $this->user('recruitment.manage');
        $interview = RecruitmentInterview::factory()->create(['scheduled_at' => now()]);

        try {
            app(RecruitmentInterviewService::class)->join($interview, $staff);
            $this->fail('Unassigned staff must not enter the room.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        Http::assertNothingSent();
        $this->assertNull($interview->refresh()->started_at);
    }

    public function test_join_calls_the_fixed_endpoint_without_holding_room_locks_and_rechecks_removed_assignment(): void
    {
        $this->travelTo('2026-10-29 09:00:00');
        $this->configureTurn();
        $staff = $this->user('recruitment.manage');
        $interview = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create(['scheduled_at' => now()]);
        $transactionLevel = DB::transactionLevel();
        Http::fake([self::ENDPOINT => function (Request $request, array $options) use ($interview, $staff, $transactionLevel) {
            $this->assertSame($transactionLevel, DB::transactionLevel());
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(2, $options['connect_timeout']);
            $this->assertSame(5, $options['timeout']);
            $interview->interviewers()->detach($staff);

            return Http::response($this->providerBody(), 201);
        }]);

        try {
            app(RecruitmentInterviewService::class)->join($interview, $staff);
            $this->fail('Assignment removal during issuance must prevent admission.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        Http::assertSentCount(1);
        $this->assertNull($interview->refresh()->started_at);
    }

    public function test_api_join_reports_safe_503_and_does_not_start_the_interview_if_turn_is_unavailable(): void
    {
        $this->travelTo('2026-10-29 09:00:00');
        config(['app.debug' => false]);
        $this->configureTurn();
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $interview = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create(['scheduled_at' => now()]);
        Http::fake([self::ENDPOINT => Http::response(['error' => self::TOKEN, 'private' => 'provider diagnostics'], 500)]);

        $this->actingAs($staff, 'web')->postJson('/api/v1/recruitment/interviews/'.$interview->id.'/join')
            ->assertServiceUnavailable()->assertExactJson(['message' => self::UNAVAILABLE]);

        Http::assertSentCount(1);
        $this->assertNull($interview->refresh()->started_at);
    }

    /** @param list<array<string, mixed>> $servers */
    private function configureTurn(array $servers = []): void
    {
        config(['recruitment.ice_servers' => $servers, 'recruitment.turn' => ['key_id' => self::KEY_ID, 'api_token' => self::TOKEN]]);
    }

    /** @return array<string, mixed> */
    private function providerBody(): array
    {
        return ['iceServers' => [
            ['urls' => ['stun:stun.cloudflare.com:3478']],
            ['urls' => ['turn:turn.cloudflare.com:3478?transport=udp', 'turns:turn.cloudflare.com:443?transport=tcp'], 'username' => 'short-lived-user', 'credential' => 'short-lived-password'],
        ]];
    }

    private function assertUnavailable(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a safe service-unavailable response.');
        } catch (ServiceUnavailableHttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertSame(self::UNAVAILABLE, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    private function iceConfiguration(?string $value): mixed
    {
        $name = 'RECRUITMENT_ICE_SERVERS';
        $previous = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
        unset($_ENV[$name], $_SERVER[$name]);
        if ($value !== null) {
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        putenv($value === null ? $name : $name.'='.$value);

        try {
            return (require config_path('recruitment.php'))['ice_servers'];
        } finally {
            [$env, $server, $process] = $previous;
            unset($_ENV[$name], $_SERVER[$name]);
            if ($env !== null) {
                $_ENV[$name] = $env;
            }
            if ($server !== null) {
                $_SERVER[$name] = $server;
            }
            putenv($process === false ? $name : $name.'='.$process);
        }
    }

    public static function invalidIceJson(): array
    {
        return [
            'malformed JSON' => ['{not-json'],
            'nonlist object' => ['{"urls":"stun:example.test:3478"}'],
            'null value' => ['null'],
        ];
    }

    public static function invalidStaticServers(): array
    {
        return [
            'not a list' => [['urls' => 'stun:example.test:3478']],
            'not an array' => ['not-json'],
            'http URL' => [[['urls' => 'https://example.test/credentials']]],
            'credentials in URL' => [[['urls' => 'turn:user:secret@example.test:3478']]],
            'TURN without credentials' => [[['urls' => 'turn:example.test:3478']]],
            'invalid port' => [[['urls' => 'stun:example.test:65536']]],
            'invalid IPv6' => [[['urls' => 'turn:[...]:3478', 'username' => 'fixture', 'credential' => 'fixture']]],
            'unexpected field' => [[['urls' => 'stun:example.test:3478', 'private' => 'not-public']]],
            'empty URLs' => [[['urls' => []]]],
            'URL not a string' => [[['urls' => [17]]]],
            'too many servers' => [array_fill(0, 9, ['urls' => 'stun:example.test:3478'])],
            'unsupported credential type' => [[['urls' => 'turn:example.test:3478', 'username' => 'fixture', 'credential' => 'fixture', 'credentialType' => 'oauth']]],
            'control character credential' => [[['urls' => 'turn:example.test:3478', 'username' => 'fixture', 'credential' => "fixture\nsecret"]]],
            'TLS over UDP' => [[['urls' => 'turns:example.test:443?transport=udp', 'username' => 'fixture', 'credential' => 'fixture']]],
        ];
    }

    public static function invalidTurnConfiguration(): array
    {
        return [
            'missing token' => [self::KEY_ID, ''],
            'missing ID' => ['', self::TOKEN],
            'unsafe ID' => ['../'.self::KEY_ID, self::TOKEN],
            'token injection' => [self::KEY_ID, self::TOKEN."\r\nInjected: value"],
            'wrong ID type' => [[], self::TOKEN],
            'wrong token type' => [self::KEY_ID, []],
        ];
    }

    public static function credentialWindows(): array
    {
        return [
            'remaining window plus grace' => ['2026-10-29 10:00:00', 3720],
            'absolute maximum' => ['2026-10-30 09:00:00', 10800],
            'near expiry' => ['2026-10-29 09:00:01', 121],
            'minimum bounded credential' => ['2026-10-29 08:00:00', 60],
        ];
    }

    public static function providerFailures(): array
    {
        return [
            'rate limited' => [429, '{"error":"provider secret"}'],
            'server failure' => [500, '{"error":"provider secret"}'],
            'redirect refused' => [302, 'redirect'],
            'invalid JSON' => [201, 'not JSON'],
            'oversized response' => [201, str_repeat('x', 32769)],
        ];
    }

    public static function invalidProviderBodies(): array
    {
        return [
            'missing list' => [[]],
            'list is not an array' => [['iceServers' => 'malformed']],
            'empty list' => [['iceServers' => []]],
            'missing credential' => [['iceServers' => [['urls' => ['turns:turn.cloudflare.com:443?transport=tcp'], 'username' => 'short-lived-user']]]],
            'no allowed relay' => [['iceServers' => [['urls' => ['turn:untrusted.example.test:3478'], 'username' => 'short-lived-user', 'credential' => 'short-lived-password']]]],
            'permanent secret reflected' => [['iceServers' => [['urls' => ['turns:turn.cloudflare.com:443?transport=tcp'], 'username' => 'short-lived-user', 'credential' => self::TOKEN]]]],
        ];
    }
}
