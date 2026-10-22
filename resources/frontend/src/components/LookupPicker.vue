<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import Select from 'primevue/select'
import Button from 'primevue/button'
import http from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { lookupLabel } from '@/utils/records'

const props = defineProps({
  field: { type: Object, required: true },
  modelValue: [Number, String],
  disabled: Boolean,
  inputId: String,
  ariaLabelledby: String,
})
const emit = defineEmits(['update:modelValue', 'selection-change'])
const auth = useAuthStore()
const rows = ref([])
const page = ref(0)
const lastPage = ref(1)
const loading = ref(false)
const unavailable = ref(false)
let active = true
let requestVersion = 0
let controller
const options = computed(() => {
  const fields = props.field.lookup?.labelFields || ['name', 'employee_number', 'code', 'title']
  const result = (props.field.lookup?.options || rows.value).map((row) => ({
    value: Number(row.value ?? row.id),
    label: row.label || lookupLabel(row, fields) || 'Unnamed record',
  }))
  if (props.modelValue && !result.some((item) => item.value === Number(props.modelValue)))
    result.unshift({
      value: Number(props.modelValue),
      label: props.field.lookup?.selectedLabel || 'Selected record',
    })
  return result
})
const selectedLabel = computed(
  () =>
    options.value.find((item) => item.value === Number(props.modelValue))?.label ||
    props.field.lookup?.placeholder ||
    'Choose a record',
)
async function load(reset = false) {
  const lookup = props.field.lookup
  if (!active || loading.value || !lookup?.endpoint || lookup.options || !auth.authenticated) return
  const version = ++requestVersion
  const account = auth.user?.id
  const requestController = new AbortController()
  controller = requestController
  const current = () =>
    active &&
    version === requestVersion &&
    !requestController.signal.aborted &&
    auth.authenticated &&
    auth.user?.id === account
  loading.value = true
  try {
    const next = reset ? 1 : page.value + 1
    const { data } = await http.get(lookup.endpoint, {
      signal: requestController.signal,
      params: {
        per_page: 100,
        page: next,
        ...(lookup.params || lookup.filters || {}),
      },
    })
    if (!current()) return
    const records = lookup.requiresBalance
      ? data.data.filter((row) => row.requires_balance)
      : data.data
    rows.value = reset ? records : [...rows.value, ...records]
    page.value = next
    lastPage.value = data.meta?.last_page || 1
    unavailable.value = false
  } catch {
    if (current()) unavailable.value = true
  } finally {
    if (current()) loading.value = false
  }
}
onBeforeUnmount(() => {
  active = false
  requestVersion += 1
  controller?.abort()
})
watch(
  [
    () =>
      JSON.stringify({
        endpoint: props.field.lookup?.endpoint,
        labelFields: props.field.lookup?.labelFields,
        params: props.field.lookup?.params || props.field.lookup?.filters,
        requiresBalance: props.field.lookup?.requiresBalance,
        options: props.field.lookup?.options,
      }),
    () => auth.user?.id,
    () => auth.authenticated,
  ],
  () => {
    requestVersion += 1
    controller?.abort()
    rows.value = []
    page.value = 0
    lastPage.value = 1
    loading.value = false
    unavailable.value = false
    load(true)
  },
  { immediate: true, flush: 'sync' },
)
watch(
  () => options.value.find((item) => item.value === Number(props.modelValue)),
  (selected) =>
    emit('selection-change', {
      value: props.modelValue === '' || props.modelValue == null ? null : Number(props.modelValue),
      label: selected?.label || '',
    }),
  { immediate: true },
)
</script>

<template>
  <div class="lookup-picker">
    <Select
      :title="selectedLabel"
      :input-id="inputId"
      :aria-labelledby="ariaLabelledby"
      :model-value="modelValue === '' || modelValue == null ? null : Number(modelValue)"
      :options="options"
      option-label="label"
      option-value="value"
      filter
      :show-clear="field.nullable"
      :loading="loading"
      :disabled="disabled || (!field.lookup?.endpoint && !field.lookup?.options)"
      :placeholder="field.lookup?.placeholder || 'Choose a record'"
      fluid
      @update:model-value="emit('update:modelValue', $event)"
    />
    <small v-if="unavailable" class="field-error">Could not load choices. Please retry.</small>
    <Button
      v-if="unavailable"
      type="button"
      label="Retry"
      text
      size="small"
      :loading="loading"
      @click="load(true)"
    />
    <Button
      v-if="!unavailable && page < lastPage"
      type="button"
      label="Load more records"
      text
      size="small"
      :loading="loading"
      @click="load()"
    />
  </div>
</template>
