<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use wmd\designfield\services\Usage;

/**
 * Counts how often each choice is picked, so long dropdowns can show the most used ones
 * first. Reading every entry is too slow for a page load, so it runs on the queue.
 *
 * @author WMD
 * @since 2.0.0
 */
class CountUsage implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(Usage $usage): void
    {
        $usage->storeCounts($usage->report());
    }
}
