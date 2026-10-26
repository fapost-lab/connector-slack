<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * The recorded Slack requests and responses under tests/fixtures/slack. Each JSON file names the Slack documentation it
 * derives from in a `_source` key, which {@see json()} strips so the rest is exactly what Slack sends or accepts.
 */
final class Fixture
{
    /**
     * @return array<string, mixed>
     */
    public static function json(string $name): array
    {
        $data = json_decode(self::raw($name . '.json'), true, 512, JSON_THROW_ON_ERROR);

        Assert::assertIsArray($data);
        Assert::assertIsString($data['_source'] ?? null, "Fixture {$name} must name its documentation source.");

        unset($data['_source']);

        return $data;
    }

    public static function raw(string $file): string
    {
        $path = dirname(__DIR__) . '/fixtures/slack/' . $file;

        Assert::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
