<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Tests\Feature;

use App\Domains\Channels\ConnectorChannels;
use Fapost\Connector\Slack\SlackChannelProfile;
use Fapost\Connector\Slack\SlackConnectorServiceProvider;
use Fapost\Connector\Slack\Tests\Support\Fixture;
use Fapost\Foundation\Channel\ChannelInterface;
use Fapost\Foundation\Channel\InboundEventIgnoredException;
use Fapost\Foundation\Connector\ConnectorChannel;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\Messaging\Button;
use Fapost\Foundation\Messaging\ButtonSet;
use Fapost\Foundation\Messaging\MessagePayload;
use Fapost\Foundation\Messaging\OutboundMessage;
use Fapost\Testing\ConnectorTestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The Slack channel inside the real Core application, through the real webhook route, Core's message sender and
 * Core's webhook registration; Slack itself is faked with the recorded responses.
 */
final class SlackChannelTest extends ConnectorTestCase
{
    private const string SIGNING_SECRET = '8f742231b10e8888abcd99aaaaaa85a5';

    private const string TOKEN = 'xoxb-test-token-0000';

    protected function setUp(): void
    {
        parent::setUp();

        // What the provider does at boot: the kit starts from a Core that has the connector installed, but this
        // package is not in Core's composer when the test runs, so it registers the channel the provider declares.
        $channels = $this->registeredChannel();

        $this->app->make(ConnectorChannels::class)->add($channels, 'slack', 'Slack');

        Http::preventStrayRequests();
        // Creating a channel runs its webhook registrar, which asks Slack who the token belongs to.
        Http::fake([
            'slack.com/api/auth.test' => Http::response(Fixture::json('api/auth_test_ok')),
            'slack.com/api/bots.info' => Http::response(Fixture::json('api/bots_info_ok')),
        ]);
    }

    public function test_a_signed_message_is_queued_with_its_event_and_normalizes_to_text(): void
    {
        $channel = $this->slackChannel();
        $event   = Fixture::json('events/message_im_text');

        $this->postSigned($channel, (string) json_encode($event))->assertOk()->assertExactJson(['ok' => true]);

        $queued = $this->dispatchedInbound();

        $this->assertCount(1, $queued);
        $this->assertSame('slack', $queued[0]->platform);
        $this->assertSame($event, $queued[0]->rawPayload);
        $this->assertSame('slack:' . $channel->getId() . ':Ev0PV52K21', $queued[0]->idempotencyKey);

        $message = $this->normalizeInbound($queued[0]);

        $this->assertSame(IncomingMessageType::Text, $message->type);
        $this->assertSame('Hello hello can you hear me?', $message->text);
        $this->assertSame('U2147483697', $message->externalUserId);
    }

    public function test_url_verification_is_answered_with_the_challenge_and_queues_nothing(): void
    {
        $channel   = $this->slackChannel();
        $challenge = Fixture::json('events/url_verification');

        $response = $this->postSigned($channel, (string) json_encode($challenge));

        $response->assertOk();
        $this->assertSame($challenge['challenge'], $response->getContent());
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $this->assertSame([], $this->dispatchedInbound());
    }

    public function test_an_unsigned_url_verification_gets_no_challenge(): void
    {
        $channel   = $this->slackChannel();
        $challenge = Fixture::json('events/url_verification');

        $response = $this->postWebhook($channel, (string) json_encode($challenge), [
            'X-Slack-Request-Timestamp' => (string) time(),
            'X-Slack-Signature'         => 'v0=' . str_repeat('0', 64),
        ]);

        $response->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame([], $this->dispatchedInbound());
    }

    public function test_a_button_press_arrives_as_a_form_and_its_payload_is_the_document(): void
    {
        $channel = $this->slackChannel();
        $press   = Fixture::json('interactivity/block_actions_fp');

        $this->postSigned($channel, 'payload=' . rawurlencode((string) json_encode($press)), 0, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ])->assertOk()->assertExactJson(['ok' => true]);

        $queued = $this->dispatchedInbound();

        $this->assertCount(1, $queued);
        $this->assertSame($press, $queued[0]->rawPayload);

        $message = $this->normalizeInbound($queued[0]);

        $this->assertSame(IncomingMessageType::CallbackQuery, $message->type);
        $this->assertSame('s1:b2', $message->text);
        $this->assertSame('D024BE91L:1721232000.123456', $message->payload['message_id']);
    }

    public function test_a_stale_request_is_answered_ok_and_queues_nothing(): void
    {
        $channel = $this->slackChannel();

        $this->postSigned($channel, (string) json_encode(Fixture::json('events/message_im_text')), -301)
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        $this->assertSame([], $this->dispatchedInbound());
    }

    public function test_a_request_just_inside_the_window_is_accepted(): void
    {
        $channel = $this->slackChannel();

        $this->postSigned($channel, (string) json_encode(Fixture::json('events/message_im_text')), -290)->assertOk();

        $this->assertCount(1, $this->dispatchedInbound());
    }

    public function test_a_request_signed_with_another_secret_queues_nothing(): void
    {
        $channel = $this->slackChannel();
        $body    = (string) json_encode(Fixture::json('events/message_im_text'));
        $time    = time();

        $this->postWebhook($channel, $body, [
            'X-Slack-Request-Timestamp' => (string) $time,
            'X-Slack-Signature'         => 'v0=' . hash_hmac('sha256', "v0:{$time}:{$body}", str_repeat('1', 32)),
        ])->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame([], $this->dispatchedInbound());
    }

    public function test_a_retried_event_is_queued_once(): void
    {
        $channel = $this->slackChannel();
        $body    = (string) json_encode(Fixture::json('events/message_im_text'));

        $this->postSigned($channel, $body)->assertOk();
        $this->postSigned($channel, $body, 0, ['X-Slack-Retry-Num' => '1', 'X-Slack-Retry-Reason' => 'http_timeout'])->assertOk();

        $this->assertCount(1, $this->dispatchedInbound());
    }

    public function test_the_apps_own_message_is_ignored_when_the_job_normalizes_it(): void
    {
        $channel = $this->slackChannel();

        $this->postSigned($channel, (string) json_encode(Fixture::json('events/message_im_bot')))->assertOk();

        $queued = $this->dispatchedInbound();

        $this->assertCount(1, $queued);

        $this->expectException(InboundEventIgnoredException::class);

        $this->normalizeInbound($queued[0]);
    }

    public function test_text_with_buttons_is_posted_to_slack_and_the_message_id_is_returned(): void
    {
        $channel = $this->slackChannel();

        Http::fake(['slack.com/api/chat.postMessage' => Http::response(Fixture::json('api/chat_post_message_ok'))]);

        $result = $this->send($this->outbound($channel, new MessagePayload(
            type: 'keyboard',
            text: 'Pick one & go',
            buttons: new ButtonSet([
                [new Button('Yes', payload: 's1:b1'), new Button('No', payload: 's1:b2')],
                [new Button('Maybe later', payload: 's1:b3')],
            ]),
        )));

        $this->assertTrue($result->sent);
        $this->assertSame('D024BE91L:1721232000.123456', $result->providerMessageId);

        $request = $this->slackRequest('chat.postMessage');

        $this->assertSame('Bearer ' . self::TOKEN, $request->header('Authorization')[0]);
        $this->assertEquals(Fixture::json('requests/chat_post_message_text_buttons'), $request->data());
    }

    public function test_the_buttons_of_a_sent_message_are_removed_with_a_history_read_and_an_update(): void
    {
        $channel = $this->slackChannel();

        Http::fake([
            'slack.com/api/conversations.history' => Http::response(Fixture::json('api/conversations_history_with_buttons')),
            'slack.com/api/chat.update'           => Http::response(Fixture::json('api/chat_update_ok')),
        ]);

        $result = $this->send($this->outbound(
            $channel,
            new MessagePayload('remove_keyboard', ''),
            ['edit_message_id' => 'D024BE91L:1721232000.123456'],
        ));

        $this->assertTrue($result->sent);

        $this->assertEquals(Fixture::json('requests/conversations_history'), $this->slackRequest('conversations.history')->data());
        $this->assertEquals(Fixture::json('requests/chat_update_remove_buttons'), $this->slackRequest('chat.update')->data());
    }

    public function test_a_slack_error_is_a_failed_delivery_without_the_token(): void
    {
        $channel = $this->slackChannel();

        Http::fake(['slack.com/api/chat.postMessage' => Http::response(Fixture::json('api/error_invalid_auth'))]);

        $result = $this->send($this->outbound($channel, new MessagePayload('text', 'Hi')));

        $this->assertFalse($result->sent);
        $this->assertStringContainsString('invalid_auth', (string) $result->error);
        $this->assertStringNotContainsString(self::TOKEN, (string) $result->error);
    }

    public function test_registering_the_webhook_stores_what_slack_says_about_the_workspace_and_the_app(): void
    {
        $channel = $this->slackChannel();

        $this->registerWebhook($channel);

        $config = $this->channelConfig($channel);

        $this->assertSame('T123ABC456', $config['team_id']);
        $this->assertSame('Acme Workspace', $config['team_name']);
        $this->assertSame('https://acme.slack.com/', $config['team_url']);
        $this->assertSame('U0LAN0Z89', $config['bot_user_id']);
        $this->assertSame('B12345678', $config['bot_id']);
        $this->assertSame('FaPost Assistant', $config['bot_name']);
        $this->assertSame('A123ABC456', $config['app_id']);

        $link = (new SlackChannelProfile())->publicLink($config);

        $this->assertSame('https://slack.com/app_redirect?app=A123ABC456&team=T123ABC456', $link?->url);
    }

    private function registeredChannel(): ConnectorChannel
    {
        $registered = [];

        $registrar = new class ($registered) implements \Fapost\Foundation\Connector\ConnectorRegistrarInterface {
            /** @param  list<ConnectorChannel>  $channels */
            public function __construct(public array &$channels)
            {
            }

            public function registerChannel(ConnectorChannel $channel): void
            {
                $this->channels[] = $channel;
            }
        };

        (new SlackConnectorServiceProvider($this->app))->registerChannelsWith($registrar);

        return $registered[0];
    }

    private function slackRequest(string $method): Request
    {
        $found = Http::recorded()
            ->map(static fn (array $pair): Request => $pair[0])
            ->filter(static fn (Request $request): bool => "https://slack.com/api/{$method}" === $request->url());

        $this->assertCount(1, $found, "Expected exactly one call of {$method}.");

        return $found->first();
    }

    private function slackChannel(): ChannelInterface
    {
        return $this->createChannel('slack', self::TOKEN, self::SIGNING_SECRET);
    }

    /**
     * Posts the body signed the way Slack signs it, `$offset` seconds from now.
     *
     * @param  array<string, string>  $headers
     */
    private function postSigned(ChannelInterface $channel, string $body, int $offset = 0, array $headers = []): \Illuminate\Testing\TestResponse
    {
        $time = time() + $offset;

        return $this->postWebhook($channel, $body, $headers + [
            'X-Slack-Request-Timestamp' => (string) $time,
            'X-Slack-Signature'         => 'v0=' . hash_hmac('sha256', "v0:{$time}:{$body}", self::SIGNING_SECRET),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function outbound(ChannelInterface $channel, MessagePayload $payload, array $metadata = []): OutboundMessage
    {
        return new OutboundMessage(
            idempotencyKey: 'slack-' . bin2hex(random_bytes(6)),
            tenantId: $this->tenantId(),
            channelId: $channel->getId(),
            channelType: 'slack',
            chatId: 'U2147483697',
            payload: $payload,
            metadata: $metadata,
        );
    }
}
