<?php
declare(strict_types=1);
namespace Airship\Engine\Continuum\Updaters;

use Airship\Alerts\{
    Continuum\CouldNotUpdate,
    Hail\NoAPIResponse
};
use Airship\Engine\{
    Contract\ContinuumInterface,
    Continuum\AutoUpdater,
    Continuum\Log,
    Continuum\Supplier,
    Hail,
    State
};
use ParagonIE\ConstantTime\Base64UrlSafe;
use Psr\Log\LogLevel;

/**
 * Class Airship
 *
 * This is used to self-update the Airship
 *
 * @package Airship\Engine\Continuum
 */
class Airship extends AutoUpdater implements ContinuumInterface
{
    /**
     * @var string
     */
    protected $pharAlias = 'airship-update.phar';

    /**
     * @var string
     */
    protected $name = 'airship'; // Package name

    /**
     * @var string
     */
    protected $ext = 'phar';

    /**
     * Airship constructor.
     *
     * @param Hail $hail
     * @param Supplier $sup
     */
    public function __construct(Hail $hail, Supplier $sup)
    {
        $this->supplier = $sup;
        $this->hail = $hail;
        $this->type = self::TYPE_ENGINE;

        if (!self::$continuumLogger) {
            self::$continuumLogger = new Log();
        }
    }

    /**
     * Process automatic updates:
     *
     * 1. Check if a new update is available.
     * 2. Download the upload file, store in a temporary file.
     * 3. Verify the signature (via Halite).
     * 4. Verify the update is recorded in Keyggdrasil.
     * 5. If all is well, run the update script.
     * @return void
     */
    public function autoUpdate()
    {
        $state = State::instance();
        try {
            /**
             * @var UpdateInfo[]
             */
            $updateInfoArray = $this->updateCheck(
                $state->universal['airship']['trusted-supplier'],
                $this->name,
                \AIRSHIP_VERSION,
                'airship_version'
            );

            /**
             * Let's iterate through every possible available update,
             * until we find the desired version.
             */
            foreach ($updateInfoArray as $updateInfo) {
                if (!$this->checkVersionSettings($updateInfo, \AIRSHIP_VERSION)) {
                    // This is not the update we are looking for.
                    $this->log(
                        'Skipping update',
                        LogLevel::DEBUG,
                        [
                            'info' => $updateInfo->getResponse(),
                            'new_version' => $updateInfo->getVersion(),
                            'current_version' => \AIRSHIP_VERSION
                        ]
                    );
                    continue;
                }

                /**
                 * @var UpdateFile
                 */
                $updateFile = $this->downloadUpdateFile(
                    $updateInfo,
                    'airship_download'
                );
                if ($this->bypassSecurityAndJustInstall) {
                    // I'm sorry, Dave. I'm afraid I can't do that.
                    $this->log('Core update verification cannot be bypassed', LogLevel::ERROR);
                    self::$continuumLogger->store(
                        LogLevel::ALERT,
                        'CMS Airship core update - security bypass ignored.',
                        $this->getLogContext($updateInfo, $updateFile)
                    );
                }

                /**
                 * Don't proceed unless we've verified the signatures
                 */
                if ($this->verifyUpdateSignature($updateInfo, $updateFile)) {
                    if ($this->checkKeyggdrasil($updateInfo, $updateFile)) {
                        $this->install($updateInfo, $updateFile);
                    } else {
                        $this->log('Keyggdrasil check failed for Airship core update', LogLevel::ALERT);
                        self::$continuumLogger->store(
                            LogLevel::ALERT,
                            'CMS Airship core update failed -- checksum not registered in Keyggdrasil',
                            $this->getLogContext($updateInfo, $updateFile)
                        );
                    }
                } else {
                    $this->log('Invalid signature for this Airship core update', LogLevel::ALERT);
                    self::$continuumLogger->store(
                        LogLevel::ALERT,
                        'CMS Airship core update failed -- invalid signature',
                        $this->getLogContext($updateInfo, $updateFile)
                    );
                }
            }
        } catch (NoAPIResponse $ex) {
            // We should log this.
            $this->log(
                'Automatic update failure: NO API Response.',
                LogLevel::ERROR,
                \Airship\throwableToArray($ex)
            );
            self::$continuumLogger->store(
                LogLevel::ALERT,
                'CMS Airship core update failed -- no API Response',
                [
                    'action' => 'UPDATE',
                    'name' => $this->name,
                    'supplier' => $this->getSupplier()->getName(),
                    'type' => $this->type
                ]
            );
        }
    }

    /**
     * Let's install the automatic update.
     *
     * If we get to this point:
     *
     * 1. We know the signature is signed by
     *    Paragon Initiative Enterprises, LLC.
     * 2. The hash was checked into Keyggdrasil, which
     *    was independently vouched for by our peers.
     *
     * @param UpdateInfo $info
     * @param UpdateFile $file
     * @return void
     * @throws CouldNotUpdate
     * @psalm-suppress ForbiddenCode because we're hooking into composer to trigger an update
     */
    protected function install(UpdateInfo $info, UpdateFile $file)
    {
        if (!$file->hashMatches($info->getChecksum())) {
            throw new CouldNotUpdate(
                \__('Checksum mismatched')
            );
        }
        // Let's open the update package:
        $path = $file->getPath();
        $updater = new \Phar(
            $path,
            \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::KEY_AS_FILENAME
        );
        $updater->setAlias($this->pharAlias);
        $metadata = $updater->getMetadata();

        // We need to do this while we're replacing files.
        $this->bringSiteDown();

        if (isset($metadata['files'])) {
            foreach ($metadata['files'] as $fileName) {
                $this->replaceFile($fileName);
            }
        }
        if (isset($metadata['autoRun'])) {
            foreach ($metadata['autoRun'] as $autoRun) {
                $this->autoRunScript($autoRun);
            }
        }

        // If we included composer.lock, we need to install the dependencies.
        if (\in_array('composer.lock', $metadata['files'], true)) {
            $composers = [
                '/usr/bin/composer',
                '/usr/bin/composer.phar',
                \dirname(ROOT) . '/composer',
                \dirname(ROOT) . '/composer.phar'
            ];
            $composerUpdated = false;
            foreach ($composers as $composer) {
                if (\file_exists($composer)) {
                    $dir = (string) \getcwd();
                    \chdir(\dirname(ROOT));
                    \shell_exec("{$composer} install");
                    \chdir($dir);
                    $composerUpdated = true;
                    break;
                }
            }

            if (!$composerUpdated) {
                self::$continuumLogger->store(
                    LogLevel::INFO,
                    'Could not update dependencies. Please run Composer manually.',
                    $this->getLogContext($info, $file)
                );
            }
        }

        // Free up the updater alias
        $garbageAlias = Base64UrlSafe::encode(\random_bytes(63)) . '.phar';
        $updater->setAlias($garbageAlias);
        unset($updater);

        // Now bring it back up.
        $this->bringSiteBackUp();
        self::$continuumLogger->store(
            LogLevel::INFO,
            'CMS Airship core update installed',
            $this->getLogContext($info, $file)
        );
    }

    /**
     * Unique to Airship updating; replace a file with one in the archive
     *
     * @param string $filename
     * @return bool
     */
    protected function replaceFile(string $filename): bool
    {
        if (\file_exists(\dirname(ROOT) . DIRECTORY_SEPARATOR . $filename . '.backup')) {
            \unlink(
                \dirname(ROOT) . DIRECTORY_SEPARATOR . $filename . '.backup'
            );
        }

        // Make backup copies of the old file, just in case someone
        // decided to edit the core files against medical advice.
        \rename(
            \dirname(ROOT) . DIRECTORY_SEPARATOR . $filename,
            \dirname(ROOT) . DIRECTORY_SEPARATOR . $filename . '.backup'
        );
        return \file_put_contents(
            \dirname(ROOT) . DIRECTORY_SEPARATOR . $filename,
            \file_get_contents('phar://' . $this->pharAlias . '/'.$filename)
        ) !== false;
    }
}
