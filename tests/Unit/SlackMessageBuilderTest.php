<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Tests\Unit;

use Fapost\Connector\Slack\Api\SlackApiException;
use Fapost\Connector\Slack\BlockKit\SlackMessageBuilder;
use Fapost\Connector\Slack\Tests\Support\Fixture;
use Fapost\Foundation\Messaging\Button;
use Fapost\Foundation\Messaging\ButtonAction;
use Fapost\Foundation\Messaging\ButtonSet;
use PHPUnit\Framework\TestCase;

final class SlackMessageBuilderTest extends TestCase
{
    private SlackMessageBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new SlackMessageBuilder();
    }

    public function test_text_is_a_mrkdwn_section_with_slacks_three_characters_escaped(): void
    {
        $built = $this->builder->text('Fish & <chips> > all', null, false);

        $this->assertSame(
            [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => 'Fish &amp; &lt;chips&gt; &gt; all']]],
            $built['blocks'],
        );
        $this->assertSame('Fish &amp; &lt;chips&gt; &gt; all', $built['text']);
    }

    public function test_every_row_of_callback_buttons_is_an_actions_block_with_positional_action_ids(): void
    {
        $built = $this->builder->text('Pick one & go', new ButtonSet([
            [new Button('Yes', payload: 's1:b1'), new Button('No', payload: 's1:b2')],
            [new Button('Maybe later', payload: 's1:b3')],
        ]), false);

        $expected = Fixture::json('requests/chat_post_message_text_buttons');

        $this->assertEquals($expected['blocks'], $built['blocks']);
        $this->assertSame($expected['text'], $built['text']);
    }

    public function test_a_message_of_buttons_only_has_no_section_and_no_fallback_text(): void
    {
        $built = $this->builder->text('', new ButtonSet([[new Button('Go', payload: 'p')]]), false);

        $this->assertCount(1, $built['blocks']);
        $this->assertSame('actions', $built['blocks'][0]['type']);
        $this->assertSame('', $built['text']);
    }

    public function test_a_row_of_25_buttons_fits_and_26_does_not(): void
    {
        $row = static fn (int $count): array => array_map(
            static fn (int $i): Button => new Button("B{$i}", payload: "p{$i}"),
            range(1, $count),
        );

        $built = $this->builder->text('x', new ButtonSet([$row(25)]), false);
        $this->assertCount(25, $built['blocks'][1]['elements']);
        $this->assertSame('fp:0:24', $built['blocks'][1]['elements'][24]['action_id']);

        $this->expectException(SlackApiException::class);

        $this->builder->text('x', new ButtonSet([$row(26)]), false);
    }

    public function test_a_label_of_75_characters_is_kept_and_a_longer_one_is_cut_with_an_ellipsis(): void
    {
        $kept = str_repeat('ы', 75);
        $long = str_repeat('ы', 76);

        $built = $this->builder->text('x', new ButtonSet([[new Button($kept, payload: 'a'), new Button($long, payload: 'b')]]), false);
        $texts = array_map(static fn (array $element): string => $element['text']['text'], $built['blocks'][1]['elements']);

        $this->assertSame($kept, $texts[0]);
        $this->assertSame(str_repeat('ы', 74) . '…', $texts[1]);
        $this->assertSame(75, mb_strlen($texts[1]));
    }

    public function test_50_blocks_fit_and_51_do_not(): void
    {
        $rows = static fn (int $count): ButtonSet => new ButtonSet(array_map(
            static fn (int $i): array => [new Button("B{$i}", payload: "p{$i}")],
            range(1, $count),
        ));

        $this->assertCount(50, $this->builder->text('', $rows(50), false)['blocks']);
        $this->assertCount(50, $this->builder->text('x', $rows(49), false)['blocks']);

        $this->expectException(SlackApiException::class);
        $this->expectExceptionMessage('51 blocks');

        $this->builder->text('x', $rows(50), false);
    }

    public function test_buttons_that_are_not_callbacks_are_refused(): void
    {
        $this->expectException(SlackApiException::class);

        $this->builder->text('x', new ButtonSet([[new Button('Call me', ButtonAction::ShareContact)]]), false);
    }

    public function test_long_text_is_split_into_sections_of_at_most_3000_characters_at_line_breaks(): void
    {
        $line = str_repeat('a', 1999);
        $text = "{$line}\n{$line}\n{$line}";

        $built = $this->builder->text($text, null, false);
        $parts = array_map(static fn (array $block): string => $block['text']['text'], $built['blocks']);

        $this->assertSame([$line, $line, $line], $parts);
    }

    public function test_text_without_a_break_is_cut_at_3000_and_loses_nothing(): void
    {
        $text = str_repeat('я', 7000);

        $built = $this->builder->text($text, null, false);
        $parts = array_map(static fn (array $block): string => $block['text']['text'], $built['blocks']);

        $this->assertSame([3000, 3000, 1000], array_map(mb_strlen(...), $parts));
        $this->assertSame($text, implode('', $parts));
    }

    public function test_an_entity_is_never_split_between_sections(): void
    {
        $text  = str_repeat('a', 2999) . '&' . str_repeat('b', 10);
        $built = $this->builder->text($text, null, false);
        $parts = array_map(static fn (array $block): string => $block['text']['text'], $built['blocks']);

        $this->assertSame(str_repeat('a', 2999), $parts[0]);
        $this->assertSame('&amp;' . str_repeat('b', 10), $parts[1]);
    }

    public function test_the_fallback_text_is_at_most_4000_characters(): void
    {
        $built = $this->builder->text(str_repeat('z', 5000), null, false);

        $this->assertSame(4000, mb_strlen($built['text']));
        $this->assertStringEndsWith('…', $built['text']);
        $this->assertCount(2, $built['blocks']);
    }

    public function test_html_is_translated_to_mrkdwn_only_when_asked(): void
    {
        $html  = $this->builder->text('<b>Bold</b> & <i>it</i>', null, true);
        $plain = $this->builder->text('<b>Bold</b> & <i>it</i>', null, false);

        $this->assertSame('*Bold* &amp; _it_', $html['text']);
        $this->assertSame('&lt;b&gt;Bold&lt;/b&gt; &amp; &lt;i&gt;it&lt;/i&gt;', $plain['text']);
    }

    public function test_an_image_by_link_is_an_image_block_with_its_caption_as_a_section(): void
    {
        $built = $this->builder->media($this->media('image/png', 'cat.png', 'https://example.test/m/cat.png?sig=abc'), '<b>Our</b> cat', null, true);

        $expected = Fixture::json('requests/chat_post_message_image');

        $this->assertEquals($expected['blocks'], $built['blocks']);
        $this->assertSame($expected['text'], $built['text']);
    }

    public function test_an_image_without_a_caption_takes_its_alt_text_from_the_file_name_and_then_a_word(): void
    {
        $named = $this->builder->media($this->media('image/jpeg', 'beach.jpg'), '', null, false);
        $bare  = $this->builder->media($this->media('image/gif', ''), '', null, false);

        $this->assertSame([['type' => 'image', 'image_url' => 'https://example.test/m/file', 'alt_text' => 'beach.jpg']], $named['blocks']);
        $this->assertSame('beach.jpg', $named['text']);
        $this->assertSame('image', $bare['blocks'][0]['alt_text']);
    }

    public function test_an_image_link_over_3000_characters_is_refused(): void
    {
        $this->expectException(SlackApiException::class);

        $this->builder->media($this->media('image/png', 'a.png', 'https://example.test/' . str_repeat('x', 3000)), '', null, false);
    }

    public function test_other_media_is_a_link_section_named_by_the_file(): void
    {
        $built = $this->builder->media($this->media('application/pdf', 'price list.pdf', 'https://example.test/m/abc?sig=1&x=2'), '', null, false);

        $expected = Fixture::json('requests/chat_post_message_link');

        $this->assertEquals($expected['blocks'], $built['blocks']);
        $this->assertSame($expected['text'], $built['text']);
    }

    public function test_a_webp_image_goes_by_link_because_slack_shows_no_such_image_block(): void
    {
        $built = $this->builder->media($this->media('image/webp', 'a.webp'), 'Caption', null, false);

        $this->assertSame(['section', 'section'], array_column($built['blocks'], 'type'));
        $this->assertSame('Caption', $built['blocks'][0]['text']['text']);
        $this->assertSame('<https://example.test/m/file|a.webp>', $built['blocks'][1]['text']['text']);
    }

    public function test_media_without_a_link_is_refused(): void
    {
        $this->expectException(SlackApiException::class);

        $this->builder->media(['photo' => 'AgACAgIAAxkBAAI'], '', null, false);
    }

    public function test_media_can_carry_buttons(): void
    {
        $built = $this->builder->media($this->media('image/png', 'a.png'), '', new ButtonSet([[new Button('Ok', payload: 'p')]]), false);

        $this->assertSame(['image', 'actions'], array_column($built['blocks'], 'type'));
    }

    public function test_without_actions_drops_only_the_actions_blocks(): void
    {
        $blocks = [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => 'x']],
            ['type' => 'actions', 'elements' => []],
            ['type' => 'image', 'image_url' => 'https://example.test/a.png', 'alt_text' => 'a'],
        ];

        $this->assertSame([$blocks[0], $blocks[2]], SlackMessageBuilder::withoutActions($blocks));
    }

    /**
     * @return array<string, mixed>
     */
    private function media(string $mime, string $fileName, string $url = 'https://example.test/m/file'): array
    {
        return ['url' => $url, 'mime_type' => $mime, 'size' => 10, 'file_name' => $fileName, 'kind' => 'image'];
    }
}
