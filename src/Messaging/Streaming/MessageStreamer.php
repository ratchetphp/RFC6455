<?php

namespace Ratchet\RFC6455\Messaging\Streaming;

use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use Ratchet\RFC6455\Messaging\Protocol\Frame;
use Ratchet\RFC6455\Messaging\Protocol\Message;

class MessageStreamer implements EventEmitterInterface {
    use EventEmitterTrait;

    /** @var  Frame */
    private $currentFrame;

    /** @var  Message */
    private $currentMessage;

    /** @var array  */
    private $closeCodes = [];

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
            $opcode = $frame->getOpcode();
            if ($opcode > 2) {
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

                        if (!$this->isValidCloseCode($closeCode)) {
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

            $this->currentMessage->addFrame($this->currentFrame);
            unset($this->currentFrame);
        }

        if ($this->currentMessage->isCoalesced()) {
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

    /**
     * Determine if a close code is valid
     * @param int|string
     * @return bool
     */
    public function isValidCloseCode($val) {
        if (array_key_exists($val, $this->closeCodes)) {
            return true;
        }

        if ($val >= 3000 && $val <= 4999) {
            return true;
        }

        return false;
    }

    /**
     * Creates a private lookup of valid, private close codes
     */
    protected function setCloseCodes() {
        $this->closeCodes[Frame::CLOSE_NORMAL]      = true;
        $this->closeCodes[Frame::CLOSE_GOING_AWAY]  = true;
        $this->closeCodes[Frame::CLOSE_PROTOCOL]    = true;
        $this->closeCodes[Frame::CLOSE_BAD_DATA]    = true;
        //$this->closeCodes[Frame::CLOSE_NO_STATUS]   = true;
        //$this->closeCodes[Frame::CLOSE_ABNORMAL]    = true;
        $this->closeCodes[Frame::CLOSE_BAD_PAYLOAD] = true;
        $this->closeCodes[Frame::CLOSE_POLICY]      = true;
        $this->closeCodes[Frame::CLOSE_TOO_BIG]     = true;
        $this->closeCodes[Frame::CLOSE_MAND_EXT]    = true;
        $this->closeCodes[Frame::CLOSE_SRV_ERR]     = true;
        //$this->closeCodes[Frame::CLOSE_TLS]         = true;
    }
} 