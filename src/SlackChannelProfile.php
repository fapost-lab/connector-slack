<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack;

use Fapost\Foundation\Channel\Settings\ChannelProfileInterface;
use Fapost\Foundation\Channel\Settings\ChannelPublicLink;
use Fapost\Foundation\Channel\Settings\ChannelSettingField;
use Fapost\Foundation\Channel\Settings\ChannelSettingsSchema;
use Fapost\Foundation\Channel\Settings\ChannelSettingType;
use Fapost\Foundation\Channel\Settings\ChannelSetupCopyKind;
use Fapost\Foundation\Channel\Settings\ChannelSetupGuideInterface;
use Fapost\Foundation\Channel\Settings\ChannelSetupStep;

/**
 * What the Slack channel tells Core about itself: the workspace and app the registrar read from Slack (all managed,
 * nothing for a person to edit), the signing secret's shape, the link that opens the app, and the steps a person
 * follows in Slack to connect it.
 *
 * Setting up a Slack app has two phases, because the Request URL exists only once the channel does: first an app is
 * created from a manifest without URLs, installed, and its token and signing secret go into the channel; then the
 * channel's URL goes back into the app. Core calls {@see setupGuide()} with a null URL before the channel exists and
 * with its URL after.
 */
final class SlackChannelProfile implements ChannelProfileInterface, ChannelSetupGuideInterface
{
    /** The signing secret of a Slack app: 32 lowercase hex characters. */
    public const string SECRET_TOKEN_PATTERN = '^[0-9a-f]{32}$';

    private const string TRANSLATIONS = 'slack::slack';

    /**
     * Managed settings: key => English label.
     *
     * @var array<string, string>
     */
    private const array FIELDS = [
        'team_id'     => 'Workspace ID',
        'team_name'   => 'Workspace',
        'team_url'    => 'Workspace URL',
        'bot_user_id' => 'Bot user ID',
        'bot_id'      => 'Bot ID',
        'bot_name'    => 'Bot name',
        'app_id'      => 'App ID',
    ];

    public function channelType(): string
    {
        return 'slack';
    }

    public function settings(): ChannelSettingsSchema
    {
        $fields = [];

        foreach (self::FIELDS as $key => $label) {
            $fields[] = new ChannelSettingField(
                key: $key,
                type: ChannelSettingType::Text,
                label: $label,
                max: 255,
                managed: true,
                translationKey: self::TRANSLATIONS . ".fields.{$key}",
            );
        }

        return new ChannelSettingsSchema(
            fields: $fields,
            usesSecretToken: true,
            secretTokenPattern: self::SECRET_TOKEN_PATTERN,
        );
    }

    public function publicLink(array $config): ?ChannelPublicLink
    {
        $appId = $config['app_id'] ?? null;
        $team  = $config['team_id'] ?? null;
        $name  = $config['bot_name'] ?? null;

        if (! is_string($appId) || '' === $appId || ! is_string($team) || '' === $team || ! is_string($name) || '' === $name) {
            return null;
        }

        return new ChannelPublicLink(
            username: $name,
            handle: '@' . $name,
            url: 'https://slack.com/app_redirect?app=' . rawurlencode($appId) . '&team=' . rawurlencode($team),
        );
    }

    public function setupGuide(?string $webhookUrl, array $config): array
    {
        $name     = is_string($config['bot_name'] ?? null) && '' !== $config['bot_name'] ? $config['bot_name'] : SlackAppManifest::DEFAULT_NAME;
        $manifest = new SlackAppManifest($name);

        if (null === $webhookUrl || '' === $webhookUrl) {
            return [
                new ChannelSetupStep(
                    title: 'Create the Slack app from a manifest',
                    text: 'Open api.slack.com/apps, choose Create New App, then From a manifest. Pick your workspace and paste this JSON. It has no Request URL yet: the channel has to exist first.',
                    copy: $manifest->toJson(),
                    copyKind: ChannelSetupCopyKind::Code,
                    translationKey: self::TRANSLATIONS . '.setup.create',
                ),
                new ChannelSetupStep(
                    title: 'Install the app to your workspace',
                    text: 'Open Install App in the app settings and choose Install to Workspace.',
                    translationKey: self::TRANSLATIONS . '.setup.install',
                ),
                new ChannelSetupStep(
                    title: 'Copy the token and the signing secret',
                    text: 'Copy the Bot User OAuth Token (OAuth & Permissions) into the Token field and the Signing Secret (Basic Information, App Credentials) into the Secret token field. Save the channel: this guide then shows the Request URL.',
                    translationKey: self::TRANSLATIONS . '.setup.credentials',
                ),
            ];
        }

        return [
            new ChannelSetupStep(
                title: 'Paste the Request URL into Slack',
                text: 'In Event Subscriptions switch events on, paste this URL into Request URL and add the bot event message.im; then paste the same URL into Request URL in Interactivity & Shortcuts. Slack checks the URL at once and shows Verified.',
                copy: $webhookUrl,
                copyKind: ChannelSetupCopyKind::Url,
                translationKey: self::TRANSLATIONS . '.setup.request_url',
            ),
            new ChannelSetupStep(
                title: 'Or update the whole app from a manifest',
                text: 'Instead of the step above, open App Manifest in the app settings, paste this JSON, save, and confirm the Request URL when Slack asks.',
                copy: $manifest->toJson($webhookUrl),
                copyKind: ChannelSetupCopyKind::Code,
                translationKey: self::TRANSLATIONS . '.setup.manifest',
            ),
            new ChannelSetupStep(
                title: 'Check the connection',
                text: 'Open the app in Slack, go to its Messages tab and write to the bot: the assistant answers there. The webhook status of this channel means only that Slack accepted the token, not that the URL is in place.',
                translationKey: self::TRANSLATIONS . '.setup.check',
            ),
        ];
    }
}
