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
     * @return bool|int true if valid - false if incomplete - int of recomended close code
     */
    public function checkMessage(MessageInterface $message) {
        // Need a progressive and complete check...this is only satisfying complete
        if (!$message->isCoalesced()) {
            return false;
        }

        $frame = $message[0];

        $frameCheck = $this->validateFrame($frame);
        if (true !== $frameCheck) {
            return $frameCheck;
        }

        // This seems incorrect - how could a frame exist with message count being 0?
        if ($frame::OP_CONTINUE === $frame->getOpcode() && 0 === count($message)) {
            return $frame::CLOSE_PROTOCOL;
        }

        // I (mbonneau) don't understand this - seems to always kill the tests
//        if (count($message) > 0 && $frame::OP_CONTINUE !== $frame->getOpcode()) {
//            return $frame::CLOSE_PROTOCOL;
//        }

        if (!$message->isBinary()) {
            $parsed = $message->getPayload();
            if (!$this->validator->checkEncoding($parsed, 'UTF-8')) {
                return $frame::CLOSE_BAD_PAYLOAD;
            }
        }

        return true;
    }

    public function validateFrame(Frame $frame) {
        if (false !== $frame->getRsv1() ||
            false !== $frame->getRsv2() ||
            false !== $frame->getRsv3()
        ) {
            return $frame::CLOSE_PROTOCOL;
        }

        // Should be checking all frames
        if ($this->checkForMask && !$frame->isMasked()) {
            return $frame::CLOSE_PROTOCOL;
        }

        $opcode = $frame->getOpcode();

        if ($opcode > 2) {
            if ($frame->getPayloadLength() > 125 || !$frame->isFinal()) {
                return $frame::CLOSE_PROTOCOL;
            }

            switch ($opcode) {
                case $frame::OP_CLOSE:
                    $closeCode = 0;

                    $bin = $frame->getPayload();


                    if (empty($bin)) {
                        return $frame::CLOSE_NORMAL;
                    }

                    if (strlen($bin) == 1) {
                        return $frame::CLOSE_PROTOCOL;
                    }

                    if (strlen($bin) >= 2) {
                        list($closeCode) = array_merge(unpack('n*', substr($bin, 0, 2)));
                    }

                    if (!$frame->isValidCloseCode($closeCode)) {
                        return $frame::CLOSE_PROTOCOL;
                    }

                    if (!$this->validator->checkEncoding(substr($bin, 2), 'UTF-8')) {
                        return $frame::CLOSE_BAD_PAYLOAD;
                    }

                    return $frame::CLOSE_NORMAL;
                break;
                case $frame::OP_PING:
                case $frame::OP_PONG:
                break;
                default:
                    return $frame::CLOSE_PROTOCOL;
                break;
            }
        }

        return true;
    }
}
