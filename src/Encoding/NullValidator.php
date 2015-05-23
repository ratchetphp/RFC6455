<?php
namespace Ratchet\RFC6455\Encoding;

class NullValidator implements ValidatorInterface {
    /**
     * What value to return when checkEncoding is valid
     * @var boolean
     */
    public $validationResponse = true;

    public function checkEncoding($str, $encoding) {
        return (boolean)$this->validationResponse;
    }
}