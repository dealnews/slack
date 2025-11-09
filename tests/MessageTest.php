<?php

namespace DealNews\Slack\Tests;

use DealNews\Slack\Message;
use DealNews\Slack\MessageAttachmentException;
use DealNews\Slack\MessageException;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(Message::class)]
class MessageTest extends TestCase {

    /**
     * Smoke test against a live Slack webhook.
     */
    #[Group('functional')]
    public function testFunctionality() {
        $url_file = __DIR__ . '/hook_url.txt';
        $this->assertTrue(file_exists($url_file));

        $slack  = new Message(file_get_contents($url_file));
        $result = $slack->send('slacktest', 'this is a test from the DealNews PHP Slack Library');
        $this->assertTrue($result);
    }


    /**
     * Ensures a standard message payload is sent to Slack.
     */
    public function testSendReturnsTrueWhenSlackRespondsOk(): void {
        $expected_payload = [
            'channel'     => '#general',
            'username'    => 'deploybot',
            'attachments' => [],
            'icon_emoji'  => ':rocket:',
            'text'        => 'Deployment complete',
        ];

        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://hooks.slack.com/services/test',
                $this->callback(function (array $options) use ($expected_payload) {
                    $this->assertSame(['Content-Type' => 'application/json'], $options['headers']);
                    $this->assertSame($expected_payload, $options['json']);

                    return true;
                })
            )
            ->willReturn(new Response(200, [], 'ok'));

        $message = new Message('https://hooks.slack.com/services/test', 'deploybot', ':rocket:', $client);

        $this->assertTrue($message->send('#general', 'Deployment complete'));
    }

    /**
     * @param string $response_body
     * @param int    $expected_code
     */
    #[DataProvider('sendFailureDataProvider')]
    public function testSendThrowsRuntimeExceptionWhenSlackDoesNotReturnOk(string $response_body, int $expected_code): void {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('request')
            ->willReturn(new Response(200, [], $response_body));

        $message = new Message('https://hooks.slack.com/services/test', null, null, $client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode($expected_code);

        $message->send('#general', 'Failure');
    }

    /**
     * @return array<string, array{0:string,1:int}>
     */
    public static function sendFailureDataProvider(): array {
        return [
            'error message' => [
                'error',
                1,
            ],
            'empty body'    => [
                '',
                2,
            ],
        ];
    }

    /**
     * Verifies that string messages include text and emoji metadata.
     */
    public function testBuildPackageWithStringMessageAddsTextAndEmoji(): void {
        $message = new Message('https://hooks.slack.com/services/test', 'deploybot', ':rocket:');

        $package = $message->buildPackage('#general', 'Deployment complete', []);

        $this->assertSame(
            [
                'channel'     => '#general',
                'username'    => 'deploybot',
                'attachments' => [],
                'icon_emoji'  => ':rocket:',
                'text'        => 'Deployment complete',
            ],
            $package
        );
    }

    /**
     * Ensures attachment payloads are validated for both message and extra attachments.
     */
    public function testBuildPackageMergesMessageAttachmentAndExtraAttachments(): void {
        $message_attachment = [
            'fallback' => 'primary',
            'color'    => '#00ff00',
            'text'     => 'Deploy output',
        ];
        $extra_attachment   = [
            'fallback' => 'secondary',
            'pretext'  => 'More detail',
        ];

        $message = new Message('https://hooks.slack.com/services/test', 'deploybot');

        $package = $message->buildPackage('#ops', $message_attachment, [$extra_attachment]);

        $this->assertSame(
            [
                'channel'     => '#ops',
                'username'    => 'deploybot',
                'attachments' => [
                    $message_attachment,
                    $extra_attachment,
                ],
            ],
            $package
        );
    }

    /**
     * @param string       $channel
     * @param array|string $message_value
     * @param array        $attachments
     */
    #[DataProvider('invalidPackageDataProvider')]
    public function testBuildPackageThrowsWhenChannelOrBodyMissing(string $channel, array|string $message_value, array $attachments): void {
        $message = new Message('https://hooks.slack.com/services/test');

        $this->expectException(MessageException::class);

        $message->buildPackage($channel, $message_value, $attachments);
    }

    /**
     * @return array<string, array{0:string,1:array|string,2:array}>
     */
    public static function invalidPackageDataProvider(): array {
        return [
            'missing channel'             => [
                '',
                'payload',
                [
                    [
                        'fallback' => 'still valid',
                    ],
                ],
            ],
            'missing text and attachments' => [
                '#ops',
                '',
                [],
            ],
        ];
    }

    /**
     * Validates attachment field filtering keeps boolean values intact.
     */
    public function testValidateAttachmentPreservesFieldValues(): void {
        $attachment = [
            'fallback' => 'primary fallback',
            'fields'   => [
                [
                    'title' => 'Ready',
                    'value' => 'yes',
                    'short' => true,
                ],
                [
                    'title' => 'Approved',
                    'value' => 'no',
                    'short' => false,
                ],
            ],
        ];

        $message    = new Message('https://hooks.slack.com/services/test');
        $validated  = $message->validateAttachment($attachment);

        $this->assertSame(
            [
                'fallback' => 'primary fallback',
                'fields'   => [
                    [
                        'title' => 'Ready',
                        'value' => 'yes',
                        'short' => true,
                    ],
                    [
                        'title' => 'Approved',
                        'value' => 'no',
                        'short' => false,
                    ],
                ],
            ],
            $validated
        );
    }

    /**
     * Ensures attachments without the fallback field are rejected.
     */
    public function testValidateAttachmentRequiresFallback(): void {
        $message = new Message('https://hooks.slack.com/services/test');

        $this->expectException(MessageAttachmentException::class);
        $this->expectExceptionMessage('All attachments require `fallback` to be set');

        $message->validateAttachment(['fallback' => '']);
    }

    /**
     * Ensures invalid colors trigger an exception.
     */
    public function testValidateAttachmentRejectsInvalidColor(): void {
        $message = new Message('https://hooks.slack.com/services/test');

        $this->expectException(MessageAttachmentException::class);
        $this->expectExceptionMessage('Invalid value for `color`');

        $message->validateAttachment(
            [
                'fallback' => 'primary fallback',
                'color'    => 'magenta',
            ]
        );
    }
}
