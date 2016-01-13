<?php
namespace Ratchet\RFC6455\Messaging\Streaming;
use Ratchet\RFC6455\Messaging\Protocol\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Protocol\MessageInterface;
use Ratchet\RFC6455\Messaging\Protocol\FrameInterface;
use Ratchet\RFC6455\Messaging\Protocol\Message;
use Ratchet\RFC6455\Messaging\Protocol\Frame;

class MessageStreamer {
    /**
     * @var \Ratchet\RFC6455\Messaging\Protocol\CloseFrameChecker
     */
    private $closeFrameChecker;

    /**
     * @var callable
     */
    private $exceptionFactory;

    /**
     * @var \Ratchet\RFC6455\Messaging\Protocol\Message
     */
    private $messageBuffer;

    /**
     * @var \Ratchet\RFC6455\Messaging\Protocol\Frame
     */
    private $frameBuffer;

    /**
     * @var callable
     */
    private $onMessage;

    /**
     * @var callable
     */
    private $onControl;

    /**
     * @var bool
     */
    private $checkForMask;

    function __construct(
        CloseFrameChecker $frameChecker,
        callable $onMessage,
        callable $onControl = null,
        $expectMask = true,
        $exceptionFactory = null
    ) {
        $this->closeFrameChecker = $frameChecker;
        $this->checkForMask = (bool)$expectMask;

        $this->exceptionFactory ?: $this->exceptionFactory = function($msg) {
            return new \UnderflowException($msg);
        };

        $this->onMessage = $onMessage;
        $this->onControl = $onControl ?: function() {};
    }

    /**
     * @param string $data
     * @return null
     */
    public function onData($data) {
        $this->messageBuffer ?: $this->messageBuffer = $this->newMessage();
        $this->frameBuffer   ?: $this->frameBuffer   = $this->newFrame();

        $this->frameBuffer->addBuffer($data);
        if (!$this->frameBuffer->isCoalesced()) {
            return;
        }

        $onMessage = $this->onMessage;
        $onControl = $this->onControl;

        $this->frameBuffer = $this->frameCheck($this->frameBuffer);

        $overflow = $this->frameBuffer->extractOverflow();
        $this->frameBuffer->unMaskPayload();

        $opcode = $this->frameBuffer->getOpcode();

        if ($opcode > 2) {
            $onControl($this->frameBuffer);

            if (Frame::OP_CLOSE === $opcode) {
                return;
            }
        } else {
            $this->messageBuffer->addFrame($this->frameBuffer);
        }

        $this->frameBuffer = null;

        if ($this->messageBuffer->isCoalesced()) {
            $msgCheck = $this->checkMessage($this->messageBuffer);
            if (true !== $msgCheck) {
                $onControl($this->newCloseFrame($msgCheck));
            } else {
                $onMessage($this->messageBuffer);
            }

            $this->messageBuffer = null;
        }

        if (strlen($overflow) > 0) {
            $this->onData($overflow); // PHP doesn't do tail recursion  :(
        }
    }

    /**
     * Check a frame to be added to the current message buffer
     * @param \Ratchet\RFC6455\Messaging\Protocol\FrameInterface|FrameInterface $frame
     * @return \Ratchet\RFC6455\Messaging\Protocol\FrameInterface|FrameInterface
     */
    public function frameCheck(FrameInterface $frame) {
        if (false !== $frame->getRsv1() ||
            false !== $frame->getRsv2() ||
            false !== $frame->getRsv3()
        ) {
            return $this->newCloseFrame(Frame::CLOSE_PROTOCOL);
        }

        if ($this->checkForMask && !$frame->isMasked()) {
            return $this->newCloseFrame(Frame::CLOSE_PROTOCOL);
        }

        $opcode = $frame->getOpcode();

        if ($opcode > 2) {
            if ($frame->getPayloadLength() > 125 || !$frame->isFinal()) {
                return $this->newCloseFrame(Frame::CLOSE_PROTOCOL);
            }

            switch ($opcode) {
                case Frame::OP_CLOSE:
                    $closeCode = 0;

                    $bin = $frame->getPayload();

                    if (empty($bin)) {
                        return $this->newCloseFrame(Frame::CLOSE_NORMAL);
                    }

                    if (strlen($bin) == 1) {
                        return $this->newCloseFrame(Frame::CLOSE_PROTOCOL);
                    }

                    if (strlen($bin) >= 2) {
                        list($closeCode) = array_merge(unpack('n*', substr($bin, 0, 2)));
                    }

                    $checker = $this->closeFrameChecker;
                    if (!$checker($closeCode)) {
                        return $this->newCloseFrame(Frame::CLOSE_PROTOCOL);
                    }

                    if (!preg_match('//u', substr($bin, 2))) {
                        return $this->newCloseFrame(Frame::CLOSE_BAD_PAYLOAD);
                    }

                    return $this->newCloseFrame(Frame::CLOSE_NORMAL);
                    break;
                case Frame::OP_PING:
                case Frame::OP_PONG:
                    break;
                default:
                    return $this->newCloseFrame(Frame::CLOSE_PROTOCOL);
                    break;
            }

            return $frame;
        }

        if (Frame::OP_CONTINUE == $frame->getOpcode() && 0 == count($this->messageBuffer)) {
            return $this->newCloseFrame(Frame::CLOSE_PROTOCOL);
        }

        if (count($this->messageBuffer) > 0 && Frame::OP_CONTINUE != $frame->getOpcode()) {
            return $this->newCloseFrame(Frame::CLOSE_PROTOCOL);
        }

        return $frame;
    }

    /**
     * Determine if a message is valid
     * @param \Ratchet\RFC6455\Messaging\Protocol\MessageInterface
     * @return bool|int true if valid - false if incomplete - int of recommended close code
     */
    public function checkMessage(MessageInterface $message) {
        if (!$message->isBinary()) {
            if (!preg_match('//u', $message->getPayload())) {
                return Frame::CLOSE_BAD_PAYLOAD;
            }
        }

        return true;
    }

    /**
     * @return \Ratchet\RFC6455\Messaging\Protocol\MessageInterface
     */
    public function newMessage() {
        return new Message;
    }

    /**
     * @param string|null $payload
     * @param bool|null   $final
     * @param int|null    $opcode
     * @return \Ratchet\RFC6455\Messaging\Protocol\FrameInterface
     */
    public function newFrame($payload = null, $final = null, $opcode = null) {
        return new Frame($payload, $final, $opcode, $this->exceptionFactory);
    }

    public function newCloseFrame($code) {
        return $this->newFrame(pack('n', $code), true, Frame::OP_CLOSE);
    }
}
