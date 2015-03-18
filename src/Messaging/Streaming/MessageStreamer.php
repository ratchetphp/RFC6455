<?php

namespace Ratchet\RFC6455\Messaging\Streaming;

use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use Ratchet\RFC6455\Encoding\Validator;
use Ratchet\RFC6455\Messaging\Protocol\Frame;
use Ratchet\RFC6455\Messaging\Protocol\Message;
use Ratchet\RFC6455\Messaging\Validation\MessageValidator;

class MessageStreamer implements EventEmitterInterface {
    use EventEmitterTrait;

    /** @var  Frame */
    private $currentFrame;

    /** @var  Message */
    private $currentMessage;

    /** @var  MessageValidator */
    private $validator;

    /** @var  bool */
    private $checkForMask;

    function __construct($client = false)
    {
        $this->checkForMask = !$client;
        $this->validator = new MessageValidator(new Validator(), $this->checkForMask);
    }


    public function onData($data) {
        $overflow = '';

        if (!isset($this->currentMessage)) {
            $this->currentMessage = $this->newMessage();
        }

        // There is a frame fragment attached to the connection, add to it
        if (!isset($this->currentFrame)) {
            $this->currentFrame = $this->newFrame();
        }

        $frame = $this->currentFrame;

        $frame->addBuffer($data);
        if ($frame->isCoalesced()) {
            $validFrame = $this->validator->validateFrame($frame);
            if ($validFrame !== true) {
                $this->emit('close', [$validFrame]);
                return;
            }

            $opcode = $frame->getOpcode();
            if ($opcode > 2) {
                if ($frame->getPayloadLength() > 125) {
                    // payload only allowed to 125 on control frames ab 2.5
                    $this->emit('close', [$frame::CLOSE_PROTOCOL]);
                    return;
                }
                switch ($opcode) {
                    case $frame::OP_CLOSE:
                        $closeCode = 0;

                        $bin = $frame->getPayload();

                        if (empty($bin)) {
                            $this->emit('close', [null]);
                            return;
                        }

                        if (strlen($bin) >= 2) {
                            list($closeCode) = array_merge(unpack('n*', substr($bin, 0, 2)));
                        }

                        if (!$frame->isValidCloseCode($closeCode)) {
                            $this->emit('close', [$frame::CLOSE_PROTOCOL]);
                            return;
                        }

                        // todo:
                        //if (!$this->validator->checkEncoding(substr($bin, 2), 'UTF-8')) {
                        //    $this->emit('close', [$frame::CLOSE_BAD_PAYLOAD]);
                        //    return;
                        //}

                        $this->emit('close', [$closeCode]);
                        return;
                        break;
                    case $frame::OP_PING:
                        // this should probably be automatic
                        //$from->send($this->newFrame($frame->getPayload(), true, $frame::OP_PONG));
                        $this->emit('ping', [$frame]);
                        break;
                    case $frame::OP_PONG:
                        $this->emit('pong', [$frame]);
                        break;
                    default:
                        $this->emit('close', [$frame::CLOSE_PROTOCOL]);
                        return;
                        break;
                }

                $overflow = $frame->extractOverflow();

                unset($this->currentFrame, $frame, $opcode);

                if (strlen($overflow) > 0) {
                    $this->onData($overflow);
                }

                return;
            }

            $overflow = $frame->extractOverflow();

            $frameAdded = $this->currentMessage->addFrame($this->currentFrame);
            if ($frameAdded !== true) {
                $this->emit('close', [$frameAdded]);
            }
            unset($this->currentFrame);
        }

        if ($this->currentMessage->isCoalesced()) {
            $msgCheck = $this->validator->checkMessage($this->currentMessage);
            if ($msgCheck !== true) {
                if ($msgCheck === false) $msgCheck = null;
                $this->emit('close', [$msgCheck]);
                return;
            }
            $this->emit('message', [$this->currentMessage]);
            //$parsed = $from->WebSocket->message->getPayload();
            unset($this->currentMessage);
        }

        if (strlen($overflow) > 0) {
            $this->onData($overflow);
        }
    }

    /**
     * @return Message
     */
    public function newMessage() {
        return new Message;
    }

    /**
     * @param string|null $payload
     * @param bool|null   $final
     * @param int|null    $opcode
     * @return Frame
     */
    public function newFrame($payload = null, $final = null, $opcode = null) {
        return new Frame($payload, $final, $opcode);
    }
}