<template>
  <NcModal size="small" @close="$emit('close')">
    <div class="modal-body">
      <h3>{{ title }}</h3>
      <form @submit.prevent="onSave">
        <NcTextField v-model.number="amount" type="number" step="0.01" min="0" label="Betrag (EUR)" required />
        <NcTextField v-model="date" type="date" label="Datum" required />
        <NcTextField v-model="description" type="text" label="Beschreibung (optional)" />
        <div class="payer">Zahler: <strong>{{ payerLabel }}</strong></div>
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

export default {
  name: 'ExpenseCreateModal',
  components: { NcModal, NcTextField, NcButton },
  props: {
    expense: { type: Object, default: null },
  },
  data() {
    const today = new Date().toISOString().slice(0, 10)
    return {
      amount: this.expense ? (this.expense.amount_cents / 100) : null,
      date: this.expense ? (this.expense.occurred_at || '').slice(0,10) : today,
      description: this.expense ? (this.expense.description || '') : '',
    }
  },
  computed: {
    title() { return this.expense ? 'Ausgabe bearbeiten' : 'Neue Ausgabe' },
    valid() { return this.amount && this.amount > 0 && !!this.date },
    payerLabel() {
      // Backend uses the authenticated user; display generic info
      return 'Aktueller Benutzer'
    },
  },
  methods: {
    onSave() {
      if (!this.valid) return
      const payload = {
        amount: this.amount,
        date: this.date,
        description: this.description,
        currency: 'EUR',
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
.payer { color: #374151; }
.actions { display: flex; gap: 8px; justify-content: flex-end; }
</style>
