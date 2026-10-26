<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack;

use Fapost\Connector\Slack\Api\SlackApiClient;
use Fapost\Connector\Slack\Media\SlackMediaDownloader;
use Fapost\Connector\Slack\Media\SlackMediaLinkPolicy;
use Fapost\Foundation\Connector\ConnectorChannel;
use Fapost\Foundation\Connector\ConnectorRegistrarInterface;
use Fapost\Foundation\Lifecycle\AbstractConnectorServiceProvider;
use Fapost\Foundation\Messaging\ButtonLimits;
use Fapost\Foundation\Messaging\ChannelCapabilities;
use Fapost\Foundation\Messaging\ChannelCapability;
use Illuminate\Http\Client\Factory;

/**
 * Registers the `slack` channel.
 *
 * Not declared on purpose: reply buttons (Slack cannot send a button's label as the person's own message), contact and
 * location buttons (no counterpart), a typing indicator (a plain bot has none), a media uploader (media goes by link;
 * an uploader would win over the link policy for the whole channel type) and media limits (Slack documents none for
 * `image_url`).
 */
final class SlackConnectorServiceProvider extends AbstractConnectorServiceProvider
{
    protected function registerChannels(ConnectorRegistrarInterface $registrar): void
    {
        $registrar->registerChannel(new ConnectorChannel(
            type: 'slack',
            sender: SlackSender::class,
            inbound: SlackInboundAdapter::class,
            webhookRegistrar: SlackWebhookRegistrar::class,
            mediaDownloader: SlackMediaDownloader::class,
            mediaLinkPolicy: SlackMediaLinkPolicy::class,
            capabilities: new ChannelCapabilities(
                [
                    ChannelCapability::CallbackButtons,
                    ChannelCapability::RemoveButtons,
                    ChannelCapability::PersistentButtons,
                ],
                // No cap on the buttons of a message: Slack's 50 blocks per message, one row each, come first.
                new ButtonLimits(maxButtons: null, maxButtonsPerRow: 25, maxLabelLength: 75),
            ),
            profile: SlackChannelProfile::class,
        ));
    }

    protected function registerBindings(): void
    {
        $this->app->bind(
            SlackApiClient::class,
            static fn ($app): SlackApiClient => new SlackApiClient($app->make(Factory::class)),
        );
    }

    protected function bootConnector(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'slack');
    }
}
