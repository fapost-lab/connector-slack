<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack;

/**
 * The Slack app manifest a person pastes into "Create New App → From a manifest" or into an existing app's
 * "App Manifest" (docs.slack.dev/reference/app-manifest).
 *
 * Without a Request URL the manifest creates an app that can be installed and gives its token and signing secret;
 * with one it also subscribes the app to direct messages and turns interactivity on, both at the channel's single
 * webhook URL. The scopes are the same either way, so adding the URL later never asks for a reinstall.
 */
final readonly class SlackAppManifest
{
    public const string DEFAULT_NAME = 'FaPost Assistant';

    public const int NAME_MAX = 35;

    public const int DISPLAY_NAME_MAX = 80;

    /** @var list<string> */
    public const array BOT_SCOPES = ['chat:write', 'im:history', 'users:read', 'files:read'];

    public function __construct(
        private string $name = self::DEFAULT_NAME,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(?string $requestUrl = null): array
    {
        $name = mb_substr(mb_trim($this->name), 0, self::NAME_MAX);
        $name = '' === $name ? self::DEFAULT_NAME : $name;

        $settings = [];

        if (null !== $requestUrl && '' !== $requestUrl) {
            $settings['event_subscriptions'] = ['request_url' => $requestUrl, 'bot_events' => ['message.im']];
            $settings['interactivity']       = ['is_enabled' => true, 'request_url' => $requestUrl];
        }

        $settings['org_deploy_enabled']     = false;
        $settings['socket_mode_enabled']    = false;
        $settings['token_rotation_enabled'] = false;

        return [
            'display_information' => ['name' => $name],
            'features'            => [
                'app_home' => [
                    'home_tab_enabled'               => false,
                    'messages_tab_enabled'           => true,
                    'messages_tab_read_only_enabled' => false,
                ],
                'bot_user' => ['display_name' => $this->displayName($name), 'always_online' => true],
            ],
            'oauth_config' => ['scopes' => ['bot' => self::BOT_SCOPES]],
            'settings'     => $settings,
        ];
    }

    public function toJson(?string $requestUrl = null): string
    {
        return (string) json_encode(
            $this->build($requestUrl),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Slack allows lowercase letters, digits, hyphens, underscores and periods in a bot's display name.
     */
    private function displayName(string $name): string
    {
        $slug = (string) preg_replace('/[^a-z0-9_.]+/', '-', mb_strtolower($name));
        $slug = mb_substr(mb_trim($slug, '-'), 0, self::DISPLAY_NAME_MAX);

        return '' === $slug ? 'fapost-assistant' : $slug;
    }
}
