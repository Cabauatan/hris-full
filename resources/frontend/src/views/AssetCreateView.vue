<script setup>
import { ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import http from '@/api/http'

const router = useRouter()
const busy = ref(false)
const error = ref('')
const savedAssetId = ref(null)
const proofError = ref('')
const form = ref(emptyForm())

const assetTypes = [
    { value: 'desktop', label: 'Desktop' }, { value: 'laptop', label: 'Laptop' },
    { value: 'monitor', label: 'Monitor' }, { value: 'printer', label: 'Printer' },
    { value: 'network', label: 'Network equipment' }, { value: 'peripheral', label: 'Peripheral' },
    { value: 'consumable', label: 'Consumable' }, { value: 'other', label: 'Other' },
]

watch(() => form.value.type, (type) => {
    if (type === 'consumable') form.value.serial_number = ''
})

function emptyForm() {
    return {
        asset_tag: '', name: '', type: 'laptop', serial_number: '', brand: '',
        model: '', division: '', location: '', quantity: 1, unit_cost: null,
        purchase_date: '', warranty_until: '', proof_file: null,
    }
}

async function create() {
    busy.value = true
    error.value = ''
    proofError.value = ''
    try {
        const proofFile = form.value.proof_file
        const assetData = { ...form.value }
        delete assetData.proof_file
        const { data } = await http.post('/api/inventory', assetData)
        const assetId = data.data.id

        if (proofFile) {
            const payload = new FormData()
            payload.append('file', proofFile)
            try {
                await http.post(`/api/inventory/${assetId}/attachments`, payload)
            } catch (failure) {
                savedAssetId.value = assetId
                proofError.value = failure.response?.data?.message || 'The asset was saved, but its proof could not be uploaded.'
                return
            }
        }

        await router.push(`/inventory/${assetId}`)
    } catch (failure) {
        error.value = failure.response?.data?.message || Object.values(failure.response?.data?.errors || {}).flat().join(' ') || 'The asset could not be saved.'
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <section class="asset-create-page">
        <div class="page-heading asset-create-page-heading">
            <div>
                <RouterLink class="back-link" to="/inventory"><i class="pi pi-arrow-left" /> Inventory</RouterLink>
                <h2>New asset</h2>
                <p>Create an equipment record or add a consumable stock item.</p>
            </div>
        </div>

        <section v-if="savedAssetId" class="panel asset-create-result">
            <span class="asset-create-result-icon"><i class="pi pi-exclamation-triangle" /></span>
            <div>
                <h3>Asset saved, proof upload failed</h3>
                <p>{{ proofError }}</p>
                <RouterLink class="button button-primary" :to="`/inventory/${savedAssetId}`">Open asset details</RouterLink>
            </div>
        </section>

        <section v-else class="panel asset-create-panel">
            <header class="asset-create-intro">
                <span class="asset-create-icon"><i :class="form.type === 'consumable' ? 'pi pi-box' : 'pi pi-desktop'" /></span>
                <div><span class="eyebrow">INVENTORY</span><h3>Asset information</h3><p>Fields marked required must be completed before saving.</p></div>
            </header>

            <p v-if="error" class="error-box asset-create-error" role="alert"><i class="pi pi-exclamation-circle" />{{ error }}</p>

            <form class="asset-create-form" @submit.prevent="create">
                <div class="asset-create-content">
                    <section class="asset-form-section">
                        <div class="asset-form-section-heading"><span>01</span><div><h4>Item details</h4><p>Identify the item and how it is tracked.</p></div></div>
                        <div class="form-grid asset-form-grid">
                            <label class="field-label"><span class="asset-field-heading"><span>Asset tag</span><b class="required-mark">Required</b></span><input v-model.trim="form.asset_tag" class="input" placeholder="e.g. IT-LAP-001" autocomplete="off" required maxlength="60"></label>
                            <label class="field-label"><span class="asset-field-heading"><span>Item name</span><b class="required-mark">Required</b></span><input v-model.trim="form.name" class="input" placeholder="e.g. Dell Latitude 5440" required maxlength="160"></label>
                            <label class="field-label">Item type<select v-model="form.type" class="select"><option v-for="type in assetTypes" :key="type.value" :value="type.value">{{ type.label }}</option></select></label>
                            <label v-if="form.type !== 'consumable'" class="field-label"><span class="asset-field-heading"><span>Serial number</span><b class="optional-mark">Optional</b></span><input v-model.trim="form.serial_number" class="input" placeholder="Enter the device serial number" autocomplete="off" maxlength="120"></label>
                        </div>
                        <p class="asset-type-hint"><i :class="form.type === 'consumable' ? 'pi pi-box' : 'pi pi-info-circle'" />{{ form.type === 'consumable' ? 'Consumables are tracked by stock quantity and do not use serial numbers.' : 'Equipment can be assigned, returned, repaired, or retired from its detail page.' }}</p>
                    </section>

                    <section class="asset-form-section">
                        <div class="asset-form-section-heading"><span>02</span><div><h4>Classification and location</h4><p>Add details that help staff identify and find the item.</p></div></div>
                        <div class="form-grid asset-form-grid">
                            <label class="field-label"><span class="asset-field-heading"><span>Brand</span><b class="optional-mark">Optional</b></span><input v-model.trim="form.brand" class="input" placeholder="e.g. Dell, HP, Canon" maxlength="80"></label>
                            <label class="field-label"><span class="asset-field-heading"><span>Model</span><b class="optional-mark">Optional</b></span><input v-model.trim="form.model" class="input" placeholder="Model or product number" maxlength="120"></label>
                            <label class="field-label"><span class="asset-field-heading"><span>Division</span><b class="optional-mark">Optional</b></span><input v-model.trim="form.division" class="input" placeholder="Division or business unit" maxlength="120"></label>
                            <label class="field-label"><span class="asset-field-heading"><span>Storage location</span><b class="optional-mark">Optional</b></span><input v-model.trim="form.location" class="input" placeholder="e.g. IT storeroom" maxlength="160"></label>
                        </div>
                    </section>

                    <section class="asset-form-section">
                        <div class="asset-form-section-heading"><span>03</span><div><h4>Stock and purchase</h4><p>Record the starting quantity and purchase value.</p></div></div>
                        <div class="form-grid asset-form-grid">
                            <label class="field-label">{{ form.type === 'consumable' ? 'Opening stock' : 'Quantity' }}<input v-model.number="form.quantity" class="input" type="number" :min="form.type === 'consumable' ? 0 : 1" max="999999" required><small class="field-help">{{ form.type === 'consumable' ? 'Use stock issue or restock on the detail page to record future changes.' : 'If each unit has a serial number, create a separate record for it.' }}</small></label>
                            <label class="field-label"><span class="asset-field-heading"><span>Unit cost (PHP)</span><b class="optional-mark">Optional</b></span><input v-model.number="form.unit_cost" class="input" type="number" min="0" max="9999999999.99" step="0.01" placeholder="0.00"></label>
                            <label class="field-label"><span class="asset-field-heading"><span>Purchase date</span><b class="optional-mark">Optional</b></span><input v-model="form.purchase_date" class="input" type="date"></label>
                            <label class="field-label"><span class="asset-field-heading"><span>Warranty end date</span><b class="optional-mark">Optional</b></span><input v-model="form.warranty_until" class="input" type="date"></label>
                        </div>
                    </section>

                    <section class="asset-form-section asset-proof-section">
                        <div class="asset-form-section-heading"><span>04</span><div><h4>Proof of purchase</h4><p>Attach an invoice or receipt for this item.</p></div><span class="optional-mark">Optional</span></div>
                        <label class="asset-proof-drop" :class="{ 'has-file': form.proof_file }">
                            <input type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" @change="form.proof_file = $event.target.files[0] || null">
                            <span class="asset-proof-icon"><i :class="form.proof_file ? 'pi pi-check' : 'pi pi-cloud-upload'" /></span>
                            <span class="asset-proof-copy"><b>{{ form.proof_file?.name || 'Choose a file to attach' }}</b><small>{{ form.proof_file ? 'Click to choose a different file' : 'PDF, JPG, or PNG · up to 10 MB' }}</small></span>
                            <span class="asset-proof-action">Browse</span>
                        </label>
                    </section>
                </div>

                <footer class="asset-create-actions">
                    <span><i class="pi pi-lock" /> Your changes are saved to the inventory audit history.</span>
                    <div><RouterLink class="button button-secondary" to="/inventory">Cancel</RouterLink><button class="button button-primary" :disabled="busy"><i :class="busy ? 'pi pi-spin pi-spinner' : 'pi pi-check'" />{{ busy ? 'Saving item…' : 'Save inventory item' }}</button></div>
                </footer>
            </form>
        </section>
    </section>
</template>
