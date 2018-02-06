<?php
declare(strict_types=1);
namespace Airship\Cabin\Bridge\Filter\FileManager;

use ParagonIE\Ionizer\Filter\StringFilter;
use ParagonIE\Ionizer\InputFilterContainer;

/**
 * Class MoveDirFilter
 * @package Airship\Cabin\Bridge\Filter\Account
 */
class MoveDirFilter extends InputFilterContainer
{
    /**
     * MoveDirFilter constructor.
     */
    public function __construct()
    {
        $this->addFilter('new_dir', new StringFilter())
            ->addFilter(
                'new_name',
                (new StringFilter())
                    ->addCallback([StringFilter::class, 'nonEmpty'])
            );
    }
}
