<template>
  <div class="import-export">
    <div class="row">
      <div class="group">
        <NcButton @click="exportCsv" :disabled="!book">CSV exportieren</NcButton>
      </div>
      <div class="group">
        <label class="file-label">
          <input ref="file" type="file" accept=".csv,text/csv" @change="onFileChange" />
          <span>{{ fileName || 'CSV wählen…' }}</span>
        </label>
        <NcButton type="primary" :disabled="!fileBlob || importing || !book" @click="importCsv">CSV importieren</NcButton>
        <small class="hint">Ersetzt alle Ausgaben dieses Buchs.</small>
      </div>
    </div>
  </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import { ocsFetch } from '../../utils/api.js'
import { showSuccess, showError } from '../../utils/notify'

export default {
  name: 'ImportExportTab',
  components: { NcButton },
  props: {
    book: { type: Object, required: false, default: null },
  },
  data() {
    return {
      fileBlob: null,
      fileName: '',
      importing: false,
    }
  },
  methods: {
    onFileChange(e) {
      const f = e?.target?.files?.[0]
      if (f) { this.fileBlob = f; this.fileName = f.name } else { this.fileBlob = null; this.fileName = '' }
    },
    async exportCsv() {
      if (!this.book?.id) return
      try {
        const res = await ocsFetch(`/apps/familybudget/books/${this.book.id}/export.csv`, { headers: { 'Accept': 'text/csv' } })
        if (!res.ok) throw new Error('export')
        const blob = await res.blob()
        const url = URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = url
        a.download = `familybudget-book-${this.book.id}.csv`
        document.body.appendChild(a)
        a.click()
        URL.revokeObjectURL(url)
        a.remove()
        this.$emit('exported')
      } catch (_) { showError('Export fehlgeschlagen') }
    },
    async importCsv() {
      if (!this.book?.id || !this.fileBlob) return
      if (!window.confirm('CSV importieren und alle Ausgaben überschreiben?')) return
      this.importing = true
      try {
        const fd = new FormData()
        fd.append('file', this.fileBlob)
        const res = await ocsFetch(`/apps/familybudget/books/${this.book.id}/import`, { method: 'POST', body: fd })
        if (!res.ok) {
          let msg = 'Import fehlgeschlagen'
          try {
            const j = await res.json()
            const data = j?.ocs?.data || j
            if (Array.isArray(data?.errors) && data.errors.length) msg = data.errors.join('\n')
            else if (data?.message) msg = data.message
          } catch(_) {}
          throw new Error(msg)
        }
        showSuccess('Import erfolgreich')
        this.$emit('imported')
        this.fileBlob = null; this.fileName=''
        if (this.$refs.file) this.$refs.file.value = ''
      } catch (e) {
        showError(e?.message || 'Import fehlgeschlagen')
      } finally { this.importing = false }
    },
  },
}
</script>

<style>
.import-export { display: grid; gap: 12px; padding: 8px 16px; }
.row { display: grid; gap: 8px; }
.group { display:flex; gap:8px; align-items:center; flex-wrap: wrap; }
.file-label { display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border:1px solid var(--color-border);
  border-radius: 6px; cursor: pointer; }
.file-label input[type="file"] { display:none; }
.hint { color: var(--color-text-maxcontrast); opacity: .7; }
</style>

