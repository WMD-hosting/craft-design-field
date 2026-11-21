<!--
  The `design-field:block-picker` Control: the settings page's block dropdown. Picking a
  block opens its URL, so the page, its address and the panel's refreshes name the same block.
-->
<script setup>
import {computed} from 'vue';

const props = defineProps({
    control: {type: Object, required: true},
    value: {default: null},
    label: {type: String, default: undefined},
    // Taken so they do not land on the root as attributes.
    editable: {type: Boolean, default: true},
    invalid: {type: Boolean, default: false},
    required: {type: Boolean, default: false},
});

const options = computed(() => props.control.props?.options ?? []);

function open(event) {
    const url = options.value.find((option) => String(option.value) === event.target.value)?.url;

    if (url) {
        window.location.assign(url);
    }
}
</script>

<template>
  <div class="df-block-picker">
    <select class="df-block-picker__select" :aria-label="label" :value="value" @change="open">
      <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
    </select>
  </div>
</template>

<style>
.df-block-picker__select {
  box-sizing: border-box;
  max-width: 100%;
  min-width: 16rem;
  height: var(--c-size-control-md, 2.25rem);
  padding: 0 0.625rem;
  border: 1px solid var(--c-form-control-border-color, #cdd5df);
  border-radius: var(--c-form-control-radius, 4px);
  background: var(--c-form-control-background-color, transparent);
  color: inherit;
  font: inherit;
}

.df-block-picker__select:focus-visible {
  outline: 2px solid var(--c-color-accent-border-loud, #2563eb);
  outline-offset: 2px;
}
</style>
