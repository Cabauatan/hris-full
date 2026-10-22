import test from 'node:test'
import assert from 'node:assert/strict'
import Aura from '@primeuix/themes/aura'
import Lara from '@primeuix/themes/lara'
import Nora from '@primeuix/themes/nora'
import {
  appearanceVariables,
  buildThemePreset,
  primaryColors,
  surfaceColors,
} from '../src/config/appearance.js'

function luminance(hex) {
  const rgb = hex
    .slice(1)
    .match(/.{2}/g)
    .map((part) => {
      const channel = Number.parseInt(part, 16) / 255
      return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4
    })
  return rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722
}
function contrast(foreground, background) {
  const values = [luminance(foreground), luminance(background)].sort((a, b) => b - a)
  return (values[0] + 0.05) / (values[1] + 0.05)
}

test('every selectable primary stays readable on filled buttons and custom heroes in both schemes', () => {
  for (const { name } of primaryColors) {
    const theme = buildThemePreset({ primaryColor: name })
    for (const scheme of ['light', 'dark']) {
      const primary = theme.semantic.colorScheme[scheme].primary
      for (const background of [primary.color, primary.hoverColor, primary.activeColor])
        assert.ok(
          contrast(primary.contrastColor, background) >= 4.5,
          `${name} ${scheme} ${background}`,
        )
      const variables = appearanceVariables({ primaryColor: name, dark: scheme === 'dark' })
      assert.ok(contrast(variables['--hero-contrast'], variables['--hero']) >= 4.5)
      assert.ok(contrast(variables['--accent-contrast'], variables['--accent']) >= 4.5)
    }
  }
})

test('preset switches retain selected palettes while keeping each installed preset component design', () => {
  for (const [preset, base] of Object.entries({ Aura, Lara, Nora })) {
    const theme = buildThemePreset({ preset, primaryColor: 'purple', surfaceColor: 'stone' })
    assert.deepEqual(theme.components.button, base.components.button)
    assert.deepEqual(theme.semantic.primary, Aura.primitive.purple)
    assert.equal(theme.semantic.colorScheme.light.surface[100], Aura.primitive.stone[100])
    assert.equal(theme.semantic.colorScheme.dark.surface[900], Aura.primitive.stone[900])
  }
})

test('each surface selection provides readable page and panel text in light and dark modes', () => {
  for (const { name } of surfaceColors) {
    for (const dark of [false, true]) {
      const variables = appearanceVariables({ surfaceColor: name, dark })
      for (const background of ['--panel', '--page', '--sidebar']) {
        assert.ok(
          contrast(variables['--text'], variables[background]) >= 4.5,
          `${name} ${dark} ${background}`,
        )
        assert.ok(
          contrast(variables['--muted'], variables[background]) >= 4.5,
          `${name} ${dark} muted ${background}`,
        )
      }
    }
  }
})
