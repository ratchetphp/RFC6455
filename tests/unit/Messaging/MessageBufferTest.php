<?php

namespace Ratchet\RFC6455\Test\Unit\Messaging;

use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\Message;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use React\EventLoop\Factory;

class MessageBufferTest extends \PHPUnit_Framework_TestCase
{
    /**
     * This is to test that MessageBuffer can handle a large receive
     * buffer with many many frames without blowing the stack (pre-v0.4 issue)
     */
    public function testProcessingLotsOfFramesInASingleChunk() {
        $frame = new Frame('a', true, Frame::OP_TEXT);

        $frameRaw = $frame->getContents();

        $data = str_repeat($frameRaw, 1000);

        $messageCount = 0;

        $messageBuffer = new MessageBuffer(
            new CloseFrameChecker(),
            function (Message $message) use (&$messageCount) {
                $messageCount++;
                $this->assertEquals('a', $message->getPayload());
            },
            null,
            false
        );

        $messageBuffer->onData($data);

        $this->assertEquals(1000, $messageCount);
    }

    public function testProcessingMessagesAsynchronouslyWhileBlockingInMessageHandler() {
        $loop = Factory::create();

        $frameA = new Frame('a', true, Frame::OP_TEXT);
        $frameB = new Frame('b', true, Frame::OP_TEXT);

        $bReceived = false;

        $messageBuffer = new MessageBuffer(
            new CloseFrameChecker(),
            function (Message $message) use (&$messageCount, &$bReceived, $loop) {
                $payload = $message->getPayload();
                $bReceived = $payload === 'b';

                if (!$bReceived) {
                    $loop->run();
                }
            },
            null,
            false
        );

        $loop->addPeriodicTimer(0.1, function () use ($messageBuffer, $frameB, $loop) {
            $loop->stop();
            $messageBuffer->onData($frameB->getContents());
        });

        $messageBuffer->onData($frameA->getContents());

        $this->assertTrue($bReceived);
    }

    public function testInvalidFrameLength() {
        $frame = new Frame(str_repeat('a', 200), true, Frame::OP_TEXT);

        $frameRaw = $frame->getContents();

        $frameRaw[1] = "\x7f"; // 127 in the first spot

        $frameRaw[2] = "\xff"; // this will unpack to -1
        $frameRaw[3] = "\xff";
        $frameRaw[4] = "\xff";
        $frameRaw[5] = "\xff";
        $frameRaw[6] = "\xff";
        $frameRaw[7] = "\xff";
        $frameRaw[8] = "\xff";
        $frameRaw[9] = "\xff";

        /** @var Frame $controlFrame */
        $controlFrame = null;
        $messageCount = 0;

        $messageBuffer = new MessageBuffer(
            new CloseFrameChecker(),
            function (Message $message) use (&$messageCount) {
                $messageCount++;
            },
            function (Frame $frame) use (&$controlFrame) {
                $this->assertNull($controlFrame);
                $controlFrame = $frame;
            },
            false,
            null,
            0,
            10
        );

        $messageBuffer->onData($frameRaw);

        $this->assertEquals(0, $messageCount);
        $this->assertTrue($controlFrame instanceof Frame);
        $this->assertEquals(Frame::OP_CLOSE, $controlFrame->getOpcode());
        $this->assertEquals([Frame::CLOSE_PROTOCOL], array_merge(unpack('n*', substr($controlFrame->getPayload(), 0, 2))));

    }

    public function testFrameLengthTooBig() {
        $frame = new Frame(str_repeat('a', 200), true, Frame::OP_TEXT);

        $frameRaw = $frame->getContents();

        $frameRaw[1] = "\x7f"; // 127 in the first spot

        $frameRaw[2] = "\x7f"; // this will unpack to -1
        $frameRaw[3] = "\xff";
        $frameRaw[4] = "\xff";
        $frameRaw[5] = "\xff";
        $frameRaw[6] = "\xff";
        $frameRaw[7] = "\xff";
        $frameRaw[8] = "\xff";
        $frameRaw[9] = "\xff";

        /** @var Frame $controlFrame */
        $controlFrame = null;
        $messageCount = 0;

        $messageBuffer = new MessageBuffer(
            new CloseFrameChecker(),
            function (Message $message) use (&$messageCount) {
                $messageCount++;
            },
            function (Frame $frame) use (&$controlFrame) {
                $this->assertNull($controlFrame);
                $controlFrame = $frame;
            },
            false,
            null,
            0,
            10
        );

        $messageBuffer->onData($frameRaw);

        $this->assertEquals(0, $messageCount);
        $this->assertTrue($controlFrame instanceof Frame);
        $this->assertEquals(Frame::OP_CLOSE, $controlFrame->getOpcode());
        $this->assertEquals([Frame::CLOSE_TOO_BIG], array_merge(unpack('n*', substr($controlFrame->getPayload(), 0, 2))));
    }

    public function testFrameLengthBiggerThanMaxMessagePayload() {
        $frame = new Frame(str_repeat('a', 200), true, Frame::OP_TEXT);

        $frameRaw = $frame->getContents();

        /** @var Frame $controlFrame */
        $controlFrame = null;
        $messageCount = 0;

        $messageBuffer = new MessageBuffer(
            new CloseFrameChecker(),
            function (Message $message) use (&$messageCount) {
                $messageCount++;
            },
            function (Frame $frame) use (&$controlFrame) {
                $this->assertNull($controlFrame);
                $controlFrame = $frame;
            },
            false,
            null,
            100,
            0
        );

        $messageBuffer->onData($frameRaw);

        $this->assertEquals(0, $messageCount);
        $this->assertTrue($controlFrame instanceof Frame);
        $this->assertEquals(Frame::OP_CLOSE, $controlFrame->getOpcode());
        $this->assertEquals([Frame::CLOSE_TOO_BIG], array_merge(unpack('n*', substr($controlFrame->getPayload(), 0, 2))));
    }

    public function testSecondFrameLengthPushesPastMaxMessagePayload() {
        $frame = new Frame(str_repeat('a', 200), false, Frame::OP_TEXT);
        $firstFrameRaw = $frame->getContents();
        $frame = new Frame(str_repeat('b', 200), true, Frame::OP_TEXT);
        $secondFrameRaw = $frame->getContents();

        /** @var Frame $controlFrame */
        $controlFrame = null;
        $messageCount = 0;

        $messageBuffer = new MessageBuffer(
            new CloseFrameChecker(),
            function (Message $message) use (&$messageCount) {
                $messageCount++;
            },
            function (Frame $frame) use (&$controlFrame) {
                $this->assertNull($controlFrame);
                $controlFrame = $frame;
            },
            false,
            null,
            300,
            0
        );

        $messageBuffer->onData($firstFrameRaw);
        // only put part of the second frame in to watch it fail fast
        $messageBuffer->onData(substr($secondFrameRaw, 0, 150));

        $this->assertEquals(0, $messageCount);
        $this->assertTrue($controlFrame instanceof Frame);
        $this->assertEquals(Frame::OP_CLOSE, $controlFrame->getOpcode());
        $this->assertEquals([Frame::CLOSE_TOO_BIG], array_merge(unpack('n*', substr($controlFrame->getPayload(), 0, 2))));
    }
}