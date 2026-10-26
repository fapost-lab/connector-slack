<?php

declare(strict_types=1);

namespace Fapost\Connector\Slack\Tests\Unit;

use Fapost\Connector\Slack\SlackInboundAdapter;
use Fapost\Connector\Slack\Tests\Support\Fixture;
use Fapost\Foundation\Channel\Ingress\IngressSpec;
use Fapost\Foundation\Channel\Ingress\IngressSpecExecutor;
use Fapost\Foundation\Channel\Ingress\SignedRequest;
use PHPUnit\Framework\TestCase;

/**
 * The adapter's spec is run by Foundation's executor, the reference every ingress runtime has to reproduce, on what
 * Slack documents and on requests signed the way Slack signs them.
 */
final class SlackIngressSpecTest extends TestCase
{
    private const string SECRET = '8f742231b10e8888abcd99yyyzzz85a5';

    private IngressSpec $spec;

    private IngressSpecExecutor $executor;

    protected function setUp(): void
    {
        $this->spec     = (new SlackInboundAdapter())->ingressSpec();
        $this->executor = new IngressSpecExecutor();
    }

    public function test_the_spec_is_version_2_and_serialises_as_declared(): void
    {
        $this->assertSame(2, $this->spec->wireVersion());
        $this->assertSame([
            'v'           => 2,
            'scheme'      => 'hmac_sha256',
            'parameter'   => 'x-slack-signature',
            'prefix'      => 'v0=',
            'idempotency' => 'slack:{channel}:{body.event_id|body.trigger_id}',
            'signed'      => 'v0:{header.x-slack-request-timestamp}:{raw}',
            'timestamp'   => ['header' => 'x-slack-request-timestamp', 'tolerance' => 300],
            'form'        => 'payload',
            'handshake'   => ['when' => '{body.type}', 'equals' => 'url_verification', 'respond' => '{body.challenge}'],
        ], $this->spec->jsonSerialize());
        $this->assertEquals($this->spec, IngressSpec::fromArray($this->spec->jsonSerialize()));
    }

    public function test_slacks_documented_example_verifies_at_its_own_time_and_nowhere_else(): void
    {
        $example = Fixture::json('signature/docs_example');
        $request = SignedRequest::create($example['body'], [
            'X-Slack-Request-Timestamp' => $example['timestamp'],
            'X-Slack-Signature'         => $example['signature'],
        ]);
        $now = (int) $example['timestamp'];

        $this->assertTrue($this->executor->verify($this->spec, $request, $example['secret'], $now));
        $this->assertTrue($this->executor->verify($this->spec, $request, $example['secret'], $now + 300), 'five minutes later');
        $this->assertFalse($this->executor->verify($this->spec, $request, $example['secret'], $now + 301), 'one second too late');
        $this->assertFalse($this->executor->verify($this->spec, $request, $example['secret'], $now - 301), 'from the future');
        $this->assertFalse($this->executor->verify($this->spec, $request, 'another-secret', $now), 'another secret');
        $this->assertFalse($this->executor->verify($this->spec, $request, '', $now), 'no secret');
    }

    public function test_the_example_without_its_timestamp_header_or_with_a_tampered_body_fails(): void
    {
        $example = Fixture::json('signature/docs_example');
        $now     = (int) $example['timestamp'];

        $noTime = SignedRequest::create($example['body'], ['X-Slack-Signature' => $example['signature']]);
        $forged = SignedRequest::create($example['body'] . '&x=1', [
            'X-Slack-Request-Timestamp' => $example['timestamp'],
            'X-Slack-Signature'         => $example['signature'],
        ]);

        $this->assertFalse($this->executor->verify($this->spec, $noTime, $example['secret'], $now));
        $this->assertFalse($this->executor->verify($this->spec, $forged, $example['secret'], $now));
    }

    public function test_a_signature_over_the_raw_body_alone_is_refused(): void
    {
        $body    = '{"type":"event_callback","event_id":"Ev1"}';
        $request = SignedRequest::create($body, [
            'X-Slack-Request-Timestamp' => '1000',
            'X-Slack-Signature'         => 'v0=' . hash_hmac('sha256', $body, self::SECRET),
        ]);

        $this->assertFalse($this->executor->verify($this->spec, $request, self::SECRET, 1000));
    }

    public function test_an_uppercase_signature_is_refused(): void
    {
        $body    = '{"type":"event_callback","event_id":"Ev1"}';
        $request = SignedRequest::create($body, [
            'X-Slack-Request-Timestamp' => '1000',
            'X-Slack-Signature'         => 'v0=' . mb_strtoupper(hash_hmac('sha256', "v0:1000:{$body}", self::SECRET)),
        ]);

        $this->assertFalse($this->executor->verify($this->spec, $request, self::SECRET, 1000));
    }

    public function test_an_event_is_a_json_document_keyed_by_its_event_id(): void
    {
        $body    = (string) file_get_contents(dirname(__DIR__) . '/fixtures/slack/events/message_im_text.json');
        $request = $this->signed($body, 1700000000);

        $this->assertTrue($this->executor->verify($this->spec, $request, self::SECRET, 1700000000));
        $this->assertSame('Ev0PV52K21', $this->executor->document($this->spec, $request)['event_id']);
        $this->assertSame('slack:chan-1:Ev0PV52K21', $this->executor->idempotencyKey($this->spec, $request, 'chan-1'));
        $this->assertNull($this->executor->handshakeResponse($this->spec, $request));
    }

    public function test_a_retried_event_has_the_same_key(): void
    {
        $body  = (string) file_get_contents(dirname(__DIR__) . '/fixtures/slack/events/message_im_text.json');
        $first = $this->signed($body, 1700000000);
        $retry = $this->signed($body, 1700000060, ['X-Slack-Retry-Num' => '1', 'X-Slack-Retry-Reason' => 'http_timeout']);

        $this->assertTrue($this->executor->verify($this->spec, $retry, self::SECRET, 1700000060));
        $this->assertSame(
            $this->executor->idempotencyKey($this->spec, $first, 'chan-1'),
            $this->executor->idempotencyKey($this->spec, $retry, 'chan-1'),
        );
    }

    public function test_url_verification_is_answered_with_the_challenge_once_signed(): void
    {
        $challenge = Fixture::json('events/url_verification');
        $request   = $this->signed((string) json_encode($challenge), 1700000000);

        $this->assertTrue($this->executor->verify($this->spec, $request, self::SECRET, 1700000000));
        $this->assertSame($challenge['challenge'], $this->executor->handshakeResponse($this->spec, $request));
    }

    public function test_a_button_press_is_a_form_whose_payload_field_is_the_document(): void
    {
        $press   = Fixture::json('interactivity/block_actions_fp');
        $body    = 'payload=' . rawurlencode((string) json_encode($press));
        $request = $this->signed($body, 1700000000, ['Content-Type' => 'application/x-www-form-urlencoded']);

        $this->assertTrue($this->executor->verify($this->spec, $request, self::SECRET, 1700000000));
        $this->assertSame($press, $this->executor->document($this->spec, $request));
        $this->assertSame(
            'slack:chan-1:13345224609.738474920.8088930838d88f008e0',
            $this->executor->idempotencyKey($this->spec, $request, 'chan-1'),
        );
        $this->assertNull($this->executor->handshakeResponse($this->spec, $request));
    }

    public function test_a_form_that_slack_url_encodes_with_plus_signs_is_read_the_same(): void
    {
        $press   = ['type' => 'block_actions', 'trigger_id' => 'T 1', 'actions' => [['action_id' => 'fp:0:0', 'value' => 'a b']]];
        $body    = 'payload=' . urlencode((string) json_encode($press));
        $request = $this->signed($body, 1700000000);

        $this->assertSame($press, $this->executor->document($this->spec, $request));
        $this->assertSame('slack:c:T 1', $this->executor->idempotencyKey($this->spec, $request, 'c'));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function signed(string $body, int $timestamp, array $headers = []): SignedRequest
    {
        return SignedRequest::create($body, $headers + [
            'X-Slack-Request-Timestamp' => (string) $timestamp,
            'X-Slack-Signature'         => 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$body}", self::SECRET),
        ]);
    }
}
