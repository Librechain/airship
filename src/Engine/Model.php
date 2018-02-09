<?php
declare(strict_types=1);
namespace Airship\Engine;

use Airship\Alerts\Database\DBException;
use Airship\Engine\Bolt\{
    Common as CommonBolt,
    Log as LogBolt,
    Security as SecurityBolt
};

/**
 * Class Model
 *
 * For MVC developers, this is analogous to a Model
 * 
 * @package Airship\Engine
 */
class Model
{
    use CommonBolt;
    use LogBolt;
    use SecurityBolt;

    const TYPE_ERROR = 'Model not an instance of the expected type';

    /**
     * @var Database
     */
    public $db;

    /**
     * Model constructor.
     * @param Database|null $db
     * @throws DBException
     */
    public function __construct(Database $db = null)
    {
        if (!$db) {
            $db = \Airship\get_database();
        }
        $this->db = $db;
    }
    
    /**
     * Shorthand for $this->db->escapeIdentifier()
     *
     * Feel free to use for table/column names, but DO NOT use this for values!
     * 
     * @param string $identifier
     * @return string
     */
    public function e(string $identifier): string
    {
        return $this->db->escapeIdentifier($identifier);
    }
}
