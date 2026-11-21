<!--
  The `design-field:rendered` Control: each choice a small card drawn with the site's own
  stylesheet and brand (a corner radius or a shadow shows this brand's real value). A card
  lives in a shadow root that adopts the stylesheet, inside a root carrying the brand
  attributes, so the brand's tokens apply while the site's reset stays out of the panel.
  Choices without a preview (Block default) and every card while the stylesheet loads, or
  if it fails, show their names. One radio group: arrows and Home/End move and pick.
-->
<script setup>
import {computed, nextTick, onMounted, ref, watch} from 'vue';
import {inputName} from '@craftcms/cp';
import {siteSheet, tileFrame} from './siteSheet.js';

const props = defineProps({
    control: {type: Object, required: true},
    value: {default: null},
    editable: {type: Boolean, default: true},
    label: {type: String, default: undefined},
    invalid: {type: Boolean, default: false},
    required: {type: Boolean, default: false},
});

const emit = defineEmits(['update:value']);

const choices = computed(() => props.control.props?.choices ?? []);
const locked = computed(() => props.control.mode !== 'editable');
const chosen = ref(props.value === null ? null : String(props.value));
watch(() => props.value, (key) => {
    chosen.value = key === null ? null : String(key);
});
const tabStop = computed(() => (choices.value.some((c) => String(c.value) === chosen.value) ? chosen.value : String(choices.value[0]?.value ?? '')));

const buttons = {};
const hosts = {};
const drawn = ref(false);

function pick(key, focus = false) {
    if (locked.value) {
        return;
    }

    chosen.value = key;

    if (focus) {
        nextTick(() => buttons[key]?.focus());
    }

    if (String(props.value) !== key) {
        emit('update:value', key, 'discrete');
    }
}

function move(event, key) {
    const keys = choices.value.map((c) => String(c.value));
    const at = keys.indexOf(key);
    const next = {
        ArrowRight: keys[(at + 1) % keys.length],
        ArrowDown: keys[(at + 1) % keys.length],
        ArrowLeft: keys[(at - 1 + keys.length) % keys.length],
        ArrowUp: keys[(at - 1 + keys.length) % keys.length],
        Home: keys[0],
        End: keys[keys.length - 1],
    }[event.key];

    if (next !== undefined) {
        event.preventDefault();
        pick(next, true);
    }
}

async function draw() {
    const url = props.control.props?.stylesheet;

    if (!url) {
        return;
    }

    let site;

    try {
        site = await siteSheet(url);
    } catch {
        return; // No stylesheet: the cards keep their names.
    }

    const attributes = Object.entries(props.control.props?.attributes ?? {});

    for (const choice of choices.value) {
        const host = hosts[String(choice.value)];

        if (!host || !choice.preview) {
            continue;
        }

        const shadow = host.shadowRoot ?? host.attachShadow({mode: 'open'});
        shadow.adoptedStyleSheets = [site, tileFrame()];
        const root = document.createElement('div');
        attributes.forEach(([name, value]) => root.setAttribute(name, value));
        root.classList.add('df-r-root');
        const card = document.createElement('div');
        card.className = `df-r-card ${choice.preview}`;
        card.append(document.createElement('i'), document.createElement('i'));
        root.append(card);
        shadow.replaceChildren(root);
    }

    drawn.value = true;
}

onMounted(draw);
watch(choices, () => nextTick(draw));
</script>

<template>
  <!-- The root's class stays static: the field adds its own (form-control) after mount,
       and a class binding here would drop it on the next redraw. -->
  <div class="df-rendered">
    <div
      class="df-rendered__list"
      role="radiogroup"
      :aria-label="label"
      :aria-invalid="invalid ? 'true' : undefined"
      :aria-disabled="locked ? 'true' : undefined"
    >
      <input v-if="editable && chosen !== null" type="hidden" :name="inputName(control.path)" :value="chosen" />
      <button
        v-for="choice in choices"
        :key="choice.value"
        :ref="(el) => { if (el) buttons[String(choice.value)] = el; }"
        type="button"
        role="radio"
        class="df-rendered__tile"
        :class="{'df-rendered__tile--on': chosen === String(choice.value)}"
        :aria-checked="chosen === String(choice.value) ? 'true' : 'false'"
        :tabindex="tabStop === String(choice.value) ? 0 : -1"
        :disabled="locked"
        @click="pick(String(choice.value))"
        @keydown="move($event, String(choice.value))"
      >
        <span
          v-if="choice.preview"
          :ref="(el) => { if (el) hosts[String(choice.value)] = el; }"
          class="df-rendered__card"
          aria-hidden="true"
        ></span>
        <!-- Block default: a dashed frame (the block decides), the name is below. -->
        <span v-else class="df-rendered__card df-rendered__card--inherit" aria-hidden="true"><i></i></span>
        <span class="df-rendered__name">{{ choice.label }}</span>
      </button>
    </div>
  </div>
</template>

<style scoped>
.df-rendered {
  flex: 1 1 auto;
  width: 100%;
}

.df-rendered__list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.df-rendered__tile {
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 4px;
  width: 6.25rem;
  margin: 0;
  padding: 4px 4px 6px;
  border: 1px solid var(--c-form-control-border-color, #cdd5df);
  border-radius: var(--c-form-control-radius, 4px);
  background: transparent;
  color: inherit;
  font: inherit;
  cursor: pointer;
}

.df-rendered__tile:hover:not(.df-rendered__tile--on):not(:disabled) {
  background: var(--c-color-neutral-fill-quiet, rgb(0 0 0 / 0.05));
}

/* As Craft's card radios (the layout tiles): the chosen card gets the accent. */
.df-rendered__tile--on {
  border-color: var(--c-color-accent-border-loud, #2563eb);
  box-shadow: inset 0 0 0 1px var(--c-color-accent-border-loud, #2563eb);
  background: var(--c-color-accent-fill-quiet, rgb(37 99 235 / 0.08));
  cursor: default;
}

.df-rendered__tile:focus-visible {
  outline: 2px solid var(--c-color-accent-border-loud, #2563eb);
  outline-offset: 2px;
}

.df-rendered__tile:disabled {
  cursor: default;
}

.df-rendered__card {
  display: block;
  height: 52px;
  border-radius: calc(var(--c-form-control-radius, 4px) - 1px);
  /* A step darker than the panel, so the site's light surface cards stand out on it. */
  background: var(--c-color-neutral-fill-normal, rgb(0 0 0 / 0.08));
  overflow: hidden;
}

.df-rendered__card--inherit {
  display: grid;
  place-items: center;
}

.df-rendered__card--inherit i {
  width: 68px;
  height: 40px;
  border: 1.5px dashed currentColor;
  border-radius: 4px;
  opacity: 0.45;
}

.df-rendered__name {
  font-size: var(--c-text-xs, 0.75rem);
  line-height: 1.2;
  text-align: center;
}

.df-rendered__tile--on .df-rendered__name {
  font-weight: 600;
}
</style>
