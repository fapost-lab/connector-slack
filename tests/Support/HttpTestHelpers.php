<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Tests\Support;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

/**
 * A Laravel HTTP client factory that answers from fixtures, and ways to read what was sent through it.
 */
trait HttpTestHelpers
{
    /**
     * @param  array<string, mixed>  $responses  URL pattern => response, as {@see Factory::fake()} takes them
     */
    private function fakeHttp(array $responses): Factory
    {
        $factory = new Factory();
        $factory->fake($responses);

        return $factory;
    }

    /**
     * @return list<Request>
     */
    private function sent(Factory $factory): array
    {
        return $factory->recorded()->map(static fn (array $pair): Request => $pair[0])->values()->all();
    }
}
