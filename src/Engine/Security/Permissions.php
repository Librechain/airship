<?php
declare(strict_types=1);
namespace Airship\Engine\Security;

use Airship\Alerts\Database\NotImplementedException;
use Airship\Engine\{
    AutoPilot,
    Database,
    State
};

/**
 * Class Permissions
 *
 * Manages user-based and role-based access controls with overlapping
 * pattern-based contexts and a multi-site architecture, with a simple
 * interface. i.e. $this->can('read') // bool(false)
 *
 * @package Airship\Engine\Security
 */
class Permissions
{
    public const MAX_RECURSE_DEPTH = 100;

    /**
     * @var Database
     */
    private $db;
    
    /**
     * @param Database $db
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Perform a permissions check
     *
     * @param string $action action label (e.g. 'read')
     * @param string $context_path context regex (in perm_contexts)
     * @param string $cabin (defaults to current cabin)
     * @param integer $user_id (defaults to current user)
     * @return bool
     *
     * @throws NotImplementedException
     */
    public function can(
        string $action,
        string $context_path = '',
        string $cabin = CABIN_NAME,
        int $user_id = 0
    ): bool {
        $state = State::instance();
        /** @var array<string, mixed> $univ */
        $univ = $state->universal;
        if (empty($cabin)) {
            $cabin = CABIN_NAME;
        }
        // If you don't specify the user ID to check, it will use the current
        // user ID instead, by default.
        if (empty($user_id)) {
            if (!empty($_SESSION['userid'])) {
                $user_id = (int) $_SESSION['userid'];
            }
        }

        // If you're a super-user, the answer is "yes, yes you can".
        if ($this->isSuperUser($user_id)) {
            return true;
        }
        $allowed = false;
        $failed_one = false;
        
        // Get all applicable contexts
        /** @var array<int, int> $contexts */
        $contexts = $this->getOverlap($context_path, $cabin);
        if (empty($contexts)) {
            // Sane default: In the absence of permissions, return false
            return false;
        }
        if ($user_id > 0) {
            // You need to be allowed in every relevant context.
            foreach ($contexts as $c_id) {
                if (
                    $this->checkUser($action, $c_id, $user_id)
                        ||
                    $this->checkUsersGroups($action, $c_id, $user_id)
                ) {
                    $allowed = true;
                } else {
                    $failed_one = true;
                }
            }
        } else {
            if (!$univ['guest_groups']) {
                return false;
            }
            // Guests can be assigned to groups. This fails closed if they aren't given any.
            /** @var array<string, int> $guest_groups */
            $guest_groups = $univ['guest_groups'];
            foreach ($contexts as $c_id) {
                $ctx_res = false;
                foreach ($guest_groups as $grp) {
                    if ($this->checkGroup($action, $c_id, $grp)) {
                        $ctx_res = true;
                    }
                }
                if ($ctx_res) {
                    $allowed = true;
                } else {
                    $failed_one = true;
                }
            }
        }
        // We return true if we were allowed at least once and we did not fail
        // in one of the overlapping contexts
        return $allowed && !$failed_one;
    }

    /**
     * Do the members of this group have permission to do something?
     *
     * @param string $action - perm_actions.label
     * @param int $context_id - perm_contexts.contextid
     * @param integer $group_id - groups.groupid
     * @param bool $deep_search - Also search groups' inheritances
     * @return bool
     */
    public function checkGroup(
        string $action,
        int $context_id = null,
        int $group_id = null,
        bool $deep_search = true
    ): bool {
        return 0 < $this->db->single(
            \Airship\queryStringRoot(
                $deep_search
                    ? 'security.permissions.check_groups_deep'
                    : 'security.permissions.check_groups',
                $this->db->getDriver()
            ),
            [
                'action' => $action,
                'context' => $context_id,
                'group' => $group_id
            ]
        );
    }

    /**
     * Check that the user, specifically, has permission to do something.
     * Ignores group-based access controls.
     *
     * @param string $action
     * @param int $context_id
     * @param int $user_id
     * @param bool $ignore_superuser
     * @return bool
     * @throws NotImplementedException
     */
    public function checkUser(
        string $action,
        int $context_id = 0,
        int $user_id = 0,
        bool $ignore_superuser = false
    ): bool {
        if (!$ignore_superuser) {
            if ($this->isSuperUser($user_id)) {
                return true;
            }
        }
        /** @var int $check */
        $check = $this->db->single(
            \Airship\queryStringRoot(
                'security.permissions.check_user',
                $this->db->getDriver()
            ),
            [
                'action' => $action,
                'context' => $context_id,
                'user' => $user_id
            ]
        );
        return $check > 0;
    }

    /**
     * Check that any of the users' groups has the permission bit
     *
     * @param string $action
     * @param int $context_id
     * @param int $user_id
     * @param bool $ignore_superuser
     * @return bool
     */
    public function checkUsersGroups(
        string $action = '',
        int $context_id = 0,
        int $user_id = 0,
        bool $ignore_superuser = false
    ): bool {
        if (!$ignore_superuser) {
            if ($this->isSuperUser($user_id)) {
                return true;
            }
        }
        return 0 < $this->db->single(
            \Airship\queryStringRoot(
                'security.permissions.check_users_groups',
                $this->db->getDriver()
            ),
            [
                'action' => $action,
                'context' => $context_id,
                'user' => $user_id
            ]
        );
    }

    /**
     * Returns an array with overlapping context IDs -- useful for when
     * contexts are used with regular expressions
     *
     * @param string $context Context
     * @param string $cabin Cabin
     * @return array
     */
    public function getOverlap(
        string $context = '',
        string $cabin = \CABIN_NAME
    ): array {
        if (empty($context)) {
            $context = AutoPilot::$path;
        }
        /** @var array<int, int> $ctx */
        $ctx = $this->db->first(
            \Airship\queryStringRoot(
                'security.permissions.get_overlap',
                $this->db->getDriver()
            ),
            $cabin,
            $context
        );
        if (empty($ctx)) {
            return [];
        }
        return $ctx;
    }
    
    /**
     * Is this user a super user? Do they belong in a superuser group?
     * 
     * @param int $user_id - User ID
     * @param bool $ignore_groups - Don't look at their groups
     * @return bool
     */
    public function isSuperUser(
        int $user_id = 0,
        bool $ignore_groups = false
    ): bool {
        if (empty($user_id)) {
            // We can short-circuit this for guests...
            return false;
        }

        $statements = [
            'check_user' => \Airship\queryStringRoot(
                'security.permissions.is_superuser_user',
                $this->db->getDriver()
            ),
            'check_groups' =>\Airship\queryStringRoot(
                'security.permissions.is_superuser_group',
                $this->db->getDriver()
            )
        ];

        if ($this->db->exists($statements['check_user'], $user_id)) {
            return true;
        } elseif (!$ignore_groups) {
            return $this->db->exists($statements['check_groups'], $user_id);
        }
        return false;
    }
}
