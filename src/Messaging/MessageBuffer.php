<?php
namespace Ratchet\RFC6455\Messaging;

class MessageBuffer {
    /**
     * @var \Ratchet\RFC6455\Messaging\CloseFrameChecker
     */
    private $closeFrameChecker;

    /**
     * @var callable
     */
    private $exceptionFactory;

    /**
     * @var \Ratchet\RFC6455\Messaging\Message
     */
    private $messageBuffer;

    /**
     * @var \Ratchet\RFC6455\Messaging\Frame
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

    /**
     * @var string
     */
    private $leftovers;

    /**
     * @var int
     */
    private $maxMessagePayloadSize;

    /**
     * @var int
     */
    private $maxFramePayloadSize;

    function __construct(
        CloseFrameChecker $frameChecker,
        callable $onMessage,
        callable $onControl = null,
        $expectMask = true,
        $exceptionFactory = null,
        $maxMessagePayloadSize = null, // null for default - zero for no limit
        $maxFramePayloadSize = null    // null for default - zero for no limit
    ) {
        $this->closeFrameChecker = $frameChecker;
        $this->checkForMask = (bool)$expectMask;

        $this->exceptionFactory ?: $this->exceptionFactory = function($msg) {
            return new \UnderflowException($msg);
        };

        $this->onMessage = $onMessage;
        $this->onControl = $onControl ?: function() {};

        $this->leftovers = '';

        $memory_limit_bytes = static::getMemoryLimit();

        if ($maxMessagePayloadSize === null) {
            $maxMessagePayloadSize = $memory_limit_bytes / 4;
        }
        if ($maxFramePayloadSize === null) {
            $maxFramePayloadSize = $memory_limit_bytes / 4;
        }

        if (!is_int($maxFramePayloadSize) || $maxFramePayloadSize > 0x7FFFFFFFFFFFFFFF || $maxFramePayloadSize < 0) { // this should be interesting on non-64 bit systems
            throw new \InvalidArgumentException($maxFramePayloadSize . ' is not a valid maxFramePayloadSize');
        }
        $this->maxFramePayloadSize = $maxFramePayloadSize;

        if (!is_int($maxMessagePayloadSize) || $maxMessagePayloadSize > 0x7FFFFFFFFFFFFFFF || $maxMessagePayloadSize < 0) {
            throw new \InvalidArgumentException($maxMessagePayloadSize . 'is not a valid maxMessagePayloadSize');
        }
        $this->maxMessagePayloadSize = $maxMessagePayloadSize;
    }

    public function onData($data) {
        $data = $this->leftovers . $data;
        $dataLen = strlen($data);

        if ($dataLen < 2) {
            $this->leftovers = $data;

            return;
        }

        $frameStart = 0;
        while ($frameStart + 2 <= $dataLen) {
            $headerSize     = 2;
            $payload_length = unpack('C', $data[$frameStart + 1] & "\x7f")[1];
            $isMasked       = ($data[$frameStart + 1] & "\x80") === "\x80";
            $headerSize     += $isMasked ? 4 : 0;
            if ($payload_length > 125 && ($dataLen - $frameStart < $headerSize + 125)) {
                // no point of checking - this frame is going to be bigger than the buffer is right now
                break;
            }
            if ($payload_length > 125) {
                $payloadLenBytes = $payload_length === 126 ? 2 : 8;
                $headerSize      += $payloadLenBytes;
                $bytesToUpack    = substr($data, $frameStart + 2, $payloadLenBytes);
                $payload_length  = $payload_length === 126
                    ? unpack('n', $bytesToUpack)[1]
                    : unpack('J', $bytesToUpack)[1];
            }

            $closeFrame = null;

            if ($payload_length < 0) {
                // this can happen when unpacking in php
                $closeFrame = $this->newCloseFrame(Frame::CLOSE_PROTOCOL, 'Invalid frame length');
            }

            if (!$closeFrame && $this->maxFramePayloadSize > 1 && $payload_length > $this->maxFramePayloadSize) {
                $closeFrame = $this->newCloseFrame(Frame::CLOSE_TOO_BIG, 'Maximum frame size exceeded');
            }

            if (!$closeFrame && $this->maxMessagePayloadSize > 0
                && $payload_length + ($this->messageBuffer ? $this->messageBuffer->getPayloadLength() : 0) > $this->maxMessagePayloadSize) {
                $closeFrame = $this->newCloseFrame(Frame::CLOSE_TOO_BIG, 'Maximum message size exceeded');
            }

            if ($closeFrame !== null) {
                $onControl = $this->onControl;
                $onControl($closeFrame);
                $this->leftovers = '';

                return;
            }

            $isCoalesced = $dataLen - $frameStart >= $payload_length + $headerSize;
            if (!$isCoalesced) {
                break;
            }
            $this->processData(substr($data, $frameStart, $payload_length + $headerSize));
            $frameStart = $frameStart + $payload_length + $headerSize;
        }

        $this->leftovers = substr($data, $frameStart);
    }

    /**
     * @param string $data
     * @return null
     */
    private function processData($data) {
        $this->messageBuffer ?: $this->messageBuffer = $this->newMessage();
        $this->frameBuffer   ?: $this->frameBuffer   = $this->newFrame();

        $this->frameBuffer->addBuffer($data);

        $onMessage = $this->onMessage;
        $onControl = $this->onControl;

        $this->frameBuffer = $this->frameCheck($this->frameBuffer);

        $this->frameBuffer->unMaskPayload();

        $opcode = $this->frameBuffer->getOpcode();

        if ($opcode > 2) {
            $onControl($this->frameBuffer);

            if (Frame::OP_CLOSE === $opcode) {
                return '';
            }
        } else {
            $this->messageBuffer->addFrame($this->frameBuffer);
        }

        $this->frameBuffer = null;

        if ($this->messageBuffer->isCoalesced()) {
            $msgCheck = $this->checkMessage($this->messageBuffer);

            $msgBuffer = $this->messageBuffer;
            $this->messageBuffer = null;

            if (true !== $msgCheck) {
                $onControl($this->newCloseFrame($msgCheck, 'Ratchet detected an invalid UTF-8 payload'));
            } else {
                $onMessage($msgBuffer);
            }
        }
    }

    /**
     * Check a frame to be added to the current message buffer
     * @param \Ratchet\RFC6455\Messaging\FrameInterface|FrameInterface $frame
     * @return \Ratchet\RFC6455\Messaging\FrameInterface|FrameInterface
     */
    public function frameCheck(FrameInterface $frame) {
        if (false !== $frame->getRsv1() ||
            false !== $frame->getRsv2() ||
            false !== $frame->getRsv3()
        ) {
            return $this->newCloseFrame(Frame::CLOSE_PROTOCOL, 'Ratchet detected an invalid reserve code');
        }

        if ($this->checkForMask && !$frame->isMasked()) {
            return $this->newCloseFrame(Frame::CLOSE_PROTOCOL, 'Ratchet detected an incorrect frame mask');
        }

        $opcode = $frame->getOpcode();

        if ($opcode > 2) {
            if ($frame->getPayloadLength() > 125 || !$frame->isFinal()) {
                return $this->newCloseFrame(Frame::CLOSE_PROTOCOL, 'Ratchet detected a mismatch between final bit and indicated payload length');
            }

            switch ($opcode) {
                case Frame::OP_CLOSE:
                    $closeCode = 0;

                    $bin = $frame->getPayload();

                    if (empty($bin)) {
                        return $this->newCloseFrame(Frame::CLOSE_NORMAL);
                    }

                    if (strlen($bin) === 1) {
                        return $this->newCloseFrame(Frame::CLOSE_PROTOCOL, 'Ratchet detected an invalid close code');
                    }

                    if (strlen($bin) >= 2) {
                        list($closeCode) = array_merge(unpack('n*', substr($bin, 0, 2)));
                    }

                    $checker = $this->closeFrameChecker;
                    if (!$checker($closeCode)) {
                        return $this->newCloseFrame(Frame::CLOSE_PROTOCOL, 'Ratchet detected an invalid close code');
                    }

                    if (!$this->checkUtf8(substr($bin, 2))) {
                        return $this->newCloseFrame(Frame::CLOSE_BAD_PAYLOAD, 'Ratchet detected an invalid UTF-8 payload in the close reason');
                    }

                    return $frame;
                    break;
                case Frame::OP_PING:
                case Frame::OP_PONG:
                    break;
                default:
                    return $this->newCloseFrame(Frame::CLOSE_PROTOCOL, 'Ratchet detected an invalid OP code');
                    break;
            }

            return $frame;
        }

        if (Frame::OP_CONTINUE === $frame->getOpcode() && 0 === count($this->messageBuffer)) {
            return $this->newCloseFrame(Frame::CLOSE_PROTOCOL, 'Ratchet detected the first frame of a message was a continue');
        }

        if (count($this->messageBuffer) > 0 && Frame::OP_CONTINUE !== $frame->getOpcode()) {
            return $this->newCloseFrame(Frame::CLOSE_PROTOCOL, 'Ratchet detected invalid OP code when expecting continue frame');
        }

        return $frame;
    }

    /**
     * Determine if a message is valid
     * @param \Ratchet\RFC6455\Messaging\MessageInterface
     * @return bool|int true if valid - false if incomplete - int of recommended close code
     */
    public function checkMessage(MessageInterface $message) {
        if (!$message->isBinary()) {
            if (!$this->checkUtf8($message->getPayload())) {
                return Frame::CLOSE_BAD_PAYLOAD;
            }
        }

        return true;
    }

    private function checkUtf8($string) {
        if (extension_loaded('mbstring')) {
            return mb_check_encoding($string, 'UTF-8');
        }

        return preg_match('//u', $string);
    }

    /**
     * @return \Ratchet\RFC6455\Messaging\MessageInterface
     */
    public function newMessage() {
        return new Message;
    }

    /**
     * @param string|null $payload
     * @param bool|null   $final
     * @param int|null    $opcode
     * @return \Ratchet\RFC6455\Messaging\FrameInterface
     */
    public function newFrame($payload = null, $final = null, $opcode = null) {
        return new Frame($payload, $final, $opcode, $this->exceptionFactory);
    }

    public function newCloseFrame($code, $reason = '') {
        return $this->newFrame(pack('n', $code) . $reason, true, Frame::OP_CLOSE);
    }

    /**
     * This is a separate function for testing purposes
     * $memory_limit is only used for testing
     *
     * @param null|string $memory_limit
     * @return int
     */
    private static function getMemoryLimit($memory_limit = null) {
        $memory_limit = $memory_limit === null ? \trim(\ini_get('memory_limit')) : $memory_limit;
        $memory_limit_bytes = 0;
        if ($memory_limit !== '') {
            $shifty = ['k' => 0, 'm' => 10, 'g' => 20];
            $multiplier = strlen($memory_limit) > 1 ? substr(strtolower($memory_limit), -1) : '';
            $memory_limit = (int)$memory_limit;
            $memory_limit_bytes = in_array($multiplier, array_keys($shifty), true) ? $memory_limit * 1024 << $shifty[$multiplier] : $memory_limit;
        }

        return $memory_limit_bytes < 0 ? 0 : $memory_limit_bytes;
    }
}
