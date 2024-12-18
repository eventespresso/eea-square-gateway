<?php

namespace EventEspresso\Square\api;

/**
 * Class ResponseHandler
 * Helper methods to handle the API responses.
 *
 * @author  Brent Christensen
 * @package EventEspresso\Square\api
 * @since   1.0.0.p
 */
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
        $this->errors = ['error' => $error];
    }


    /**
     * This removes all errors from the list.
     *
     * @return void
     */
    public function clearErrors()
    {
        $this->errors = [];
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
     * @param $api_response
     * @return void
     */
    public function checkForResponseErrors($api_response): void
    {
        $response_body = json_decode($api_response['body'] ?? '');
        if (! $response_body) {
            $this->setError([
                'code'         => 'unrecognizable_body',
                'message'      => esc_html__('Unable to read the response body.', 'event_espresso'),
                'api_response' => $api_response['body'] ?? $api_response,
            ]);
            $this->setInValid();
            return;
        }
        // Check the data for errors.
        if (isset($response_body->errors)) {
            $responseErrorMessage = $responseErrorCode = '';
            $errorCodes           = [];
            foreach ($response_body->errors as $responseError) {
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
        }
    }
}
