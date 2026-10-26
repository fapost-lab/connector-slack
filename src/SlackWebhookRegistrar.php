<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack;

use Fapost\Connector\Slack\Api\SlackApiClient;
use Fapost\Connector\Slack\Api\SlackApiException;
use Fapost\Foundation\Channel\Settings\ManagedChannelSettingsInterface;
use Fapost\Foundation\Channel\WebhookRegistrarInterface;
use Fapost\Foundation\Channel\WebhookRegistrationPayload;

/**
 * Slack has no API that points an app at a webhook: the Request URL is pasted into the app's settings by a person (see
 * {@see SlackChannelProfile}). So "registered" here means "the token was accepted", and the registrar reads the
 * workspace and the app from Slack to fill the channel's managed settings.
 *
 * An error is an exception, which Core records as a failed webhook status.
 */
final readonly class SlackWebhookRegistrar implements WebhookRegistrarInterface
{
    public function __construct(
        private SlackApiClient $client,
        private ManagedChannelSettingsInterface $managedSettings,
    ) {
    }

    /**
     * @throws SlackApiException when Slack does not accept the token, or it is not a bot token
     */
    public function register(WebhookRegistrationPayload $payload): void
    {
        $auth  = $this->client->postForm($payload->token, 'auth.test');
        $botId = $this->string($auth['bot_id'] ?? null);

        if ('' === $botId) {
            throw SlackApiException::unsupported('The Slack token is not a bot token (it starts with xoxb-).');
        }

        $info = $this->client->postForm($payload->token, 'bots.info', ['bot' => $botId]);
        $bot  = is_array($info['bot'] ?? null) ? $info['bot'] : [];

        $this->managedSettings->report($payload->channelId, [
            'team_id'     => $this->nullable($auth['team_id'] ?? null),
            'team_name'   => $this->nullable($auth['team'] ?? null),
            'team_url'    => $this->nullable($auth['url'] ?? null),
            'bot_user_id' => $this->nullable($auth['user_id'] ?? null),
            'bot_id'      => $botId,
            'bot_name'    => $this->nullable($bot['name'] ?? null),
            'app_id'      => $this->nullable($bot['app_id'] ?? null),
        ]);
    }

    public function deregister(WebhookRegistrationPayload $payload): void
    {
        // Slack has no call that detaches an app from a Request URL; the person removes the app or the URL.
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function nullable(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }
}
