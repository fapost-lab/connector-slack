<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Tests\Unit;

use Fapost\Connector\Slack\SlackChannelProfile;
use Fapost\Connector\Slack\Tests\Support\Fixture;
use Fapost\Foundation\Channel\Settings\ChannelSettingType;
use Fapost\Foundation\Channel\Settings\ChannelSetupCopyKind;
use Fapost\Foundation\Channel\Settings\ChannelSetupGuideInterface;
use PHPUnit\Framework\TestCase;

final class SlackChannelProfileTest extends TestCase
{
    private const string URL = 'https://hooks.example.test/webhook/slack/abc123';

    public function test_the_schema_has_managed_text_fields_only_and_a_signing_secret_pattern(): void
    {
        $schema = (new SlackChannelProfile())->settings();

        $this->assertSame(
            ['team_id', 'team_name', 'team_url', 'bot_user_id', 'bot_id', 'bot_name', 'app_id'],
            array_map(static fn ($field): string => $field->key, $schema->fields),
        );
        $this->assertSame([], $schema->editable());
        $this->assertCount(7, $schema->managedKeys());
        $this->assertTrue($schema->usesSecretToken);

        foreach ($schema->fields as $field) {
            $this->assertSame(ChannelSettingType::Text, $field->type);
            $this->assertTrue($field->managed);
            $this->assertSame("slack::slack.fields.{$field->key}", $field->translationKey);
        }
    }

    public function test_the_secret_token_pattern_accepts_a_slack_signing_secret_only(): void
    {
        $pattern = '~' . (new SlackChannelProfile())->settings()->secretTokenPattern . '~D';

        $this->assertSame(1, preg_match($pattern, '8f742231b10e8888abcd99aaaaaa85a5'));
        $this->assertSame(0, preg_match($pattern, '8F742231B10E8888ABCD99AAAAAA85A5'), 'uppercase');
        $this->assertSame(0, preg_match($pattern, '8f742231b10e8888abcd99aaaaaa85a'), '31 characters');
        $this->assertSame(0, preg_match($pattern, "8f742231b10e8888abcd99aaaaaa85a5\n"), 'trailing newline');
        $this->assertSame(0, preg_match($pattern, 'xoxb-1234-5678'), 'a bot token');
    }

    public function test_the_public_link_opens_the_app_in_the_workspace(): void
    {
        $link = (new SlackChannelProfile())->publicLink([
            'app_id'   => 'A123ABC456',
            'team_id'  => 'T123ABC456',
            'bot_name' => 'FaPost Assistant',
        ]);

        $this->assertNotNull($link);
        $this->assertSame('FaPost Assistant', $link->username);
        $this->assertSame('@FaPost Assistant', $link->handle);
        $this->assertSame('https://slack.com/app_redirect?app=A123ABC456&team=T123ABC456', $link->url);
    }

    public function test_there_is_no_link_until_the_registrar_has_reported(): void
    {
        $profile = new SlackChannelProfile();

        $this->assertNull($profile->publicLink([]));
        $this->assertNull($profile->publicLink(['app_id' => 'A1', 'team_id' => 'T1']));
        $this->assertNull($profile->publicLink(['app_id' => 'A1', 'bot_name' => 'x']));
        $this->assertNull($profile->publicLink(['team_id' => 'T1', 'bot_name' => 'x']));
        $this->assertNull($profile->publicLink(['app_id' => '', 'team_id' => 'T1', 'bot_name' => 'x']));
    }

    public function test_the_profile_is_a_setup_guide_too(): void
    {
        $this->assertInstanceOf(ChannelSetupGuideInterface::class, new SlackChannelProfile());
        $this->assertSame('slack', (new SlackChannelProfile())->channelType());
    }

    public function test_the_guide_of_a_new_channel_creates_the_app_without_urls_and_says_where_the_credentials_go(): void
    {
        $steps = (new SlackChannelProfile())->setupGuide(null, []);

        $this->assertSame(
            ['slack::slack.setup.create', 'slack::slack.setup.install', 'slack::slack.setup.credentials'],
            array_map(static fn ($step): ?string => $step->translationKey, $steps),
        );

        $this->assertSame(ChannelSetupCopyKind::Code, $steps[0]->copyKind);
        $this->assertEquals(Fixture::json('manifest/phase1'), json_decode((string) $steps[0]->copy, true));
        $this->assertNull($steps[1]->copy);
        $this->assertNull($steps[2]->copy);
        $this->assertStringContainsString('Bot User OAuth Token', (string) $steps[2]->text);
        $this->assertStringContainsString('Signing Secret', (string) $steps[2]->text);
        $this->assertEquals($steps, (new SlackChannelProfile())->setupGuide('', []), 'an empty URL is no URL');
    }

    public function test_the_guide_of_an_existing_channel_gives_the_request_url_the_full_manifest_and_a_check(): void
    {
        $steps = (new SlackChannelProfile())->setupGuide(self::URL, []);

        $this->assertSame(
            ['slack::slack.setup.request_url', 'slack::slack.setup.manifest', 'slack::slack.setup.check'],
            array_map(static fn ($step): ?string => $step->translationKey, $steps),
        );

        $this->assertSame(self::URL, $steps[0]->copy);
        $this->assertSame(ChannelSetupCopyKind::Url, $steps[0]->copyKind);
        $this->assertStringContainsString('message.im', (string) $steps[0]->text);

        $this->assertSame(ChannelSetupCopyKind::Code, $steps[1]->copyKind);
        $this->assertEquals(Fixture::json('manifest/phase2'), json_decode((string) $steps[1]->copy, true));

        $this->assertNull($steps[2]->copy);
    }

    public function test_the_manifest_of_an_existing_channel_keeps_the_apps_name(): void
    {
        $steps = (new SlackChannelProfile())->setupGuide(self::URL, ['bot_name' => 'Acme Support']);

        $manifest = json_decode((string) $steps[1]->copy, true);

        $this->assertSame('Acme Support', $manifest['display_information']['name']);
        $this->assertSame('acme-support', $manifest['features']['bot_user']['display_name']);
    }

    public function test_every_step_has_an_english_title_and_text(): void
    {
        foreach ([null, self::URL] as $url) {
            foreach ((new SlackChannelProfile())->setupGuide($url, []) as $step) {
                $this->assertNotSame('', $step->title);
                $this->assertNotSame('', (string) $step->text);
            }
        }
    }
}
