<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Tests\Support;

use Fapost\Foundation\Channel\Settings\ManagedChannelSettingsInterface;

final class RecordingManagedSettings implements ManagedChannelSettingsInterface
{
    /** @var list<array{string, array<string, scalar|list<scalar>|null>}> */
    public array $reports = [];

    public function report(string $channelId, array $values): void
    {
        $this->reports[] = [$channelId, $values];
    }
}
