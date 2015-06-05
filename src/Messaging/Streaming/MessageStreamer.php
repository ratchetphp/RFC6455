<?php
namespace Ratchet\RFC6455\Messaging\Streaming;
use Ratchet\RFC6455\Encoding\ValidatorInterface;
use Ratchet\RFC6455\Messaging\Protocol\MessageInterface;
use Ratchet\RFC6455\Messaging\Protocol\FrameInterface;
use Ratchet\RFC6455\Messaging\Protocol\Message;
use Ratchet\RFC6455\Messaging\Protocol\Frame;

class MessageStreamer {
    /**
     * @var \Ratchet\RFC6455\Encoding\ValidatorInterface
     */
    private $validator;

    /**
     * @var callable
     */
    private $exceptionFactory;

    /**
     * @var bool
     */
    private $checkForMask;

    /**
     * @var array
     */
    private $validCloseCodes;

    function __construct(ValidatorInterface $encodingValidator, $expectMask = true) {
        $this->validator    = $encodingValidator;
        $this->checkForMask = (bool)$expectMask;

        $exception = new \UnderflowException;
        $this->exceptionFactory = function() use ($exception) {
            return $exception;
        };

        $this->noop = function() {};

        $this->validCloseCodes = [
            Frame::CLOSE_NORMAL,
            Frame::CLOSE_GOING_AWAY,
            Frame::CLOSE_PROTOCOL,
            Frame::CLOSE_BAD_DATA,
            Frame::CLOSE_BAD_PAYLOAD,
            Frame::CLOSE_POLICY,
            Frame::CLOSE_TOO_BIG,
            Frame::CLOSE_MAND_EXT,
            Frame::CLOSE_SRV_ERR,
        ];
    }

    /**
     * @param                            $data
     * @param mixed                      $context
     * @param MessageInterface           $message
     * @param callable(MessageInterface) $onMessage
     * @param callable(FrameInterface)   $onControl
     * @return MessageInterface
     */
    public function onData($data, MessageInterface $message = null, callable $onMessage, callable $onControl = null, $context = null) {
        $overflow = '';

        $onControl ?: $this->noop;
        $message   ?: $message = $this->newMessage();

        $prevFrame  = null;
        $frameCount = count($message);

        if ($frameCount > 0) {
            $frame = $message[$frameCount - 1];

            if ($frame->isCoalesced()) {
                $prevFrame = $frame;
                $frame = $this->newFrame();
                $message->addFrame($frame);
                $frameCount++;
            } elseif ($frameCount > 1) {
                $prevFrame = $message[$frameCount - 2];
            }
        } else {
            $frame = $this->newFrame();
            $message->addFrame($frame);
            $frameCount++;
        }

        $frame->addBuffer($data);
        if ($frame->isCoalesced()) {
            $frame = $this->frameCheck($frame, $prevFrame);

            $opcode = $frame->getOpcode();
            if ($opcode > 2) {
                $onControl($frame, $context);
                unset($message[$frameCount - 1]);

                $overflow = $frame->extractOverflow();

                if (strlen($overflow) > 0) {
                    $message = $this->onData($overflow, $message, $onMessage, $onControl, $context);
                }

                return $message;
            }

            $overflow = $frame->extractOverflow();

            $frame->unMaskPayload();
        }

        if ($message->isCoalesced()) {
            $msgCheck = $this->checkMessage($message);
            if (true !== $msgCheck) {
                $onControl($this->newCloseFrame($msgCheck), $context);

                return $this->newMessage();
            }

            $onMessage($message, $context);
            $message = $this->newMessage();
        }

        if (strlen($overflow) > 0) {
            $this->onData($overflow, $message, $onMessage, $onControl, $context);
        }

        return $message;
    }

    /**
     * Check a frame and previous frame in a message; returns the frame that should be dealt with
     * @param \Ratchet\RFC6455\Messaging\Protocol\FrameInterface|FrameInterface $frame
     * @param \Ratchet\RFC6455\Messaging\Protocol\FrameInterface|FrameInterface $previousFrame
     * @return \Ratchet\RFC6455\Messaging\Protocol\FrameInterface|FrameInterface
     */
    public function frameCheck(FrameInterface $frame, FrameInterface $previousFrame = null) {
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

                    if (!$this->isValidCloseCode($closeCode)) {
                        return $this->newCloseFrame(Frame::CLOSE_PROTOCOL);
                    }

                    if (!$this->validator->checkEncoding(substr($bin, 2), 'UTF-8')) {
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

        if (Frame::OP_CONTINUE === $frame->getOpcode() && null === $previousFrame) {
            return $this->newCloseFrame(Frame::CLOSE_PROTOCOL);
        }

        if (null !== $previousFrame && Frame::OP_CONTINUE != $frame->getOpcode()) {
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
            if (!$this->validator->checkEncoding($message->getPayload(), 'UTF-8')) {
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

    public function isValidCloseCode($val) {
        return ($val >= 3000 && $val <= 4999) || in_array($val, $this->validCloseCodes);
    }
}