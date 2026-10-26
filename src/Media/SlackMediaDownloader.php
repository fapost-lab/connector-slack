<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Media;

use Fapost\Connector\Slack\Api\SlackApiClient;
use Fapost\Connector\Slack\Api\SlackApiException;
use Fapost\Foundation\Channel\ChannelInterface;
use Fapost\Foundation\Media\ChannelMediaDownloaderInterface;
use Fapost\Foundation\Media\DTO\DownloadResult;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * Downloads a file a person sent in Slack: `files.info` names its private URL, which needs the bot token (scope
 * `files:read`).
 *
 * Slack answers a request with a bad token with 200 and its HTML sign-in page, so a response of type `text/html` is a
 * failure. The private URL is never logged or put into an exception, and the token goes only to Slack's own hosts.
 */
final readonly class SlackMediaDownloader implements ChannelMediaDownloaderInterface
{
    public function __construct(
        private SlackApiClient $client,
        private Factory $http,
    ) {
    }

    public function channelType(): string
    {
        return 'slack';
    }

    /**
     * @throws SlackApiException
     */
    public function download(ChannelInterface $channel, string $providerFileId): DownloadResult
    {
        $token = $channel->getConfig()['token'] ?? null;

        if (! is_string($token) || '' === $token) {
            throw SlackApiException::unsupported('Slack bot token is missing.');
        }

        $info = $this->client->postForm($token, 'files.info', ['file' => $providerFileId]);
        $file = is_array($info['file'] ?? null) ? $info['file'] : [];
        $url  = $file['url_private_download'] ?? $file['url_private'] ?? null;

        if (! is_string($url) || ! $this->isSlackUrl($url)) {
            throw SlackApiException::unsupported('Slack gave no downloadable URL for the file.');
        }

        try {
            $response = $this->http->withToken($token)->withOptions(['stream' => true])->get($url)->throw();
        } catch (Throwable $exception) {
            // The message of the HTTP stack quotes the URL of the file.
            throw SlackApiException::transport('files.download', $exception::class);
        }

        $contentType = mb_strtolower($response->header('Content-Type'));

        if (str_starts_with($contentType, 'text/html')) {
            throw SlackApiException::failed('files.download', 'not_authed');
        }

        $mime = is_string($file['mimetype'] ?? null) && '' !== $file['mimetype']
            ? $file['mimetype']
            : ('' !== $contentType ? mb_trim(explode(';', $contentType)[0]) : 'application/octet-stream');

        $size = is_int($file['size'] ?? null) ? $file['size'] : (int) $response->header('Content-Length');
        $name = is_string($file['name'] ?? null) && '' !== $file['name'] ? $file['name'] : null;

        return new DownloadResult(
            stream: $response->toPsrResponse()->getBody(),
            mimeType: $mime,
            size: $size,
            originalFilename: $name,
        );
    }

    private function isSlackUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host  = is_array($parts) && is_string($parts['host'] ?? null) ? mb_strtolower($parts['host']) : '';

        return is_array($parts)
            && 'https' === ($parts['scheme'] ?? null)
            && ! isset($parts['user'], $parts['pass'])
            && 1 === preg_match('/(^|\.)(slack\.com|slack-files\.com)$/D', $host);
    }
}
