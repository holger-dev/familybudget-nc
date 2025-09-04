<template>
  <li class="app-navigation-entry-edit new-book-form">
    <form class="field-wrap" @submit.prevent="create">
      <NcTextField v-model="name" type="text" label="Neues Buch" placeholder="Neues Buch" @keyup.enter="create" />
      <NcButton class="inline-create" :disabled="!valid" native-type="submit" variant="tertiary" :ariaLabel="'Anlegen'">
        <template #icon>
          <Plus :size="20" />
        </template>
      </NcButton>
    </form>
  </li>
</template>

<script>
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import Plus from 'vue-material-design-icons/Plus.vue'
import { api } from '../../utils/api.js'

export default {
  name: 'NewBookForm',
  components: { NcTextField, NcButton, Plus },
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
      const res = await fetch(api('/books'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name }),
      })
      if (res.ok) {
        const b = await res.json()
        this.$emit('book-created', b)
        this.name = ''
      }
    },
  },
}
</script>

<style>
.new-book-form { padding: 8px 12px; }
.new-book-form .field-wrap { position: relative; }
.new-book-form .inline-create { position: absolute; top: 50%; right: 8px; transform: translateY(-50%); }
.new-book-form .inline-create .button-vue { padding: 6px; }
.new-book-form input { padding-right: 40px; }
</style>
