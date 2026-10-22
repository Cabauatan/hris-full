<script setup>
import { useId } from 'vue'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import Checkbox from 'primevue/checkbox'
import LookupPicker from './LookupPicker.vue'
import PayrollItemsEditor from './PayrollItemsEditor.vue'

const props = defineProps({
  modelValue: { type: Object, default: () => ({}) },
  fields: { type: Array, default: () => [] },
  errors: { type: Object, default: () => ({}) },
  disabled: Boolean,
  mode: { type: String, default: 'create' },
})
const emit = defineEmits(['update:modelValue'])
const formId = useId()
function inputId(field) {
  return `${formId}-${field.key}`
}
function labelId(field) {
  return `${formId}-label-${field.key}`
}
function update(key, value) {
  emit('update:modelValue', { ...props.modelValue, [key]: value })
}
function kind(field) {
  if (field.type !== 'settingValue') return field.type
  const settingType = props.modelValue.type || 'string'
  return ['string', 'integer'].includes(settingType) ? 'text' : settingType
}
function isIntegerSetting(field) {
  return field.type === 'settingValue' && props.modelValue.type === 'integer'
}
function required(field) {
  return field.required || (props.mode === 'create' ? field.createRequired : field.updateRequired)
}
function fileChanged(field, event) {
  update(field.key, event.target.files?.[0] || null)
}
</script>

<template>
  <div class="form-grid">
    <div
      v-for="field in fields.filter(
        (item) => !(mode === 'edit' && item.createOnly) && !(mode === 'create' && item.updateOnly),
      )"
      :key="field.key"
      class="field-label"
      :class="{ full: field.full || ['textarea', 'json', 'payrollItems'].includes(kind(field)) }"
    >
      <label :id="labelId(field)" :for="inputId(field)"
        >{{ field.label }}
        <span v-if="required(field)" class="required-mark" aria-label="required">*</span></label
      >
      <label v-if="field.type === 'settingValue'" class="null-toggle"
        ><Checkbox
          :model-value="modelValue[field.key] === null"
          binary
          :disabled="disabled"
          @update:model-value="
            update(field.key, $event ? null : kind(field) === 'boolean' ? false : '')
          "
        /><span>Set an empty value (null)</span></label
      >
      <template v-if="field.type !== 'settingValue' || modelValue[field.key] !== null">
        <LookupPicker
          v-if="kind(field) === 'lookup'"
          :input-id="inputId(field)"
          :aria-labelledby="labelId(field)"
          :field="field"
          :model-value="modelValue[field.key]"
          :disabled="disabled"
          @update:model-value="update(field.key, $event)"
        />
        <PayrollItemsEditor
          v-else-if="kind(field) === 'payrollItems'"
          :model-value="modelValue[field.key] || []"
          :disabled="disabled"
          :errors="errors"
          @update:model-value="update(field.key, $event)"
        />
        <div v-else-if="kind(field) === 'boolean'" class="toggle-field">
          <ToggleSwitch
            :input-id="inputId(field)"
            :model-value="Boolean(modelValue[field.key])"
            :disabled="disabled"
            @update:model-value="update(field.key, $event)"
          /><span>{{ modelValue[field.key] ? 'Yes' : 'No' }}</span>
        </div>
        <Select
          v-else-if="kind(field) === 'select'"
          :input-id="inputId(field)"
          :aria-labelledby="labelId(field)"
          :model-value="modelValue[field.key]"
          :options="field.options"
          option-label="label"
          option-value="value"
          :show-clear="field.nullable"
          :placeholder="field.placeholder || 'Select an option'"
          :disabled="disabled"
          fluid
          @update:model-value="update(field.key, $event)"
        />
        <Textarea
          v-else-if="['textarea', 'json'].includes(kind(field))"
          :id="inputId(field)"
          :model-value="
            typeof modelValue[field.key] === 'object' && modelValue[field.key] !== null
              ? JSON.stringify(modelValue[field.key], null, 2)
              : modelValue[field.key]
          "
          :rows="kind(field) === 'json' ? 6 : 3"
          :maxlength="field.maxLength"
          :disabled="disabled"
          :required="required(field)"
          auto-resize
          fluid
          @update:model-value="update(field.key, $event)"
        />
        <InputNumber
          v-else-if="kind(field) === 'integer'"
          :input-id="inputId(field)"
          :model-value="
            modelValue[field.key] === '' || modelValue[field.key] == null
              ? null
              : Number(modelValue[field.key])
          "
          :use-grouping="false"
          :min="field.min"
          :max="field.max"
          :disabled="disabled"
          :required="required(field)"
          fluid
          @update:model-value="update(field.key, $event)"
        />
        <input
          v-else-if="['date', 'datetime', 'time'].includes(kind(field))"
          :id="inputId(field)"
          class="native-input"
          :value="modelValue[field.key] || ''"
          :type="kind(field) === 'datetime' ? 'datetime-local' : kind(field)"
          :step="kind(field) === 'time' || kind(field) === 'datetime' ? 1 : undefined"
          :disabled="disabled"
          :required="required(field)"
          @input="update(field.key, $event.target.value)"
        />
        <input
          v-else-if="kind(field) === 'file'"
          :id="inputId(field)"
          class="native-input file-input"
          type="file"
          :disabled="disabled"
          :required="required(field) && !modelValue[field.key]"
          @change="fileChanged(field, $event)"
        />
        <InputText
          v-else
          :id="inputId(field)"
          :model-value="modelValue[field.key] == null ? '' : String(modelValue[field.key])"
          :type="['email', 'url'].includes(kind(field)) ? kind(field) : 'text'"
          :inputmode="
            kind(field) === 'decimal' ? 'decimal' : isIntegerSetting(field) ? 'numeric' : undefined
          "
          :pattern="isIntegerSetting(field) ? '-?[0-9]+' : undefined"
          :maxlength="field.maxLength"
          :placeholder="field.placeholder"
          :disabled="disabled"
          :required="required(field)"
          fluid
          @update:model-value="update(field.key, $event)"
        />
      </template>
      <small v-if="field.help" class="field-help">{{ field.help }}</small>
      <small v-for="(message, index) in errors[field.key] || []" :key="index" class="field-error">{{
        message
      }}</small>
    </div>
  </div>
</template>
