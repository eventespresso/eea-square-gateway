<?php

namespace EventEspresso\Square\api\order;

use EventEspresso\Square\api\IdempotencyKey;
use EventEspresso\Square\api\SquareApi;

/**
 * Class OrderService
 * abstract parent class for CreateOrder and CancelOrder
 *
 * @author  Nazar Kolivoshka
 * @package EventEspresso\Square\api\order
 * @since   1.0.0.p
 */
abstract class OrdersApi
{

    /**
     * @var SquareApi
     */
    protected $api;

    /**
     * @var IdempotencyKey
     */
    protected $idempotency_key;

    /**
     * @var string
     */
    protected $post_url;


    /**
     * CancelOrder constructor.
     *
     * @param SquareApi      $api
     * @param int $TXN_ID
     */
    public function __construct(SquareApi $api, int $TXN_ID)
    {
        $this->api             = $api;
        $this->idempotency_key = new IdempotencyKey($this->api->isSandboxMode(), $TXN_ID);
        $this->post_url        = $this->api->apiEndpoint() . 'orders/';
    }
}
