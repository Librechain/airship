<?php
declare(strict_types=1);
namespace Airship\Cabin\Bridge\Filter\Permissions;

use ParagonIE\Ionizer\Filter\StringFilter;
use ParagonIE\Ionizer\InputFilterContainer;

/**
 * Class SaveContextFilter
 * @package Airship\Cabin\Bridge\Filter\Account
 */
class SaveContextFilter extends InputFilterContainer
{
    /**
     * SaveContextFilter constructor.
     */
    public function __construct()
    {
        $this->addFilter(
            'context',
            (new StringFilter())
                ->addCallback([StringFilter::class, 'nonEmpty'])
        );
    }
}
