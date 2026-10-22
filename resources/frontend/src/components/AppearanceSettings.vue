<script setup>
import { watch } from 'vue'
import Drawer from 'primevue/drawer'
import Button from 'primevue/button'
import { useUiStore } from '@/stores/ui'
import { primaryColors, surfaceColors, presets, contrastColor } from '@/config/appearance'

const ui = useUiStore()
let triggerElement

watch(
  () => ui.settingsOpen,
  (open) => {
    if (open) triggerElement = globalThis.document?.activeElement
  },
  { flush: 'sync' },
)

function restoreFocus() {
  if (triggerElement?.isConnected) triggerElement.focus()
  triggerElement = undefined
}
</script>

<template>
  <Drawer
    v-model:visible="ui.settingsOpen"
    header="Settings"
    position="right"
    class="appearance-settings"
    :style="{ width: '20rem', maxWidth: '100vw' }"
    block-scroll
    @after-hide="restoreFocus"
  >
    <div class="appearance-controls">
      <fieldset>
        <legend>Primary</legend>
        <div class="appearance-swatches">
          <button
            v-for="color in primaryColors"
            :key="color.name"
            type="button"
            class="appearance-swatch"
            :style="{ backgroundColor: color.color, color: contrastColor(color.color) }"
            :aria-label="`Use ${color.label} primary color`"
            :aria-pressed="ui.primaryColor === color.name"
            :title="color.label"
            @click="ui.setPrimaryColor(color.name)"
          >
            <i v-if="ui.primaryColor === color.name" class="pi pi-check" aria-hidden="true" />
          </button>
        </div>
      </fieldset>
      <fieldset>
        <legend>Surface</legend>
        <div class="appearance-swatches">
          <button
            v-for="color in surfaceColors"
            :key="color.name"
            type="button"
            class="appearance-swatch"
            :style="{ backgroundColor: color.color, color: contrastColor(color.color) }"
            :aria-label="`Use ${color.label} surface color`"
            :aria-pressed="ui.surfaceColor === color.name"
            :title="color.label"
            @click="ui.setSurfaceColor(color.name)"
          >
            <i v-if="ui.surfaceColor === color.name" class="pi pi-check" aria-hidden="true" />
          </button>
        </div>
      </fieldset>
      <fieldset>
        <legend>Presets</legend>
        <div class="appearance-segments">
          <button
            v-for="preset in presets"
            :key="preset"
            type="button"
            :aria-pressed="ui.preset === preset"
            @click="ui.setPreset(preset)"
          >
            {{ preset }}
          </button>
        </div>
      </fieldset>
      <fieldset>
        <legend>Color scheme</legend>
        <div class="appearance-segments">
          <button type="button" :aria-pressed="!ui.dark" @click="ui.setDark(false)">Light</button>
          <button type="button" :aria-pressed="ui.dark" @click="ui.setDark(true)">Dark</button>
        </div>
      </fieldset>
      <Button label="Reset to defaults" icon="pi pi-refresh" outlined @click="ui.resetAppearance" />
    </div>
  </Drawer>
</template>

<style scoped>
.appearance-controls {
  display: grid;
  gap: 26px;
  padding-top: 6px;
}
.appearance-controls fieldset {
  min-width: 0;
  border: 0;
  padding: 0;
  margin: 0;
}
.appearance-controls legend {
  margin-bottom: 12px;
  padding: 0;
  color: var(--muted);
  font-size: 14px;
  font-weight: 650;
}
.appearance-swatches {
  display: grid;
  grid-template-columns: repeat(7, 28px);
  gap: 10px;
}
.appearance-swatch {
  display: grid;
  place-items: center;
  width: 28px;
  height: 28px;
  padding: 0;
  border: 1px solid rgb(0 0 0 / 8%);
  border-radius: 5px;
}
.appearance-swatch[aria-pressed='true'] {
  outline: 2px solid var(--text);
  outline-offset: 2px;
}
.appearance-swatch i {
  font-size: 13px;
}
.appearance-segments {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 4px;
  padding: 4px;
  border-radius: 8px;
  background: var(--soft);
}
.appearance-segments button {
  padding: 6px 12px;
  border: 1px solid transparent;
  border-radius: 6px;
  background: transparent;
  color: var(--muted);
  font-size: 13px;
}
.appearance-segments button[aria-pressed='true'] {
  border-color: var(--line);
  background: var(--panel);
  color: var(--text);
  box-shadow: 0 1px 2px rgb(0 0 0 / 5%);
}
.appearance-controls > .p-button {
  justify-self: start;
}
@media (max-width: 350px) {
  .appearance-swatches {
    grid-template-columns: repeat(6, 28px);
  }
}
</style>
