<?php
declare(strict_types=1);
namespace Airship\Cabin\Bridge\Filter\Permissions;

use ParagonIE\Ionizer\InputFilterContainer;
use ParagonIE\Ionizer\Filter\StringFilter;

/**
 * Class SaveActionFilter
 * @package Airship\Cabin\Bridge\Filter\Account
 */
class SaveActionFilter extends InputFilterContainer
{
    /**
     * SaveActionFilter constructor.
     */
    public function __construct()
    {
        $this->addFilter(
            'label',
            (new StringFilter())
                ->addCallback([StringFilter::class, 'nonEmpty'])
        );
    }
}
