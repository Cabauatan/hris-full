<script setup>
import { computed } from 'vue'
import Tag from 'primevue/tag'
import { formatValue, statusSeverity } from '@/utils/forms'
import { displayLabel, displayRecord, isInternalIdentifier } from '@/utils/records'
const props = defineProps({
  record: { type: Object, default: () => ({}) },
  columns: { type: Array, default: () => [] },
})
const fields = computed(() =>
  Object.entries(displayRecord(props.record) || {})
    .filter(
      ([key]) =>
        ![
          'abilities',
          'approval_actions',
          'items',
          'file_path',
          'document_path',
          'attachment_path',
          'logo_path',
        ].includes(key),
    )
    .map(([key, value]) => ({
      key,
      value,
      label:
        props.columns.find((column) => column.key === key)?.label ||
        props.columns
          .find(
            (column) =>
              isInternalIdentifier(column.key) && column.key.replace(/_ids?$/, '') === key,
          )
          ?.label?.replace(/\s+IDs?\b/i, '') ||
        displayLabel(key),
      type:
        props.columns.find((column) => column.key === key)?.type ||
        (key.endsWith('_at') || key.endsWith('_in') || key.endsWith('_out')
          ? 'datetime'
          : key.endsWith('_date')
            ? 'date'
            : typeof value === 'boolean'
              ? 'boolean'
              : 'text'),
    })),
)
</script>

<template>
  <dl class="record-details">
    <div
      v-for="field in fields"
      :key="field.key"
      :class="{
        'detail-wide':
          (typeof field.value === 'object' && field.value !== null) ||
          [
            'content',
            'remarks',
            'reason',
            'purpose',
            'description',
            'old_values',
            'new_values',
          ].includes(field.key),
      }"
    >
      <dt>{{ field.label }}</dt>
      <dd>
        <Tag
          v-if="field.type === 'status' || field.key === 'status'"
          :value="formatValue(field.value)"
          :severity="statusSeverity(field.value)"
        />
        <pre v-else-if="typeof field.value === 'object' && field.value !== null">{{
          formatValue(field.value, field.type)
        }}</pre>
        <span v-else>{{ formatValue(field.value, field.type) }}</span>
      </dd>
    </div>
  </dl>
</template>
