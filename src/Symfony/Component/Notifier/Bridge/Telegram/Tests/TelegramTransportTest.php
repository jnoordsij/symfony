<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Notifier\Bridge\Telegram\Tests;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramOptions;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramTransport;
use Symfony\Component\Notifier\Exception\MultipleExclusiveOptionsUsedException;
use Symfony\Component\Notifier\Exception\TransportException;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Test\TransportTestCase;
use Symfony\Component\Notifier\Tests\Transport\DummyMessage;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class TelegramTransportTest extends TransportTestCase
{
    public static function createTransport(HttpClientInterface $client = null, string $channel = null): TelegramTransport
    {
        return new TelegramTransport('token', $channel, $client ?? new MockHttpClient());
    }

    public static function toStringProvider(): iterable
    {
        yield ['telegram://api.telegram.org', self::createTransport()];
        yield ['telegram://api.telegram.org?channel=testChannel', self::createTransport(null, 'testChannel')];
    }

    public static function supportedMessagesProvider(): iterable
    {
        yield [new ChatMessage('Hello!')];
    }

    public static function unsupportedMessagesProvider(): iterable
    {
        yield [new SmsMessage('0611223344', 'Hello!')];
        yield [new DummyMessage()];
    }

    public function testSendWithErrorResponseThrowsTransportException()
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/post.+testDescription.+400/');

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(400);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn(json_encode(['description' => 'testDescription', 'error_code' => 400]));

        $client = new MockHttpClient(static fn (): ResponseInterface => $response);

        $transport = self::createTransport($client, 'testChannel');

        $transport->send(new ChatMessage('testMessage'));
    }

    public function testSendWithErrorResponseThrowsTransportExceptionForEdit()
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/edit.+testDescription.+404/');

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(400);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn(json_encode(['description' => 'testDescription', 'error_code' => 404]));

        $client = new MockHttpClient(static fn (): ResponseInterface => $response);

        $transport = $this->createTransport($client, 'testChannel');

        $transport->send(new ChatMessage('testMessage', (new TelegramOptions())->edit(123)));
    }

    public function testSendWithOptions()
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(200);

        $content = <<<JSON
            {
                "ok": true,
                "result": {
                    "message_id": 1,
                    "from": {
                        "id": 12345678,
                        "first_name": "YourBot",
                        "username": "YourBot"
                    },
                    "chat": {
                        "id": 1234567890,
                        "first_name": "John",
                        "last_name": "Doe",
                        "username": "JohnDoe",
                        "type": "private"
                    },
                    "date": 1459958199,
                    "text": "Hello from Bot!"
                }
            }
JSON;

        $response->expects($this->once())
            ->method('getContent')
            ->willReturn($content)
        ;

        $expectedBody = [
            'chat_id' => 'testChannel',
            'text' => 'testMessage',
            'parse_mode' => 'MarkdownV2',
        ];

        $client = new MockHttpClient(function (string $method, string $url, array $options = []) use ($response, $expectedBody): ResponseInterface {
            $this->assertStringEndsWith('/sendMessage', $url);
            $this->assertEqualsCanonicalizing($expectedBody, json_decode($options['body'], true));

            return $response;
        });

        $transport = self::createTransport($client, 'testChannel');

        $sentMessage = $transport->send(new ChatMessage('testMessage'));

        $this->assertEquals(1, $sentMessage->getMessageId());
        $this->assertEquals('telegram://api.telegram.org?channel=testChannel', $sentMessage->getTransport());
    }

    public function testSendWithOptionForEditMessage()
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(200);

        $content = <<<JSON
            {
                "ok": true,
                "result": {
                    "message_id": 1,
                    "from": {
                        "id": 12345678,
                        "first_name": "YourBot",
                        "username": "YourBot"
                    },
                    "chat": {
                        "id": 1234567890,
                        "first_name": "John",
                        "last_name": "Doe",
                        "username": "JohnDoe",
                        "type": "private"
                    },
                    "date": 1459958199,
                    "text": "Hello from Bot!"
                }
            }
JSON;

        $response->expects($this->once())
            ->method('getContent')
            ->willReturn($content)
        ;

        $client = new MockHttpClient(function (string $method, string $url) use ($response): ResponseInterface {
            $this->assertStringEndsWith('/editMessageText', $url);

            return $response;
        });

        $transport = $this->createTransport($client, 'testChannel');
        $options = (new TelegramOptions())->edit(123);

        $transport->send(new ChatMessage('testMessage', $options));
    }

    public function testSendWithOptionToAnswerCallbackQuery()
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(200);

        $content = <<<JSON
            {
                "ok": true,
                "result": true
            }
JSON;

        $response->expects($this->once())
            ->method('getContent')
            ->willReturn($content)
        ;

        $client = new MockHttpClient(function (string $method, string $url) use ($response): ResponseInterface {
            $this->assertStringEndsWith('/answerCallbackQuery', $url);

            return $response;
        });

        $transport = $this->createTransport($client, 'testChannel');
        $options = (new TelegramOptions())->answerCallbackQuery('123', true, 1);

        $transport->send(new ChatMessage('testMessage', $options));
    }

    public function testSendWithChannelOverride()
    {
        $channelOverride = 'channelOverride';

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(200);
        $content = <<<JSON
            {
                "ok": true,
                "result": {
                    "message_id": 1,
                    "from": {
                        "id": 12345678,
                        "first_name": "YourBot",
                        "username": "YourBot"
                    },
                    "chat": {
                        "id": 1234567890,
                        "first_name": "John",
                        "last_name": "Doe",
                        "username": "JohnDoe",
                        "type": "private"
                    },
                    "date": 1459958199,
                    "text": "Hello from Bot!"
                }
            }
JSON;

        $response->expects($this->once())
            ->method('getContent')
            ->willReturn($content)
        ;

        $expectedBody = [
            'chat_id' => $channelOverride,
            'text' => 'testMessage',
            'parse_mode' => 'MarkdownV2',
        ];

        $client = new MockHttpClient(function (string $method, string $url, array $options = []) use ($response, $expectedBody): ResponseInterface {
            $this->assertEqualsCanonicalizing($expectedBody, json_decode($options['body'], true));

            return $response;
        });

        $transport = self::createTransport($client, 'defaultChannel');

        $messageOptions = new TelegramOptions();
        $messageOptions->chatId($channelOverride);

        $sentMessage = $transport->send(new ChatMessage('testMessage', $messageOptions));

        $this->assertEquals(1, $sentMessage->getMessageId());
        $this->assertEquals('telegram://api.telegram.org?channel=defaultChannel', $sentMessage->getTransport());
    }

    public function testSendWithMarkdownShouldEscapeSpecialCharacters()
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(200);

        $content = <<<JSON
            {
                "ok": true,
                "result": {
                    "message_id": 1,
                    "from": {
                        "id": 12345678,
                        "first_name": "YourBot",
                        "username": "YourBot"
                    },
                    "chat": {
                        "id": 1234567890,
                        "first_name": "John",
                        "last_name": "Doe",
                        "username": "JohnDoe",
                        "type": "private"
                    },
                    "date": 1459958199,
                    "text": "Hello from Bot!"
                }
            }
JSON;

        $response->expects($this->once())
            ->method('getContent')
            ->willReturn($content)
        ;

        $expectedBody = [
            'chat_id' => 'testChannel',
            'text' => 'I contain special characters \_ \* \[ \] \( \) \~ \` \> \# \+ \- \= \| \{ \} \. \! \\\\ to send\.',
            'parse_mode' => 'MarkdownV2',
        ];

        $client = new MockHttpClient(function (string $method, string $url, array $options = []) use ($response, $expectedBody): ResponseInterface {
            $this->assertEqualsCanonicalizing($expectedBody, json_decode($options['body'], true));

            return $response;
        });

        $transport = self::createTransport($client, 'testChannel');

        $transport->send(new ChatMessage('I contain special characters _ * [ ] ( ) ~ ` > # + - = | { } . ! \\ to send.'));
    }

    public static function sendFileByHttpUrlProvider(): array
    {
        return [
            'photo' => [
                'messageOptions' => (new TelegramOptions())->photo('https://localhost/photo.png')->hasSpoiler(true),
                'endpoint' => 'sendPhoto',
                'expectedBody' => [
                    'photo' => 'https://localhost/photo.png',
                    'has_spoiler' => true,
                    'chat_id' => 'testChannel',
                    'parse_mode' => 'MarkdownV2',
                    'caption' => 'testMessage',
                ],
                'responseContent' => <<<JSON
                    "photo": [
                        {
                            "file_id": "ABCDEF",
                            "file_unique_id" : "ABCDEF1",
                            "file_size": 1378,
                            "width": 90,
                            "height": 51
                        }
                    ],
                    "caption": "testMessage"
                    JSON,
            ],
            'video' => [
                'messageOptions' => (new TelegramOptions())->video('https://localhost/video.mp4'),
                'endpoint' => 'sendVideo',
                'expectedBody' => [
                    'video' => 'https://localhost/video.mp4',
                    'chat_id' => 'testChannel',
                    'parse_mode' => 'MarkdownV2',
                    'caption' => 'testMessage',
                ],
                'responseContent' => <<<JSON
                    "video": [
                        {
                            "file_id": "ABCDEF",
                            "file_unique_id" : "ABCDEF1",
                            "file_size": 1378
                        }
                    ],
                    "caption": "testMessage"
                    JSON,
            ],
            'animation' => [
                'messageOptions' => (new TelegramOptions())->animation('https://localhost/animation.gif'),
                'endpoint' => 'sendAnimation',
                'expectedBody' => [
                    'animation' => 'https://localhost/animation.gif',
                    'chat_id' => 'testChannel',
                    'parse_mode' => 'MarkdownV2',
                    'caption' => 'testMessage',
                ],
                'responseContent' => <<<JSON
                    "animation": [
                        {
                            "file_id": "ABCDEF",
                            "file_unique_id" : "ABCDEF1",
                            "file_size": 1378
                        }
                    ],
                    "caption": "testMessage"
                    JSON,
            ],
            'audio' => [
                'messageOptions' => (new TelegramOptions())->audio('https://localhost/audio.ogg'),
                'endpoint' => 'sendAudio',
                'expectedBody' => [
                    'audio' => 'https://localhost/audio.ogg',
                    'chat_id' => 'testChannel',
                    'parse_mode' => 'MarkdownV2',
                    'caption' => 'testMessage',
                ],
                'responseContent' => <<<JSON
                    "audio": [
                        {
                            "file_id": "ABCDEF",
                            "file_unique_id" : "ABCDEF1",
                            "file_size": 1378
                        }
                    ],
                    "caption": "testMessage"
                    JSON,
            ],
            'document' => [
                'messageOptions' => (new TelegramOptions())->document('https://localhost/document.odt'),
                'endpoint' => 'sendDocument',
                'expectedBody' => [
                    'document' => 'https://localhost/document.odt',
                    'chat_id' => 'testChannel',
                    'parse_mode' => 'MarkdownV2',
                    'caption' => 'testMessage',
                ],
                'responseContent' => <<<JSON
                    "document": [
                        {
                            "file_id": "ABCDEF",
                            "file_unique_id" : "ABCDEF1",
                            "file_size": 1378,
                            "file_name": "document.odt",
                            "mime_type": "application/vnd.oasis.opendocument.text"
                        }
                    ],
                    "caption": "testMessage"
                    JSON,
            ],
            'sticker' => [
                'messageOptions' => (new TelegramOptions())->sticker('https://localhost/sticker.webp', '🤖'),
                'endpoint' => 'sendSticker',
                'expectedBody' => [
                    'sticker' => 'https://localhost/sticker.webp',
                    'chat_id' => 'testChannel',
                    'parse_mode' => 'MarkdownV2',
                    'emoji' => '🤖',
                ],
                'responseContent' => <<<JSON
                    "sticker": [
                        {
                            "file_id": "ABCDEF",
                            "file_unique_id" : "ABCDEF1",
                            "file_size": 1378,
                            "type": "regular",
                            "width": 100,
                            "height": 110,
                            "is_animated": false,
                            "is_video": false,
                            "emoji": "🤖"
                        }
                    ],
                    "caption": "testMessage"
                    JSON,
            ],
            'sticker-without-emoji' => [
                'messageOptions' => (new TelegramOptions())->sticker('https://localhost/sticker.webp'),
                'endpoint' => 'sendSticker',
                'expectedBody' => [
                    'sticker' => 'https://localhost/sticker.webp',
                    'chat_id' => 'testChannel',
                    'parse_mode' => 'MarkdownV2',
                ],
                'responseContent' => <<<JSON
                    "sticker": [
                        {
                            "file_id": "ABCDEF",
                            "file_unique_id" : "ABCDEF1",
                            "file_size": 1378,
                            "type": "regular",
                            "width": 100,
                            "height": 110,
                            "is_animated": false,
                            "is_video": false
                        }
                    ],
                    "caption": "testMessage"
                    JSON,
            ],
        ];
    }

    /**
     * @dataProvider sendFileByHttpUrlProvider
     */
    public function testSendFileByHttpUrlWithOptions(
        TelegramOptions $messageOptions,
        string $endpoint,
        array $expectedBody,
        string $responseContent,
    ) {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(200);

        $content = <<<JSON
            {
                "ok": true,
                "result": {
                    "message_id": 1,
                    "from": {
                        "id": 12345678,
                        "is_bot": true,
                        "first_name": "YourBot",
                        "username": "YourBot"
                    },
                    "chat": {
                        "id": 1234567890,
                        "first_name": "John",
                        "last_name": "Doe",
                        "username": "JohnDoe",
                        "type": "private"
                    },
                    "date": 1459958199,
                    $responseContent
                }
            }
JSON;

        $response->expects($this->once())
            ->method('getContent')
            ->willReturn($content)
        ;

        $client = new MockHttpClient(function (string $method, string $url, array $options = []) use ($response, $expectedBody, $endpoint): ResponseInterface {
            $this->assertStringEndsWith($endpoint, $url);
            $this->assertEqualsCanonicalizing($expectedBody, json_decode($options['body'], true));

            return $response;
        });

        $transport = self::createTransport($client, 'testChannel');
        $sentMessage = $transport->send(new ChatMessage('testMessage', $messageOptions));

        $this->assertEquals(1, $sentMessage->getMessageId());
        $this->assertEquals('telegram://api.telegram.org?channel=testChannel', $sentMessage->getTransport());
    }

    public static function sendFileByFileIdProvider(): array
    {
        return [
            'photo' => [
                'messageOptions' => (new TelegramOptions())->photo('ABCDEF')->hasSpoiler(true),
                'endpoint' => 'sendPhoto',
                'expectedBody' => [
                    'photo' => 'ABCDEF',
                    'has_spoiler' => true,
                    'chat_id' => 'testChannel',
                    'parse_mode' => 'MarkdownV2',
                    'caption' => 'testMessage',
                ],
                'responseContent' => <<<JSON
                    "photo": [
                        {
                            "file_id": "ABCDEF",
                            "file_unique_id" : "ABCDEF1",
                            "file_size": 1378,
                            "width": 90,
                            "height": 51
                        }
                    ],
                    "caption": "testMessage"
                    JSON,
            ],
            'video' => [
                'messageOptions' => (new TelegramOptions())->video('ABCDEF'),
                'endpoint' => 'sendVideo',
                'expectedBody' => [
                    'video' => 'ABCDEF',
                    'chat_id' => 'testChannel',
                    'parse_mode' => 'MarkdownV2',
                    'caption' => 'testMessage',
                ],
                'responseContent' => <<<JSON
                    "video": [
                        {
                            "file_id": "ABCDEF",
                            "file_unique_id" : "ABCDEF1",
                            "file_size": 1378
                        }
                    ],
                    "caption": "testMessage"
                    JSON,
            ],
            'animation' => [
                'messageOptions' => (new TelegramOptions())->animation('ABCDEF'),
                'endpoint' => 'sendAnimation',
                'expectedBody' => [
                    'animation' => 'ABCDEF',
                    'chat_id' => 'testChannel',
                    'parse_mode' => 'MarkdownV2',
                    'caption' => 'testMessage',
                ],
                'responseContent' => <<<JSON
                    "animation": [
                        {
                            "file_id": "ABCDEF",
                            "file_unique_id" : "ABCDEF1",
                            "file_size": 1378
                        }
                    ],
                    "caption": "testMessage"
                    JSON,
            ],
            'audio' => [
                'messageOptions' => (new TelegramOptions())->audio('ABCDEF'),
                'endpoint' => 'sendAudio',
                'expectedBody' => [
                    'audio' => 'ABCDEF',
                    'chat_id' => 'testChannel',
                    'parse_mode' => 'MarkdownV2',
                    'caption' => 'testMessage',
                ],
                'responseContent' => <<<JSON
                    "audio": [
                        {
                            "file_id": "ABCDEF",
                            "file_unique_id" : "ABCDEF1",
                            "file_size": 1378
                        }
                    ],
                    "caption": "testMessage"
                    JSON,
            ],
            'document' => [
                'messageOptions' => (new TelegramOptions())->document('ABCDEF'),
                'endpoint' => 'sendDocument',
                'expectedBody' => [
                    'document' => 'ABCDEF',
                    'chat_id' => 'testChannel',
                    'parse_mode' => 'MarkdownV2',
                    'caption' => 'testMessage',
                ],
                'responseContent' => <<<JSON
                    "document": [
                        {
                            "file_id": "ABCDEF",
                            "file_unique_id" : "ABCDEF1",
                            "file_size": 1378,
                            "file_name": "document.odt",
                            "mime_type": "application/vnd.oasis.opendocument.text"
                        }
                    ],
                    "caption": "testMessage"
                    JSON,
            ],
        ];
    }

    /**
     * @dataProvider sendFileByFileIdProvider
     */
    public function testSendFileByFileIdWithOptions(
        TelegramOptions $messageOptions,
        string $endpoint,
        array $expectedBody,
        string $responseContent,
    ) {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(200);

        $content = <<<JSON
            {
                "ok": true,
                "result": {
                    "message_id": 1,
                    "from": {
                        "id": 12345678,
                        "is_bot": true,
                        "first_name": "YourBot",
                        "username": "YourBot"
                    },
                    "chat": {
                        "id": 1234567890,
                        "first_name": "John",
                        "last_name": "Doe",
                        "username": "JohnDoe",
                        "type": "private"
                    },
                    "date": 1459958199,
                    $responseContent
                }
            }
JSON;

        $response->expects($this->once())
            ->method('getContent')
            ->willReturn($content)
        ;

        $client = new MockHttpClient(function (string $method, string $url, array $options = []) use ($response, $expectedBody, $endpoint): ResponseInterface {
            $this->assertStringEndsWith($endpoint, $url);
            $this->assertSame($expectedBody, json_decode($options['body'], true));

            return $response;
        });

        $transport = self::createTransport($client, 'testChannel');
        $sentMessage = $transport->send(new ChatMessage('testMessage', $messageOptions));

        $this->assertEquals(1, $sentMessage->getMessageId());
        $this->assertEquals('telegram://api.telegram.org?channel=testChannel', $sentMessage->getTransport());
    }

    private const FIXTURE_FILE = __DIR__.'/fixtures.png';

    public static function sendFileByUploadProvider(): array
    {
        return [
            'photo' => [
                'messageOptions' => (new TelegramOptions())->uploadPhoto(self::FIXTURE_FILE)->hasSpoiler(true),
                'endpoint' => 'sendPhoto',
                'fileOption' => 'photo',
                'expectedBody' => [
                    'has_spoiler' => true,
                    'chat_id' => 'testChannel',
                    'parse_mode' => 'MarkdownV2',
                    'photo' => self::FIXTURE_FILE,
                    'caption' => 'testMessage',
                ],
                'responseContent' => <<<JSON
                    "photo": [
                        {
                            "file_id": "ABCDEF",
                            "file_unique_id" : "ABCDEF1",
                            "file_size": 1378,
                            "width": 90,
                            "height": 51
                        }
                    ],
                    "caption": "testMessage"
                    JSON,
            ],
            'video' => [
                'messageOptions' => (new TelegramOptions())->uploadVideo(self::FIXTURE_FILE),
                'endpoint' => 'sendVideo',
                'fileOption' => 'video',
                'expectedBody' => [
                    'chat_id' => 'testChannel',
                    'parse_mode' => 'MarkdownV2',
                    'video' => self::FIXTURE_FILE,
                    'caption' => 'testMessage',
                ],
                'responseContent' => <<<JSON
                    "video": [
                        {
                            "file_id": "ABCDEF",
                            "file_unique_id" : "ABCDEF1",
                            "file_size": 1378
                        }
                    ],
                    "caption": "testMessage"
                    JSON,
            ],
            'animation' => [
                'messageOptions' => (new TelegramOptions())->uploadAnimation(self::FIXTURE_FILE),
                'endpoint' => 'sendAnimation',
                'fileOption' => 'animation',
                'expectedBody' => [
                    'chat_id' => 'testChannel',
                    'parse_mode' => 'MarkdownV2',
                    'animation' => self::FIXTURE_FILE,
                    'caption' => 'testMessage',
                ],
                'responseContent' => <<<JSON
                    "animation": [
                        {
                            "file_id": "ABCDEF",
                            "file_unique_id" : "ABCDEF1",
                            "file_size": 1378
                        }
                    ],
                    "caption": "testMessage"
                    JSON,
            ],
            'audio' => [
                'messageOptions' => (new TelegramOptions())->uploadAudio(self::FIXTURE_FILE),
                'endpoint' => 'sendAudio',
                'fileOption' => 'audio',
                'expectedBody' => [
                    'chat_id' => 'testChannel',
                    'parse_mode' => 'MarkdownV2',
                    'audio' => self::FIXTURE_FILE,
                    'caption' => 'testMessage',
                ],
                'responseContent' => <<<JSON
                    "audio": [
                        {
                            "file_id": "ABCDEF",
                            "file_unique_id" : "ABCDEF1",
                            "file_size": 1378
                        }
                    ],
                    "caption": "testMessage"
                    JSON,
            ],
            'document' => [
                'messageOptions' => (new TelegramOptions())->uploadDocument(self::FIXTURE_FILE),
                'endpoint' => 'sendDocument',
                'fileOption' => 'document',
                'expectedBody' => [
                    'chat_id' => 'testChannel',
                    'parse_mode' => 'MarkdownV2',
                    'document' => self::FIXTURE_FILE,
                    'caption' => 'testMessage',
                ],
                'responseContent' => <<<JSON
                    "document": [
                        {
                            "file_id": "ABCDEF",
                            "file_unique_id" : "ABCDEF1",
                            "file_size": 1378,
                            "file_name": "document.odt",
                            "mime_type": "application/vnd.oasis.opendocument.text"
                        }
                    ],
                    "caption": "testMessage"
                    JSON,
            ],
            'sticker' => [
                'messageOptions' => (new TelegramOptions())->uploadSticker(self::FIXTURE_FILE, '🤖'),
                'endpoint' => 'sendSticker',
                'fileOption' => 'sticker',
                'expectedBody' => [
                    'emoji' => '🤖',
                    'chat_id' => 'testChannel',
                    'parse_mode' => 'MarkdownV2',
                    'sticker' => self::FIXTURE_FILE,
                ],
                'responseContent' => <<<JSON
                    "sticker": [
                        {
                            "file_id": "ABCDEF",
                            "file_unique_id" : "ABCDEF1",
                            "file_size": 1378,
                            "type": "regular",
                            "width": 100,
                            "height": 110,
                            "is_animated": false,
                            "is_video": false,
                            "emoji": "🤖"
                        }
                    ],
                    "caption": "testMessage"
                    JSON,
            ],
            'sticker-without-emoji' => [
                'messageOptions' => (new TelegramOptions())->uploadSticker(self::FIXTURE_FILE),
                'endpoint' => 'sendSticker',
                'fileOption' => 'sticker',
                'expectedBody' => [
                    'chat_id' => 'testChannel',
                    'parse_mode' => 'MarkdownV2',
                    'sticker' => self::FIXTURE_FILE,
                ],
                'responseContent' => <<<JSON
                    "sticker": [
                        {
                            "file_id": "ABCDEF",
                            "file_unique_id" : "ABCDEF1",
                            "file_size": 1378,
                            "type": "regular",
                            "width": 100,
                            "height": 110,
                            "is_animated": false,
                            "is_video": false
                        }
                    ],
                    "caption": "testMessage"
                    JSON,
            ],
        ];
    }

    /**
     * @dataProvider sendFileByUploadProvider
     *
     * @requires extension fileinfo
     */
    public function testSendFileByUploadWithOptions(
        TelegramOptions $messageOptions,
        string $endpoint,
        string $fileOption,
        array $expectedParameters,
        string $responseContent,
    ) {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(200);

        $content = <<<JSON
            {
                "ok": true,
                "result": {
                    "message_id": 1,
                    "from": {
                        "id": 12345678,
                        "is_bot": true,
                        "first_name": "YourBot",
                        "username": "YourBot"
                    },
                    "chat": {
                        "id": 1234567890,
                        "first_name": "John",
                        "last_name": "Doe",
                        "username": "JohnDoe",
                        "type": "private"
                    },
                    "date": 1459958199,
                    $responseContent
                }
            }
JSON;

        $response->expects($this->once())
            ->method('getContent')
            ->willReturn($content)
        ;

        $client = new MockHttpClient(function (string $method, string $url, array $options = []) use ($response, $expectedParameters, $fileOption, $endpoint): ResponseInterface {
            $this->assertStringEndsWith($endpoint, $url);
            $this->assertSame(1, preg_match('/^Content-Type: multipart\/form-data; boundary=(?<boundary>.+)$/', $options['normalized_headers']['content-type'][0], $matches));

            $expectedBody = '';
            foreach ($expectedParameters as $key => $value) {
                if (\is_bool($value)) {
                    if (!$value) {
                        continue;
                    }
                    $value = 1;
                }
                if ($key === $fileOption) {
                    $expectedBody .= <<<BODY
                        --{$matches['boundary']}
                        Content-Disposition: form-data; name="$key"; filename="fixtures.png"
                        Content-Type: image/png

                        %s

                        BODY;
                    continue;
                }
                $expectedBody .= <<<BODY
                    --{$matches['boundary']}
                    Content-Disposition: form-data; name="$key"

                    $value

                    BODY;
            }
            $expectedBody .= <<<BODY
                --{$matches['boundary']}--

                BODY;
            $expectedBody = str_replace("\n", "\r\n", $expectedBody);
            $expectedBody = sprintf($expectedBody, file_get_contents(__DIR__.'/fixtures.png'));

            $body = '';
            do {
                $body .= $chunk = $options['body']();
            } while ('' !== $chunk);
            $this->assertSame($expectedBody, $body);

            return $response;
        });

        $transport = self::createTransport($client, 'testChannel');
        $sentMessage = $transport->send(new ChatMessage('testMessage', $messageOptions));

        $this->assertEquals(1, $sentMessage->getMessageId());
        $this->assertEquals('telegram://api.telegram.org?channel=testChannel', $sentMessage->getTransport());
    }

    public function testSendLocationWithOptions()
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(200);

        $content = <<<JSON
            {
                "ok": true,
                "result": {
                    "message_id": 1,
                    "from": {
                        "id": 12345678,
                        "is_bot": true,
                        "first_name": "YourBot",
                        "username": "YourBot"
                    },
                    "chat": {
                        "id": 1234567890,
                        "first_name": "John",
                        "last_name": "Doe",
                        "username": "JohnDoe",
                        "type": "private"
                    },
                    "date": 1459958199,
                    "location": {
                         "latitude": 48.8566,
                         "longitude": 2.3522
                    }
                }
            }
JSON;

        $response->expects($this->once())
            ->method('getContent')
            ->willReturn($content)
        ;

        $expectedBody = [
            'latitude' => 48.8566,
            'longitude' => 2.3522,
            'chat_id' => 'testChannel',
            'parse_mode' => 'MarkdownV2',
        ];

        $client = new MockHttpClient(function (string $method, string $url, array $options = []) use ($response, $expectedBody): ResponseInterface {
            $this->assertStringEndsWith('/sendLocation', $url);
            $this->assertEqualsCanonicalizing($expectedBody, json_decode($options['body'], true));

            return $response;
        });

        $transport = self::createTransport($client, 'testChannel');

        $messageOptions = new TelegramOptions();
        $messageOptions
            ->location(48.8566, 2.3522)
        ;

        $sentMessage = $transport->send(new ChatMessage('', $messageOptions));

        $this->assertEquals(1, $sentMessage->getMessageId());
        $this->assertEquals('telegram://api.telegram.org?channel=testChannel', $sentMessage->getTransport());
    }

    public function testSendVenueWithOptions()
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(200);

        $content = <<<JSON
            {
                "ok": true,
                "result": {
                    "message_id": 1,
                    "from": {
                        "id": 12345678,
                        "is_bot": true,
                        "first_name": "YourBot",
                        "username": "YourBot"
                    },
                    "chat": {
                        "id": 1234567890,
                        "first_name": "John",
                        "last_name": "Doe",
                        "username": "JohnDoe",
                        "type": "private"
                    },
                    "date": 1459958199,
                    "location": {
                         "latitude": 48.8566,
                         "longitude": 2.3522
                    },
                    "venue": {
                        "location": {
                             "latitude": 48.8566,
                             "longitude": 2.3522
                        },
                        "title": "Center of Paris",
                        "address": "France, Paris"
                    }
                }
            }
JSON;

        $response->expects($this->once())
            ->method('getContent')
            ->willReturn($content)
        ;

        $expectedBody = [
            'latitude' => 48.8566,
            'longitude' => 2.3522,
            'title' => 'Center of Paris',
            'address' => 'France, Paris',
            'chat_id' => 'testChannel',
            'parse_mode' => 'MarkdownV2',
        ];

        $client = new MockHttpClient(function (string $method, string $url, array $options = []) use ($response, $expectedBody): ResponseInterface {
            $this->assertStringEndsWith('/sendVenue', $url);
            $this->assertEqualsCanonicalizing($expectedBody, json_decode($options['body'], true));

            return $response;
        });

        $transport = self::createTransport($client, 'testChannel');

        $messageOptions = new TelegramOptions();
        $messageOptions
            ->venue(48.8566, 2.3522, 'Center of Paris', 'France, Paris')
        ;

        $sentMessage = $transport->send(new ChatMessage('', $messageOptions));

        $this->assertEquals(1, $sentMessage->getMessageId());
        $this->assertEquals('telegram://api.telegram.org?channel=testChannel', $sentMessage->getTransport());
    }

    public function testSendContactWithOptions()
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(200);
        $vCard = <<<V_CARD
            BEGIN:VCARD
            VERSION:3.0
            N:Doe;John;;;
            FN:John Doe
            EMAIL;type=INTERNET;type=WORK;type=pref:johnDoe@example.org
            TEL;type=WORK;type=pref:+330186657200
            END:VCARD
            V_CARD;

        $content = <<<JSON
            {
                "ok": true,
                "result": {
                    "message_id": 1,
                    "from": {
                        "id": 12345678,
                        "is_bot": true,
                        "first_name": "YourBot",
                        "username": "YourBot"
                    },
                    "chat": {
                        "id": 1234567890,
                        "first_name": "John",
                        "last_name": "Doe",
                        "username": "JohnDoe",
                        "type": "private"
                    },
                    "date": 1459958199,
                    "contact": {
                      "phone_number": "+330186657200",
                      "first_name": "John",
                      "last_name": "Doe",
                      "vcard": "BEGIN:VCARD\\nVERSION:3.0\\nN:Doe;John;;;\\nFN:John Doe\\nEMAIL;type=INTERNET;type=WORK;type=pref:johnDoe@example.org\\nTEL;type=WORK;type=pref:+330186657200\\nEND:VCARD",
                      "user_id": 1234567891
                    }
                }
            }
JSON;

        $response->expects($this->once())
            ->method('getContent')
            ->willReturn($content)
        ;

        $expectedBody = [
            'phone_number' => '+330186657200',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'vcard' => $vCard,
            'chat_id' => 'testChannel',
            'parse_mode' => 'MarkdownV2',
        ];

        $client = new MockHttpClient(function (string $method, string $url, array $options = []) use ($response, $expectedBody): ResponseInterface {
            $this->assertStringEndsWith('/sendContact', $url);
            $this->assertEqualsCanonicalizing($expectedBody, json_decode($options['body'], true));

            return $response;
        });

        $transport = self::createTransport($client, 'testChannel');

        $messageOptions = new TelegramOptions();
        $messageOptions
            ->contact('+330186657200', 'John', 'Doe', $vCard)
        ;

        $sentMessage = $transport->send(new ChatMessage('', $messageOptions));

        $this->assertEquals(1, $sentMessage->getMessageId());
        $this->assertEquals('telegram://api.telegram.org?channel=testChannel', $sentMessage->getTransport());
    }

    public static function exclusiveOptionsDataProvider(): array
    {
        return [
            'edit' => [(new TelegramOptions())->edit(1)->video('')],
            'answerCallbackQuery' => [(new TelegramOptions())->answerCallbackQuery('')->video('')],
            'photo' => [(new TelegramOptions())->photo('')->video('')],
            'location' => [(new TelegramOptions())->location(48.8566, 2.3522)->video('')],
            'audio' => [(new TelegramOptions())->audio('')->video('')],
            'document' => [(new TelegramOptions())->document('')->video('')],
            'video' => [(new TelegramOptions())->video('')->animation('')],
            'animation' => [(new TelegramOptions())->animation('')->video('')],
            'venue' => [(new TelegramOptions())->venue(48.8566, 2.3522, '', '')->video('')],
            'contact' => [(new TelegramOptions())->contact('', '')->video('')],
            'sticker' => [(new TelegramOptions())->sticker('')->video('')],
            'uploadPhoto' => [(new TelegramOptions())->uploadPhoto(self::FIXTURE_FILE)->video('')],
            'uploadAudio' => [(new TelegramOptions())->uploadAudio(self::FIXTURE_FILE)->video('')],
            'uploadDocument' => [(new TelegramOptions())->uploadDocument(self::FIXTURE_FILE)->video('')],
            'uploadVideo' => [(new TelegramOptions())->uploadVideo(self::FIXTURE_FILE)->animation('')],
            'uploadAnimation' => [(new TelegramOptions())->uploadAnimation(self::FIXTURE_FILE)->video('')],
            'uploadSticker' => [(new TelegramOptions())->uploadSticker(self::FIXTURE_FILE)->video('')],
        ];
    }

    /**
     * @dataProvider exclusiveOptionsDataProvider
     */
    public function testUsingMultipleExclusiveOptionsWillProvideExceptions(TelegramOptions $messageOptions)
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options = []): ResponseInterface {
            self::fail('Telegram API should not be called');
        });
        $transport = self::createTransport($client, 'testChannel');

        $this->expectException(MultipleExclusiveOptionsUsedException::class);
        $transport->send(new ChatMessage('', $messageOptions));
    }
}
