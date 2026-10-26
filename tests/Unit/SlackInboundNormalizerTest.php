<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Tests\Unit;

use Fapost\Connector\Slack\SlackInboundAdapter;
use Fapost\Connector\Slack\SlackInboundNormalizer;
use Fapost\Connector\Slack\Tests\Support\Fixture;
use Fapost\Foundation\Channel\InboundEventIgnoredException;
use Fapost\Foundation\DTO\IncomingMedia;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\Media\Enums\MediaKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlackInboundNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function ignoredDeliveries(): array
    {
        return [
            'a message of a bot'               => ['events/message_im_bot', 'message from a bot'],
            'the app\'s own user'              => ['events/message_im_own_user', 'message from the app itself'],
            'an edited message'                => ['events/message_changed', 'message subtype message_changed'],
            'the app home being opened'        => ['events/app_home_opened', 'event app_home_opened is not a message'],
            'a url verification'               => ['events/url_verification', 'request type url_verification'],
            'a button of another app or block' => ['interactivity/block_actions_foreign', 'action of another app or block'],
        ];
    }

    /**
     * @return array<string, array{string, IncomingMessageType}>
     */
    public static function fileTypes(): array
    {
        return [
            'image'    => ['image/jpeg', IncomingMessageType::Photo],
            'video'    => ['video/mp4', IncomingMessageType::Video],
            'audio'    => ['audio/mpeg', IncomingMessageType::Audio],
            'document' => ['application/pdf', IncomingMessageType::Document],
        ];
    }

    public function test_a_direct_message_with_text_is_a_text_message(): void
    {
        $message = $this->normalize(Fixture::json('events/message_im_text'));

        $this->assertSame('Ev0PV52K21', $message->updateId);
        $this->assertSame('U2147483697', $message->externalUserId);
        $this->assertSame('D024BE91L', $message->externalChatId);
        $this->assertSame('Hello hello can you hear me?', $message->text);
        $this->assertSame(IncomingMessageType::Text, $message->type);
        $this->assertSame('slack', $message->platform);
        $this->assertSame(
            ['team_id' => 'T123ABC456', 'api_app_id' => 'A123ABC456', 'ts' => '1355517523.000005'],
            $message->payload,
        );
        $this->assertSame([], $message->media);
    }

    public function test_slacks_three_entities_are_decoded_and_nothing_else(): void
    {
        $document                  = Fixture::json('events/message_im_text');
        $document['event']['text'] = 'Tom &amp; Jerry &lt;3 &gt; <@U0LAN0Z89> <https://example.test|site> &amp;lt;';

        $this->assertSame(
            'Tom & Jerry <3 > <@U0LAN0Z89> <https://example.test|site> &lt;',
            $this->normalize($document)->text,
        );
    }

    public function test_a_file_share_carries_its_file_and_the_type_of_the_first_one(): void
    {
        $message = $this->normalize(Fixture::json('events/message_im_file_share'));

        $this->assertSame(IncomingMessageType::Photo, $message->type);
        $this->assertSame('Look at this & tell me <what> it is', $message->text);
        $this->assertEquals(
            [new IncomingMedia('F0S43P1CZ', MediaKind::Image, 'image/gif', 'tedair.gif', 71123)],
            $message->media,
        );
    }

    public function test_a_file_without_text_has_no_text(): void
    {
        $document                   = Fixture::json('events/message_im_file_share');
        $document['event']['text']  = '';
        $document['event']['files'] = [
            ['id' => 'F1', 'name' => 'a.pdf', 'mimetype' => 'application/pdf', 'size' => 5],
            ['id' => 'F2', 'name' => 'b.png', 'mimetype' => 'image/png', 'size' => 6],
        ];

        $message = $this->normalize($document);

        $this->assertNull($message->text);
        $this->assertSame(IncomingMessageType::Document, $message->type);
        $this->assertCount(2, $message->media);
        $this->assertSame('F2', $message->media[1]->providerFileId);
    }

    #[DataProvider('fileTypes')]
    public function test_the_message_type_follows_the_first_file(string $mime, IncomingMessageType $type): void
    {
        $document                   = Fixture::json('events/message_im_file_share');
        $document['event']['files'] = [['id' => 'F1', 'name' => 'x', 'mimetype' => $mime, 'size' => 1]];

        $this->assertSame($type, $this->normalize($document)->type);
    }

    public function test_a_file_that_slack_withholds_is_still_named_by_its_id(): void
    {
        $document                   = Fixture::json('events/message_im_file_share');
        $document['event']['files'] = [['id' => 'F9', 'file_access' => 'check_file_info']];

        $media = $this->normalize($document)->media;

        $this->assertCount(1, $media);
        $this->assertSame('F9', $media[0]->providerFileId);
        $this->assertNull($media[0]->mimeType);
    }

    public function test_a_message_in_a_channel_is_ignored(): void
    {
        $document                          = Fixture::json('events/message_im_text');
        $document['event']['channel_type'] = 'channel';

        $this->expectException(InboundEventIgnoredException::class);
        $this->expectExceptionMessage('message outside a direct message');

        $this->normalize($document);
    }

    #[DataProvider('ignoredDeliveries')]
    public function test_everything_that_is_not_a_users_message_is_ignored_with_a_reason(string $fixture, string $reason): void
    {
        try {
            $this->normalize(Fixture::json($fixture));
            $this->fail('The delivery should have been ignored.');
        } catch (InboundEventIgnoredException $exception) {
            $this->assertSame($reason, $exception->getMessage());
        }
    }

    public function test_a_message_whose_bot_id_is_null_is_a_users_message(): void
    {
        $document                    = Fixture::json('events/message_im_text');
        $document['event']['bot_id'] = null;

        $this->assertSame('Ev0PV52K21', $this->normalize($document)->updateId);
    }

    public function test_a_message_without_authorizations_is_judged_by_its_bot_id_alone(): void
    {
        $document = Fixture::json('events/message_im_own_user');
        unset($document['authorizations']);

        $this->assertSame('U0LAN0Z89', $this->normalize($document)->externalUserId);
    }

    public function test_a_press_on_a_button_of_this_connector_is_a_callback_query_with_the_payload_verbatim(): void
    {
        $message = $this->normalize(Fixture::json('interactivity/block_actions_fp'));

        $this->assertSame(IncomingMessageType::CallbackQuery, $message->type);
        $this->assertSame('s1:b2', $message->text);
        $this->assertSame('13345224609.738474920.8088930838d88f008e0', $message->updateId);
        $this->assertSame('U2147483697', $message->externalUserId);
        $this->assertSame('D024BE91L', $message->externalChatId);
        $this->assertSame('slack', $message->platform);
        $this->assertSame(
            ['message_id' => 'D024BE91L:1721232000.123456', 'team_id' => 'T123ABC456'],
            $message->payload,
        );
    }

    public function test_the_first_action_of_this_connector_wins_among_several(): void
    {
        $document            = Fixture::json('interactivity/block_actions_fp');
        $document['actions'] = [
            ['action_id' => 'other:x', 'value' => 'nope'],
            ['action_id' => 'fp:2:3', 'value' => 'first-ours'],
            ['action_id' => 'fp:0:0', 'value' => 'second-ours'],
        ];

        $this->assertSame('first-ours', $this->normalize($document)->text);
    }

    public function test_a_press_without_a_value_is_ignored(): void
    {
        $document = Fixture::json('interactivity/block_actions_fp');
        unset($document['actions'][0]['value']);

        $this->expectException(InboundEventIgnoredException::class);

        $this->normalize($document);
    }

    public function test_an_unknown_interaction_type_is_ignored(): void
    {
        $this->expectException(InboundEventIgnoredException::class);
        $this->expectExceptionMessage('request type view_submission');

        $this->normalize(['type' => 'view_submission']);
    }

    public function test_an_empty_document_is_ignored(): void
    {
        $this->expectException(InboundEventIgnoredException::class);

        $this->normalize([]);
    }

    public function test_the_adapter_delegates_to_the_normalizer(): void
    {
        $message = (new SlackInboundAdapter())->normalize(Fixture::json('events/message_im_text'));

        $this->assertSame('Ev0PV52K21', $message->updateId);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function normalize(array $document): \Fapost\Foundation\DTO\IncomingMessage
    {
        return (new SlackInboundNormalizer())->normalize($document);
    }
}
