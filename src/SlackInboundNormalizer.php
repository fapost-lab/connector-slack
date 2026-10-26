<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack;

use Fapost\Foundation\Channel\InboundEventIgnoredException;
use Fapost\Foundation\DTO\IncomingMedia;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\Media\Enums\MediaKind;

/**
 * Turns a Slack request document (an Events API `event_callback` or an interactivity `block_actions` payload) into an
 * {@see IncomingMessage}, or says it is not one.
 *
 * Only a person's text or file in a direct message with the app, and a press on one of this connector's own buttons
 * (action id `fp:…`), reach the flow. Everything else is an {@see InboundEventIgnoredException} with a short reason:
 * the app's own messages, edits, deletions, other events and other apps' actions.
 */
final readonly class SlackInboundNormalizer
{
    public const string PLATFORM = 'slack';

    public const string ACTION_PREFIX = 'fp:';

    /**
     * @param  array<string, mixed>  $document
     *
     * @throws InboundEventIgnoredException
     */
    public function normalize(array $document): IncomingMessage
    {
        $type = $document['type'] ?? null;

        return match ($type) {
            'event_callback' => $this->message($document),
            'block_actions'  => $this->callback($document),
            default          => throw $this->ignored('request type ' . (is_string($type) ? $type : 'unknown')),
        };
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function message(array $document): IncomingMessage
    {
        $event = $this->map($document['event'] ?? null);

        if ('message' !== ($event['type'] ?? null)) {
            throw $this->ignored('event ' . $this->name($event['type'] ?? null) . ' is not a message');
        }

        if ('im' !== ($event['channel_type'] ?? null)) {
            throw $this->ignored('message outside a direct message');
        }

        $subtype = $event['subtype'] ?? null;

        if (null !== $subtype && 'file_share' !== $subtype) {
            throw $this->ignored('message subtype ' . $this->name($subtype));
        }

        if ($this->filled($event['bot_id'] ?? null)) {
            throw $this->ignored('message from a bot');
        }

        $user = $this->string($event['user'] ?? null);
        $chat = $this->string($event['channel'] ?? null);
        $id   = $this->string($document['event_id'] ?? null);

        if ('' === $user || '' === $chat || '' === $id) {
            throw $this->ignored('message without a user, a channel or an event id');
        }

        $authorizations = $document['authorizations'] ?? null;
        $botUserId      = is_array($authorizations) ? $this->string($this->map($authorizations[0] ?? null)['user_id'] ?? null) : '';

        if ('' !== $botUserId && $botUserId === $user) {
            throw $this->ignored('message from the app itself');
        }

        $media = $this->media($event['files'] ?? null);
        $text  = $this->decode($this->string($event['text'] ?? null));

        return new IncomingMessage(
            updateId: $id,
            externalUserId: $user,
            externalChatId: $chat,
            text: '' === $text ? null : $text,
            type: $this->typeOf($media),
            platform: self::PLATFORM,
            payload: [
                'team_id'    => $this->string($document['team_id'] ?? null),
                'api_app_id' => $this->string($document['api_app_id'] ?? null),
                'ts'         => $this->string($event['ts'] ?? null),
            ],
            media: $media,
        );
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function callback(array $document): IncomingMessage
    {
        $action = null;

        foreach (is_array($document['actions'] ?? null) ? $document['actions'] : [] as $candidate) {
            $candidate = $this->map($candidate);

            if (str_starts_with($this->string($candidate['action_id'] ?? null), self::ACTION_PREFIX)) {
                $action = $candidate;

                break;
            }
        }

        if (null === $action) {
            throw $this->ignored('action of another app or block');
        }

        $value     = $this->string($action['value'] ?? null);
        $triggerId = $this->string($document['trigger_id'] ?? null);
        $user      = $this->string($this->map($document['user'] ?? null)['id'] ?? null);
        $container = $this->map($document['container'] ?? null);
        $chat      = $this->string($container['channel_id'] ?? null);
        $messageTs = $this->string($container['message_ts'] ?? null);

        if ('' === $value || '' === $triggerId || '' === $user || '' === $chat) {
            throw $this->ignored('button press without a value, a trigger, a user or a channel');
        }

        $payload = ['team_id' => $this->string($this->map($document['team'] ?? null)['id'] ?? $this->map($document['user'] ?? null)['team_id'] ?? null)];

        if ('' !== $messageTs) {
            $payload = ['message_id' => "{$chat}:{$messageTs}"] + $payload;
        }

        return new IncomingMessage(
            updateId: $triggerId,
            externalUserId: $user,
            externalChatId: $chat,
            text: $value,
            type: IncomingMessageType::CallbackQuery,
            platform: self::PLATFORM,
            payload: $payload,
        );
    }

    /**
     * @return list<IncomingMedia>
     */
    private function media(mixed $files): array
    {
        $media = [];

        foreach (is_array($files) ? $files : [] as $file) {
            $file = $this->map($file);
            $id   = $this->string($file['id'] ?? null);

            if ('' === $id) {
                continue;
            }

            $mime = $this->string($file['mimetype'] ?? null);
            $name = $this->string($file['name'] ?? null);

            $media[] = new IncomingMedia(
                providerFileId: $id,
                kind: MediaKind::fromMimeType($mime),
                mimeType: '' === $mime ? null : $mime,
                fileName: '' === $name ? null : $name,
                size: is_int($file['size'] ?? null) ? $file['size'] : null,
            );
        }

        return $media;
    }

    /**
     * @param  list<IncomingMedia>  $media
     */
    private function typeOf(array $media): IncomingMessageType
    {
        if ([] === $media) {
            return IncomingMessageType::Text;
        }

        return match ($media[0]->kind) {
            MediaKind::Image => IncomingMessageType::Photo,
            MediaKind::Video => IncomingMessageType::Video,
            MediaKind::Audio => IncomingMessageType::Audio,
            default          => IncomingMessageType::Document,
        };
    }

    /**
     * Slack escapes exactly three characters in the text it sends; everything else (`<@U…>`, `<https://…>`) stays.
     */
    private function decode(string $text): string
    {
        return strtr($text, ['&amp;' => '&', '&lt;' => '<', '&gt;' => '>']);
    }

    /**
     * @return array<string, mixed>
     */
    private function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) ? (string) $value : '');
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && '' !== $value;
    }

    private function name(mixed $value): string
    {
        return is_string($value) && 1 === preg_match('/^[a-z0-9_.]{1,64}$/D', $value) ? $value : 'unknown';
    }

    private function ignored(string $reason): InboundEventIgnoredException
    {
        return new InboundEventIgnoredException($reason);
    }
}
