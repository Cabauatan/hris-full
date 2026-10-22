<script setup>
import Button from 'primevue/button'
defineProps({ kind: { type: String, default: 'not-found' } })
</script>
<template>
  <section class="access-state">
    <span class="access-icon"
      ><i :class="kind === 'forbidden' ? 'pi pi-lock' : 'pi pi-compass'" /></span
    ><span class="eyebrow">{{
      kind === 'forbidden' ? 'ACCESS RESTRICTED' : 'PAGE NOT FOUND'
    }}</span>
    <h1>
      {{
        kind === 'forbidden' ? 'This page is outside your access.' : 'We couldn’t find that page.'
      }}
    </h1>
    <p>
      {{
        kind === 'forbidden'
          ? 'Contact your HR administrator if you need access to this workspace.'
          : 'The page may have moved. Return to your workspace to continue.'
      }}
    </p>
    <RouterLink to="/home"><Button label="Back to home" icon="pi pi-arrow-left" /></RouterLink>
  </section>
</template>
