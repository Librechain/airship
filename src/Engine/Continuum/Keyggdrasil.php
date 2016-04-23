<?php
declare(strict_types=1);

namespace Airship\Engine\Continuum;

use \Airship\Alerts\Continuum\ChannelSignatureFailed;
use \Airship\Alerts\Continuum\CouldNotUpdate;
use \Airship\Engine\{
    Bolt\Supplier as SupplierBolt,
    Bolt\Log,
    Contract\DBInterface,
    Hail,
    State
};
use \GuzzleHttp\Psr7\Response;
use \GuzzleHttp\Exception\TransferException;
use \ParagonIE\ConstantTime\Base64;
use ParagonIE\ConstantTime\Base64UrlSafe;
use \ParagonIE\Halite\{
    Asymmetric\Crypto as AsymmetricCrypto,
    Structure\MerkleTree,
    Structure\Node
};
use \Psr\Log\LogLevel;

/**
 * Class Keygdrassil
 *
 * (Yggdrasil = "world tree")
 *
 * This synchronizes our public keys for each channel with the rest of the network,
 * taking care to verify that a random subset of trusted peers sees the same keys.
 *
 * @package Airship\Engine\Continuum
 */
class Keyggdrasil
{
    use SupplierBolt;
    use Log;

    protected $db;
    protected $hail;
    protected $supplierCache;
    protected $channelCache;

    /**
     * Keyggdrasil constructor.
     *
     * @param Hail|null $hail
     * @param DBInterface|null $db
     * @param array $channels
     */
    public function __construct(Hail $hail = null, DBInterface $db = null, array $channels = [])
    {
        $config = State::instance();
        if (empty($hail)) {
            $this->hail = $config->hail;
        } else {
            $this->hail = $hail;
        }

        if (!$db) {
            $db = \Airship\get_database();
        }
        $this->db = $db;

        foreach ($channels as $ch => $config) {
            $this->channelCache[$ch] = new Channel($this, $ch, $config);
        }
    }

    /**
     * Launch the update process.
     *
     * This updates our keys for each channel.
     */
    public function doUpdate()
    {
        if (empty($this->channelCache)) {
            return;
        }
        foreach ($this->channelCache as $chan) {
            $this->updateChannel($chan);
        }
    }

    /**
     * Fetch all of the updates from the remote server.
     *
     * @param Channel $chan
     * @param string $url
     * @return KeyUpdate[]
     * @throws TransferException
     */
    protected function fetchKeyUpdates(Channel $chan, string $url): array
    {
        $response = $this->hail->post($url . API::get('fetch_keys'));
        if ($response instanceof Response) {
            $code = $response->getStatusCode();
            if ($code >= 200 && $code < 300) {
                $body = (string) $response->getBody();

                // This should return an array of KeyUpdate objects:
                return $this->parseKeyUpdateResponse($chan, $body);
            }
        }
        // When all else fails, TransferException
        throw new TransferException();
    }

    /**
     * Get the tree of existing Merkle roots.
     *
     * @param Channel $chan
     * @return MerkleTree
     */
    protected function getMerkleTree(Channel $chan): MerkleTree
    {
        $nodeList = [];
        $queryString = 'SELECT data FROM airship_key_updates WHERE channel = ? ORDER BY keyupdateid ASC';
        foreach ($this->db->run($queryString, $chan->getName()) as $node) {
            $nodeList []= new Node($node['data']);
        }
        return new MerkleTree(...$nodeList);
    }

    /**
     * Interpret the KeyUpdate objects from the API response. OR verify the signature
     * of the "no updates" message to prevent a DoS.
     *
     * @param Channel $chan
     * @param string $body
     * @return KeyUpdate[]
     * @throws ChannelSignatureFailed
     * @throws CouldNotUpdate
     */
    protected function parseKeyUpdateResponse(Channel $chan, string $body): array
    {
        $response = \Airship\parseJSON($body);
        if (empty($response['updates'])) {
            // The "no updates" message should be authenticated.
            if (!AsymmetricCrypto::verify($response['no_updates'], $chan->getPublicKey(), $response['signature'])) {
                throw new ChannelSignatureFailed();
            }
            $datetime = new \DateTime($response['no_updates']);

            // One hour ago:
            $stale = (new \DateTime('now'))
                ->sub(new \DateInterval('PT01H'));

            if ($datetime < $stale) {
                throw new CouldNotUpdate('Stale response.');
            }

            // We got nothing to do:
            return [];
        }

        /**
         * $response['updates'] should look like this:
         * [
         *    {
         *        "id" 10,
         *        "data": "base64urlSafeEncoded_JSON_Blob",
         *        "signature": "blahblahblah"
         *    },
         *    {
         *        ...
         *    },
         *    ...
         * ]
         */
        $keyUpdateArray = [];
        foreach ($response['updates'] as $update) {
            $data = Base64UrlSafe::decode($update['data']);
            // Verify the signature of each update.
            if (!AsymmetricCrypto::verify($data, $chan->getPublicKey(), $update['signature'])) {
                // Invalid signature
                throw new ChannelSignatureFailed();
            }
            $keyUpdateArray[] = new KeyUpdate($chan, \json_decode($data, true));
        }
        return $keyUpdateArray;
    }

    /**
     * Insert/delete entries in supplier_keys, while updating the database.
     *
     * Return the updated Merkle Tree if all is well
     *
     * @param Channel $chan)
     * @param KeyUpdate[] $updates
     */
    protected function processKeyUpdates(Channel $chan, KeyUpdate ...$updates)
    {

    }

    /**
     * Update a particular channel.
     *
     * 1. Identify a working URL for the channel.
     * 2. Query server for updates.
     * 3. For each update:
     *    1. Verify that our trusted notaries see the same update.
     *       (Ed25519 signature of challenge nonce || Merkle root)
     *    2. Add/remove the supplier's key.
     *
     * @param Channel $chan
     */
    protected function updateChannel(Channel $chan)
    {
        $originalTree = $this->getMerkleTree($chan);
        foreach ($chan->getAllURLs() as $url) {
            try {
                $updates = $this->fetchKeyUpdates($chan, $url); // KeyUpdate[]
                while (!empty($updates)) {
                    $merkleTree = $originalTree;
                    if ($this->verifyResponseWithPeers($chan, $merkleTree, ...$updates)) {
                        $this->processKeyUpdates($chan, ...$updates);
                        return;
                    }
                    \array_pop($updates);
                }
                // Received a successful API response.
                return;
            } catch (ChannelSignatureFailed $ex) {
                $this->log(
                    'Invalid Channel Signature for ' . $chan->getName(),
                    LogLevel::ALERT,
                    \Airship\throwableToArray($ex)
                );
            } catch (TransferException $ex) {
                $this->log(
                    'Channel update error',
                    LogLevel::NOTICE,
                    \Airship\throwableToArray($ex)
                );
            }
        }
        // IF we get HERE, we've run out of updates to try.

        $this->log('Channel update concluded with no changes', LogLevel::ALERT);
    }

    /**
     * Return true if the Merkle roots match.
     *
     * This employs challenge-response authentication:
     * @ref https://github.com/paragonie/airship/issues/13
     *
     * @param Channel $channel
     * @param MerkleTree $originalTree
     * @param KeyUpdate[] ...$updates
     * @return bool
     */
    protected function verifyResponseWithPeers(
        Channel $channel,
        MerkleTree $originalTree,
        KeyUpdate ...$updates
    ): bool {
        
    }
}