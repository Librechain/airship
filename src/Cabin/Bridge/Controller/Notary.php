<?php
declare(strict_types=1);
namespace Airship\Cabin\Bridge\Controller;

use Airship\Cabin\Bridge\Model\ChannelUpdates;
use Airship\Engine\Model;
use Airship\Engine\State;
use ParagonIE\ConstantTime\{
    Base64UrlSafe,
    Binary
};
use ParagonIE\Halite\Asymmetric\{
    SignaturePublicKey,
    SignatureSecretKey
};

require_once __DIR__.'/init_gear.php';

/**
 * Class Notary
 *
 * Notary service for Airship updates
 *
 * @package Airship\Cabin\Bridge\Controller
 */
class Notary extends ControllerGear
{
    /**
     * @var SignatureSecretKey
     */
    private $sk;

    /**
     * @var string
     */
    protected $channel;

    /**
     * @var SignaturePublicKey
     */
    protected $pk;

    /**
     * @var ChannelUpdates
     */
    protected $chanUp;

    /**
     * This function is called after the dependencies have been injected by
     * AutoPilot. Think of it as a user-land constructor.
     */
    public function airshipLand(): void
    {
        parent::airshipLand();
        $config = State::instance();
        if (empty($config->universal['notary']['enabled'])) {
            \Airship\json_response([
                'status' =>
                    'error',
                'message' =>
                    'This Airship does not offer Notary services.'
            ]);
        }
        $sk = $config->keyring['notary.online_signing_key'];
        if (!($sk instanceof SignatureSecretKey)) {
            throw new \TypeError('Expected a SignatureSecretKey');
        }
        $this->sk = $sk;
        $this->pk = $this->sk->derivePublicKey();

        $this->channel = $config->universal['notary']['channel'];

        $chanUp = $this->model('ChannelUpdates', $this->channel);
        if (!($chanUp instanceof ChannelUpdates)) {
            throw new \TypeError(Model::TYPE_ERROR);
        }
        $this->chanUp = $chanUp;
    }

    /**
     * @route notary
     */
    public function index(): void
    {
        \Airship\json_response(
            [
                'status' =>
                    'OK',
                'channel' =>
                    $this->channel,
                'message' =>
                    '',
                'public_key' =>
                    Base64UrlSafe::encode(
                        $this->pk->getRawKeyMaterial()
                    )
            ]
        );
    }

    /**
     * @route notary/verify
     */
    public function verify(): void
    {
        // Input validation
        if (empty($_POST['challenge'])) {
            \Airship\json_response([
                'status' =>
                    'error',
                'message' =>
                    'Expected a challenge=something HTTP POST parameter.'
            ]);
        }
        if (!\is_string($_POST['challenge'])) {
            \Airship\json_response([
                'status' =>
                    'error',
                'message' =>
                    'Challenge must be a string.'
            ]);
        }
        if (Binary::safeStrlen($_POST['challenge']) < 20) {
            \Airship\json_response([
                'status' =>
                    'error',
                'message' =>
                    'Challenge is too short. Continuum should be generating a long random nonce.'
            ]);
        }
        
        try {
            list($update, $signature) = $this->chanUp->verifyUpdate(
                $this->sk,
                $_POST['challenge']
            );
            \Airship\json_response([
                'status' =>
                    'OK',
                'response' =>
                    $update,
                'signature' =>
                    $signature
            ]);
        } catch (\Exception $ex) {
            \Airship\json_response([
                'status' =>
                    'error',
                'message' =>
                    $ex->getMessage()
            ]);
        }
    }
}
