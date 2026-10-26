<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack;

use InvalidArgumentException;

/**
 * Where a sent message lives in Slack: the channel and the `ts` that names the message there.
 *
 * Core addresses a person by user id (`U…`), but `chat.update` and `conversations.history` need the id of the direct
 * message channel (`D…`) that `chat.postMessage` answers with. The pair travels through Core as one opaque string,
 * `{channel}:{ts}`, which Core stores as the provider message id and hands back as `metadata.edit_message_id`.
 */
final readonly class SlackMessageRef
{
    private const string PATTERN = '/^([A-Z0-9]{1,32}):([0-9]{1,12}\.[0-9]{1,8})$/D';

    /**
     * @throws InvalidArgumentException when the channel or the timestamp has a shape Slack never uses
     */
    public function __construct(
        public string $channel,
        public string $ts,
    ) {
        if (1 !== preg_match(self::PATTERN, "{$channel}:{$ts}")) {
            throw new InvalidArgumentException('A Slack message reference is a channel id and a message timestamp.');
        }
    }

    /**
     * @throws InvalidArgumentException when the value is not a reference this connector made
     */
    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::PATTERN, $value, $matches)) {
            throw new InvalidArgumentException('Not a Slack message reference.');
        }

        return new self($matches[1], $matches[2]);
    }

    public static function tryFromString(string $value): ?self
    {
        return 1 === preg_match(self::PATTERN, $value, $matches) ? new self($matches[1], $matches[2]) : null;
    }

    public function toString(): string
    {
        return "{$this->channel}:{$this->ts}";
    }
}
