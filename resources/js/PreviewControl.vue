<!--
  The `design-field:preview` Control (the settings page's Preview tab): the Design panel
  as editors get it, and beside it the block itself in a frame that loads again after each
  change. The frame is as wide as a real desktop, tablet or phone and scaled down to fit,
  so the block's breakpoints are the device's. On a narrow screen the frame goes under
  the panel. Nothing is saved: the page it loads sets the choices for that render only.

  Make tile pictures (when the server sends `tiles`): each layout of the block loads in a
  hidden frame through the same page, is drawn to a 480 px JPEG in the browser
  (modern-screenshot, loaded on the first click) and posted to the server, which writes it
  next to the other tiles. Missing pictures only; with none missing, all again after asking.
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

const tiles = computed(() => props.control.props?.tiles ?? null);
const busy = ref(false);
const status = ref('');

// Laravel's CSRF cookie, sent back as the header it checks.
function csrf() {
    const cookie = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

async function draw(box, domToJpeg) {
    const doc = box.contentDocument;
    const win = box.contentWindow;
    const pending = [...doc.images].filter((img) => !img.complete).map((img) => new Promise((done) => {
        img.onload = img.onerror = done;
    }));

    await Promise.race([Promise.all(pending), new Promise((done) => setTimeout(done, 4000))]);

    const paint = (el) => {
        const colour = win.getComputedStyle(el).backgroundColor;

        return colour && colour !== 'transparent' && colour !== 'rgba(0, 0, 0, 0)' ? colour : null;
    };
    const skip = tiles.value.skip;

    return domToJpeg(doc.body, {
        width: 1280,
        // A popup or drawer is a fixed overlay over an empty page: the window's height then.
        height: Math.min(Math.max(Math.ceil(doc.body.getBoundingClientRect().height), 0) || 900, 900),
        scale: 480 / 1280,
        quality: 0.85,
        // A transparent body shows the page's own background, not black.
        backgroundColor: paint(doc.body) || paint(doc.documentElement) || '#ffffff',
        // Dev toolbars and badges the site names in `tileSkip` are not part of the block.
        filter: (node) => !(skip && node.nodeType === 1 && node.matches(skip)),
        // The copy becomes an SVG image (XML): attribute names XML does not allow, such as
        // Alpine's @click and :class, would make it fail and the picture come out blank.
        onCloneNode: (root) => {
            const strip = (el) => {
                [...el.attributes].forEach((attribute) => {
                    if (!/^[A-Za-z_][\w.-]*$/.test(attribute.name)) {
                        el.removeAttribute(attribute.name);
                    }
                });
                [...el.children].forEach(strip);
            };

            if (root.nodeType === 1) {
                strip(root);
            }
        },
    });
}

async function save(key, image) {
    const response = await fetch(tiles.value.saveUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest'},
        body: JSON.stringify({block: tiles.value.block, key, image}),
    });

    if (!response.ok) {
        throw new Error(`${response.status}`);
    }
}

async function makeTiles() {
    let list = tiles.value.layouts.filter((layout) => !layout.picture);

    if (!list.length) {
        if (!window.confirm(t('Every layout has a picture. Make them all again from the preview?'))) {
            return;
        }

        list = [...tiles.value.layouts];
    }

    busy.value = true;
    status.value = '';

    const box = document.createElement('iframe');
    box.setAttribute('aria-hidden', 'true');
    box.tabIndex = -1;
    box.style.cssText = 'position:fixed;left:-10000px;top:0;width:1280px;height:900px;border:0';
    document.body.append(box);

    let made = 0;
    let failed = 0;

    try {
        const {domToJpeg} = await import('modern-screenshot');

        for (const [index, layout] of list.entries()) {
            status.value = `${index + 1} / ${list.length}: ${layout.label}`;

            try {
                await new Promise((done, fail) => {
                    box.onload = done;
                    box.onerror = fail;
                    box.src = frame.value.url.replace('{design}', encodeURIComponent(JSON.stringify({variant: layout.key})));
                });
                // Fonts, sliders and entrances settle.
                await new Promise((done) => setTimeout(done, 1500));
                await save(layout.key, await draw(box, domToJpeg));
                made++;
            } catch {
                failed++;
            }
        }
    } finally {
        box.remove();
        busy.value = false;
    }

    status.value = `${made} ${t('made')}${failed ? `, ${failed} ${t('failed')}` : ''}`;

    // The panel shows a layout's picture from the server: load the page again to see the new ones.
    if (made) {
        setTimeout(() => window.location.reload(), 1200);
    }
}

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
          <button v-if="tiles" type="button" class="df-preview__tiles" :disabled="busy" @click="makeTiles">
            {{ t('Make tile pictures') }}
          </button>
          <span v-if="tiles" class="df-preview__status" role="status">{{ status }}</span>
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

.df-preview__tiles {
  box-sizing: border-box;
  height: var(--c-size-control-sm, 1.875rem);
  margin: 0 0 0 auto;
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

.df-preview__tiles:hover:not(:disabled) {
  background: var(--c-color-neutral-fill-quiet, rgb(0 0 0 / 0.05));
}

.df-preview__tiles:focus-visible {
  outline: 2px solid var(--c-color-accent-border-loud, #2563eb);
  outline-offset: 2px;
}

.df-preview__tiles:disabled {
  cursor: default;
  opacity: 0.6;
}

.df-preview__status {
  font-size: var(--c-text-sm, 0.875rem);
}

.df-preview__status:empty {
  display: none;
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
