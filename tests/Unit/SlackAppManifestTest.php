<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Tests\Unit;

use Fapost\Connector\Slack\SlackAppManifest;
use Fapost\Connector\Slack\Tests\Support\Fixture;
use PHPUnit\Framework\TestCase;

final class SlackAppManifestTest extends TestCase
{
    private const string URL = 'https://hooks.example.test/webhook/slack/abc123';

    public function test_phase_one_manifest_has_no_request_urls(): void
    {
        $this->assertEquals(Fixture::json('manifest/phase1'), (new SlackAppManifest())->build());
        $this->assertEquals(Fixture::json('manifest/phase1'), (new SlackAppManifest())->build(''));
    }

    public function test_phase_two_manifest_adds_the_same_url_to_events_and_interactivity(): void
    {
        $this->assertEquals(Fixture::json('manifest/phase2'), (new SlackAppManifest())->build(self::URL));
    }

    public function test_the_scopes_are_the_same_in_both_phases(): void
    {
        $manifest = new SlackAppManifest();

        $this->assertSame(
            $manifest->build()['oauth_config'],
            $manifest->build(self::URL)['oauth_config'],
        );
        $this->assertSame(['chat:write', 'im:history', 'users:read', 'files:read'], $manifest->build()['oauth_config']['scopes']['bot']);
    }

    public function test_the_json_is_pretty_and_keeps_slashes_and_unicode(): void
    {
        $json = (new SlackAppManifest('Ассистент'))->toJson(self::URL);

        $this->assertStringContainsString("\n    \"", $json);
        $this->assertStringContainsString('"request_url": "' . self::URL . '"', $json);
        $this->assertStringContainsString('Ассистент', $json);
        $this->assertSame(self::URL, json_decode($json, true)['settings']['interactivity']['request_url']);
    }

    public function test_the_app_name_is_cut_to_35_characters_and_defaults_when_blank(): void
    {
        $long  = (new SlackAppManifest(str_repeat('n', 60)))->build();
        $blank = (new SlackAppManifest("  \n "))->build();

        $this->assertSame(35, mb_strlen($long['display_information']['name']));
        $this->assertSame('FaPost Assistant', $blank['display_information']['name']);
    }

    public function test_the_bot_display_name_uses_only_what_slack_allows(): void
    {
        $this->assertSame('acme-support.bot_2', (new SlackAppManifest('Acme Support.Bot_2'))->build()['features']['bot_user']['display_name']);
        $this->assertSame('fapost-assistant', (new SlackAppManifest('Ассистент'))->build()['features']['bot_user']['display_name']);
        $this->assertSame('a-b', (new SlackAppManifest('  A !!! B  '))->build()['features']['bot_user']['display_name']);
    }
}
