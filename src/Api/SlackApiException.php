<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Api;

use RuntimeException;

/**
 * A Slack Web API call that did not succeed, or a message the connector cannot express for Slack.
 *
 * The message never holds the bot token, a request URL or a media link: it names the method and Slack's own error
 * code (`invalid_auth`, `channel_not_found`, `ratelimited`), the reason Slack gives in `response_metadata` when there
 * is one, or the class of a transport failure.
 */
final class SlackApiException extends RuntimeException
{
    /**
     * @param  string|null  $errorCode  Slack's `error` value, such as `ratelimited`; null for a transport failure
     * @param  int|null  $retryAfter  seconds Slack asked to wait, from `Retry-After`
     * @param  string|null  $reason  Slack's explanation from `response_metadata`, already cleaned of links and bounded
     */
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?int $retryAfter = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $reason = null,
    ) {
        parent::__construct($message);
    }

    public static function failed(string $method, string $errorCode, ?int $retryAfter = null, ?int $httpStatus = null, ?string $reason = null): self
    {
        $message = "Slack API {$method} failed: {$errorCode}";

        if (null !== $reason && '' !== $reason) {
            $message .= " ({$reason})";
        }

        if (null !== $retryAfter) {
            $message .= " (retry after {$retryAfter}s)";
        }

        return new self($message, $errorCode, $retryAfter, $httpStatus, $reason);
    }

    public static function transport(string $method, string $reason): self
    {
        return new self("Slack API {$method} request failed: {$reason}");
    }

    /**
     * A message or a file the connector cannot send or read; not an answer of Slack.
     */
    public static function unsupported(string $reason): self
    {
        return new self($reason);
    }
}
