<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Api;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * The few Slack Web API methods the connector calls, over Laravel's HTTP client.
 *
 * The bot token comes with each call and is never kept. Failures become {@see SlackApiException} whose message
 * carries Slack's error code, the reason from `response_metadata` or the class of the transport failure, and never the
 * token or a request URL.
 */
final readonly class SlackApiClient
{
    public const string BASE_URL = 'https://slack.com/api';

    /** The longest reason from Slack that is kept, in characters. */
    public const int REASON_MAX = 500;

    public function __construct(
        private Factory $http,
        private string $baseUrl = self::BASE_URL,
    ) {
    }

    /**
     * Call a method that takes a JSON body, such as `chat.postMessage` and `chat.update`.
     *
     * @param  array<string, mixed>  $body
     *
     * @return array<string, mixed>
     *
     * @throws SlackApiException
     */
    public function postJson(string $token, string $method, array $body = []): array
    {
        return $this->send($token, $method, $body, true);
    }

    /**
     * Call a read method, which Slack documents with form-encoded arguments.
     *
     * @param  array<string, scalar>  $arguments
     *
     * @return array<string, mixed>
     *
     * @throws SlackApiException
     */
    public function postForm(string $token, string $method, array $arguments = []): array
    {
        return $this->send($token, $method, $arguments, false);
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @return array<string, mixed>
     */
    private function send(string $token, string $method, array $body, bool $json): array
    {
        $request = $this->http
            ->withToken($token)
            ->retry(3, 100, static function (Throwable $exception): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException && $exception->response->serverError();
            })
            ->acceptJson();

        $request = $json ? $request->asJson() : $request->asForm();

        try {
            $response = $request->post("{$this->baseUrl}/{$method}", $body)->throw();
        } catch (RequestException $exception) {
            throw $this->fromResponse($method, $exception->response);
        } catch (Throwable $exception) {
            // The HTTP stack quotes URLs and headers in its messages; only the class is safe to pass on.
            throw SlackApiException::transport($method, $exception::class);
        }

        /** @var mixed $data */
        $data = $response->json();

        if (! is_array($data) || true !== ($data['ok'] ?? false)) {
            $error = is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : 'invalid_response';

            throw SlackApiException::failed($method, $error, $this->retryAfter($response), $response->status(), $this->reason($data));
        }

        return $data;
    }

    private function fromResponse(string $method, Response $response): SlackApiException
    {
        $data  = $response->json();
        $error = is_array($data) && is_string($data['error'] ?? null)
            ? $data['error']
            : (429 === $response->status() ? 'ratelimited' : 'http_' . $response->status());

        return SlackApiException::failed($method, $error, $this->retryAfter($response), $response->status(), $this->reason($data));
    }

    /**
     * Slack explains a refusal in `response_metadata.messages` (and sometimes `warnings`), such as
     * `[ERROR] downloading image failed [json-pointer:/blocks/0/image_url]`. The strings are joined, stripped of every
     * link and token-looking word (a media link is a signed public URL) and cut to {@see REASON_MAX} characters.
     */
    private function reason(mixed $data): ?string
    {
        $metadata = is_array($data) && is_array($data['response_metadata'] ?? null) ? $data['response_metadata'] : [];
        $parts    = [];

        foreach (['messages', 'warnings'] as $key) {
            foreach (is_array($metadata[$key] ?? null) ? $metadata[$key] : [] as $line) {
                if (is_string($line)) {
                    $parts[] = $line;
                }
            }
        }

        $text = (string) preg_replace(
            ['~\b[a-z][a-z0-9+.-]*://\S+~i', '~\bxox[a-z]-[A-Za-z0-9-]+~', '~\s+~u'],
            ['[link]', '[token]', ' '],
            implode('; ', $parts),
        );
        $text = mb_trim($text);

        if ('' === $text) {
            return null;
        }

        return mb_strlen($text) > self::REASON_MAX ? mb_substr($text, 0, self::REASON_MAX - 1) . '…' : $text;
    }

    private function retryAfter(Response $response): ?int
    {
        $value = $response->header('Retry-After');

        return 1 === preg_match('/^[0-9]{1,6}$/D', $value) ? (int) $value : null;
    }
}
