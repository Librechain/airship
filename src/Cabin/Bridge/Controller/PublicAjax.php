<?php
declare(strict_types=1);
namespace Airship\Cabin\Bridge\Controller;

use Airship\Cabin\Bridge\Model\UserAccounts;

require_once __DIR__.'/init_gear.php';

/**
 * Class PublicAjax
 * @package Airship\Cabin\Bridge\Controller
 */
class PublicAjax extends ControllerGear
{
    /**
     * @var UserAccounts
     */
    protected $acct;

    /**
     * This function is called after the dependencies have been injected by
     * AutoPilot. Think of it as a user-land constructor.
     */
    public function airshipLand(): void
    {
        parent::airshipLand();
        $acct = $this->model('UserAccounts');
        if (!($acct instanceof UserAccounts)) {
            throw new \TypeError(
                \__('UserAccounts Model')
            );
        }
        $this->acct = $acct;
    }

    /**
     * AJAX + JSON API to see if a username is taken or invalid.
     */
    public function checkUsername(): void
    {
        // If you didn't supply a username, it's not available.
        if (!\array_key_exists('username', $_POST)) {
            \Airship\json_response([
                'status' => 'error',
                'message' => \__('You did not supply a username'),
                'result' => []
            ]);
        }

        // Did someone else reserve this username?
        if ($this->acct->isUsernameTaken($_POST['username'])) {
            \Airship\json_response([
                'status' => 'success',
                'message' => \__('Username is not available'),
                'result' => [
                    'available' => false
                ]
            ]);
        }

        if ($this->acct->isUsernameInvalid($_POST['username'])) {
            \Airship\json_response([
                'status' => 'success',
                'message' => \__('Username is not available'),
                'result' => [
                    'available' => false
                ]
            ]);
        }

        // The username has not been reserved.
        \Airship\json_response([
            'status' => 'success',
            'message' => \__('Username is available'),
            'result' => [
                'available' => true
            ]
        ]);
    }
}
