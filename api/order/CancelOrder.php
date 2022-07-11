<?php

namespace EventEspresso\Square\api\order;

/**
 * Class CancelOrder
 * Generates and sends cancel order requests through the Square API
 *
 * @author  Nazar Kolivoshka
 * @package EventEspresso\Square\api\order
 * @since   1.0.0.p
 */
class CancelOrder extends OrdersApi
{

    /**
     * Cancel the Square Order.
     *
     * @param string  $order_id
     * @param string  $order_version
     * @return Object|array
     */
    public function cancel(string $order_id, string $order_version)
    {
        // Send cancel Order request.
        return $this->api->sendRequest(
            [
                'idempotency_key' => $this->idempotency_key->value(),
                'order'           => [
                    'location_id' => $this->api->locationId(),
                    'version'     => (int)$order_version + 1,
                    'state'       => 'CANCELED',
                ],
            ],
            $this->post_url . $order_id,
            'PUT'
        );
    }
}
