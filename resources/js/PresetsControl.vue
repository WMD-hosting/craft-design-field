<!--
  The `design-field:presets` Control: the Design panel with a row of presets above it.
  The options are core's own Content Block, drawn by core's `craft:content-block`
  component; this one only adds the row. It holds the whole option map, so a preset sets
  its options in one change: the map with the preset's values in it. The field then asks
  the server for one refresh, and options that wait for another (showIf) follow.

  A preset shows as pressed while every option it sets has its value, whether it was
  picked or the editor chose the same values one by one.
-->
<script setup>
import {computed, getCurrentInstance, toRaw} from 'vue';
import {t} from '@craftcms/cp';

const props = defineProps({
    control: {type: Object, required: true},
    value: {default: null},
    values: {type: Object, required: true},
    errors: {type: Array, default: () => []},
    touchedPaths: {type: Set, default: () => new Set()},
    editable: {type: Boolean, default: true},
    // Taken so they do not land on the root as attributes; the block has no use for them.
    label: {type: String, default: undefined},
    invalid: {type: Boolean, default: false},
    required: {type: Boolean, default: false},
    uiScope: {type: Array, default: undefined},
    uiRefreshable: {type: Boolean, default: false},
});

const emit = defineEmits(['update:value', 'change']);

// Core's Content Block, as the field would have drawn it without presets.
const contentBlock = getCurrentInstance().appContext.components['craft:content-block'];

const presets = computed(() => props.control.props?.presets ?? []);
const locked = computed(() => !props.editable || props.control.mode !== 'editable');

// A switch holds true/false; every other option its key, which may arrive as a number.
const same = (a, b) => (typeof a === 'boolean' || typeof b === 'boolean' ? a === b : String(a ?? '') === String(b ?? ''));

const applied = computed(() => presets.value.map((preset) =>
    Object.entries(preset.values).every(([handle, value]) => same(props.value?.[handle], value)),
));

function apply(preset) {
    if (locked.value) {
        return;
    }

    // A copy: the panel's own state is not ours to change in place.
    const map = structuredClone(toRaw(props.value ?? {}));

    emit('update:value', {...map, ...preset.values}, 'discrete');
}
</script>

<template>
  <!-- The root's class stays static: the field adds its own (form-control) after mount,
       and a class binding here would drop it on the next redraw. -->
  <div class="df-presets-block">
    <div class="df-presets" role="group" :aria-label="t('Presets')">
      <span class="df-presets__label" aria-hidden="true">{{ t('Presets') }}</span>
      <button
        v-for="(preset, index) in presets"
        :key="preset.label"
        type="button"
        class="df-presets__preset"
        :class="{'df-presets__preset--on': applied[index]}"
        :aria-pressed="applied[index] ? 'true' : 'false'"
        :title="preset.summary"
        :disabled="locked"
        @click="apply(preset)"
      >
        {{ preset.label }}
      </button>
    </div>
    <component
      :is="contentBlock"
      :control="control"
      :value="value"
      :values="values"
      :errors="errors"
      :touched-paths="touchedPaths"
      :editable="editable"
      @update:value="(...args) => emit('update:value', ...args)"
      @change="(change) => emit('change', change)"
    />
  </div>
</template>

<style>
.df-presets {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--c-spacing-xs, 4px) var(--c-spacing-sm, 8px);
  margin-bottom: var(--c-spacing-md, 12px);
  padding-bottom: var(--c-spacing-md, 12px);
  border-bottom: 1px solid var(--c-color-neutral-border-quiet, #e5e7eb);
}

.df-presets__label {
  margin-inline-end: var(--c-spacing-xs, 4px);
  font-size: var(--c-text-sm, 0.875rem);
  font-weight: 600;
}

/* Like the slider's Block default button. */
.df-presets__preset {
  box-sizing: border-box;
  height: var(--c-size-control-sm, 1.875rem);
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

.df-presets__preset:hover:not(.df-presets__preset--on):not(:disabled) {
  background: var(--c-color-neutral-fill-quiet, rgb(0 0 0 / 0.05));
}

.df-presets__preset--on {
  border-color: var(--c-color-neutral-fill-loud, #5f6c7b);
  background: var(--c-color-neutral-fill-loud, #5f6c7b);
  color: var(--c-color-neutral-on-loud, #fff);
}

.df-presets__preset:focus-visible {
  outline: 2px solid var(--c-color-accent-border-loud, #2563eb);
  outline-offset: 2px;
}

.df-presets__preset:disabled {
  cursor: default;
  opacity: 0.6;
}
</style>
