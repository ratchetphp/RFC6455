<?php
namespace Ratchet\RFC6455\Messaging\Streaming;
use Ratchet\RFC6455\Encoding\ValidatorInterface;
use Ratchet\RFC6455\Messaging\Protocol\Frame;
use Ratchet\RFC6455\Messaging\Protocol\Message;
use Ratchet\RFC6455\Messaging\Validation\MessageValidator;

class MessageStreamer {
    /** @var  MessageValidator */
    private $validator;

    function __construct(ValidatorInterface $encodingValidator, $expectMask = false) {
        $this->validator = new MessageValidator($encodingValidator, !$expectMask);
    }


    public function onData($data, ContextInterface $context) {
        $overflow = '';

        $context->getMessage() || $context->setMessage($this->newMessage());
        $context->getFrame() || $context->setFrame($this->newFrame());

        $frame = $context->getFrame();

        $frame->addBuffer($data);
        if ($frame->isCoalesced()) {
            $validFrame = $this->validator->validateFrame($frame);
            if (true !== $validFrame) {
                $context->onClose($validFrame);

                return;
            }

            $opcode = $frame->getOpcode();
            if ($opcode > 2) {
                switch ($opcode) {
                    case $frame::OP_PING:
                        $context->onPing($frame);
                    break;
                    case $frame::OP_PONG:
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

            $frameAdded = $context->getMessage()->addFrame($frame);
            if (true !== $frameAdded) {
                $context->onClose($frameAdded);
            }
            $context->setFrame(null);
        }

        if ($context->getMessage()->isCoalesced()) {
            $msgCheck = $this->validator->checkMessage($context->getMessage());
            if ($msgCheck !== true) {
                $context->onClose($msgCheck || null);
                return;
            }
            $context->onMessage($context->getMessage());
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
        return new Frame($payload, $final, $opcode);
    }
}