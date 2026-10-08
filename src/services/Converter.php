<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\models\EntryType;
use InvalidArgumentException;
use Throwable;
use wmd\designfield\fields\Design;
use wmd\designfield\helpers\MigrationPlan;
use wmd\designfield\helpers\Registry;
use wmd\designfield\models\DesignValue;
use wmd\designfield\Plugin;
use yii\base\Component;

/**
 * Moves the values of old option fields into a Design field, so blocks can
 * switch without losing their saved settings.
 *
 * Two modes: in place (the Design field is already in the block's layout),
 * or switching entries to another entry type that has the Design field.
 *
 * @author WMD
 * @since 1.0.0
 */
class Converter extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Migrates every entry of a type.
     *
     * @param string $typeHandle Entry type whose entries hold the old fields
     * @param ?string $toTypeHandle Entry type to switch them to, or null to stay
     * @param string $fieldHandle Handle of the Design field in the target layout
     * @param array<string,string> $explicitMap Old field handle => group handle
     * @param bool $overwrite Replace choices already made in the Design field
     * @param bool $dryRun Report only, save nothing
     * @param bool $drafts Include drafts
     * @param int[] $ids Limit to these entry IDs; empty means every entry of the type
     * @param ?callable(Entry,array<string,mixed>):void $onEntry Called per entry with its result
     * @return array{scanned:int,changed:int,saved:int,failed:array<int,string>,moved:array<string,int>,unknown:array<string,array<string,int>>,kept:array<string,int>,empty:array<string,int>,savedIds:int[],mapping:array<string,array<string,string>>}
     * @throws InvalidArgumentException if a type, the Design field or a mapping is invalid
     *
     * @author WMD
     * @since 1.0.0
     */
    public function migrate(
        string $typeHandle,
        ?string $toTypeHandle,
        string $fieldHandle,
        array $explicitMap = [],
        bool $overwrite = false,
        bool $dryRun = true,
        bool $drafts = false,
        array $ids = [],
        ?callable $onEntry = null,
    ): array {
        $from = $this->_entryType($typeHandle);
        $to = $toTypeHandle !== null ? $this->_entryType($toTypeHandle) : $from;
        $field = $to->getFieldLayout()->getFieldByHandle($fieldHandle);

        // A dry run saves nothing, so it may look before the Design field is in the layout.
        if (!$field instanceof Design && !$dryRun) {
            throw new InvalidArgumentException("The $to->handle layout has no Design field \"$fieldHandle\". Add it to the layout first.");
        }

        $oldHandles = array_map(static fn($f) => $f->handle, $from->getFieldLayout()->getCustomFields());
        $registry = Plugin::getInstance()->getGroups()->getRegistry();
        $plans = [];

        $report = [
            'scanned' => 0,
            'changed' => 0,
            'saved' => 0,
            'failed' => [],
            'moved' => [],
            'unknown' => [],
            'kept' => [],
            'empty' => [],
            'mapping' => [],
            'savedIds' => [],
        ];

        $query = Entry::find()
            ->typeId($from->id)
            ->status(null)
            ->site('*')
            ->unique()
            ->drafts($drafts ? null : false);

        if ($ids !== []) {
            $query->id($ids);
        }

        foreach ($query->each() as $entry) {
            /** @var Entry $entry */
            $report['scanned']++;

            try {
                $this->_migrateEntry($entry, $from, $to, $fieldHandle, $oldHandles, $registry, $plans, $explicitMap, $overwrite, $dryRun, $onEntry, $report);
            } catch (Throwable $e) {
                // e.g. an orphaned nested entry whose owner field was deleted
                $report['failed'][$entry->id] = 'Could not load or save: ' . $e->getMessage();
            }
        }

        return $report;
    }

    /**
     * Moves one entry type onto the Design field in place: adds the field
     * where the old option fields are, copies their values (drafts included),
     * checks every saved value really reached the database, and only then
     * takes the old option fields out of the layout. Their values stay in the
     * content rows, so putting the fields back restores the old setup.
     *
     * @param string $typeHandle
     * @param string $fieldHandle Handle of the Design field to use
     * @param ?callable(Entry,array<string,mixed>):void $onEntry Called per entry while the values are copied (progress)
     * @param bool $dryRun Report only
     * @return array{type:string,old:string[],status:string,report:?array<string,mixed>,unverified:int[]}
     * @throws InvalidArgumentException if the type or the Design field does not exist
     *
     * @author WMD
     * @since 1.0.0
     */
    public function adopt(string $typeHandle, string $fieldHandle = 'design', bool $dryRun = true, ?callable $onEntry = null): array
    {
        $entries = Craft::$app->getEntries();
        $fields = Craft::$app->getFields();
        $type = $this->_entryType($typeHandle);
        $design = $fields->getFieldByHandle($fieldHandle);

        if (!$design instanceof Design) {
            throw new InvalidArgumentException("No Design field \"$fieldHandle\".");
        }

        $registry = Plugin::getInstance()->getGroups()->getRegistry();
        $layout = $type->getFieldLayout();
        $layoutHandles = array_map(static fn($f) => $f->handle, $layout->getCustomFields());
        $old = array_keys(MigrationPlan::build($layoutHandles, $registry->groupsFor([$typeHandle]), $typeHandle, $registry->fieldMap, false)->fields);
        $result = ['type' => $typeHandle, 'old' => $old, 'status' => 'nothing to move', 'report' => null, 'unverified' => []];

        if ($old === []) {
            return $result;
        }

        // 0. Every stored value must have an option to go to; otherwise nothing changes.
        $preview = $this->migrate($typeHandle, null, $fieldHandle, [], false, true, true);
        $result['report'] = $preview;
        if ($preview['unknown'] !== []) {
            $result['status'] = 'stopped: some stored values have no matching option';

            return $result;
        }

        if ($dryRun) {
            $result['status'] = 'dry run';

            return $result;
        }

        // 1. The Design field goes where the first old option field is.
        if (!in_array($fieldHandle, $layoutHandles, true)) {
            foreach ($layout->getTabs() as $tab) {
                $elements = $tab->getElements();
                foreach ($elements as $i => $element) {
                    if ($element instanceof CustomField && in_array($element->getField()->handle, $old, true)) {
                        array_splice($elements, $i, 0, [new CustomField($design)]);
                        $tab->setElements($elements);
                        break 2;
                    }
                }
            }
            $type->setFieldLayout($layout);
            if (!$entries->saveEntryType($type)) {
                $result['status'] = 'could not add the Design field: ' . implode(' ', $type->getFirstErrors());

                return $result;
            }
            $fields->refreshFields();
            $entries->refreshEntryTypes();
            $type = $this->_entryType($typeHandle);
        }

        // 2. Copy the values.
        $report = $this->migrate($typeHandle, null, $fieldHandle, [], false, false, true, [], $onEntry);
        $result['report'] = $report;

        // 3. Verify in the database, by the layout element the values are stored under.
        $element = null;
        foreach ($type->getFieldLayout()->getCustomFieldElements() as $candidate) {
            if ($candidate->getField()->handle === $fieldHandle) {
                $element = $candidate;
            }
        }
        foreach ($report['savedIds'] as $id) {
            $stored = (new Query())->from(Table::ELEMENTS_SITES)
                ->where(['elementId' => $id])
                ->andWhere(['like', 'content', (string)$element?->uid])
                ->exists();
            if (!$stored) {
                $result['unverified'][] = $id;
            }
        }

        if ($report['failed'] !== [] || $result['unverified'] !== []) {
            $result['status'] = 'old fields kept: some values were not stored';

            return $result;
        }

        // 4. Old option fields out of the layout.
        $layout = $type->getFieldLayout();
        foreach ($layout->getTabs() as $tab) {
            $tab->setElements(array_values(array_filter(
                $tab->getElements(),
                static fn($el) => !($el instanceof CustomField && in_array($el->getField()->handle, $old, true)),
            )));
        }
        $type->setFieldLayout($layout);
        $result['status'] = $entries->saveEntryType($type) ? 'done' : 'values moved, but the old fields could not be removed';

        return $result;
    }

    // Private Methods
    // =========================================================================

    /**
     * Migrates one entry and adds its outcome to the report.
     *
     * @param Entry $entry
     * @param EntryType $from
     * @param EntryType $to
     * @param string $fieldHandle
     * @param string[] $oldHandles
     * @param Registry $registry
     * @param array<string,MigrationPlan> $plans Plan cache, by profile candidates
     * @param array<string,string> $explicitMap
     * @param bool $overwrite
     * @param bool $dryRun
     * @param ?callable $onEntry
     * @param array<string,mixed> $report
     * @return void
     */
    private function _migrateEntry(
        Entry $entry,
        EntryType $from,
        EntryType $to,
        string $fieldHandle,
        array $oldHandles,
        Registry $registry,
        array &$plans,
        array $explicitMap,
        bool $overwrite,
        bool $dryRun,
        ?callable $onEntry,
        array &$report,
    ): void {

        // Same profile matching as the field itself: field:type, then type.
        $candidates = [$to->handle];
        if ($entry->fieldId && ($owner = Craft::$app->getFields()->getFieldById($entry->fieldId))) {
            array_unshift($candidates, "$owner->handle:$to->handle");
        }
        $planKey = implode('|', $candidates);
        // Configured fieldMap pairs apply when their field and group exist; --map pairs are strict.
        $plans[$planKey] ??= MigrationPlan::build(
            $oldHandles,
            $registry->groupsFor($candidates),
            $from->handle,
            $explicitMap + array_filter($registry->fieldMap, static fn($group, $field) => in_array($field, $oldHandles, true) && isset($registry->groupsFor($candidates)[$group]), ARRAY_FILTER_USE_BOTH),
        );
        $plan = $plans[$planKey];
        $report['mapping'][$planKey] = $plan->fields;

        // An entry can carry an older layout than its type (drafts, stale
        // revisions); read only the fields its own layout has.
        $entryLayout = $entry->getFieldLayout();
        $values = [];
        foreach (array_keys($plan->fields) as $handle) {
            if ($entryLayout?->getFieldByHandle($handle) !== null) {
                $values[$handle] = $entry->getFieldValue($handle);
            }
        }

        $current = [];
        if (in_array($fieldHandle, $oldHandles, true) && $entryLayout?->getFieldByHandle($fieldHandle) !== null) {
            $existing = $entry->getFieldValue($fieldHandle);
            $current = $existing instanceof DesignValue ? $existing->toArray() : [];
        }

        $result = $plan->apply($values, $current, $overwrite);

        foreach ($result['moved'] as $group => $key) {
            $report['moved'][$group] = ($report['moved'][$group] ?? 0) + 1;
        }
        foreach ($result['unknown'] as $group => $key) {
            $report['unknown'][$group][$key] = ($report['unknown'][$group][$key] ?? 0) + 1;
        }
        foreach ($result['kept'] as $group => $key) {
            $report['kept'][$group] = ($report['kept'][$group] ?? 0) + 1;
        }
        foreach ($result['empty'] as $group) {
            $report['empty'][$group] = ($report['empty'][$group] ?? 0) + 1;
        }

        $switching = $to->id !== $from->id;

        if ($result['moved'] === [] && !$switching) {
            $onEntry && $onEntry($entry, $result);
            return;
        }

        $report['changed']++;
        $onEntry && $onEntry($entry, $result);

        if ($dryRun) {
            return;
        }

        if ($switching) {
            // Field values are stored per layout element, so a new type's layout
            // cannot find them: load every value first, the save then writes them
            // under the new layout. Without this, plain fields are lost.
            foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                $entry->getFieldValue($field->handle);
            }
            $entry->setTypeId($to->id);
        }

        // Saving a value for a field the entry's layout does not have stores
        // nothing, silently. Refuse and report it instead.
        if ($entry->getFieldLayout()?->getFieldByHandle($fieldHandle) === null) {
            $report['failed'][$entry->id] = "The Design field \"$fieldHandle\" is not in this entry's field layout; nothing saved.";
            return;
        }

        $entry->setFieldValue($fieldHandle, $result['keys']);

        if (!Craft::$app->getElements()->saveElement($entry, false)) {
            $report['failed'][$entry->id] = implode(' ', $entry->getFirstErrors()) ?: 'Unknown error';
            return;
        }

        $report['saved']++;
        $report['savedIds'][] = $entry->id;
    }

    // Private Methods
    // =========================================================================

    /**
     * @param string $handle
     * @return EntryType
     * @throws InvalidArgumentException if no such entry type
     */
    private function _entryType(string $handle): EntryType
    {
        return Craft::$app->getEntries()->getEntryTypeByHandle($handle)
            ?? throw new InvalidArgumentException("No entry type \"$handle\".");
    }
}
