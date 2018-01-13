<?php
declare(strict_types=1);
namespace Airship\Cabin\Bridge\Controller;

use Airship\Cabin\Bridge\Model\Skyport as SkyportBP;
use Airship\Cabin\Bridge\Filter\SkyportFilter;
use Airship\Engine\Security\{
    Util
};
use ParagonIE\Halite\HiddenString;
use Psr\Log\LogLevel;

require_once __DIR__.'/init_gear.php';

/**
 * Class Skyport
 * @package Airship\Cabin\Bridge\Controller
 */
class Skyport extends AdminOnly
{
    /**
     * @var string
     */
    protected $channel = 'paragonie';

    /**
     * @var int
     */
    protected $perPage = 10;

    /**
     * @var SkyportBP
     */
    protected $skyport;

    /**
     * This function is called after the dependencies have been injected by
     * AutoPilot. Think of it as a user-land constructor.
     *
     * @return void
     * @throws \TypeError
     */
    public function airshipLand()
    {
        parent::airshipLand();
        $skyport = $this->model('Skyport');
        if (!($skyport instanceof SkyportBP)) {
            throw new \TypeError(
                \trk('errors.type.wrong_class', SkyportBP::class)
            );
        }
        $this->skyport = $skyport;
        $this->storeViewVar('active_submenu', ['Admin', 'Extensions']);
        $this->storeViewVar('active_link', 'bridge-link-skyport');
    }

    /**
     * @route ajax/admin/skyport/browse
     */
    public function ajaxGetAvailablePackages(): void
    {
        $post = $this->ajaxPost($_POST ?? [], 'csrf_token');
        $type = '';
        $headline = 'Available Extensions';
        if (isset($post['type'])) {
            switch ($post['type']) {
                case 'cabin':
                    $headline = 'Available Cabins';
                    $type = 'Cabin';
                    break;
                case 'gadget':
                    $headline = 'Available Gadgets';
                    $type = 'Gadget';
                    break;
                case 'motif':
                    $headline = 'Available Motifs';
                    $type = 'Motif';
                    break;
            }
        }
        $query = (string) ($post['query'] ?? '');
        $numAvailable = $this->skyport->countAvailable($type);
        list($page, $offset) = $this->getPaginated($numAvailable);

        $this->view(
            'skyport/list',
            [
                'headline' => $headline,
                'extensions' => $this->skyport->getAvailable(
                        $type,
                        $query,
                        $offset,
                        $this->perPage
                    ),
                'pagination' => [
                    'count' => $numAvailable,
                    'page' => $page,
                    'per_page' => $this->perPage
                ]
            ]
        );
    }

    /**
     * @route ajax/admin/skyport/installed
     */
    public function ajaxGetInstalledPackages(): void
    {
        $numInstalled = $this->skyport->countInstalled();
        list($page, $offset) = $this->getPaginated($numInstalled);
        $this->view(
            'skyport/list',
            [
                'headline' => 'Installed Extensions',
                'extensions' => $this->skyport->getInstalled(
                    false,
                    $offset,
                    $this->perPage
                ),
                'pagination' => [
                    'count' => $numInstalled,
                    'page' => $page,
                    'per_page' => $this->perPage
                ]
            ]
        );
    }

    /**
     * @route ajax/admin/skyport/leftmenu
     */
    public function ajaxGetLeftMenu(): void
    {
        $this->view(
            'skyport/left',
            [
                'left' => $this->skyport->getLeftMenu()
            ]
        );
    }

    /**
     * @route ajax/admin/skyport/stale
     */
    public function ajaxGetOutdatedPackages(): void
    {
        $this->view(
            'skyport/outdated',
            [
                'headline' => 'Outdated Extensions',
                'extensions' => $this->skyport->getOutdatedPackages()
            ]
        );
    }

    /**
     * @route ajax/admin/skyport/refresh
     */
    public function ajaxRefreshPackageInfo(): void
    {
        $expected = [
            'package',
            'supplier',
            'type'
        ];
        $post = $this->ajaxPost($_POST ?? [], 'csrf_token');
        if (!\Airship\all_keys_exist($expected, $post)) {
            echo 'Invalid POST request.', "\n";
            return;
        }
        $type = '';
        if (isset($post['type'])) {
            switch ($post['type']) {
                case 'cabin':
                    $type = 'Cabin';
                    break;
                case 'gadget':
                    $type = 'Gadget';
                    break;
                case 'motif':
                    $type = 'Motif';
                    break;
                default:
                    echo 'Invalid POST request.', "\n";
                    return;
            }
        }

        $this->skyport->manualRefresh(
            $type,
            $_POST['supplier'],
            $_POST['package']
        );

        $this->view(
            'skyport/view',
            [
                'package' => $this->skyport->getDetails(
                    $_POST['type'],
                    $_POST['supplier'],
                    $_POST['package']
                ),
                'skyport_url' => $this->skyport->getURL(
                    $_POST['type'],
                    $_POST['supplier'],
                    $_POST['package']
                )
            ]
        );
    }


    /**
     * @route ajax/admin/skyport/view
     */
    public function ajaxViewPackageInfo(): void
    {
        $expected = [
            'package',
            'supplier',
            'type'
        ];
        $post = $this->ajaxPost($_POST ?? [], 'csrf_token');
        if (!\Airship\all_keys_exist($expected, $post)) {
            echo 'Invalid POST request.', "\n";
            return;
        }

        $this->view(
            'skyport/view',
            [
                'package' => $this->skyport->getDetails(
                    $_POST['type'],
                    $_POST['supplier'],
                    $_POST['package']
                ),
                'skyport_url' => $this->skyport->getURL(
                    $_POST['type'],
                    $_POST['supplier'],
                    $_POST['package']
                )
            ]
        );
    }

    /**
     * @route admin/skyport
     */
    public function index(): void
    {
        $this->includeAjaxToken()->view(
            'skyport',
            [
                'left' => $this->skyport->getLeftMenu()
            ]
        );
    }

    /**
     * Trigger the package install process
     *
     */
    public function installPackage(): void
    {
        $expected = [
            'package',
            'supplier',
            'type'
        ];
        $post = $this->ajaxPost($_POST ?? [], 'csrf_token');
        if (!\Airship\all_keys_exist($expected, $post)) {
            \Airship\json_response([
                'status' => 'ERROR',
                'message' => \__('Incomplete request.')
            ]);
        }
        if ($this->skyport->isLocked()) {
            $locked = true;
            if ($this->skyport->isPasswordLocked() && !empty($post['password'])) {
                $password = new HiddenString($post['password']);
                if ($this->skyport->tryUnlockPassword($password)) {
                    $_SESSION['airship_install_lock_override'] = true;
                    $locked = false;
                }
                unset($password);
            }
            if ($locked) {
                if ($this->skyport->isPasswordLocked()) {
                    \Airship\json_response([
                        'status' => 'PROMPT',
                        'message' => \__(
                            'The skyport is locked. To unlock the skyport, please provide the password.'
                        )
                    ]);
                }
                \Airship\json_response([
                    'status' => 'ERROR',
                    'message' => \__(
                        'The skyport is locked. You cannot install packages from the web interface.'
                    )
                ]);
            }
        }
        try {
            $filter = new SkyportFilter();
            $post = $filter($post);
        } catch (\TypeError $ex) {
            $this->log(
                "Input violation",
                LogLevel::ALERT,
                \Airship\throwableToArray($ex)
            );
            \Airship\json_response([
                'status' => 'ERROR',
                'message' => \__(
                    'Invalid input.'
                )
            ]);
        }

        /**
         * @security We need to guarantee RCE isn't possible:
         */
        $args = \implode(
            ' ',
            [
                \escapeshellarg(
                    Util::charWhitelist($post['type'], Util::PRINTABLE_ASCII)
                ),
                \escapeshellarg(
                    Util::charWhitelist($post['supplier'], Util::PRINTABLE_ASCII) .
                        '/' .
                    Util::charWhitelist($post['package'], Util::PRINTABLE_ASCII)
                )
            ]
        );
        /** @psalm-suppress ForbiddenCode */
        $output = (string) \shell_exec('php -dphar.readonly=0 ' . ROOT . '/CommandLine/install.sh ' . $args);

        \Airship\json_response([
            'status' => 'OK',
            'message' => $output
        ]);
    }

    /**
     * Trigger the package uninstall process.
     */
    public function removePackage(): void
    {
        $expected = [
            'package',
            'supplier',
            'type'
        ];
        $post = $this->ajaxPost($_POST ?? [], 'csrf_token');
        if (!\Airship\all_keys_exist($expected, $_POST)) {
            \Airship\json_response([
                'status' => 'ERROR',
                'message' => \__('Incomplete request.')
            ]);
        }
        if ($this->skyport->isLocked()) {
            $locked = true;
            if ($this->skyport->isPasswordLocked() && !empty($post['password'])) {
                $password = new HiddenString($post['password']);
                if ($this->skyport->tryUnlockPassword($password)) {
                    $_SESSION['airship_install_lock_override'] = true;
                    $locked = false;
                }
                unset($password);
            }
            if ($locked) {
                if ($this->skyport->isPasswordLocked()) {
                    \Airship\json_response([
                        'status' => 'PROMPT',
                        'message' => \__(
                            'The skyport is locked. To unlock the skyport, please provide the password.'
                        )
                    ]);
                }
                \Airship\json_response([
                    'status' => 'ERROR',
                    'message' => \__(
                        'The skyport is locked. You cannot install packages from the web interface.'
                    )
                ]);
            }
        }

        try {
            $filter = new SkyportFilter();
            $post = $filter($post);
        } catch (\TypeError $ex) {
            $this->log(
                "Input violation",
                LogLevel::ALERT,
                \Airship\throwableToArray($ex)
            );
            \Airship\json_response([
                'status' => 'ERROR',
                'message' => 'Type Error'
            ]);
            return;
        }

        $output = $this->skyport->forceRemoval(
            $post['type'],
            $post['supplier'],
            $post['package']
        );

        \Airship\json_response([
            'status' => 'OK',
            'message' => $output
        ]);
    }

    /**
     * Trigger the package install process
     */
    public function updatePackage(): void
    {
        $expected = [
            'package',
            'supplier',
            'type',
            'version'
        ];
        $post = $this->ajaxPost($_POST ?? [], 'csrf_token');
        if (!\Airship\all_keys_exist($expected, $post)) {
            \Airship\json_response([
                'status' => 'ERROR',
                'message' => \__('Incomplete request.')
            ]);
        }
        try {
            $filter = new SkyportFilter();
            $post = $filter($post);
        } catch (\TypeError $ex) {
            $this->log(
                "Input violation",
                LogLevel::ALERT,
                \Airship\throwableToArray($ex)
            );
            \Airship\json_response([
                'status' => 'ERROR',
                'message' => \__(
                    'Invalid input.'
                )
            ]);
        }

        /**
         * @security We need to guarantee RCE isn't possible:
         */
        $args = \implode(
            ' ',
            [
                \escapeshellarg(
                    Util::charWhitelist($post['type'], Util::PRINTABLE_ASCII)
                ),
                \escapeshellarg(
                    Util::charWhitelist($post['supplier'], Util::PRINTABLE_ASCII) .
                        '/' .
                    Util::charWhitelist($post['package'], Util::PRINTABLE_ASCII)
                ),
                \escapeshellarg(
                    Util::charWhitelist($post['version'], Util::PRINTABLE_ASCII)
                )
            ]
        );
        /** @psalm-suppress ForbiddenCode */
        $output = (string) \shell_exec(
            'php -dphar.readonly=0 ' . ROOT . '/CommandLine/update_one.sh ' . $args
        );

        \Airship\json_response([
            'status' => 'OK',
            'message' => $output
        ]);
    }

    /**
     * View the update log
     *
     * @route admin/skyport/log
     */
    public function viewLog(): void
    {
        /** @todo allow a more granular window of logged events to be viewed */
        $this->view(
            'skyport/log',
            [
                'active_link' =>
                    'bridge-link-admin-ext-log',
                'logged' =>
                    $this->skyport->getLogMessages()
            ]
        );
    }

    /**
     * Get the page number and offset
     *
     * @param int $sizeOfList
     * @return int[]
     */
    protected function getPaginated(int $sizeOfList): array
    {
        $page = (int) ($_POST['page'] ?? 1);
        if ((($page - 1) * $this->perPage) > $sizeOfList) {
            $page = 1;
        }
        if ($page < 1) {
            $page = 1;
        }
        return [
            (int) $page,
            (int) ($page - 1) * $this->perPage
        ];
    }
}