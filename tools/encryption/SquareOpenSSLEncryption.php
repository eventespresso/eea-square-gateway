<?php

namespace EventEspresso\Square\tools\encryption;

use EventEspresso\core\services\encryption\Base64Encoder;
use EventEspresso\core\services\encryption\openssl\CipherMethod;
use EventEspresso\core\services\encryption\openssl\OpenSSLv2;
use Exception;

/**
 * Class SquareOpenSSLEncryption
 *
 * Encryption helper class set for use in the Square add-on. Uses OpenSSL v2.
 *
 * @author  Nazar Kolivoshka
 * @package EventEspresso\Square\tools\encryption
 * @since   1.0.0.p
 */
class SquareOpenSSLEncryption extends OpenSSLv2
{
    /**
     * Class constructor.
     * Setup OpenSSLv2 with Square specific encryption keys.
     *
     * @param Base64Encoder $base64_encoder
     * @throws Exception
     */
    public function __construct(Base64Encoder $base64_encoder)
    {
        // Square specific encryption keys with a default OpenSSLv2 cipher method.
        parent::__construct(
            $base64_encoder,
            new CipherMethod(OpenSSLv2::CIPHER_METHOD, OpenSSLv2::CIPHER_METHOD_OPTION_NAME),
            new SquareEncryptionKeyManager($base64_encoder)
        );
    }
}
