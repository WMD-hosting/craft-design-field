<!--
  The `design-field:preview` Control (the settings page's Preview tab): the Design panel
  as editors get it, and beside it the block itself in a frame that loads again after each
  change. The frame is as wide as a real desktop, tablet or phone and scaled down to fit,
  so the block's breakpoints are the device's. On a narrow screen the frame goes under
  the panel. Nothing is saved: the page it loads sets the choices for that render only.
-->
<script setup>
import {computed, onBeforeUnmount, onMounted, ref, watch} from 'vue';
import {t} from '@craftcms/cp';
import PresetsControl from './PresetsControl.vue';

const props = defineProps({
    control: {type: Object, required: true},
    value: {default: null},
    values: {type: Object, required: true},
    errors: {type: Array, default: () => []},
    touchedPaths: {type: Set, default: () => new Set()},
    editable: {type: Boolean, default: true},
    // Taken so they do not land on the root as attributes.
    label: {type: String, default: undefined},
    invalid: {type: Boolean, default: false},
    required: {type: Boolean, default: false},
    uiScope: {type: Array, default: undefined},
    uiRefreshable: {type: Boolean, default: false},
});

const emit = defineEmits(['update:value', 'change']);

const sizes = [
    {key: 'desktop', label: 'Desktop', width: 1280, height: 900},
    {key: 'tablet', label: 'Tablet', width: 768, height: 1024},
    {key: 'mobile', label: 'Mobile', width: 375, height: 812},
];

const frame = computed(() => props.control.props?.frame ?? null);
const size = ref(sizes[0]);
const viewport = ref(null);
const available = ref(0);
const src = ref('');

// Never scaled up: a wide screen shows a phone at its real size.
const scale = computed(() => (available.value > 0 ? Math.min(1, available.value / size.value.width) : 1));
// The panel holds a switch as true/false; the block's page takes option keys, as stored.
const choices = computed(() => {
    const keys = {...(props.value ?? {})};

    for (const [handle, [off, on]] of Object.entries(frame.value?.toggles ?? {})) {
        if (handle in keys && (typeof keys[handle] === 'boolean' || keys[handle] === '')) {
            keys[handle] = keys[handle] === true ? on : off;
        }
    }

    return JSON.stringify(keys);
});

function load() {
    if (frame.value) {
        src.value = frame.value.url.replace('{design}', encodeURIComponent(choices.value));
    }
}

// A slider dragged across its stops is one load, not five.
let timer = null;
watch(choices, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});

let observer = null;
onMounted(() => {
    load();

    if (viewport.value) {
        observer = new ResizeObserver(([entry]) => {
            available.value = entry.contentRect.width;
        });
        observer.observe(viewport.value);
    }
});

onBeforeUnmount(() => {
    clearTimeout(timer);
    observer?.disconnect();
});
</script>

<template>
  <!-- The root's class stays static: the field adds its own after mount. -->
  <div class="df-preview">
    <div class="df-preview__grid" :data-framed="frame ? 'true' : 'false'">
      <div class="df-preview__panel">
        <PresetsControl
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
      <div v-if="frame" class="df-preview__block">
        <div class="df-preview__bar">
          <div class="df-preview__sizes" role="group" :aria-label="t('Preview width')">
            <button
              v-for="option in sizes"
              :key="option.key"
              type="button"
              class="df-preview__size"
              :class="{'df-preview__size--on': option.key === size.key}"
              :aria-pressed="option.key === size.key ? 'true' : 'false'"
              :title="`${option.width} × ${option.height}`"
              @click="size = option"
            >
              {{ t(option.label) }}
            </button>
          </div>
          <span class="df-preview__scale">{{ size.width }} px{{ scale < 1 ? ` · ${Math.round(scale * 100)}%` : '' }}</span>
        </div>
        <div ref="viewport" class="df-preview__viewport" :style="{height: `${Math.round(size.height * scale)}px`}">
          <iframe
            class="df-preview__frame"
            :title="t('Block preview')"
            :src="src"
            :style="{width: `${size.width}px`, height: `${size.height}px`, transform: `scale(${scale})`}"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<style>
.df-preview {
  container-type: inline-size;
}

.df-preview__grid {
  display: grid;
  gap: var(--c-spacing-lg, 24px);
  align-items: start;
}

/* Side by side from a laptop's content width, so a change and the block are seen together;
   under that the block goes below. A wide screen gives the block the larger share. */
@container (min-width: 46rem) {
  .df-preview__grid[data-framed='true'] {
    grid-template-columns: minmax(20rem, 1fr) minmax(0, 1fr);
  }

  .df-preview__block {
    position: sticky;
    top: var(--c-spacing-lg, 24px);
  }
}

@container (min-width: 75rem) {
  .df-preview__grid[data-framed='true'] {
    grid-template-columns: minmax(22rem, 2fr) minmax(0, 3fr);
  }
}

.df-preview__panel {
  min-width: 0;
}

.df-preview__bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--c-spacing-sm, 8px) var(--c-spacing-md, 12px);
  margin-bottom: var(--c-spacing-sm, 8px);
}

.df-preview__sizes {
  display: inline-flex;
}

.df-preview__size {
  box-sizing: border-box;
  height: var(--c-size-control-sm, 1.875rem);
  margin: 0 -1px 0 0;
  padding: 0 0.75rem;
  border: 1px solid var(--c-form-control-border-color, #cdd5df);
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: var(--c-text-sm, 0.875rem);
  cursor: pointer;
}

.df-preview__size:first-child {
  border-radius: var(--c-form-control-radius, 4px) 0 0 var(--c-form-control-radius, 4px);
}

.df-preview__size:last-child {
  border-radius: 0 var(--c-form-control-radius, 4px) var(--c-form-control-radius, 4px) 0;
}

.df-preview__size--on {
  border-color: var(--c-color-neutral-fill-loud, #5f6c7b);
  background: var(--c-color-neutral-fill-loud, #5f6c7b);
  color: var(--c-color-neutral-on-loud, #fff);
}

.df-preview__size:focus-visible {
  position: relative;
  outline: 2px solid var(--c-color-accent-border-loud, #2563eb);
  outline-offset: 2px;
}

.df-preview__scale {
  font-size: var(--c-text-sm, 0.875rem);
  opacity: 0.7;
}

.df-preview__viewport {
  overflow: hidden;
  border: 1px solid var(--c-form-control-border-color, #cdd5df);
  border-radius: var(--c-form-control-radius, 4px);
  background: #fff;
}

.df-preview__frame {
  display: block;
  border: 0;
  transform-origin: 0 0;
}
</style>
