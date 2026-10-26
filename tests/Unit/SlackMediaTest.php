<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Tests\Unit;

use Fapost\Connector\Slack\Api\SlackApiClient;
use Fapost\Connector\Slack\Api\SlackApiException;
use Fapost\Connector\Slack\Media\SlackMediaDownloader;
use Fapost\Connector\Slack\Tests\Support\Fixture;
use Fapost\Connector\Slack\Tests\Support\HttpTestHelpers;
use Fapost\Connector\Slack\Tests\Support\StubChannel;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

final class SlackMediaTest extends TestCase
{
    use HttpTestHelpers;

    private const string INFO     = 'slack.com/api/files.info';
    private const string DOWNLOAD = 'https://files.slack.com/files-pri/T123ABC456-F0S43P1CZ/download/tedair.gif';

    public function test_it_asks_slack_for_the_file_and_streams_it_with_the_bot_token(): void
    {
        $http = $this->fakeHttp([
            self::INFO     => Factory::response(Fixture::json('api/files_info_ok')),
            self::DOWNLOAD => Factory::response('GIF89a-bytes', 200, ['Content-Type' => 'image/gif']),
        ]);

        $result = $this->downloader($http)->download(new StubChannel(), 'F0S43P1CZ');

        $this->assertSame('GIF89a-bytes', (string) $result->stream);
        $this->assertSame('image/gif', $result->mimeType);
        $this->assertSame(11, $result->size);
        $this->assertSame('tedair.gif', $result->originalFilename);

        $sent = $this->sent($http);

        $this->assertSame(['file' => 'F0S43P1CZ'], $sent[0]->data());
        $this->assertSame(self::DOWNLOAD, $sent[1]->url());
        $this->assertSame('Bearer ' . StubChannel::TOKEN, $sent[1]->header('Authorization')[0]);
    }

    public function test_an_html_page_is_a_refused_token_not_a_file(): void
    {
        $http = $this->fakeHttp([
            self::INFO     => Factory::response(Fixture::json('api/files_info_ok')),
            self::DOWNLOAD => Factory::response('<html>Sign in</html>', 200, ['Content-Type' => 'text/html; charset=utf-8']),
        ]);

        $this->expectException(SlackApiException::class);
        $this->expectExceptionMessage('not_authed');

        $this->downloader($http)->download(new StubChannel(), 'F0S43P1CZ');
    }

    public function test_a_file_slack_cannot_describe_is_an_exception(): void
    {
        $http = $this->fakeHttp([self::INFO => Factory::response(['ok' => false, 'error' => 'file_not_found'])]);

        $this->expectException(SlackApiException::class);
        $this->expectExceptionMessage('file_not_found');

        $this->downloader($http)->download(new StubChannel(), 'F404');
    }

    public function test_the_token_is_never_sent_to_a_host_that_is_not_slacks(): void
    {
        $info                                 = Fixture::json('api/files_info_ok');
        $info['file']['url_private_download'] = 'https://evil.example.test/steal.gif';
        $http                                 = $this->fakeHttp([self::INFO => Factory::response($info)]);

        try {
            $this->downloader($http)->download(new StubChannel(), 'F0S43P1CZ');
            $this->fail('The URL should have been refused.');
        } catch (SlackApiException $exception) {
            $this->assertStringNotContainsString('evil.example.test', $exception->getMessage());
        }

        $this->assertCount(1, $this->sent($http));
    }

    public function test_hosts_that_only_look_like_slack_are_refused(): void
    {
        foreach (['https://files.slack.com.evil.test/a', 'http://files.slack.com/a', 'https://evilslack.com/a', 'https://user:pw@files.slack.com/a'] as $url) {
            $info                                 = Fixture::json('api/files_info_ok');
            $info['file']['url_private_download'] = $url;
            $http                                 = $this->fakeHttp([self::INFO => Factory::response($info)]);

            try {
                $this->downloader($http)->download(new StubChannel(), 'F0S43P1CZ');
                $this->fail("{$url} should have been refused.");
            } catch (SlackApiException) {
                $this->assertCount(1, $this->sent($http), $url);
            }
        }
    }

    public function test_a_failed_download_never_names_the_file_url(): void
    {
        $http = $this->fakeHttp([
            self::INFO     => Factory::response(Fixture::json('api/files_info_ok')),
            self::DOWNLOAD => Factory::response('gone', 404),
        ]);

        try {
            $this->downloader($http)->download(new StubChannel(), 'F0S43P1CZ');
            $this->fail('The download should have failed.');
        } catch (SlackApiException $exception) {
            $this->assertStringNotContainsString('files.slack.com/files-pri', $exception->getMessage());
            $this->assertStringNotContainsString(StubChannel::TOKEN, $exception->getMessage());
        }
    }

    public function test_a_channel_type_and_a_missing_token(): void
    {
        $downloader = $this->downloader($this->fakeHttp([]));

        $this->assertSame('slack', $downloader->channelType());

        $this->expectException(SlackApiException::class);

        $downloader->download(new StubChannel(''), 'F1');
    }

    private function downloader(Factory $http): SlackMediaDownloader
    {
        return new SlackMediaDownloader(new SlackApiClient($http), $http);
    }
}
