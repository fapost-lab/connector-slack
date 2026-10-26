<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack;

use Fapost\Connector\Slack\Api\SlackApiClient;
use Fapost\Connector\Slack\Api\SlackApiException;
use Fapost\Connector\Slack\BlockKit\SlackMessageBuilder;
use Fapost\Foundation\Channel\ChannelInterface;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\OutboundMessage;
use Fapost\Foundation\Messaging\ProviderSenderInterface;
use InvalidArgumentException;

/**
 * Sends FaPost messages into a Slack direct message with the app.
 *
 * Core addresses a person by user id (`U…`); `chat.postMessage` to a user id writes into the app's direct message with
 * that user and answers with the channel (`D…`) and the `ts`, which together become the provider message id
 * ({@see SlackMessageRef}). A failure is a {@see DeliveryResult} that is not sent, with Slack's error code and without
 * the token or a media link, as the Telegram sender does.
 */
final readonly class SlackSender implements ProviderSenderInterface
{
    private const array MEDIA_TYPES = ['photo', 'video', 'voice', 'document'];

    public function __construct(
        private SlackApiClient $client,
        private SlackMessageBuilder $builder = new SlackMessageBuilder(),
    ) {
    }

    public function deliver(OutboundMessage $message, ChannelInterface $channel): DeliveryResult
    {
        $token = $channel->getConfig()['token'] ?? null;

        if (! is_string($token) || '' === $token) {
            return new DeliveryResult(sent: false, error: 'Slack bot token is missing.');
        }

        try {
            $ref = 'remove_keyboard' === $message->payload->type
                ? $this->removeButtons($token, $message)
                : $this->post($token, $message);
        } catch (SlackApiException $exception) {
            return new DeliveryResult(sent: false, error: $exception->getMessage());
        }

        return new DeliveryResult(sent: true, providerMessageId: $ref?->toString());
    }

    private function post(string $token, OutboundMessage $message): ?SlackMessageRef
    {
        $payload = $message->payload;
        $html    = 'HTML' === ($message->metadata['parse_mode'] ?? null);

        $built = match (true) {
            in_array($payload->type, ['text', 'keyboard'], true) => $this->builder->text($payload->text, $payload->buttons, $html),
            in_array($payload->type, self::MEDIA_TYPES, true)    => $this->builder->media($payload->media ?? [], $payload->text, $payload->buttons, $html),
            default                                              => throw SlackApiException::unsupported("Unsupported Slack payload type [{$payload->type}]."),
        };

        $body = ['channel' => $message->chatId, 'blocks' => $built['blocks']];

        if ('' !== $built['text']) {
            $body['text'] = $built['text'];
        }

        $response = $this->client->postJson($token, 'chat.postMessage', $body);

        return $this->refOf($response['channel'] ?? null, $response['ts'] ?? null);
    }

    /**
     * Reads the sent message, drops its actions blocks and writes it back. A message without buttons is left alone.
     */
    private function removeButtons(string $token, OutboundMessage $message): ?SlackMessageRef
    {
        $edit = $message->metadata['edit_message_id'] ?? null;
        $ref  = is_string($edit) ? SlackMessageRef::tryFromString($edit) : null;

        if (null === $ref) {
            throw SlackApiException::unsupported('The message to strip buttons from is not a Slack message reference.');
        }

        $history = $this->client->postForm($token, 'conversations.history', [
            'channel'   => $ref->channel,
            'latest'    => $ref->ts,
            'inclusive' => 'true',
            'limit'     => 1,
        ]);

        $sent = $history['messages'][0] ?? null;

        if (! is_array($sent) || ($sent['ts'] ?? null) !== $ref->ts) {
            throw SlackApiException::failed('conversations.history', 'message_not_found');
        }

        /** @var list<array<string, mixed>> $blocks */
        $blocks   = is_array($sent['blocks'] ?? null) ? array_values($sent['blocks']) : [];
        $stripped = SlackMessageBuilder::withoutActions($blocks);

        if (count($stripped) === count($blocks)) {
            return $ref;
        }

        $body = ['channel' => $ref->channel, 'ts' => $ref->ts, 'blocks' => $stripped];

        if (is_string($sent['text'] ?? null) && '' !== $sent['text']) {
            $body['text'] = $sent['text'];
        }

        $this->client->postJson($token, 'chat.update', $body);

        return $ref;
    }

    private function refOf(mixed $channel, mixed $ts): ?SlackMessageRef
    {
        if (! is_string($channel) || ! is_string($ts)) {
            return null;
        }

        try {
            return new SlackMessageRef($channel, $ts);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
