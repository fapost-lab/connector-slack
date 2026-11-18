<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Tests\Unit;

use Fapost\Connector\Slack\Api\SlackApiClient;
use Fapost\Connector\Slack\Api\SlackApiException;
use Fapost\Connector\Slack\Tests\Support\HttpTestHelpers;
use Fapost\Connector\Slack\Tests\Support\StubChannel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

final class SlackApiClientTest extends TestCase
{
    use HttpTestHelpers;

    public function test_a_json_call_sends_a_json_body_with_the_bearer_token(): void
    {
        $http = $this->fakeHttp(['slack.com/api/chat.update' => Factory::response(['ok' => true, 'ts' => '1.2'])]);

        $answer = (new SlackApiClient($http))->postJson(StubChannel::TOKEN, 'chat.update', ['channel' => 'D1']);

        $this->assertSame(['ok' => true, 'ts' => '1.2'], $answer);

        $request = $this->sent($http)[0];

        $this->assertTrue($request->isJson());
        $this->assertSame('Bearer ' . StubChannel::TOKEN, $request->header('Authorization')[0]);
        $this->assertSame(['channel' => 'D1'], $request->data());
    }

    public function test_a_form_call_sends_form_fields(): void
    {
        $http = $this->fakeHttp(['slack.com/api/files.info' => Factory::response(['ok' => true])]);

        (new SlackApiClient($http))->postForm(StubChannel::TOKEN, 'files.info', ['file' => 'F1']);

        $request = $this->sent($http)[0];

        $this->assertTrue($request->isForm());
        $this->assertSame('file=F1', $request->body());
    }

    public function test_a_transport_failure_names_only_its_class(): void
    {
        $http = new Factory();
        $http->fake(static function (): never {
            throw new ConnectionException('cURL error 6 for https://slack.com/api/chat.postMessage Authorization: Bearer ' . StubChannel::TOKEN);
        });

        try {
            (new SlackApiClient($http))->postJson(StubChannel::TOKEN, 'chat.postMessage');
            $this->fail('The call should have failed.');
        } catch (SlackApiException $exception) {
            $this->assertSame('Slack API chat.postMessage request failed: ' . ConnectionException::class, $exception->getMessage());
            $this->assertNull($exception->errorCode);
            $this->assertNull($exception->getPrevious(), 'the original message quotes the token');
        }
    }

    public function test_the_reason_slack_gives_in_response_metadata_joins_the_message(): void
    {
        $http = $this->fakeHttp(['slack.com/api/*' => Factory::response([
            'ok'                => false,
            'error'             => 'invalid_blocks',
            'response_metadata' => [
                'messages' => ['[ERROR] downloading image failed [json-pointer:/blocks/0/image_url]', '[ERROR] another'],
                'warnings' => ['missing_charset'],
            ],
        ])]);

        try {
            (new SlackApiClient($http))->postJson(StubChannel::TOKEN, 'chat.postMessage');
            $this->fail('The call should have failed.');
        } catch (SlackApiException $exception) {
            $this->assertSame('invalid_blocks', $exception->errorCode);
            $this->assertSame(
                '[ERROR] downloading image failed [json-pointer:/blocks/0/image_url]; [ERROR] another; missing_charset',
                $exception->reason,
            );
            $this->assertSame(
                'Slack API chat.postMessage failed: invalid_blocks ([ERROR] downloading image failed [json-pointer:/blocks/0/image_url]; [ERROR] another; missing_charset)',
                $exception->getMessage(),
            );
        }
    }

    public function test_the_reason_is_read_from_a_refused_http_status_too(): void
    {
        $http = $this->fakeHttp(['slack.com/api/*' => Factory::response(['ok' => false, 'error' => 'invalid_arguments', 'response_metadata' => ['messages' => ['bad']]], 400)]);

        try {
            (new SlackApiClient($http))->postJson(StubChannel::TOKEN, 'chat.postMessage');
            $this->fail('The call should have failed.');
        } catch (SlackApiException $exception) {
            $this->assertSame('Slack API chat.postMessage failed: invalid_arguments (bad)', $exception->getMessage());
        }
    }

    public function test_the_reason_never_holds_a_link_or_a_token_and_is_bounded(): void
    {
        $http = $this->fakeHttp(['slack.com/api/*' => Factory::response([
            'ok'                => false,
            'error'             => 'invalid_blocks',
            'response_metadata' => ['messages' => [
                'cannot download https://files.example.test/m/abc?signature=secret-signed&expires=1 now',
                'token ' . StubChannel::TOKEN . ' leaked',
                str_repeat('x', 900),
            ]],
        ])]);

        try {
            (new SlackApiClient($http))->postJson(StubChannel::TOKEN, 'chat.postMessage');
            $this->fail('The call should have failed.');
        } catch (SlackApiException $exception) {
            $message = $exception->getMessage();

            $this->assertStringContainsString('cannot download [link] now', $message);
            $this->assertStringNotContainsString('secret-signed', $message);
            $this->assertStringNotContainsString('files.example.test', $message);
            $this->assertStringNotContainsString(StubChannel::TOKEN, $message);
            $this->assertSame(SlackApiClient::REASON_MAX, mb_strlen((string) $exception->reason));
            $this->assertStringEndsWith('…', (string) $exception->reason);
        }
    }

    public function test_a_failure_without_metadata_keeps_the_plain_message(): void
    {
        $http = $this->fakeHttp(['slack.com/api/*' => Factory::response(['ok' => false, 'error' => 'invalid_auth', 'response_metadata' => ['messages' => []]])]);

        try {
            (new SlackApiClient($http))->postJson(StubChannel::TOKEN, 'chat.postMessage');
            $this->fail('The call should have failed.');
        } catch (SlackApiException $exception) {
            $this->assertNull($exception->reason);
            $this->assertSame('Slack API chat.postMessage failed: invalid_auth', $exception->getMessage());
        }
    }

    public function test_a_body_that_is_not_slacks_answer_is_an_invalid_response(): void
    {
        $http = $this->fakeHttp(['slack.com/api/*' => Factory::response('<html>proxy</html>', 200)]);

        try {
            (new SlackApiClient($http))->postJson(StubChannel::TOKEN, 'chat.postMessage');
            $this->fail('The call should have failed.');
        } catch (SlackApiException $exception) {
            $this->assertSame('invalid_response', $exception->errorCode);
        }
    }

    public function test_a_client_error_without_slacks_body_is_named_by_its_status(): void
    {
        $http = $this->fakeHttp(['slack.com/api/*' => Factory::response('no', 404)]);

        try {
            (new SlackApiClient($http))->postJson(StubChannel::TOKEN, 'chat.postMessage');
            $this->fail('The call should have failed.');
        } catch (SlackApiException $exception) {
            $this->assertSame('http_404', $exception->errorCode);
            $this->assertSame(404, $exception->httpStatus);
        }
    }

    public function test_a_server_error_is_retried_and_then_reported(): void
    {
        $http = $this->fakeHttp(['slack.com/api/*' => Factory::response('down', 503)]);

        try {
            (new SlackApiClient($http))->postJson(StubChannel::TOKEN, 'chat.postMessage');
            $this->fail('The call should have failed.');
        } catch (SlackApiException $exception) {
            $this->assertSame('http_503', $exception->errorCode);
        }

        $this->assertCount(3, $this->sent($http));
    }

    public function test_the_retry_after_header_is_read_as_seconds_and_garbage_is_ignored(): void
    {
        foreach (['30' => 30, 'soon' => null, '-1' => null] as $header => $seconds) {
            $http = $this->fakeHttp(['slack.com/api/*' => Factory::response(['ok' => false, 'error' => 'ratelimited'], 429, ['Retry-After' => (string) $header])]);

            try {
                (new SlackApiClient($http))->postJson(StubChannel::TOKEN, 'chat.postMessage');
                $this->fail('The call should have failed.');
            } catch (SlackApiException $exception) {
                $this->assertSame('ratelimited', $exception->errorCode);
                $this->assertSame($seconds, $exception->retryAfter, (string) $header);
            }
        }
    }
}
