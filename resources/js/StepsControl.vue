<!--
  The `design-field:steps` Control: a slider over an option's named choices.
  Every stop's name sits under the track and the chosen one is marked. The text choice
  (Block default) is a button before the slider; while it is chosen the thumb is dimmed. When the names do not
  fit the width (long names, a half-width column), the stops become ticks and only the chosen
  name shows under the track. The name follows the thumb while it is dragged; the value (the
  choice's key) is emitted when it is let go, so a reactive option asks the server for a
  refresh once per choice, not once per step.
-->
<script setup>
import {computed, nextTick, onBeforeUnmount, onMounted, ref, watch} from 'vue';
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

// Thumb width in px; the same value is --df-thumb in the styles.
const THUMB = 16;
// Room kept between two neighbouring names.
const GAP = 10;

const steps = computed(() => props.control.props?.steps ?? []);
const text = computed(() => props.control.props?.text ?? null);
const chosen = ref(props.value === null ? null : String(props.value));
const indexOf = (key) => steps.value.findIndex((step) => String(step.value) === key);
// Where the thumb sits; while the text choice is chosen it stays where it was (dimmed).
const place = ref(Math.max(0, indexOf(chosen.value)));

watch(() => props.value, (key) => {
    chosen.value = key === null ? null : String(key);
    const at = indexOf(chosen.value);

    if (at !== -1) {
        place.value = at;
    }
});

const unset = computed(() => text.value !== null && chosen.value === String(text.value.value));
const current = computed(() => (unset.value ? null : steps.value[place.value] ?? null));
const locked = computed(() => props.control.mode !== 'editable');

function emitIfChanged(key) {
    chosen.value = key;

    if (key !== String(props.value)) {
        emit('update:value', key, 'discrete');
    }
}

function slide(event) {
    place.value = Number(event.target.value);
}

function commit(at = place.value) {
    place.value = at;
    const key = steps.value[at]?.value;

    if (key !== undefined) {
        emitIfChanged(String(key));
    }
}

// A click on the dimmed thumb where it already sits fires no change: take it as a pick.
function release() {
    if (unset.value) {
        commit();
    }
}

function pickText() {
    if (!locked.value && text.value) {
        emitIfChanged(String(text.value.value));
    }
}

// A stop sits under the thumb's centre at that stop.
const stopStyle = (index) => {
    const last = Math.max(1, steps.value.length - 1);

    return {left: `calc(var(--df-thumb) / 2 + (100% - var(--df-thumb)) * ${index / last})`};
};

// Whether every name fits under its stop: neighbours must not touch, and the end names,
// which line up with the track's ends, must not reach the next one.
const root = ref(null);
const scale = ref(null);
const measure = ref(null);
const compact = ref(false);
let observer = null;

function fit() {
    const width = scale.value?.clientWidth ?? 0;
    const spans = measure.value ? [...measure.value.children] : [];

    if (!width || spans.length < 2) {
        return;
    }

    const names = spans.map((span) => span.offsetWidth);
    const spacing = (width - THUMB) / (names.length - 1);
    const last = names.length - 1;
    let overlap = false;

    for (let i = 0; i < last && !overlap; i++) {
        // The first name starts at the track's left end, the last one ends at its right end.
        const right = i === 0 ? names[0] : spacing * i + THUMB / 2 + names[i] / 2;
        const left = i + 1 === last ? width - names[last] : spacing * (i + 1) + THUMB / 2 - names[i + 1] / 2;
        overlap = right + GAP > left;
    }

    compact.value = overlap;
}

onMounted(() => {
    nextTick(fit);

    if (typeof ResizeObserver !== 'undefined' && scale.value) {
        observer = new ResizeObserver(() => fit());
        observer.observe(scale.value);
    }
});

onBeforeUnmount(() => observer?.disconnect());
watch(steps, () => nextTick(fit));
</script>

<template>
  <!-- The root's class stays static: the field adds its own (form-control) after mount,
       and a class binding here would drop it on the next redraw. -->
  <div ref="root" class="df-steps">
   <div class="df-steps__body" :class="{'df-steps--locked': locked, 'df-steps--invalid': invalid, 'df-steps--compact': compact, 'df-steps--unset': unset}">
    <input
      v-if="editable && chosen !== null"
      type="hidden"
      :name="inputName(control.path)"
      :value="chosen"
    />
    <button
      v-if="text"
      type="button"
      class="df-steps__text"
      :class="{'df-steps__text--on': unset}"
      :aria-pressed="unset ? 'true' : 'false'"
      :disabled="locked"
      @click="pickText"
    >
      {{ text.label }}
    </button>
    <div ref="scale" class="df-steps__scale">
    <input
      class="df-steps__track"
      type="range"
      min="0"
      :max="Math.max(0, steps.length - 1)"
      step="1"
      :value="place"
      :aria-label="label"
      :aria-valuetext="current?.label ?? text?.label"
      :aria-invalid="invalid ? 'true' : undefined"
      :disabled="locked"
      @input="slide"
      @change="commit()"
      @pointerup="release"
    />
    <div class="df-steps__stops">
      <button
        v-for="(step, index) in steps"
        :key="step.value"
        type="button"
        class="df-steps__stop"
        :class="{
          'df-steps__stop--on': !unset && index === place,
          'df-steps__stop--first': index === 0,
          'df-steps__stop--last': index === steps.length - 1,
        }"
        :style="stopStyle(index)"
        :title="compact ? step.label : undefined"
        :aria-label="step.label"
        :disabled="locked"
        tabindex="-1"
        @click="commit(index)"
      >
        <span class="df-steps__name">{{ step.label }}</span>
      </button>
    </div>
    <div v-if="compact && current" class="df-steps__readout" aria-hidden="true">{{ current.label }}</div>
    <div ref="measure" class="df-steps__measure" aria-hidden="true">
      <span v-for="step in steps" :key="step.value">{{ step.label }}</span>
    </div>
    </div>
   </div>
  </div>
</template>

<style scoped>
.df-steps {
  --df-thumb: 16px;
  position: relative;
  flex: 1 1 auto;
  width: 100%;
  max-width: 28rem;
  padding-bottom: 0.25rem;
}

/* Block default on its own row above the scale, so the scale keeps the full width for its names. */
.df-steps__body {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 0.5rem;
}

.df-steps__scale {
  position: relative;
}

.df-steps__text {
  align-self: flex-start;
}

/* Block default: a button like the grid's text choice. */
.df-steps__text {
  box-sizing: border-box;
  height: var(--c-size-control-md, 2.25rem);
  margin: 0;
  padding: 0 0.75rem;
  border: 1px solid var(--c-form-control-border-color, #cdd5df);
  border-radius: var(--c-form-control-radius, 4px);
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: var(--c-text-sm, 0.875rem);
  white-space: nowrap;
  cursor: pointer;
}

.df-steps__text:hover:not(.df-steps__text--on):not(:disabled) {
  background: var(--c-color-neutral-fill-quiet, rgb(0 0 0 / 0.05));
}

.df-steps__text--on {
  border-color: var(--c-color-neutral-fill-loud, #5f6c7b);
  background: var(--c-color-neutral-fill-loud, #5f6c7b);
  color: var(--c-color-neutral-on-loud, #fff);
  cursor: default;
}

.df-steps__text:focus-visible {
  outline: 2px solid var(--c-color-accent-border-loud, #2563eb);
  outline-offset: 2px;
}

/* The block decides: the scale is there to pick from, but nothing on it is chosen. */
.df-steps--unset .df-steps__track {
  opacity: 0.45;
  filter: grayscale(1);
}

.df-steps__track {
  display: block;
  width: 100%;
  margin: 0.25rem 0 0;
  accent-color: var(--c-color-accent-fill-loud, #2563eb);
  cursor: pointer;
}

.df-steps--locked .df-steps__track {
  cursor: default;
}

.df-steps__stops {
  position: relative;
  height: 1.5rem;
  margin-top: 0.25rem;
}

.df-steps__stop {
  position: absolute;
  top: 0;
  transform: translateX(-50%);
  padding: 0 0.25rem;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  font-size: 0.8125rem;
  line-height: 1.5rem;
  white-space: nowrap;
  opacity: 0.6;
  cursor: pointer;
}

/* The end names stay inside the track's width instead of hanging over its ends. */
.df-steps__stop--first {
  transform: translateX(calc(var(--df-thumb) / -2));
}

.df-steps__stop--last {
  transform: translateX(calc(-100% + var(--df-thumb) / 2));
}

.df-steps__stop--on {
  opacity: 1;
  font-weight: 600;
}

.df-steps__stop:disabled {
  cursor: default;
}

/* Names that do not fit: each stop becomes a tick, the chosen name is read out below. */
.df-steps--compact .df-steps__stops {
  height: 0.75rem;
}

.df-steps--compact .df-steps__stop,
.df-steps--compact .df-steps__stop--first,
.df-steps--compact .df-steps__stop--last {
  width: 0.875rem;
  height: 0.75rem;
  padding: 0;
  transform: translateX(-50%);
}

.df-steps--compact .df-steps__stop::before {
  content: '';
  position: absolute;
  left: 50%;
  top: 0;
  width: 2px;
  height: 0.5rem;
  margin-left: -1px;
  border-radius: 1px;
  background: currentColor;
}

.df-steps--compact .df-steps__name {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
}

.df-steps__readout {
  margin-top: 0.125rem;
  font-size: 0.8125rem;
  font-weight: 600;
}

.df-steps__measure {
  position: absolute;
  visibility: hidden;
  height: 0;
  overflow: hidden;
  white-space: nowrap;
  font-size: 0.8125rem;
  font-weight: 600;
  pointer-events: none;
}

.df-steps__measure span {
  display: inline-block;
  padding: 0 0.25rem;
}
</style>
