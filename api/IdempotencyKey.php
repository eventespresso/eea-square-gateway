<?php

namespace EventEspresso\Square\api;

/**
 * Class IdempotencyKey
 * Value Object used by the SquareAPI
 *
 * @author  Nazar Kolivoshka
 * @package EventEspresso\Square\api
 * @since   1.0.0.p
 */
class IdempotencyKey
{
    /**
     * @var string
     */
    private $value;


    /**
     *
     * @param bool $sandboxMode
     * @param int  $TXN_ID
     */
    public function __construct(bool $sandboxMode, int $TXN_ID)
    {
        $keyPrefix   = $sandboxMode ? 'TEST-payment' : 'event-payment';
        $pre_number  = $this->generatePreNumber();
        $TXN_ID      = absint($TXN_ID) ? $TXN_ID : uniqid();
        $this->value = "$keyPrefix-$pre_number-$TXN_ID";
    }


    /**
     * @return string
     */
    private function generatePreNumber(): string
    {
        return substr(
            number_format(time() * rand(2, 99999), 0, '', ''),
            0,
            30
        );
    }


    /**
     * @return string
     */
    public function value(): string
    {
        return $this->value;
    }


    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
