<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Media;

use Fapost\Foundation\Media\ChannelMediaLinkPolicyInterface;

/**
 * Slack fetches an `image_url` itself, and a person may open a link message long after it was sent, so the link lives
 * as long as Core allows: a day.
 */
final readonly class SlackMediaLinkPolicy implements ChannelMediaLinkPolicyInterface
{
    public const int TTL_SECONDS = 86400;

    public function channelType(): string
    {
        return 'slack';
    }

    public function linkTtlSeconds(): ?int
    {
        return self::TTL_SECONDS;
    }
}
