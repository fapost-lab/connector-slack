<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack;

use Fapost\Foundation\Channel\ChannelInboundAdapterInterface;
use Fapost\Foundation\Channel\Ingress\IngressHandshake;
use Fapost\Foundation\Channel\Ingress\IngressSpec;
use Fapost\Foundation\Channel\Ingress\IngressTimestamp;
use Fapost\Foundation\Channel\Ingress\SignatureScheme;
use Fapost\Foundation\DTO\IncomingMessage;

/**
 * The inbound half of the Slack channel.
 *
 * Slack signs `v0:{timestamp}:{raw body}` with the app's signing secret, which is the channel's secret token, and
 * refuses nothing older than five minutes. Events and interactivity arrive at the same URL: an event is a JSON body, an
 * interactivity request a form whose `payload` field holds the JSON; the request document is one or the other.
 * Slack retries an event with the same `event_id`, and a button press has no event id, only a `trigger_id`.
 */
final readonly class SlackInboundAdapter implements ChannelInboundAdapterInterface
{
    public function __construct(
        private SlackInboundNormalizer $normalizer = new SlackInboundNormalizer(),
    ) {
    }

    public function ingressSpec(): IngressSpec
    {
        return new IngressSpec(
            scheme: SignatureScheme::HmacSha256,
            parameter: 'x-slack-signature',
            prefix: 'v0=',
            idempotencyTemplate: 'slack:{channel}:{body.event_id|body.trigger_id}',
            signedPayload: 'v0:{header.x-slack-request-timestamp}:{raw}',
            timestamp: new IngressTimestamp('x-slack-request-timestamp', 300),
            formField: 'payload',
            handshake: new IngressHandshake(when: '{body.type}', equals: 'url_verification', respond: '{body.challenge}'),
        );
    }

    public function normalize(array $payload): IncomingMessage
    {
        return $this->normalizer->normalize($payload);
    }
}
