<script setup>
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  disabled: Boolean,
  errors: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue'])
const types = [
  { label: 'Earning', value: 'earning' },
  { label: 'Deduction', value: 'deduction' },
]
function set(index, key, value) {
  emit(
    'update:modelValue',
    props.modelValue.map((item, position) =>
      position === index ? { ...item, [key]: value } : item,
    ),
  )
}
function add() {
  emit('update:modelValue', [
    ...props.modelValue,
    { code: '', name: '', type: 'earning', amount: '', quantity: null, rate: null, remarks: null },
  ])
}
function remove(index) {
  emit(
    'update:modelValue',
    props.modelValue.filter((_, position) => index !== position),
  )
}
</script>

<template>
  <div class="payroll-item-editor">
    <p class="muted">
      This replaces the full item list. Saving an empty list clears all earnings and deductions.
    </p>
    <article v-for="(item, index) in modelValue" :key="index" class="payroll-item-row">
      <div class="item-heading">
        <b>Item {{ index + 1 }}</b
        ><Button
          type="button"
          icon="pi pi-trash"
          label="Remove"
          severity="danger"
          text
          size="small"
          :disabled="disabled"
          @click="remove(index)"
        />
      </div>
      <div class="form-grid">
        <label class="field-label"
          >Code
          <InputText
            :model-value="item.code"
            :disabled="disabled"
            maxlength="100"
            required
            @update:model-value="set(index, 'code', $event)"
        /></label>
        <label class="field-label"
          >Name
          <InputText
            :model-value="item.name"
            :disabled="disabled"
            maxlength="150"
            required
            @update:model-value="set(index, 'name', $event)"
        /></label>
        <label class="field-label"
          >Type
          <Select
            :model-value="item.type"
            :options="types"
            option-label="label"
            option-value="value"
            :disabled="disabled"
            @update:model-value="set(index, 'type', $event)"
        /></label>
        <label class="field-label"
          >Amount
          <InputText
            :model-value="String(item.amount ?? '')"
            inputmode="decimal"
            placeholder="0.00"
            :disabled="disabled"
            required
            @update:model-value="set(index, 'amount', $event)"
        /></label>
        <label class="field-label"
          >Quantity
          <InputText
            :model-value="String(item.quantity ?? '')"
            inputmode="decimal"
            :disabled="disabled"
            @update:model-value="set(index, 'quantity', $event || null)"
        /></label>
        <label class="field-label"
          >Rate
          <InputText
            :model-value="String(item.rate ?? '')"
            inputmode="decimal"
            :disabled="disabled"
            @update:model-value="set(index, 'rate', $event || null)"
        /></label>
        <label class="field-label full"
          >Remarks
          <InputText
            :model-value="item.remarks"
            :disabled="disabled"
            @update:model-value="set(index, 'remarks', $event || null)"
        /></label>
      </div>
      <small
        v-for="(messages, key) in Object.fromEntries(
          Object.entries(errors).filter(([key]) => key.startsWith(`items.${index}.`)),
        )"
        :key="key"
        class="field-error"
        >{{ messages.join(' ') }}</small
      >
    </article>
    <Button
      type="button"
      label="Add item"
      icon="pi pi-plus"
      outlined
      :disabled="disabled"
      @click="add"
    />
  </div>
</template>
