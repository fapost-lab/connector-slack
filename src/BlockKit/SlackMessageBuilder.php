<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\BlockKit;

use Fapost\Connector\Slack\Api\SlackApiException;
use Fapost\Foundation\Messaging\ButtonSet;

/**
 * Builds the `blocks` and the fallback `text` of a Slack message from what FaPost sends: text, buttons, media by link.
 *
 * The limits are Slack's own (docs.slack.dev, Block Kit reference): 3000 characters in a section, 50 blocks in a
 * message, 25 elements in an actions block, 75 characters on a button, 4000 characters in `text`. A label that is too
 * long is cut with an ellipsis; anything else that does not fit is an exception, never a silently different message.
 *
 * Buttons are callback buttons only: the capability list of the channel does not offer the others.
 */
final readonly class SlackMessageBuilder
{
    public const int SECTION_MAX = 3000;

    public const int FALLBACK_MAX = 4000;

    public const int BLOCKS_MAX = 50;

    public const int BUTTONS_PER_ROW_MAX = 25;

    public const int LABEL_MAX = 75;

    public const int IMAGE_URL_MAX = 3000;

    public const int ALT_TEXT_MAX = 2000;

    /** Mime types Slack's image block shows. */
    private const array IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif'];

    /**
     * The blocks of a sent message without its buttons.
     *
     * @param  list<array<string, mixed>>  $blocks
     *
     * @return list<array<string, mixed>>
     */
    public static function withoutActions(array $blocks): array
    {
        return array_values(array_filter(
            $blocks,
            static fn (array $block): bool => 'actions' !== ($block['type'] ?? null),
        ));
    }

    /**
     * @return array{blocks: list<array<string, mixed>>, text: string}
     *
     * @throws SlackApiException when the message does not fit Slack's limits or has buttons Slack cannot show
     */
    public function text(string $text, ?ButtonSet $buttons, bool $html): array
    {
        $mrkdwn = $this->mrkdwn($text, $html);
        $blocks = [...$this->sections($mrkdwn), ...$this->actions($buttons)];

        return $this->finish($blocks, $mrkdwn);
    }

    /**
     * @param  array<string, mixed>  $media  `url`, `mime_type`, `file_name`, `kind` as Core puts them in the payload
     *
     * @return array{blocks: list<array<string, mixed>>, text: string}
     *
     * @throws SlackApiException when there is no link, the link is too long for an image block, or the limits are exceeded
     */
    public function media(array $media, string $caption, ?ButtonSet $buttons, bool $html): array
    {
        $url = is_string($media['url'] ?? null) ? $media['url'] : '';

        if ('' === $url) {
            throw SlackApiException::unsupported('Slack sends media by public link only; the payload has no link.');
        }

        $mime     = is_string($media['mime_type'] ?? null) ? mb_strtolower($media['mime_type']) : '';
        $fileName = is_string($media['file_name'] ?? null) ? $media['file_name'] : '';
        $mrkdwn   = $this->mrkdwn($caption, $html);
        $isImage  = in_array($mime, self::IMAGE_TYPES, true);

        if ($isImage) {
            if (mb_strlen($url) > self::IMAGE_URL_MAX) {
                throw SlackApiException::unsupported('The media link is too long for a Slack image block.');
            }

            $alt    = '' !== mb_trim($caption) ? ($html ? strip_tags($caption) : $caption) : $fileName;
            $alt    = '' !== mb_trim($alt) ? $alt : 'image';
            $blocks = [
                ['type' => 'image', 'image_url' => $url, 'alt_text' => mb_substr($alt, 0, self::ALT_TEXT_MAX)],
                ...$this->sections($mrkdwn),
            ];
        } else {
            $label  = '' !== $fileName ? $fileName : 'file';
            $link   = '<' . str_replace('|', '%7C', HtmlToMrkdwn::escape($url)) . '|' . HtmlToMrkdwn::escape($label) . '>';
            $blocks = [...$this->sections($mrkdwn), ...$this->sections($link)];
        }

        $blocks = [...$blocks, ...$this->actions($buttons)];
        $text   = '' !== mb_trim($mrkdwn) ? $mrkdwn : HtmlToMrkdwn::escape('' !== $fileName ? $fileName : ($isImage ? 'image' : 'file'));

        return $this->finish($blocks, $text);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     *
     * @return array{blocks: list<array<string, mixed>>, text: string}
     */
    private function finish(array $blocks, string $text): array
    {
        if (count($blocks) > self::BLOCKS_MAX) {
            throw SlackApiException::unsupported(
                sprintf('The message needs %d blocks; Slack takes at most %d.', count($blocks), self::BLOCKS_MAX),
            );
        }

        if (mb_strlen($text) > self::FALLBACK_MAX) {
            $text = mb_substr($text, 0, self::FALLBACK_MAX - 1) . '…';
        }

        return ['blocks' => $blocks, 'text' => $text];
    }

    private function mrkdwn(string $text, bool $html): string
    {
        return $html ? HtmlToMrkdwn::convert($text) : HtmlToMrkdwn::escape($text);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sections(string $mrkdwn): array
    {
        $sections = [];

        while ('' !== $mrkdwn) {
            [$head, $mrkdwn] = $this->cut($mrkdwn);

            if ('' !== mb_trim($head)) {
                $sections[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $head]];
            }
        }

        return $sections;
    }

    /**
     * The first chunk of at most {@see SECTION_MAX} characters, cut at a line break or a space when there is one, and
     * never inside an entity.
     *
     * @return array{string, string}
     */
    private function cut(string $text): array
    {
        if (mb_strlen($text) <= self::SECTION_MAX) {
            return [$text, ''];
        }

        $window = mb_substr($text, 0, self::SECTION_MAX);
        $at     = mb_strrpos($window, "\n");

        if (false === $at || 0 === $at) {
            $at = mb_strrpos($window, ' ');
        }

        if (false !== $at && 0 !== $at) {
            return [mb_substr($text, 0, $at), mb_substr($text, $at + 1)];
        }

        $head = (string) preg_replace('/&(?:a|am|amp|l|lt|g|gt)?$/D', '', $window);
        $head = '' === $head ? $window : $head;

        return [$head, mb_substr($text, mb_strlen($head))];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function actions(?ButtonSet $buttons): array
    {
        if (null === $buttons) {
            return [];
        }

        if (! $buttons->expectsCallback()) {
            throw SlackApiException::unsupported('Slack shows callback buttons only; reply, contact and location buttons are not supported.');
        }

        $blocks = [];

        foreach ($buttons->rows as $rowIndex => $row) {
            if (count($row) > self::BUTTONS_PER_ROW_MAX) {
                throw SlackApiException::unsupported(
                    sprintf('A row of %d buttons does not fit a Slack actions block of %d.', count($row), self::BUTTONS_PER_ROW_MAX),
                );
            }

            $elements = [];

            foreach ($row as $columnIndex => $button) {
                $elements[] = [
                    'type'      => 'button',
                    'text'      => ['type' => 'plain_text', 'text' => $this->label($button->label)],
                    'action_id' => "fp:{$rowIndex}:{$columnIndex}",
                    'value'     => (string) $button->payload,
                ];
            }

            $blocks[] = ['type' => 'actions', 'elements' => $elements];
        }

        return $blocks;
    }

    private function label(string $label): string
    {
        return mb_strlen($label) > self::LABEL_MAX ? mb_substr($label, 0, self::LABEL_MAX - 1) . '…' : $label;
    }
}
