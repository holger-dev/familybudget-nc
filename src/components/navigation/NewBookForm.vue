<template>
  <li class="app-navigation-entry-edit new-book-form">
    <form class="field-wrap" @submit.prevent="create">
      <NcTextField v-model="name" type="text" label="Neues Buch" placeholder="Neues Buch" @keyup.enter="create" />
      <NcButton class="create-button" :disabled="!valid" native-type="submit" variant="primary" :ariaLabel="'Buch anlegen'">
        Buch anlegen
      </NcButton>
    </form>
  </li>
</template>

<script>
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import { apiFetch } from '../../utils/api.js'
import { showError } from '../../utils/notify'

export default {
  name: 'NewBookForm',
  components: { NcTextField, NcButton },
  inject: ['store'],
  data() {
    return { name: '' }
  },
  computed: {
    valid() { return this.name.trim().length > 0 },
  },
  methods: {
    async create() {
      const name = this.name.trim(); if (!name) return
      const res = await apiFetch('/books', { method: 'POST', body: { name } })
      if (res.ok) {
        const b = await res.json()
        this.$emit('book-created', b)
        this.name = ''
      } else {
        let detail = ''
        try {
          const j = await res.json()
          if (j?.detail) detail = ` (${j.detail})`
          else if (j?.message) detail = ` (${j.message})`
        } catch (_) {}
        showError(`Buch anlegen fehlgeschlagen${detail}`)
      }
    },
  },
}
</script>

<style>
.new-book-form { padding: 8px 12px; }
.new-book-form .field-wrap { display: flex; flex-direction: column; gap: 8px; }
.new-book-form .create-button { width: 100%; justify-content: center; }
</style>
