<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Tests\Unit;

use Fapost\Connector\Slack\Api\SlackApiClient;
use Fapost\Connector\Slack\SlackSender;
use Fapost\Connector\Slack\Tests\Support\Fixture;
use Fapost\Connector\Slack\Tests\Support\HttpTestHelpers;
use Fapost\Connector\Slack\Tests\Support\StubChannel;
use Fapost\Foundation\Messaging\Button;
use Fapost\Foundation\Messaging\ButtonSet;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\MessagePayload;
use Fapost\Foundation\Messaging\OutboundMessage;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

final class SlackSenderTest extends TestCase
{
    use HttpTestHelpers;

    private const string POST = 'slack.com/api/chat.postMessage';

    public function test_text_with_buttons_is_posted_to_the_user_and_the_answer_becomes_the_provider_message_id(): void
    {
        $http = $this->fakeHttp([self::POST => Factory::response(Fixture::json('api/chat_post_message_ok'))]);

        $result = $this->sender($http)->deliver($this->message(new MessagePayload(
            type: 'keyboard',
            text: 'Pick one & go',
            buttons: new ButtonSet([
                [new Button('Yes', payload: 's1:b1'), new Button('No', payload: 's1:b2')],
                [new Button('Maybe later', payload: 's1:b3')],
            ]),
        )), new StubChannel());

        $this->assertTrue($result->sent);
        $this->assertSame('D024BE91L:1721232000.123456', $result->providerMessageId);

        $sent = $this->sent($http);

        $this->assertCount(1, $sent);
        $this->assertSame('POST', $sent[0]->method());
        $this->assertSame('https://slack.com/api/chat.postMessage', $sent[0]->url());
        $this->assertSame('Bearer ' . StubChannel::TOKEN, $sent[0]->header('Authorization')[0]);
        $this->assertTrue($sent[0]->isJson());
        $this->assertEquals(Fixture::json('requests/chat_post_message_text_buttons'), $sent[0]->data());
    }

    public function test_plain_text_is_posted_as_a_section_and_its_fallback(): void
    {
        $http = $this->fakeHttp([self::POST => Factory::response(Fixture::json('api/chat_post_message_ok'))]);

        $this->sender($http)->deliver($this->message(new MessagePayload('text', 'Hi')), new StubChannel());

        $this->assertEquals([
            'channel' => 'U2147483697',
            'blocks'  => [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => 'Hi']]],
            'text'    => 'Hi',
        ], $this->sent($http)[0]->data());
    }

    public function test_parse_mode_html_is_translated_and_anything_else_is_escaped(): void
    {
        $http = $this->fakeHttp([self::POST => Factory::response(Fixture::json('api/chat_post_message_ok'))]);

        $this->sender($http)->deliver($this->message(new MessagePayload('text', '<b>Hi</b>'), ['parse_mode' => 'HTML']), new StubChannel());
        $this->sender($http)->deliver($this->message(new MessagePayload('text', '<b>Hi</b>')), new StubChannel());

        $sent = $this->sent($http);

        $this->assertSame('*Hi*', $sent[0]->data()['text']);
        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', $sent[1]->data()['text']);
    }

    public function test_an_image_by_link_is_posted_as_an_image_block(): void
    {
        $http = $this->fakeHttp([self::POST => Factory::response(Fixture::json('api/chat_post_message_ok'))]);

        $result = $this->sender($http)->deliver($this->message(new MessagePayload(
            type: 'photo',
            text: '<b>Our</b> cat',
            media: ['url' => 'https://example.test/m/cat.png?sig=abc', 'mime_type' => 'image/png', 'size' => 9, 'file_name' => 'cat.png', 'kind' => 'image'],
        ), ['parse_mode' => 'HTML']), new StubChannel());

        $this->assertTrue($result->sent);
        $this->assertEquals(Fixture::json('requests/chat_post_message_image'), $this->sent($http)[0]->data());
    }

    public function test_a_document_by_link_is_posted_as_a_link_section(): void
    {
        $http = $this->fakeHttp([self::POST => Factory::response(Fixture::json('api/chat_post_message_ok'))]);

        $this->sender($http)->deliver($this->message(new MessagePayload(
            type: 'document',
            text: '',
            media: ['url' => 'https://example.test/m/abc?sig=1&x=2', 'mime_type' => 'application/pdf', 'size' => 9, 'file_name' => 'price list.pdf', 'kind' => 'document'],
        )), new StubChannel());

        $this->assertEquals(Fixture::json('requests/chat_post_message_link'), $this->sent($http)[0]->data());
    }

    public function test_removing_the_buttons_reads_the_message_and_updates_it_without_its_actions_block(): void
    {
        $http = $this->fakeHttp([
            'slack.com/api/conversations.history' => Factory::response(Fixture::json('api/conversations_history_with_buttons')),
            'slack.com/api/chat.update'           => Factory::response(Fixture::json('api/chat_update_ok')),
        ]);

        $result = $this->sender($http)->deliver($this->message(
            new MessagePayload('remove_keyboard', ''),
            ['edit_message_id' => 'D024BE91L:1721232000.123456'],
        ), new StubChannel());

        $this->assertTrue($result->sent);
        $this->assertSame('D024BE91L:1721232000.123456', $result->providerMessageId);

        $sent = $this->sent($http);

        $this->assertCount(2, $sent);
        $this->assertSame('https://slack.com/api/conversations.history', $sent[0]->url());
        $this->assertEquals(Fixture::json('requests/conversations_history'), $sent[0]->data());
        $this->assertSame('https://slack.com/api/chat.update', $sent[1]->url());
        $this->assertEquals(Fixture::json('requests/chat_update_remove_buttons'), $sent[1]->data());
    }

    public function test_a_message_that_has_no_buttons_is_not_updated(): void
    {
        $http = $this->fakeHttp(['slack.com/api/*' => Factory::response(Fixture::json('api/conversations_history_without_buttons'))]);

        $result = $this->sender($http)->deliver($this->message(
            new MessagePayload('remove_keyboard', ''),
            ['edit_message_id' => 'D024BE91L:1721232000.123456'],
        ), new StubChannel());

        $this->assertTrue($result->sent);
        $this->assertCount(1, $this->sent($http));
    }

    public function test_a_message_that_is_gone_fails_without_an_update(): void
    {
        $http = $this->fakeHttp(['slack.com/api/*' => Factory::response(['ok' => true, 'messages' => [], 'has_more' => false])]);

        $result = $this->sender($http)->deliver($this->message(
            new MessagePayload('remove_keyboard', ''),
            ['edit_message_id' => 'D024BE91L:1721232000.123456'],
        ), new StubChannel());

        $this->assertFalse($result->sent);
        $this->assertSame('Slack API conversations.history failed: message_not_found', $result->error);
        $this->assertCount(1, $this->sent($http));
    }

    public function test_a_provider_message_id_that_is_not_a_slack_reference_fails_without_a_request(): void
    {
        $http = $this->fakeHttp([]);

        $result = $this->sender($http)->deliver($this->message(new MessagePayload('remove_keyboard', ''), ['edit_message_id' => '12345']), new StubChannel());

        $this->assertFalse($result->sent);
        $this->assertSame([], $this->sent($http));
    }

    public function test_an_api_error_is_a_failed_result_with_slacks_code_and_without_the_token(): void
    {
        $http = $this->fakeHttp([self::POST => Factory::response(Fixture::json('api/error_invalid_auth'))]);

        $result = $this->sender($http)->deliver($this->message(new MessagePayload('text', 'Hi')), new StubChannel());

        $this->assertFalse($result->sent);
        $this->assertNull($result->providerMessageId);
        $this->assertSame('Slack API chat.postMessage failed: invalid_auth', $result->error);
        $this->assertStringNotContainsString(StubChannel::TOKEN, (string) $result->error);
    }

    public function test_a_rate_limit_reports_how_long_slack_asked_to_wait(): void
    {
        $http = $this->fakeHttp([self::POST => Factory::response(Fixture::json('api/error_ratelimited'), 429, ['Retry-After' => '30'])]);

        $result = $this->sender($http)->deliver($this->message(new MessagePayload('text', 'Hi')), new StubChannel());

        $this->assertFalse($result->sent);
        $this->assertSame('Slack API chat.postMessage failed: ratelimited (retry after 30s)', $result->error);
        $this->assertCount(1, $this->sent($http), 'a rate limit is not retried in the same call');
    }

    public function test_a_payload_type_slack_cannot_send_fails_loudly(): void
    {
        $http = $this->fakeHttp([]);

        $result = $this->sender($http)->deliver($this->message(new MessagePayload('contact', '')), new StubChannel());

        $this->assertFalse($result->sent);
        $this->assertSame('Unsupported Slack payload type [contact].', $result->error);
        $this->assertSame([], $this->sent($http));
    }

    public function test_media_without_a_link_fails_and_never_reaches_slack(): void
    {
        $http = $this->fakeHttp([]);

        $result = $this->sender($http)->deliver($this->message(new MessagePayload('photo', '', media: ['photo' => 'AgAC'])), new StubChannel());

        $this->assertFalse($result->sent);
        $this->assertSame([], $this->sent($http));
    }

    public function test_a_missing_token_fails_without_a_request(): void
    {
        $http = $this->fakeHttp([]);

        $result = $this->sender($http)->deliver($this->message(new MessagePayload('text', 'Hi')), new StubChannel(''));

        $this->assertFalse($result->sent);
        $this->assertSame('Slack bot token is missing.', $result->error);
        $this->assertSame([], $this->sent($http));
    }

    public function test_a_failed_result_never_carries_the_media_link(): void
    {
        $http = $this->fakeHttp([self::POST => Factory::response(['ok' => false, 'error' => 'invalid_blocks'])]);

        $result = $this->sender($http)->deliver($this->message(new MessagePayload(
            type: 'photo',
            text: '',
            media: ['url' => 'https://example.test/m/secret-signed-link', 'mime_type' => 'image/png', 'file_name' => 'a.png'],
        )), new StubChannel());

        $this->assertFalse($result->sent);
        $this->assertStringNotContainsString('secret-signed-link', (string) $result->error);
    }

    public function test_the_result_is_a_delivery_result_even_when_slack_answers_without_a_ts(): void
    {
        $http = $this->fakeHttp([self::POST => Factory::response(['ok' => true])]);

        $result = $this->sender($http)->deliver($this->message(new MessagePayload('text', 'Hi')), new StubChannel());

        $this->assertInstanceOf(DeliveryResult::class, $result);
        $this->assertTrue($result->sent);
        $this->assertNull($result->providerMessageId);
    }

    private function sender(Factory $http): SlackSender
    {
        return new SlackSender(new SlackApiClient($http));
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function message(MessagePayload $payload, array $metadata = []): OutboundMessage
    {
        return new OutboundMessage(
            idempotencyKey: 'k1',
            tenantId: 't1',
            channelId: '0190c1a2-0000-7000-8000-000000000001',
            channelType: 'slack',
            chatId: 'U2147483697',
            payload: $payload,
            metadata: $metadata,
        );
    }
}
