<?php

namespace EventEspresso\Square\api\order;

/**
 * Class CancelOrder
 * Generates and sends cancel order requests through the Square API
 *
 * @author  Nazar Kolivoshka
 * @package EventEspresso\Square\api\order
 * @since   $VID:$
 */
class CancelOrder extends OrdersApi
{

    /**
     * Cancel the Square Order.
     *
     * @param string         $orderId
     * @param string         $orderVersion
     * @return Object|array
     */
    public function sendRequest(string $orderId, string $orderVersion)
    {
        // Send cancel Order request.
        return $this->api->sendRequest(
            [
                'idempotency_key' => $this->idempotency_key->value(),
                'order'           => [
                    'location_id' => $this->api->locationId(),
                    'version'     => (int)$orderVersion + 1,
                    'state'       => 'CANCELED',
                ],
            ],
            $this->post_url . $orderId,
            'PUT'
        );
    }
}
