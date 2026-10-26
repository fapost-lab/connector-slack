<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Tests\Unit;

use Fapost\Connector\Slack\Api\SlackApiClient;
use Fapost\Connector\Slack\Api\SlackApiException;
use Fapost\Connector\Slack\SlackWebhookRegistrar;
use Fapost\Connector\Slack\Tests\Support\Fixture;
use Fapost\Connector\Slack\Tests\Support\HttpTestHelpers;
use Fapost\Connector\Slack\Tests\Support\RecordingManagedSettings;
use Fapost\Connector\Slack\Tests\Support\StubChannel;
use Fapost\Foundation\Channel\WebhookRegistrationPayload;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

final class SlackWebhookRegistrarTest extends TestCase
{
    use HttpTestHelpers;

    public function test_it_checks_the_token_reads_the_bot_and_reports_the_managed_settings(): void
    {
        $http = $this->fakeHttp([
            'slack.com/api/auth.test' => Factory::response(Fixture::json('api/auth_test_ok')),
            'slack.com/api/bots.info' => Factory::response(Fixture::json('api/bots_info_ok')),
        ]);
        $settings = new RecordingManagedSettings();

        (new SlackWebhookRegistrar(new SlackApiClient($http), $settings))->register($this->payload());

        $this->assertSame([[
            'chan-1',
            [
                'team_id'     => 'T123ABC456',
                'team_name'   => 'Acme Workspace',
                'team_url'    => 'https://acme.slack.com/',
                'bot_user_id' => 'U0LAN0Z89',
                'bot_id'      => 'B12345678',
                'bot_name'    => 'FaPost Assistant',
                'app_id'      => 'A123ABC456',
            ],
        ]], $settings->reports);

        $sent = $this->sent($http);

        $this->assertSame(['https://slack.com/api/auth.test', 'https://slack.com/api/bots.info'], array_map(static fn ($request): string => $request->url(), $sent));
        $this->assertSame('Bearer ' . StubChannel::TOKEN, $sent[0]->header('Authorization')[0]);
        $this->assertSame(['bot' => 'B12345678'], $sent[1]->data());
    }

    public function test_a_token_slack_refuses_is_an_exception_and_reports_nothing(): void
    {
        $http     = $this->fakeHttp(['slack.com/api/auth.test' => Factory::response(Fixture::json('api/error_invalid_auth'))]);
        $settings = new RecordingManagedSettings();

        try {
            (new SlackWebhookRegistrar(new SlackApiClient($http), $settings))->register($this->payload());
            $this->fail('The token should have been refused.');
        } catch (SlackApiException $exception) {
            $this->assertSame('invalid_auth', $exception->errorCode);
            $this->assertSame('Slack API auth.test failed: invalid_auth', $exception->getMessage());
            $this->assertStringNotContainsString(StubChannel::TOKEN, $exception->getMessage());
        }

        $this->assertSame([], $settings->reports);
        $this->assertCount(1, $this->sent($http));
    }

    public function test_a_user_token_is_refused_because_it_names_no_bot(): void
    {
        $http     = $this->fakeHttp(['slack.com/api/auth.test' => Factory::response(Fixture::json('api/auth_test_user_token'))]);
        $settings = new RecordingManagedSettings();

        $this->expectException(SlackApiException::class);
        $this->expectExceptionMessage('not a bot token');

        try {
            (new SlackWebhookRegistrar(new SlackApiClient($http), $settings))->register($this->payload());
        } finally {
            $this->assertSame([], $settings->reports);
        }
    }

    public function test_a_failure_of_bots_info_is_an_exception_and_reports_nothing(): void
    {
        $http = $this->fakeHttp([
            'slack.com/api/auth.test' => Factory::response(Fixture::json('api/auth_test_ok')),
            'slack.com/api/bots.info' => Factory::response(['ok' => false, 'error' => 'missing_scope', 'needed' => 'users:read']),
        ]);
        $settings = new RecordingManagedSettings();

        try {
            (new SlackWebhookRegistrar(new SlackApiClient($http), $settings))->register($this->payload());
            $this->fail('The scope is missing.');
        } catch (SlackApiException $exception) {
            $this->assertSame('missing_scope', $exception->errorCode);
        }

        $this->assertSame([], $settings->reports);
    }

    public function test_deregistering_calls_nothing(): void
    {
        $http = $this->fakeHttp([]);

        (new SlackWebhookRegistrar(new SlackApiClient($http), new RecordingManagedSettings()))->deregister($this->payload());

        $this->assertSame([], $this->sent($http));
    }

    private function payload(): WebhookRegistrationPayload
    {
        return new WebhookRegistrationPayload('chan-1', StubChannel::TOKEN, '8f742231b10e8888abcd99yyyzzz85a5', 'abc123');
    }
}
