<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

declare(strict_types=1);

namespace wmd\designfield\entryfields;

/**
 * Stops render loops: a block whose row shows its own page's page builder
 * would otherwise render itself again, without end.
 *
 * @author WMD
 * @since 1.1.0
 */
final class RenderStack
{
    /** @var array<string,true> */
    private array $_keys = [];

    public function __construct(
        private readonly int $maxDepth = 3,
    ) {
    }

    /**
     * @param string $key
     * @return bool false when the key is already being rendered or the depth is reached
     *
     * @author WMD
     * @since 1.1.0
     */
    public function enter(string $key): bool
    {
        if (isset($this->_keys[$key]) || count($this->_keys) >= $this->maxDepth) {
            return false;
        }

        $this->_keys[$key] = true;

        return true;
    }

    /**
     * @param string $key
     *
     * @author WMD
     * @since 1.1.0
     */
    public function leave(string $key): void
    {
        unset($this->_keys[$key]);
    }
}
