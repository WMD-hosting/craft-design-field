<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

declare(strict_types=1);

namespace wmd\designfield\entryfields;

/**
 * The value of an Entry Fields field: an optional preset name plus the block's own rows.
 *
 * Presets are stored by reference; their rows come from config at render time,
 * so editing a preset changes every block that uses it.
 *
 * @author WMD
 * @since 1.1.0
 */
final class EntryFieldsValue
{
    /**
     * @param string|null $preset
     * @param list<Row> $rows
     */
    public function __construct(
        public readonly ?string $preset = null,
        public readonly array $rows = [],
    ) {
    }

    /**
     * Normalizes a stored JSON string, a posted array, or anything else into a value.
     *
     * @param mixed $value
     * @return self
     *
     * @author WMD
     * @since 1.1.0
     */
    public static function fromMixed(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return new self();
        }

        $preset = is_string($value['preset'] ?? null) && trim($value['preset']) !== '' ? trim($value['preset']) : null;
        $rows = [];

        foreach (is_array($value['rows'] ?? null) ? $value['rows'] : [] as $data) {
            $row = is_array($data) ? Row::fromArray($data) : null;
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return new self($preset, $rows);
    }

    /**
     * Rows for one slot: the preset's rows first, then the block's own, in order.
     *
     * @param string $slot
     * @param array<string,array<mixed>> $presets Config rows; untrusted shape
     * @return list<Row>
     *
     * @author WMD
     * @since 1.1.0
     */
    public function rowsFor(string $slot, array $presets): array
    {
        $all = [];

        foreach ($this->preset !== null ? ($presets[$this->preset] ?? []) : [] as $data) {
            $row = is_array($data) ? Row::fromArray($data) : null;
            if ($row !== null) {
                $all[] = $row;
            }
        }

        return array_values(array_filter([...$all, ...$this->rows], static fn(Row $row) => $row->slot === $slot));
    }

    /**
     * @return bool
     *
     * @author WMD
     * @since 1.1.0
     */
    public function isEmpty(): bool
    {
        return $this->preset === null && $this->rows === [];
    }

    /**
     * @return array{preset: string|null, rows: list<array<string,mixed>>}
     *
     * @author WMD
     * @since 1.1.0
     */
    public function toArray(): array
    {
        return ['preset' => $this->preset, 'rows' => array_map(static fn(Row $row) => $row->toArray(), $this->rows)];
    }
}
