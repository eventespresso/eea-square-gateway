<?php

namespace EventEspresso\Square\tools\encryption;

use EventEspresso\core\services\encryption\Base64Encoder;
use EventEspresso\core\services\encryption\EncryptionKeyManager;
use Exception;

/**
 * Class SquareEncryptionOpenSSL
 *
 * Encryption key manager class set for use in this add-on.
 *
 * @author  Nazar Kolivoshka
 * @package EventEspresso\Square\tools\encryption
 * @since   $VID:$
 */
class SquareEncryptionKeyManager extends EncryptionKeyManager
{
    /**
     * Holds the name of the live encryption key.
     */
    public const PRODUCTION_ENCRYPTION_KEY_ID = 'eea-square-api-encryption-key-production';

    /**
     * Holds the name of the sandbox encryption key.
     */
    public const SANDBOX_ENCRYPTION_KEY_ID = 'ee-square-api-encryption-key-sandbox';

    /**
     * Holds the name of the sandbox encryption key.
     */
    public const ENCRYPTION_KEYS_ID = 'eea-square-api-encryption-keys';


    /**
     * Class constructor.
     * Setup two keys, for production and sandbox.
     *
     * @param Base64Encoder $base64_encoder
     * @throws Exception
     */
    public function __construct(Base64Encoder $base64_encoder)
    {
        // Register a production key.
        parent::__construct($base64_encoder, self::PRODUCTION_ENCRYPTION_KEY_ID, self::ENCRYPTION_KEYS_ID);

        // Now add the sandbox key if doesn't exist.
        $encryption_keys = $this->retrieveEncryptionKeys();
        if (! $encryption_keys || ! isset($encryption_keys[ self::SANDBOX_ENCRYPTION_KEY_ID ])) {
            $this->addEncryptionKey(self::SANDBOX_ENCRYPTION_KEY_ID);
        }
    }
}
