<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Tests\Unit;

use Fapost\Connector\Slack\SlackMessageRef;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlackMessageRefTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function notReferences(): array
    {
        return [
            'empty'             => [''],
            'a telegram id'     => ['12345'],
            'no timestamp'      => ['D024BE91L:'],
            'no channel'        => [':1721232000.123456'],
            'lowercase channel' => ['d024be91l:1721232000.123456'],
            'a user id only'    => ['U2147483697'],
            'trailing newline'  => ["D024BE91L:1721232000.123456\n"],
            'extra part'        => ['D024BE91L:1721232000.123456:x'],
        ];
    }

    public function test_it_round_trips(): void
    {
        $ref = new SlackMessageRef('D024BE91L', '1721232000.123456');

        $this->assertSame('D024BE91L:1721232000.123456', $ref->toString());
        $this->assertEquals($ref, SlackMessageRef::fromString($ref->toString()));
    }

    #[DataProvider('notReferences')]
    public function test_a_value_that_is_not_a_reference_is_refused(string $value): void
    {
        $this->assertNull(SlackMessageRef::tryFromString($value));

        $this->expectException(InvalidArgumentException::class);

        SlackMessageRef::fromString($value);
    }

    public function test_the_constructor_applies_the_same_rule(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SlackMessageRef('D024BE91L', 'yesterday');
    }
}
