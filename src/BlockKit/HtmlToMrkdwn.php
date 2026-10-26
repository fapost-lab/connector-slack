<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\BlockKit;

/**
 * Turns the small HTML subset FaPost writes for Telegram (`parse_mode: HTML`) into Slack's mrkdwn.
 *
 * Understood: `b`, `strong`, `i`, `em`, `s`, `strike`, `del`, `code`, `pre`, `a href` and `br`. Any other tag is
 * dropped and its text kept. Entities are decoded, then `&`, `<` and `>` are escaped the way Slack wants them.
 */
final class HtmlToMrkdwn
{
    private const array MARKERS = [
        'b'      => '*',
        'strong' => '*',
        'i'      => '_',
        'em'     => '_',
        's'      => '~',
        'strike' => '~',
        'del'    => '~',
        'code'   => '`',
        'pre'    => '```',
    ];

    public static function convert(string $html): string
    {
        $tokens = preg_split('/(<\/?[a-zA-Z][^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if (false === $tokens) {
            return self::escape($html);
        }

        $out      = '';
        $open     = [];
        $inPre    = false;
        $linkOpen = false;

        foreach ($tokens as $token) {
            if (1 !== preg_match('/^<(\/?)([a-zA-Z][a-zA-Z0-9]*)([^>]*)>$/', $token, $tag)) {
                $out .= self::escape(html_entity_decode($token, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                continue;
            }

            $closing = '/' === $tag[1];
            $name    = mb_strtolower($tag[2]);

            if ('br' === $name) {
                $out .= "\n";

                continue;
            }

            if ('a' === $name) {
                if ($closing) {
                    if ($linkOpen) {
                        $out .= '>';
                        $linkOpen = false;
                    }

                    continue;
                }

                $url = self::href($tag[3]);

                if (null !== $url && ! $linkOpen) {
                    $out .= "<{$url}|";
                    $linkOpen = true;
                }

                continue;
            }

            $marker = self::MARKERS[$name] ?? null;

            if (null === $marker) {
                continue;
            }

            if ($closing) {
                $index = array_search($name, $open, true);

                if (false === $index) {
                    continue;
                }

                unset($open[$index]);
                $inPre = 'pre' === $name ? false : $inPre;
                $out .= $marker;

                continue;
            }

            // Inside a code block nothing is formatted.
            if ($inPre) {
                continue;
            }

            $open[] = $name;
            $inPre  = 'pre' === $name;
            $out .= $marker;
        }

        if ($linkOpen) {
            $out .= '>';
        }

        foreach (array_reverse($open) as $name) {
            $out .= self::MARKERS[$name];
        }

        return $out;
    }

    /**
     * Slack's escaping for text: `&`, `<` and `>` are the only characters with a meaning.
     */
    public static function escape(string $text): string
    {
        return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
    }

    private static function href(string $attributes): ?string
    {
        if (1 !== preg_match('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $attributes, $matches)) {
            return null;
        }

        $url = html_entity_decode($matches[1] . ($matches[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (1 !== preg_match('~^(https?://|mailto:)[^\s<>|]+$~i', $url)) {
            return null;
        }

        return self::escape($url);
    }
}
