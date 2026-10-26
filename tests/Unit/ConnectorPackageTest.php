<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Tests\Unit;

use Fapost\Connector\Slack\Media\SlackMediaDownloader;
use Fapost\Connector\Slack\Media\SlackMediaLinkPolicy;
use Fapost\Connector\Slack\SlackChannelProfile;
use Fapost\Connector\Slack\SlackConnectorServiceProvider;
use Fapost\Connector\Slack\SlackInboundAdapter;
use Fapost\Connector\Slack\SlackSender;
use Fapost\Connector\Slack\SlackWebhookRegistrar;
use Fapost\Foundation\Channel\ChannelInboundAdapterInterface;
use Fapost\Foundation\Channel\Settings\ChannelProfileInterface;
use Fapost\Foundation\Channel\WebhookRegistrarInterface;
use Fapost\Foundation\Connector\ConnectorChannel;
use Fapost\Foundation\Connector\ConnectorRegistrarInterface;
use Fapost\Foundation\Connector\Manifest\ConnectorManifestParser;
use Fapost\Foundation\Lifecycle\AbstractConnectorServiceProvider;
use Fapost\Foundation\Media\ChannelMediaDownloaderInterface;
use Fapost\Foundation\Media\ChannelMediaLinkPolicyInterface;
use Fapost\Foundation\Messaging\ChannelCapability;
use Fapost\Foundation\Messaging\ProviderSenderInterface;
use Illuminate\Contracts\Foundation\Application;
use PHPUnit\Framework\TestCase;

/**
 * The package's declarations are checked with Foundation's parser, the same one Core runs on `platform:update`, and the
 * channel it registers is read back from a registrar.
 */
final class ConnectorPackageTest extends TestCase
{
    public function test_composer_json_holds_a_valid_connector_manifest(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        $result = (new ConnectorManifestParser())->parse($composer);

        $this->assertSame([], $result->lines());
        $this->assertSame('slack', $result->manifest?->id);
        $this->assertSame(['slack'], $result->manifest->channels);
        $this->assertSame(SlackConnectorServiceProvider::class, $result->manifest->provider);
        $this->assertTrue(is_subclass_of(SlackConnectorServiceProvider::class, AbstractConnectorServiceProvider::class));
    }

    public function test_the_provider_registers_one_slack_channel_with_its_services_and_capabilities(): void
    {
        $registrar = new class () implements ConnectorRegistrarInterface {
            /** @var list<ConnectorChannel> */
            public array $channels = [];

            public function registerChannel(ConnectorChannel $channel): void
            {
                $this->channels[] = $channel;
            }
        };

        (new SlackConnectorServiceProvider($this->createStub(Application::class)))->registerChannelsWith($registrar);

        $this->assertCount(1, $registrar->channels);

        $channel = $registrar->channels[0];

        $this->assertSame('slack', $channel->type);
        $this->assertSame(SlackSender::class, $channel->sender);
        $this->assertSame(SlackInboundAdapter::class, $channel->inbound);
        $this->assertSame(SlackWebhookRegistrar::class, $channel->webhookRegistrar);
        $this->assertSame(SlackMediaDownloader::class, $channel->mediaDownloader);
        $this->assertSame(SlackMediaLinkPolicy::class, $channel->mediaLinkPolicy);
        $this->assertSame(SlackChannelProfile::class, $channel->profile);
        $this->assertNull($channel->mediaUploader, 'an uploader would win over the link policy');
        $this->assertNull($channel->mediaLimits);

        $this->assertTrue(is_subclass_of($channel->sender, ProviderSenderInterface::class));
        $this->assertTrue(is_subclass_of($channel->inbound, ChannelInboundAdapterInterface::class));
        $this->assertTrue(is_subclass_of($channel->webhookRegistrar, WebhookRegistrarInterface::class));
        $this->assertTrue(is_subclass_of($channel->mediaDownloader, ChannelMediaDownloaderInterface::class));
        $this->assertTrue(is_subclass_of($channel->mediaLinkPolicy, ChannelMediaLinkPolicyInterface::class));
        $this->assertTrue(is_subclass_of($channel->profile, ChannelProfileInterface::class));

        $capabilities = $channel->capabilities;

        $this->assertEqualsCanonicalizing(
            [ChannelCapability::CallbackButtons, ChannelCapability::RemoveButtons, ChannelCapability::PersistentButtons],
            $capabilities->features,
        );
        $this->assertFalse($capabilities->supports(ChannelCapability::ReplyButtons));
        $this->assertFalse($capabilities->supports(ChannelCapability::ShareContact));
        $this->assertFalse($capabilities->supports(ChannelCapability::ShareLocation));
        $this->assertNull($capabilities->buttonLimits->maxButtons);
        $this->assertSame(25, $capabilities->buttonLimits->maxButtonsPerRow);
        $this->assertSame(75, $capabilities->buttonLimits->maxLabelLength);
    }

    public function test_the_channel_type_of_every_service_is_slack(): void
    {
        $this->assertSame('slack', (new SlackChannelProfile())->channelType());
        $this->assertSame('slack', (new SlackMediaLinkPolicy())->channelType());
        $this->assertSame(86400, (new SlackMediaLinkPolicy())->linkTtlSeconds());
    }
}
