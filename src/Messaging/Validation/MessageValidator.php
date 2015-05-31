<?php
namespace Ratchet\RFC6455\Messaging\Validation;
use Ratchet\RFC6455\Encoding\ValidatorInterface;
use Ratchet\RFC6455\Messaging\Protocol\Frame;
use Ratchet\RFC6455\Messaging\Protocol\FrameInterface;
use Ratchet\RFC6455\Messaging\Protocol\MessageInterface;

class MessageValidator {
    public $checkForMask;

    private $validator;

    public function __construct(ValidatorInterface $validator, $checkForMask = true) {
        $this->validator = $validator;
        $this->checkForMask = $checkForMask;
    }

    /**
     * Determine if a message is valid
     * @param \Ratchet\RFC6455\Messaging\Protocol\MessageInterface
     * @return bool|int true if valid - false if incomplete - int of recommended close code
     */
    public function checkMessage(MessageInterface $message) {
        $frame = $message[0];

        if (!$message->isBinary()) {
            $parsed = $message->getPayload();
            if (!$this->validator->checkEncoding($parsed, 'UTF-8')) {
                return $frame::CLOSE_BAD_PAYLOAD;
            }
        }

        return true;
    }

    /**
     * @param FrameInterface $frame
     * @param FrameInterface $previousFrame
     * @return int Return 0 if everything is good, an integer close code if not
     */
    public function validateFrame(FrameInterface $frame, FrameInterface $previousFrame = null) {
        if (false !== $frame->getRsv1() ||
            false !== $frame->getRsv2() ||
            false !== $frame->getRsv3()
        ) {
            return Frame::CLOSE_PROTOCOL;
        }

        // Should be checking all frames
        if ($this->checkForMask && !$frame->isMasked()) {
            return Frame::CLOSE_PROTOCOL;
        }

        $opcode = $frame->getOpcode();

        if ($opcode > 2) {
            if ($frame->getPayloadLength() > 125 || !$frame->isFinal()) {
                return Frame::CLOSE_PROTOCOL;
            }

            switch ($opcode) {
                case Frame::OP_CLOSE:
                    $closeCode = 0;

                    $bin = $frame->getPayload();

                    if (empty($bin)) {
                        return Frame::CLOSE_NORMAL;
                    }

                    if (strlen($bin) == 1) {
                        return Frame::CLOSE_PROTOCOL;
                    }

                    if (strlen($bin) >= 2) {
                        list($closeCode) = array_merge(unpack('n*', substr($bin, 0, 2)));
                    }

                    if (!$frame->isValidCloseCode($closeCode)) {
                        return Frame::CLOSE_PROTOCOL;
                    }

                    if (!$this->validator->checkEncoding(substr($bin, 2), 'UTF-8')) {
                        return Frame::CLOSE_BAD_PAYLOAD;
                    }

                    return Frame::CLOSE_NORMAL;
                break;
                case Frame::OP_PING:
                case Frame::OP_PONG:
                break;
                default:
                    return Frame::CLOSE_PROTOCOL;
                break;
            }

            return 0;
        }

        if (Frame::OP_CONTINUE === $frame->getOpcode() && null === $previousFrame) {
            return Frame::CLOSE_PROTOCOL;
        }

        if (null !== $previousFrame && Frame::OP_CONTINUE != $frame->getOpcode()) {
            return Frame::CLOSE_PROTOCOL;
        }

        return 0;
    }
}
