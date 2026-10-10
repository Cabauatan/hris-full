<?php

namespace Tests\Feature;

use App\Services\Telegram\TelegramClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\FormRequestTestCase;

class TelegramCommandMenuTest extends FormRequestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['telegram.enabled' => true, 'telegram.bot_token' => '12345:test_token', 'telegram.bot_username' => 'example_bot', 'telegram.bot_id' => 12345]);
    }

    public function test_registration_publishes_private_chat_commands_and_checks_the_complete_menu(): void
    {
        $this->fakeMenu();

        $this->artisan('telegram:commands')->expectsOutputToContain('registered and verified')->assertExitCode(0);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/setMyCommands')
            && $request['commands'] === $this->menu() && $request['scope'] === ['type' => 'all_private_chats'] && $request['language_code'] === '');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/getMyCommands')
            && $request['scope'] === ['type' => 'all_private_chats'] && $request['language_code'] === '');
        Http::assertSentCount(3);
    }

    public function test_check_reads_the_menu_without_overwriting_commands_or_sending_messages(): void
    {
        $this->fakeMenu();

        $this->artisan('telegram:commands', ['--check' => true])->expectsOutputToContain('up to date')->assertExitCode(0);

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/setMyCommands') || str_ends_with($request->url(), '/sendMessage'));
        Http::assertSentCount(2);
    }

    public function test_a_changed_description_makes_check_fail_without_overwriting_the_remote_menu(): void
    {
        $menu = $this->menu();
        $menu[0]['description'] = 'A different description';
        $this->fakeMenu(getResult: $menu);

        $this->artisan('telegram:commands', ['--check' => true])->expectsOutputToContain('does not match')->assertExitCode(1);

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/setMyCommands'));
        Http::assertSentCount(2);
    }

    public function test_registration_fails_if_the_readback_does_not_match_the_requested_menu(): void
    {
        $this->fakeMenu(getResult: []);

        $this->artisan('telegram:commands')->expectsOutputToContain('does not match')->assertExitCode(1);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/setMyCommands'));
        Http::assertSentCount(3);
    }

    public function test_disabled_configuration_fails_before_any_api_call(): void
    {
        config(['telegram.enabled' => false]);
        Http::preventStrayRequests();

        $this->artisan('telegram:commands')->expectsOutputToContain('not configured')->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_configuration_check_succeeds_without_contacting_telegram_or_changing_the_menu(): void
    {
        Http::preventStrayRequests();

        $this->artisan('telegram:commands', ['--configured' => true])->expectsOutput('Telegram notifications are configured.')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_configuration_check_fails_for_a_disabled_bot_without_any_api_call(): void
    {
        config(['telegram.enabled' => false]);
        Http::preventStrayRequests();

        $this->artisan('telegram:commands', ['--configured' => true])->expectsOutput('Telegram notifications are not configured.')->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_a_different_bot_identity_cannot_change_the_command_menu(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/bot12345:test_token/getMe' => Http::response([
            'ok' => true, 'result' => ['id' => 54321, 'is_bot' => true, 'username' => 'other_bot'],
        ])]);

        $this->artisan('telegram:commands')->expectsOutputToContain('Telegram request failed (identity).')->assertExitCode(1);

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/setMyCommands'));
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidSetResults')]
    public function test_registration_requires_true_confirmation_from_the_bot_api(mixed $result): void
    {
        $this->fakeMenu(setResult: $result);

        $this->artisan('telegram:commands')->expectsOutputToContain('Telegram request failed (protocol).')->assertExitCode(1);

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/getMyCommands'));
        Http::assertSentCount(2);
    }

    public static function invalidSetResults(): array
    {
        return ['false' => [false], 'null' => [null], 'string' => ['true'], 'number' => [1]];
    }

    #[DataProvider('invalidMenus')]
    public function test_malformed_menu_responses_fail_with_a_sanitized_protocol_error(mixed $result): void
    {
        $this->fakeMenu(getResult: $result);

        $this->artisan('telegram:commands', ['--check' => true])->expectsOutputToContain('Telegram request failed (protocol).')->assertExitCode(1);

        Http::assertSentCount(2);
    }

    public static function invalidMenus(): array
    {
        return [
            'not an array' => ['private response'],
            'object rather than list' => [['command' => 'help', 'description' => 'Show help']],
            'missing fields' => [[[]]],
            'non-string command' => [[['command' => 1, 'description' => 'Show help']]],
            'non-string description' => [[['command' => 'help', 'description' => false]]],
            'invalid command' => [[['command' => '/HELP', 'description' => 'Show help']]],
            'empty description' => [[['command' => 'help', 'description' => '']]],
        ];
    }

    public function test_client_returns_only_command_and_description_fields(): void
    {
        $menu = $this->menu();
        $menu[0]['remote_metadata'] = 'Private response details';
        $this->fakeMenu(getResult: $menu);

        $commands = app(TelegramClient::class)->commands();

        $this->assertSame($this->menu(), $commands);
        Http::assertSentCount(2);
    }

    public function test_api_failure_discards_private_response_text(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/bot12345:test_token/getMe' => $this->identityResponse(),
            'https://api.telegram.org/bot12345:test_token/setMyCommands' => Http::response([
                'ok' => false, 'error_code' => 401, 'description' => 'Private response and test_token',
            ], 401),
        ]);

        $this->artisan('telegram:commands')->expectsOutput('Telegram request failed (api).')->assertExitCode(1);

        Http::assertSentCount(2);
    }

    public function test_connection_failure_discards_private_transport_details(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/bot12345:test_token/getMe' => $this->identityResponse(),
            'https://api.telegram.org/bot12345:test_token/setMyCommands' => Http::failedConnection('Private URL and test_token'),
        ]);

        $this->artisan('telegram:commands')->expectsOutput('Telegram request failed (connection).')->assertExitCode(1);

        Http::assertSentCount(2);
    }

    public function test_unexpected_failure_does_not_print_exception_details(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/bot12345:test_token/getMe' => $this->identityResponse(),
            'https://api.telegram.org/bot12345:test_token/setMyCommands' => function (): never {
                throw new RuntimeException('Private exception and test_token');
            },
        ]);

        $this->artisan('telegram:commands')->expectsOutput('Telegram command menu could not be verified.')->assertExitCode(1);

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/getMyCommands'));
    }

    private function fakeMenu(mixed $setResult = true, mixed $getResult = null): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/bot12345:test_token/getMe' => $this->identityResponse(),
            'https://api.telegram.org/bot12345:test_token/setMyCommands' => Http::response(['ok' => true, 'result' => $setResult]),
            'https://api.telegram.org/bot12345:test_token/getMyCommands' => Http::response(['ok' => true, 'result' => $getResult ?? $this->menu()]),
        ]);
    }

    private function identityResponse(): PromiseInterface
    {
        return Http::response(['ok' => true, 'result' => ['id' => 12345, 'username' => 'example_bot', 'is_bot' => true]]);
    }

    private function menu(): array
    {
        return [
            ['command' => 'start', 'description' => 'Start the HRIS bot and link your account'],
            ['command' => 'help', 'description' => 'Show available HRIS commands'],
            ['command' => 'status', 'description' => 'Check your HRIS notification connection'],
            ['command' => 'portal', 'description' => 'Open the employee or applicant portal'],
            ['command' => 'unlink', 'description' => 'Disconnect your HRIS notification links'],
            ['command' => 'stop', 'description' => 'Stop HRIS Telegram notifications'],
        ];
    }
}
