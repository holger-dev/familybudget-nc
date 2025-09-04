<template>
  <div class="filters">
    <div class="search-filter">
      <NcTextField v-model="local.query" label="Suche" placeholder="Beschreibung durchsuchen" @input="emit" />
    </div>
    <div class="user-filter">
      <NcSelect
        class="user-select"
        :options="userOptions"
        label="label"
        :clearable="true"
        :value="selectedOption"
        :append-to-body="false"
        :aria-label-combobox="'Benutzer filtern'"
        @input="onUserChange" />
    </div>
    <NcTextField v-model="local.from" type="date" label="Von" @input="emit" />
    <NcTextField v-model="local.to" type="date" label="Bis" @input="emit" />
    <NcButton variant="tertiary" @click="reset" aria-label="Filter zurücksetzen">Zurücksetzen</NcButton>
  </div>
</template>

<script>
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

export default {
  name: 'ExpenseFilters',
  components: { NcTextField, NcSelect, NcButton },
  props: {
    value: { type: Object, required: true },
    expenses: { type: Array, default: () => [] },
  },
  data() {
    return { local: { query: '', user: null, from: '', to: '' } }
  },
  computed: {
    userOptions() {
      const set = new Set(this.expenses.map(e => e.user_uid).filter(Boolean))
      return Array.from(set).sort().map(u => ({ id: u, label: u }))
    },
    selectedOption() {
      const uid = this.local.user || null
      return uid ? { id: uid, label: uid } : null
    },
  },
  watch: {
    value: {
      deep: true,
      immediate: true,
      handler(v) { this.local = { ...{ query: '', user: null, from: '', to: '' }, ...(v || {}) } },
    },
  },
  methods: {
    onUserChange(val) { this.local.user = val ? (val.id || val.value || val) : null; this.emit() },
    emit() { this.$emit('input', { ...this.local }) },
    reset() { this.local = { query: '', user: null, from: '', to: '' }; this.emit() },
  },
}
</script>

<style>
.filters {
  display: grid;
  grid-template-columns: 240px 220px 140px 140px 120px;
  gap: 2px;
  align-items: end;
  max-width: 900px;
  margin: 8px auto 12px;
  padding: 0 8px;
}
.filters > * { min-width: 0; }
.filters .search-filter { max-width: 240px; width: 100%; }
.filters .search-filter input { width: 100%; }
/* Ensure the select respects its grid cell and does not overflow */
.filters .user-filter { width: 100%; overflow: hidden; }
.filters .user-filter .multiselect { width: 100% !important; min-width: 0 !important; }
.filters .user-filter .multiselect__tags { width: 100%; overflow: hidden; }
.filters .user-filter .multiselect__single { text-overflow: ellipsis; overflow: hidden; white-space: nowrap; }

@media (max-width: 860px) {
  .filters { grid-template-columns: 1fr 1fr; }
}
</style>
