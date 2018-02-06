<?php
declare(strict_types=1);
namespace Airship\Cabin\Bridge\Filter;

use ParagonIE\Ionizer\InputFilterContainer;
use ParagonIE\Ionizer\Filter\StringFilter;

/**
 * Class AnnounceFilter
 * @package Airship\Cabin\Bridge\Filter\Account
 */
class AnnounceFilter extends InputFilterContainer
{
    /**
     * RecoveryFilter constructor.
     */
    public function __construct()
    {
        $this
            ->addFilter('contents', new StringFilter())
            ->addFilter(
                'contents',
                (new StringFilter())
                    ->setDefault('Rich Text')
            )
            ->addFilter(
                'title',
                (new StringFilter())
                    ->addCallback([StringFilter::class, 'nonEmpty'])
            );
    }
}
