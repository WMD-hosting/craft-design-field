<!--
  The `design-field:grid` Control: a position option as a grid of cells (a nine-point grid,
  the corners, a row or column of marks, a line of icons). The text choice (Block default)
  sits before the cells in one line, or above them in a grid. Cells that only draw something
  have their name as the tooltip, and the chosen one's name is read out beside them. It is
  one radio group: Tab reaches the chosen cell, the arrow keys move through rows and columns
  and pick as they go, Home and End jump to the ends.
-->
<script setup>
import {computed, nextTick, ref, watch} from 'vue';
import {inputName} from '@craftcms/cp';

const props = defineProps({
    control: {type: Object, required: true},
    value: {default: null},
    editable: {type: Boolean, default: true},
    label: {type: String, default: undefined},
    invalid: {type: Boolean, default: false},
    required: {type: Boolean, default: false},
});

const emit = defineEmits(['update:value']);

const text = computed(() => props.control.props?.text ?? null);
const cells = computed(() => props.control.props?.cells ?? []);
// No columns: one line of cells that wraps when the width runs out (colour chips, icons).
const line = computed(() => !(props.control.props?.columns > 0));
const cols = computed(() => (line.value ? Math.max(1, cells.value.length) : props.control.props.columns));
const rows = computed(() => Math.ceil(cells.value.length / cols.value));
const drawn = computed(() => cells.value.some((cell) => !cell.gap && cell.html));
const locked = computed(() => props.control.mode !== 'editable');

const chosen = ref(props.value === null ? null : String(props.value));
watch(() => props.value, (key) => {
    chosen.value = key === null ? null : String(key);
});

// Every choice that can be picked, with its place: the text choice is row -1.
const items = computed(() => {
    const list = text.value ? [{value: String(text.value.value), row: -1, col: 0}] : [];

    cells.value.forEach((cell, index) => {
        if (!cell.gap) {
            list.push({value: String(cell.value), row: Math.floor(index / cols.value), col: index % cols.value});
        }
    });

    return list;
});

const chosenCell = computed(() => cells.value.find((cell) => !cell.gap && String(cell.value) === chosen.value) ?? null);
const readout = computed(() => (chosenCell.value?.html ? chosenCell.value.label : null));
const tabStop = computed(() => (items.value.some((item) => item.value === chosen.value) ? chosen.value : items.value[0]?.value));

const buttons = ref({});

function pick(key, focus = false) {
    if (locked.value || key === undefined) {
        return;
    }

    chosen.value = key;

    if (focus) {
        nextTick(() => buttons.value[key]?.focus());
    }

    if (String(props.value) !== key) {
        emit('update:value', key, 'discrete');
    }
}

// The nearest pickable item in a row, by column.
function inRow(row, col) {
    const candidates = items.value.filter((item) => item.row === row);

    return candidates.sort((a, b) => Math.abs(a.col - col) - Math.abs(b.col - col))[0];
}

function move(event, key) {
    const list = items.value;
    const at = list.findIndex((item) => item.value === key);
    const here = list[at];
    let next;

    switch (event.key) {
        case 'ArrowRight':
            next = list[(at + 1) % list.length];
            break;
        case 'ArrowLeft':
            next = list[(at - 1 + list.length) % list.length];
            break;
        case 'ArrowDown':
            if (rows.value <= 1) {
                next = list[(at + 1) % list.length];
            } else {
                for (let row = here.row + 1; row < rows.value && !next; row++) {
                    next = list.find((item) => item.row === row && item.col === Math.max(0, here.col)) ?? (here.row === -1 ? inRow(row, 0) : undefined);
                }
            }
            break;
        case 'ArrowUp':
            if (rows.value <= 1) {
                next = list[(at - 1 + list.length) % list.length];
            } else {
                for (let row = here.row - 1; row >= -1 && !next; row--) {
                    next = row === -1 ? list.find((item) => item.row === -1) : list.find((item) => item.row === row && item.col === here.col);
                }
            }
            break;
        case 'Home':
            next = list[0];
            break;
        case 'End':
            next = list[list.length - 1];
            break;
        default:
            return;
    }

    event.preventDefault();

    if (next) {
        pick(next.value, true);
    }
}

const setRef = (key) => (el) => {
    if (el) {
        buttons.value[key] = el;
    } else {
        delete buttons.value[key];
    }
};
</script>

<template>
  <!-- The root's class stays static: the field adds its own (form-control) after mount,
       and a class binding here would drop it on the next redraw. -->
  <div class="df-grid">
    <div
      class="df-grid__body"
      :class="{'df-grid--rows': rows > 1, 'df-grid--locked': locked, 'df-grid--invalid': invalid}"
      role="radiogroup"
      :aria-label="label"
      :aria-invalid="invalid ? 'true' : undefined"
      :aria-disabled="locked ? 'true' : undefined"
    >
      <input v-if="editable && chosen !== null" type="hidden" :name="inputName(control.path)" :value="chosen" />
      <div class="df-grid__stack">
        <button
          v-if="text"
          :ref="setRef(String(text.value))"
          type="button"
          role="radio"
          class="df-grid__cell df-grid__cell--text"
          :class="{'df-grid__cell--on': chosen === String(text.value)}"
          :aria-checked="chosen === String(text.value) ? 'true' : 'false'"
          :tabindex="tabStop === String(text.value) ? 0 : -1"
          :disabled="locked"
          @click="pick(String(text.value))"
          @keydown="move($event, String(text.value))"
        >
          {{ text.label }}
        </button>
        <div
          class="df-grid__cells"
          :class="{'df-grid__cells--named': !drawn, 'df-grid__cells--line': line}"
          :style="{'--df-cols': cols}"
        >
          <template v-for="cell in cells" :key="cell.value">
            <span v-if="cell.gap" class="df-grid__cell df-grid__cell--gap" aria-hidden="true"></span>
            <button
              v-else
              :ref="setRef(String(cell.value))"
              type="button"
              role="radio"
              class="df-grid__cell"
              :class="{'df-grid__cell--on': chosen === String(cell.value), 'df-grid__cell--named': !cell.html}"
              :aria-checked="chosen === String(cell.value) ? 'true' : 'false'"
              :aria-label="cell.html ? cell.label : undefined"
              :title="cell.html ? cell.label : undefined"
              :tabindex="tabStop === String(cell.value) ? 0 : -1"
              :disabled="locked"
              @click="pick(String(cell.value))"
              @keydown="move($event, String(cell.value))"
            >
              <span v-if="cell.html" class="df-grid__drawn" v-html="cell.html"></span>
              <template v-else>{{ cell.label }}</template>
            </button>
          </template>
        </div>
      </div>
      <span v-if="readout" class="df-grid__readout" aria-hidden="true">{{ readout }}</span>
    </div>
  </div>
</template>

<style scoped>
.df-grid {
  flex: 1 1 auto;
  width: 100%;
  --df-cell: var(--c-size-control-md, 2.25rem);
  --df-gap: 4px;
}

.df-grid__body {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.375rem 0.75rem;
}

.df-grid__stack {
  display: flex;
  align-items: center;
  gap: var(--df-gap);
}

.df-grid--rows .df-grid__stack {
  flex-direction: column;
  align-items: stretch;
}

.df-grid__cells {
  display: grid;
  grid-template-columns: repeat(var(--df-cols), var(--df-cell));
  gap: var(--df-gap);
}

.df-grid__cells--named {
  grid-template-columns: repeat(var(--df-cols), auto);
}

.df-grid__cells--line {
  display: flex;
  flex-wrap: wrap;
  min-width: 0;
}

.df-grid__stack:has(> .df-grid__cells--line) {
  flex-wrap: wrap;
  min-width: 0;
}

.df-grid__cell {
  box-sizing: border-box;
  display: grid;
  place-items: center;
  min-width: var(--df-cell);
  height: var(--df-cell);
  margin: 0;
  padding: 0;
  border: 1px solid var(--c-form-control-border-color, #cdd5df);
  border-radius: var(--c-form-control-radius, 4px);
  /* As Craft's own button radios: no fill (the form-control fill is an input's, grey in dark). */
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: var(--c-text-sm, 0.875rem);
  line-height: 1;
  cursor: pointer;
}

.df-grid__cell--text,
.df-grid__cell--named {
  padding-inline: 0.75rem;
  white-space: nowrap;
}

.df-grid__cell:hover:not(.df-grid__cell--on):not(:disabled) {
  background: var(--c-color-neutral-fill-quiet, rgb(0 0 0 / 0.05));
}

.df-grid__cell--on {
  border-color: var(--c-color-neutral-fill-loud, #5f6c7b);
  background: var(--c-color-neutral-fill-loud, #5f6c7b);
  color: var(--c-color-neutral-on-loud, #fff);
  cursor: default;
}

.df-grid__cell:focus-visible {
  outline: 2px solid var(--c-color-accent-border-loud, #2563eb);
  outline-offset: 2px;
}

.df-grid__cell:disabled {
  cursor: default;
  opacity: 0.6;
}

.df-grid__cell--on:disabled {
  opacity: 1;
}

.df-grid__cell--gap {
  visibility: hidden;
}

.df-grid__drawn {
  display: grid;
  place-items: center;
  pointer-events: none;
}

.df-grid__readout {
  font-size: var(--c-text-sm, 0.875rem);
  font-weight: 600;
  white-space: nowrap;
}

.df-grid--invalid .df-grid__cell {
  border-color: var(--c-color-danger-border-loud, #dc2626);
}
</style>
