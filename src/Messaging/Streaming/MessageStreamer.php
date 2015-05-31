<?php
namespace Ratchet\RFC6455\Messaging\Streaming;
use Ratchet\RFC6455\Encoding\ValidatorInterface;
use Ratchet\RFC6455\Messaging\Protocol\Frame;
use Ratchet\RFC6455\Messaging\Protocol\Message;
use Ratchet\RFC6455\Messaging\Validation\MessageValidator;

class MessageStreamer {
    /**
     * @var MessageValidator
     */
    private $validator;

    private $exceptionFactory;

    function __construct(ValidatorInterface $encodingValidator, $expectMask = false) {
        $this->validator = new MessageValidator($encodingValidator, !$expectMask);

        $exception = new \UnderflowException;
        $this->exceptionFactory = function() use ($exception) {
            return $exception;
        };
    }


    public function onData($data, ContextInterface $context) {
        $overflow = '';

        $message = $context->getMessage() ?: $context->setMessage($this->newMessage());
        $frame   = $context->getFrame()   ?: $context->setFrame($this->newFrame());

        $frame->addBuffer($data);
        if ($frame->isCoalesced()) {
            $frameCount = $message->count();
            $prevFrame  = $frameCount  > 0 ? $message[$frameCount - 1] : null;

            $frameStatus = $this->validator->validateFrame($frame, $prevFrame);

            if (0 !== $frameStatus) {
                return $context->onClose($frameStatus);
            }

            $opcode = $frame->getOpcode();
            if ($opcode > 2) {
                switch ($opcode) {
                    case Frame::OP_PING:
                        $context->onPing($frame);
                    break;
                    case Frame::OP_PONG:
                        $context->onPong($frame);
                    break;
                }

                $overflow = $frame->extractOverflow();
                $context->setFrame(null);

                if (strlen($overflow) > 0) {
                    $this->onData($overflow, $context);
                }

                return;
            }

            $overflow = $frame->extractOverflow();

            $frame->unMaskPayload();
            $message->addFrame($frame);
            $context->setFrame(null);
        }

        if ($message->isCoalesced()) {
            $msgCheck = $this->validator->checkMessage($message);
            if (true !== $msgCheck) {
                return $context->onClose($msgCheck);
            }

            $context->onMessage($message);
            $context->setMessage(null);
        }

        if (strlen($overflow) > 0) {
            $this->onData($overflow, $context);
        }
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
}