<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\jobs;

use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;
use InvalidArgumentException;
use RuntimeException;
use wmd\designfield\Plugin;

/**
 * Moves one block type onto the Design field (Converter::adopt), from the "Tidy up" tab.
 * One move per block type at a time (a lock); progress follows the entries copied.
 *
 * @author WMD
 * @since 1.0.0
 */
class AdoptJob extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var string Entry type handle to move
     */
    public string $type = '';

    /**
     * @var string Handle of the Design field the options move into
     */
    public string $field = 'design';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws RuntimeException when the move stops, or another move of this type is running
     * @throws InvalidArgumentException for an unknown type or Design field
     */
    public function execute($queue): void
    {
        $mutex = Craft::$app->getMutex();
        $lock = "design-field:adopt:$this->type";

        if (!$mutex->acquire($lock)) {
            throw new RuntimeException("$this->type is already being moved.");
        }

        try {
            $total = max(1, (int)Entry::find()->type($this->type)->status(null)->site('*')->unique()->count());
            $done = 0;
            $result = Plugin::getInstance()->getConverter()->adopt($this->type, $this->field, false, function() use ($queue, $total, &$done): void {
                $this->setProgress($queue, min(1, ++$done / $total), Craft::t('design-field', '{n} of {total} blocks', ['n' => $done, 'total' => $total]));
            });
        } finally {
            $mutex->release($lock);
        }

        Craft::info("Design Field adopt $this->type: {$result['status']}", __METHOD__);

        if ($result['status'] !== 'done' && $result['status'] !== 'nothing to move') {
            throw new RuntimeException("$this->type: {$result['status']}");
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('design-field', 'Moving {type} onto the Design field', ['type' => $this->type]);
    }
}
