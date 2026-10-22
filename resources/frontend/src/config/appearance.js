import Aura from '@primeuix/themes/aura'
import Lara from '@primeuix/themes/lara'
import Nora from '@primeuix/themes/nora'
import { definePreset, usePreset } from '@primeuix/themes'

const bases = { Aura, Lara, Nora }
const shades = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950]
const primaryNames = [
  'slate',
  'emerald',
  'green',
  'lime',
  'orange',
  'amber',
  'yellow',
  'teal',
  'cyan',
  'sky',
  'blue',
  'indigo',
  'violet',
  'purple',
  'fuchsia',
  'pink',
  'rose',
]
const surfaceNames = ['slate', 'gray', 'zinc', 'neutral', 'stone']
const paletteOption = (name) => ({
  name,
  label: name[0].toUpperCase() + name.slice(1),
  color: Aura.primitive[name][500],
})

export const primaryColors = primaryNames.map(paletteOption)
export const surfaceColors = surfaceNames.map(paletteOption)
export const presets = Object.keys(bases)
export const defaultAppearance = { primaryColor: 'blue', surfaceColor: 'slate', preset: 'Aura' }

export function normalizeAppearance(value) {
  const candidate = value && typeof value === 'object' && !Array.isArray(value) ? value : {}
  return {
    primaryColor: primaryNames.includes(candidate.primaryColor)
      ? candidate.primaryColor
      : defaultAppearance.primaryColor,
    surfaceColor: surfaceNames.includes(candidate.surfaceColor)
      ? candidate.surfaceColor
      : defaultAppearance.surfaceColor,
    preset: presets.includes(candidate.preset) ? candidate.preset : defaultAppearance.preset,
  }
}

function luminance(color) {
  const channels = color
    .slice(1)
    .match(/.{2}/g)
    .map((channel) => {
      const value = parseInt(channel, 16) / 255
      return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4
    })
  return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722
}

export function contrastColor(background) {
  const lightness = luminance(background)
  return 1.05 / (lightness + 0.05) >= (lightness + 0.05) / 0.05 ? '#ffffff' : '#000000'
}

function primaryTokens(palette, shade) {
  const color = palette[shade]
  const contrast = contrastColor(color)
  const lighter = contrast === '#000000'
  return {
    color,
    contrastColor: contrast,
    hoverColor: palette[lighter ? shade - 100 : shade + 100],
    activeColor: palette[lighter ? shade - 200 : shade + 200],
  }
}

export function buildThemePreset(appearance = defaultAppearance) {
  const { primaryColor, surfaceColor, preset } = normalizeAppearance(appearance)
  const primary = Aura.primitive[primaryColor]
  const surface = { 0: '#ffffff', ...Aura.primitive[surfaceColor] }
  return definePreset(bases[preset], {
    semantic: {
      primary: Object.fromEntries(shades.map((shade) => [shade, primary[shade]])),
      colorScheme: {
        light: { surface, primary: primaryTokens(primary, 600) },
        dark: {
          surface,
          primary: primaryTokens(primary, 400),
          formField: { background: '{surface.900}' },
        },
      },
    },
  })
}

export function appearanceVariables(appearance) {
  const { primaryColor, surfaceColor } = normalizeAppearance(appearance)
  const primary = Aura.primitive[primaryColor]
  const surface = Aura.primitive[surfaceColor]
  const dark = appearance?.dark === true
  const accent = primary[dark ? 300 : 700]
  const hero = primary[600]
  const heroContrast = contrastColor(hero)
  return {
    '--page': dark ? surface[950] : surface[100],
    '--panel': dark ? surface[900] : '#ffffff',
    '--text': surface[dark ? 100 : 700],
    '--muted': surface[dark ? 400 : 600],
    '--line': surface[dark ? 700 : 200],
    '--soft': surface[dark ? 800 : 50],
    '--accent': accent,
    '--accent-contrast': contrastColor(accent),
    '--accent-soft': dark
      ? `color-mix(in srgb, ${primary[400]} 16%, ${surface[900]})`
      : primary[50],
    '--sidebar': dark ? surface[900] : surface[50],
    '--sidebar-hover': dark ? surface[800] : primary[100],
    '--nav-active': hero,
    '--primary-contrast': heroContrast,
    '--hero': hero,
    '--hero-contrast': heroContrast,
    '--hero-muted': heroContrast,
    '--primary-strong': primary[800],
  }
}

export function applyAppearance(appearance) {
  const element = globalThis.document?.documentElement
  element?.classList?.toggle('hris-dark', appearance.dark === true)
  for (const [name, value] of Object.entries(appearanceVariables(appearance)))
    element?.style?.setProperty(name, value)
  if (typeof globalThis.document?.createElement === 'function')
    usePreset(buildThemePreset(appearance))
}
