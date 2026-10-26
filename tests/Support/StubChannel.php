<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Tests\Support;

use Fapost\Foundation\Channel\ChannelInterface;

final readonly class StubChannel implements ChannelInterface
{
    public const string TOKEN = 'xoxb-test-token-0000';

    public function __construct(private string $token = self::TOKEN)
    {
    }

    public function getId(): string
    {
        return '0190c1a2-0000-7000-8000-000000000001';
    }

    public function getType(): string
    {
        return 'slack';
    }

    public function getConfig(): array
    {
        return [
            'token'               => $this->token,
            'secret_token'        => '8f742231b10e8888abcd99yyyzzz85a5',
            'webhook_public_hash' => 'abc123',
            'options'             => [],
        ];
    }
}
