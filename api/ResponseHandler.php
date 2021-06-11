<?php

namespace EventEspresso\Square\api;

class ResponseHandler
{
    /**
     * @var array
     */
    protected $errors = [];

    /**
     * @var bool true if response was error free
     */
    protected $valid = true;



    /**
     * @return array
     */
    public function errors(): array
    {
        return $this->errors;
    }


    /**
     * @return bool
     */
    public function isInvalid(): bool
    {
        return ! $this->valid;
    }


    /**
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->valid;
    }


    /**
     * @param array $error
     */
    private function setError(array $error)
    {
        $this->errors = array_merge($this->errors, $error);
    }


    private function setInValid()
    {
        $this->valid = false;
    }


    /**
     * @param $apiResponse
     * @return void
     */
    public function checkForRequestErrors($apiResponse): void
    {
        if (is_wp_error($apiResponse)) {
            $this->setError(
                [
                    'code'    => $apiResponse->get_error_code(),
                    'message' => sprintf(
                        // translators: %1$s: An error message.
                        esc_html__('Request error encountered. Message: %1$s.', 'event_espresso'),
                        $apiResponse->get_error_messages()
                    ),
                ]
            );
            $this->setInValid();
            return;
        }
        if (! isset($apiResponse['body'])) {
            $this->setError(
                [
                    'code'    => 'no_body',
                    'message' => esc_html__('No response body provided.', 'event_espresso'),
                ]
            );
            $this->setInValid();
        }
    }


    /**
     * @param $apiResponse
     * @return void
     */
    public function checkForResponseErrors($apiResponse): void
    {
        if (! $apiResponse) {
            $this->setError([
                'code'    => 'unrecognizable_body',
                'message' => esc_html__('Unable to read the response body.', 'event_espresso'),
            ]);
            $this->setInValid();
            return;
        }
        // Check the data for errors.
        if (isset($apiResponse->errors)) {
            $responseErrorMessage = $responseErrorCode = '';
            $errorCodes           = [];
            foreach ($apiResponse->errors as $responseError) {
                $responseErrorMessage .= $responseError->detail;
                $errorCodes[]         = $responseError->code;
            }
            if ($errorCodes) {
                $responseErrorCode = implode(',', $errorCodes);
            }
            $this->setError(
                [
                    'code'    => $responseErrorCode,
                    'message' => $responseErrorMessage,
                ]
            );
            $this->setInValid();
            return;
        }
        if (! isset($response->order)) {
            $this->setError(
                [
                    'code'    => 'missing_order',
                    'message' => esc_html__(
                        'Unexpected error. A Square Order Response was not returned.',
                        'event_espresso'
                    ),
                ]
            );
            $this->setInValid();
        }
    }
}
