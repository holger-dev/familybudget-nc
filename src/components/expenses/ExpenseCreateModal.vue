<template>
  <NcModal size="small" @close="$emit('close')">
    <div class="modal-body">
      <h3>{{ title }}</h3>
      <form @submit.prevent="onSave">
        <NcTextField
          :value="amountInput"
          type="text"
          inputmode="decimal"
          label="Betrag (EUR)"
          required
          @update:value="onAmountInput" />
        <NcTextField v-model="date" type="date" label="Datum" required />
        <NcTextField v-model="description" type="text" label="Beschreibung (optional)" />
        <NcSelect
          :value="selectedPayerOption"
          input-label="Eingetragen für"
          label="label"
          :clearable="false"
          :searchable="false"
          :options="payerOptions"
          :append-to-body="false"
          @input="onPayerChange" />
        <div class="actions">
          <NcButton type="secondary" @click.prevent="$emit('close')">Abbrechen</NcButton>
          <NcButton type="primary" native-type="submit" :disabled="!valid">Speichern</NcButton>
        </div>
      </form>
    </div>
  </NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'

export default {
  name: 'ExpenseCreateModal',
  components: { NcModal, NcTextField, NcButton, NcSelect },
  props: {
    expense: { type: Object, default: null },
    bookMembers: { type: Array, default: () => [] },
    currentUserUid: { type: String, default: null },
  },
  data() {
    const today = new Date().toISOString().slice(0, 10)
    return {
      amountInput: this.expense ? this.formatAmount(this.expense.amount_cents / 100) : '',
      date: this.expense ? (this.expense.occurred_at || '').slice(0,10) : today,
      description: this.expense ? (this.expense.description || '') : '',
      selectedPayerUid: null,
    }
  },
  computed: {
    title() { return this.expense ? 'Ausgabe bearbeiten' : 'Neue Ausgabe' },
    amountValue() {
      const normalized = this.amountInput.replace(',', '.')
      const value = Number.parseFloat(normalized)
      return Number.isFinite(value) ? value : 0
    },
    valid() { return this.amountValue > 0 && !!this.date && !!this.selectedPayerUid },
    payerOptions() {
      return this.bookMembers.map(member => ({
        id: member.user_uid,
        label: member.display_name && member.display_name !== member.user_uid
          ? `${member.display_name} (${member.user_uid})`
          : member.user_uid,
      }))
    },
    selectedPayerOption() {
      return this.payerOptions.find(option => option.id === this.selectedPayerUid) || null
    },
  },
  watch: {
    payerOptions: {
      immediate: true,
      handler(options) {
        if (!options.length) {
          this.selectedPayerUid = null
          return
        }
        const preferredUid = this.expense?.user_uid || this.currentUserUid
        const preferred = options.find(option => option.id === preferredUid)
        this.selectedPayerUid = (preferred || options[0]).id
      },
    },
  },
  methods: {
    formatAmount(value) {
      return Number.isFinite(value) ? value.toFixed(2).replace('.', ',') : ''
    },
    onAmountInput(value) {
      const nextValue = value && typeof value === 'object' && 'target' in value
        ? value.target?.value
        : value
      this.amountInput = String(nextValue || '').replace(/\./g, ',')
    },
    onPayerChange(option) {
      this.selectedPayerUid = option?.id || null
    },
    onSave() {
      if (!this.valid) return
      const payload = {
        amount: this.amountValue,
        date: this.date,
        description: this.description,
        currency: 'EUR',
        user_uid: this.selectedPayerUid,
      }
      if (this.expense) payload.id = this.expense.id
      this.$emit('save', payload)
    },
  },
}
</script>

<style>
.modal-body { display: grid; gap: 12px; padding: 8px; }
.modal-body form { display: grid; gap: 12px; }
.actions { display: flex; gap: 8px; justify-content: flex-end; }
</style>
