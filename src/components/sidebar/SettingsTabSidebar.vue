<template>
  <div class="settings">
    <h3 class="sr-only">Einstellungen</h3>
    <div class="row">
      <NcTextField v-model="localName" label="Buchname" @keyup.enter="save" />
      <NcButton type="primary" :disabled="!dirty" @click="save">Speichern</NcButton>
    </div>
    <div class="row danger">
      <NcButton type="error" :aria-label="'Buch löschen'" @click="confirmDelete">Buch löschen</NcButton>
    </div>
    <slot />
  </div>
</template>

<script>
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

export default {
  name: 'SettingsTabSidebar',
  components: { NcTextField, NcButton },
  props: {
    book: { type: Object, required: false, default: null },
  },
  data() {
    return {
      localName: this.book?.name || '',
      dirty: false,
    }
  },
  watch: {
    localName() { this.dirty = (this.localName || '') !== (this.book?.name || '') },
    book: {
      immediate: true,
      handler(b) { this.localName = b?.name || ''; this.dirty = false },
    },
  },
  methods: {
    async save() {
      // Placeholder: rename is not wired yet; just emit for now
      this.$emit('rename', this.localName)
      this.dirty = false
    },
    confirmDelete() {
      if (window.confirm('Buch wirklich löschen? Alle Ausgaben gehen verloren.')) {
        this.$emit('delete')
      }
    },
  },
}
</script>

<style>
.settings { display: grid; gap: 12px; padding: 8px 16px; }
.row { display: grid; gap: 8px; }
.row.danger { border-top: 1px solid var(--color-border); padding-top: 12px; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
</style>
