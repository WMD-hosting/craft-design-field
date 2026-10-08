<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\jobs;

use Craft;
use craft\queue\BaseJob;
use wmd\designfield\Plugin;

/**
 * Counts which choices editors pick (the usage report) and caches it, so long dropdowns
 * can show the most used ones first.
 *
 * @author WMD
 * @since 1.0.0
 */
class UsageCountsJob extends BaseJob
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $usage = Plugin::getInstance()->getUsage();
        $usage->storeCounts($usage->report());
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('design-field', 'Counting design choices');
    }
}
