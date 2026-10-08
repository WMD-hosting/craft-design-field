<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\services;

use Craft;
use craft\elements\Entry;
use craft\helpers\Queue;
use DateTimeInterface;
use Throwable;
use wmd\designfield\fields\Design;
use wmd\designfield\helpers\UsageReport;
use wmd\designfield\jobs\UsageCountsJob;
use wmd\designfield\models\DesignValue;
use yii\base\Component;

/**
 * Collects the choices editors made in Design fields, per entry type.
 *
 * Drafts and revisions are left out: the report is about what pages show.
 *
 * @author WMD
 * @since 1.0.0
 */
class Usage extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * Cache key of counts().
     */
    public const COUNTS_KEY = 'design-field:usage-counts';

    // Public Methods
    // =========================================================================

    /**
     * @param string[] $types Only these entry type handles; empty means every type with a Design field
     * @param ?DateTimeInterface $since Only entries saved since then (a yearly review: what editors still touch); null for all
     * @return array<string,array{entries:int,groups:array<string,array<string,mixed>>}> Entry type handle => report
     *
     * @author WMD
     * @since 1.0.0
     */
    public function report(array $types = [], ?DateTimeInterface $since = null): array
    {
        $report = [];

        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $type) {
            if ($types !== [] && !in_array($type->handle, $types, true)) {
                continue;
            }

            $handle = null;
            foreach ($type->getFieldLayout()->getCustomFields() as $field) {
                if ($field instanceof Design) {
                    $handle = $field->handle;
                    break;
                }
            }
            if ($handle === null) {
                continue;
            }

            $groups = [];
            $entries = [];
            $query = Entry::find()->typeId($type->id)->status(null)->site('*')->unique();
            if ($since !== null) {
                $query->dateUpdated('>= ' . $since->format(DateTimeInterface::ATOM));
            }

            foreach ($query->each() as $entry) {
                /** @var Entry $entry */
                // Entries can carry an older layout than their type, and orphaned
                // nested entries cannot load at all: neither says anything about use.
                try {
                    if ($entry->getFieldLayout()?->getFieldByHandle($handle) === null) {
                        continue;
                    }
                    $value = $entry->getFieldValue($handle);
                } catch (Throwable) {
                    continue;
                }
                if (!$value instanceof DesignValue) {
                    continue;
                }
                $groups += $value->groups();
                $entries[] = $value->keys();
            }

            $report[$type->handle] = [
                'entries' => count($entries),
                'groups' => UsageReport::analyze($groups, $entries),
            ];
        }

        return $report;
    }

    /**
     * How many blocks picked each choice, by entry type, option and key, for "Most used"
     * in long dropdowns. Cached for a day; when the cache is empty this returns nothing and
     * a queue job counts in the background (reading every entry is too slow for a page load).
     *
     * @return array<string,array<string,array<string,int>>> Entry type => option => key => count
     *
     * @author WMD
     * @since 1.0.0
     */
    public function counts(): array
    {
        $cache = Craft::$app->getCache();
        $counts = $cache->get(self::COUNTS_KEY);

        if (is_array($counts)) {
            return $counts;
        }

        // One job at a time, even when many editors open pages at once.
        if ($cache->add(self::COUNTS_KEY . ':queued', true, 600)) {
            Queue::push(new UsageCountsJob());
        }

        return [];
    }

    /**
     * Keeps a report's counts for counts(): the job, and the settings page's usage report,
     * which counted everything anyway.
     *
     * @param array<string,array{entries:int,groups:array<string,array<string,mixed>>}> $report report() for every type
     * @return void
     *
     * @author WMD
     * @since 1.0.0
     */
    public function storeCounts(array $report): void
    {
        $counts = [];
        foreach ($report as $type => $data) {
            foreach ($data['groups'] as $handle => $group) {
                $counts[(string)$type][(string)$handle] = array_map('intval', (array)($group['counts'] ?? []));
            }
        }

        Craft::$app->getCache()->set(self::COUNTS_KEY, $counts, 86400);
    }
}
