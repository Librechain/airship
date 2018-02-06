<?php
declare(strict_types=1);
namespace Airship\Cabin\Bridge\Filter\PageManager;

use ParagonIE\Ionizer\Filter\StringFilter;
use ParagonIE\Ionizer\InputFilterContainer;

/**
 * Class PageFilter
 * @package Airship\Cabin\Bridge\Filter\Account
 */
class PageFilter extends InputFilterContainer
{
    /**
     * PageFilter constructor.
     */
    public function __construct()
    {
        $this
            ->addFilter(
                'url',
                (new StringFilter())
                    ->addCallback([StringFilter::class, 'nonEmpty'])
            )
            ->addFilter(
                'format',
                (new StringFilter())->setDefault('Rich Text')
            )
            ->addFilter('page_body', new StringFilter());
    }
}
