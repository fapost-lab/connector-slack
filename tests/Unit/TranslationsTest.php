<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Tests\Unit;

use Fapost\Connector\Slack\SlackChannelProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The managed-field labels and the setup steps exist in every language Core's console speaks, and English matches
 * the fallback text declared in the code.
 */
final class TranslationsTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return ['en' => ['en'], 'ru' => ['ru'], 'uk' => ['uk']];
    }

    #[DataProvider('locales')]
    public function test_a_locale_has_exactly_the_keys_of_english(string $locale): void
    {
        $this->assertSame(
            $this->keys($this->load('en')),
            $this->keys($this->load($locale)),
        );
    }

    #[DataProvider('locales')]
    public function test_no_text_is_empty(string $locale): void
    {
        $texts = $this->load($locale);

        array_walk_recursive($texts, function (mixed $text): void {
            $this->assertIsString($text);
            $this->assertNotSame('', mb_trim($text));
        });
    }

    public function test_english_is_the_text_the_code_falls_back_to(): void
    {
        $english = $this->load('en');
        $profile = new SlackChannelProfile();

        foreach ($profile->settings()->fields as $field) {
            $this->assertSame($field->label, $english['fields'][$field->key]['label']);
        }

        $steps = [...$profile->setupGuide(null, []), ...$profile->setupGuide('https://hooks.example.test/webhook/slack/x', [])];

        foreach ($steps as $step) {
            $name = mb_substr((string) $step->translationKey, mb_strlen('slack::slack.setup.'));

            $this->assertSame($step->title, $english['setup'][$name]['title']);
            $this->assertSame($step->text, $english['setup'][$name]['text']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $locale): array
    {
        $texts = require dirname(__DIR__, 2) . "/lang/{$locale}/slack.php";

        $this->assertIsArray($texts);

        return $texts;
    }

    /**
     * @param  array<string, mixed>  $texts
     *
     * @return list<string>
     */
    private function keys(array $texts, string $prefix = ''): array
    {
        $keys = [];

        foreach ($texts as $key => $value) {
            $path = $prefix . $key;

            if (is_array($value)) {
                array_push($keys, ...$this->keys($value, $path . '.'));
            } else {
                $keys[] = $path;
            }
        }

        return $keys;
    }
}
