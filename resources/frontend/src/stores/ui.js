import { ref } from 'vue'
import { defineStore } from 'pinia'
import {
  applyAppearance,
  defaultAppearance,
  normalizeAppearance,
  primaryColors,
  surfaceColors,
  presets,
} from '../config/appearance.js'

export const useUiStore = defineStore('ui', () => {
  const dark = ref(false)
  const navCollapsed = ref(false)
  const primaryColor = ref(defaultAppearance.primaryColor)
  const surfaceColor = ref(defaultAppearance.surfaceColor)
  const preset = ref(defaultAppearance.preset)
  const settingsOpen = ref(false)
  let initialized = false

  function applyTheme() {
    applyAppearance({
      dark: dark.value,
      primaryColor: primaryColor.value,
      surfaceColor: surfaceColor.value,
      preset: preset.value,
    })
  }

  function initializeTheme() {
    if (initialized) return
    initialized = true
    dark.value = globalThis.document?.documentElement?.classList.contains('hris-dark') ?? false
    try {
      const saved = globalThis.localStorage?.getItem('hris-theme')
      if (saved === 'dark' || saved === 'light') dark.value = saved === 'dark'
      const appearance = normalizeAppearance(
        JSON.parse(globalThis.localStorage?.getItem('hris-appearance') || 'null'),
      )
      primaryColor.value = appearance.primaryColor
      surfaceColor.value = appearance.surfaceColor
      preset.value = appearance.preset
    } catch {
      /* Keep the current theme when storage is unavailable. */
    }
    applyTheme()
  }

  function setDark(value) {
    if (typeof value !== 'boolean') return
    dark.value = value
    applyTheme()
    try {
      globalThis.localStorage?.setItem('hris-theme', dark.value ? 'dark' : 'light')
    } catch {
      /* Theme still works without persistence. */
    }
  }

  function toggleTheme() {
    setDark(!dark.value)
  }

  function saveAppearance() {
    applyTheme()
    try {
      globalThis.localStorage?.setItem(
        'hris-appearance',
        JSON.stringify({
          primaryColor: primaryColor.value,
          surfaceColor: surfaceColor.value,
          preset: preset.value,
        }),
      )
    } catch {
      /* Color settings remain usable when storage is unavailable. */
    }
  }

  function setPrimaryColor(value) {
    if (!primaryColors.some((color) => color.name === value)) return
    primaryColor.value = value
    saveAppearance()
  }

  function setSurfaceColor(value) {
    if (!surfaceColors.some((color) => color.name === value)) return
    surfaceColor.value = value
    saveAppearance()
  }

  function setPreset(value) {
    if (!presets.includes(value)) return
    preset.value = value
    saveAppearance()
  }

  function openSettings() {
    settingsOpen.value = true
  }

  function closeSettings() {
    settingsOpen.value = false
  }

  function resetAppearance() {
    primaryColor.value = defaultAppearance.primaryColor
    surfaceColor.value = defaultAppearance.surfaceColor
    preset.value = defaultAppearance.preset
    setDark(false)
    saveAppearance()
  }

  function toggleNavigation() {
    navCollapsed.value = !navCollapsed.value
  }

  return {
    dark,
    navCollapsed,
    primaryColor,
    surfaceColor,
    preset,
    settingsOpen,
    initializeTheme,
    toggleTheme,
    setDark,
    toggleNavigation,
    setPrimaryColor,
    setSurfaceColor,
    setPreset,
    openSettings,
    closeSettings,
    resetAppearance,
  }
})
