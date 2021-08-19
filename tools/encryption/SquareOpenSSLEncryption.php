<?php

namespace EventEspresso\Square\tools\encryption;

use EventEspresso\core\services\encryption\Base64Encoder;
use EventEspresso\core\services\encryption\openssl\CipherMethod;
use EventEspresso\core\services\encryption\openssl\OpenSSLv2;
use Exception;

/**
 * Class SquareOpenSSLEncryption
 *
 * Encryption helper class set for use in the Square add-on.
 *
 * @author  Nazar Kolivoshka
 * @package EventEspresso\Square\tools\encryption
 * @since   $VID:$
 */
class SquareOpenSSLEncryption extends OpenSSLv2
{
    /**
     * Class constructor.
     * Setup OpenSSLv2 with Square specific encryption keys.
     *
     * @throws Exception
     */
    public function __construct()
    {
        $base64_encoder = new Base64Encoder();
        // Square specific encryption keys with a default cipher method.
        parent::__construct(
            $base64_encoder,
            new CipherMethod(OpenSSLv2::CIPHER_METHOD, OpenSSLv2::CIPHER_METHOD_OPTION_NAME),
            new SquareEncryptionKeyManager($base64_encoder)
        );
    }
}
