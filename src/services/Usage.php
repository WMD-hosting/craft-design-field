<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\services;

use Craft;
use craft\elements\Entry;
use DateTimeInterface;
use Throwable;
use wmd\designfield\fields\Design;
use wmd\designfield\helpers\UsageReport;
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
}
