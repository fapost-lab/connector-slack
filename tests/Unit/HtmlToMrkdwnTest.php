<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Tests\Unit;

use Fapost\Connector\Slack\BlockKit\HtmlToMrkdwn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlToMrkdwnTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function conversions(): array
    {
        return [
            'plain text'                    => ['Hello', 'Hello'],
            'bold'                          => ['<b>x</b> and <strong>y</strong>', '*x* and *y*'],
            'italic'                        => ['<i>x</i> and <em>y</em>', '_x_ and _y_'],
            'strike'                        => ['<s>x</s><strike>y</strike><del>z</del>', '~x~~y~~z~'],
            'inline code'                   => ['<code>a &lt; b</code>', '`a &lt; b`'],
            'a code block'                  => ["<pre>line 1\nline 2</pre>", "```line 1\nline 2```"],
            'a code block with code'        => ['<pre><code>x</code></pre>', '```x```'],
            'a link'                        => ['<a href="https://example.test/?a=1&amp;b=2">site</a>', '<https://example.test/?a=1&amp;b=2|site>'],
            'a link with single quotes'     => ["<a href='https://example.test'>site</a>", '<https://example.test|site>'],
            'a mail link'                   => ['<a href="mailto:a@example.test">mail</a>', '<mailto:a@example.test|mail>'],
            'an unsafe link keeps its text' => ['<a href="javascript:alert(1)">click</a>', 'click'],
            'a link without href'           => ['<a name="x">text</a>', 'text'],
            'a line break'                  => ['a<br>b<br/>c', "a\nb\nc"],
            'other tags are dropped'        => ['<div><span class="x">kept</span></div><u>under</u>', 'keptunder'],
            'entities are decoded'          => ['Tom &amp; Jerry &lt;3 &quot;q&quot;', 'Tom &amp; Jerry &lt;3 "q"'],
            'a bare ampersand is escaped'   => ['fish & chips', 'fish &amp; chips'],
            'a bare angle is escaped'       => ['1 < 2 > 0', '1 &lt; 2 &gt; 0'],
            'nested'                        => ['<b>bold <i>both</i></b>', '*bold _both_*'],
            'unclosed tags are closed'      => ['<b>never closed', '*never closed*'],
            'a stray closing tag'           => ['text</b>', 'text'],
            'formatting inside a block'     => ['<pre><b>x</b></pre>', '```x```'],
            'a pipe in a url is refused'    => ['<a href="https://example.test/a|b">t</a>', 't'],
        ];
    }

    #[DataProvider('conversions')]
    public function test_the_conversion(string $html, string $expected): void
    {
        $this->assertSame($expected, HtmlToMrkdwn::convert($html));
    }

    public function test_escape_changes_only_ampersand_and_angle_brackets(): void
    {
        $this->assertSame('a &amp; b &lt;c&gt; &amp;amp; "d" *e*', HtmlToMrkdwn::escape('a & b <c> &amp; "d" *e*'));
    }
}
