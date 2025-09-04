<template>
  <div class="sharing">
    <NcSelect
      v-model="selectedSharee"
      class="shareInput"
      :aria-label-combobox="'Benutzer suchen'"
      label="displayName"
      :placeholder="'Benutzer suchen'"
      :options="formattedSharees"
      :filterable="false"
      :clear-search-on-blur="() => false"
      :append-to-body="false"
      @search="asyncFind"
    />
    <NcButton type="primary" :disabled="!selectedSharee" @click="inviteFromSelect">Einladen</NcButton>
    <ul class="member-list" v-if="members.length">
      <li v-for="m in members" :key="m.user_uid" class="member-row">
        <span class="uid">{{ m.display_name || m.user_uid }}</span>
        <span class="spacer" />
        <span class="role">{{ m.role }}</span>
        <NcButton
          v-if="m.role !== 'owner'"
          class="icon-btn"
          variant="tertiary"
          :ariaLabel="'Entfernen'"
          @click="removeMember(m)">
          <template #icon><DeleteIcon :size="18" /></template>
        </NcButton>
      </li>
    </ul>
  </div>
</template>

<script>
 import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
 import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
 import DeleteIcon from 'vue-material-design-icons/Delete.vue'
 import { apiFetch } from '../../utils/api.js'
import { showSuccess, showError } from '../../utils/notify'

export default {
  name: 'Sharing',
  components: { NcSelect, NcButton, DeleteIcon },
  props: {
    bookId: { type: [String, Number], required: true },
  },
  data() {
    return { selectedSharee: null, sharees: [], members: [], currentUid: null }
  },
  methods: {
    async inviteFromSelect() {
      if (!this.bookId) return
      const ids = this.selectedSharee ? [this.selectedSharee.uid] : []
      try {
        await Promise.all(ids.map(uid => apiFetch(`/books/${this.bookId}/invite`, { method: 'POST', body: { user: uid } })))
        this.selectedSharee = null
        await this.loadMembers()
        showSuccess('Einladung gesendet')
        this.$emit('invited')
      } catch (e) {
        showError('Einladung fehlgeschlagen')
      }
    },
    async asyncFind(query) {
      if (!query) { this.sharees = []; return }
      try {
        const url = `/ocs/v2.php/core/autocomplete/get?format=json&search=${encodeURIComponent(query)}&itemType=%20&itemId=%20&shareTypes[]=${0}`
        const res = await fetch(url, { headers: { 'OCS-APIREQUEST': 'true', 'Accept': 'application/json' } })
        if (!res.ok) { this.sharees = []; return }
        const j = await res.json()
        const data = Array.isArray(j?.ocs?.data) ? j.ocs.data : []
        const users = data.filter(s => s.source === 'users')
        this.sharees = users.map(s => ({ uid: s.id, displayName: s.id !== s.label ? `${s.label} (${s.id})` : s.label }))
      } catch (e) {
        this.sharees = []
      }
    },
    async loadMembers() {
      try {
        if (!this.bookId) return
        const res = await apiFetch(`/books/${this.bookId}/members`)
        if (res.ok) {
          const j = await res.json(); this.members = j.members || []
        }
      } catch (_) { /* ignore */ }
    },
    async removeMember(m) {
      if (!this.bookId || !m?.user_uid) return
      if (this.currentUid && m.user_uid === this.currentUid) { showError('Du kannst dich nicht selbst entfernen'); return }
      if (!window.confirm(`Benutzer ${m.display_name || m.user_uid} entfernen?`)) return
      try {
        const res = await apiFetch(`/books/${this.bookId}/members/${encodeURIComponent(m.user_uid)}`, { method: 'DELETE' })
        if (!res.ok) throw new Error('remove')
        await this.loadMembers()
        showSuccess('Benutzer entfernt')
      } catch (_) { showError('Entfernen fehlgeschlagen') }
    },
    async loadCurrentUser() {
      try {
        const res = await fetch('/ocs/v2.php/cloud/user?format=json', { headers: { 'OCS-APIREQUEST': 'true', 'Accept': 'application/json' } })
        if (res.ok) { const j = await res.json(); this.currentUid = j?.ocs?.data?.id || null }
      } catch (_) {}
    },
  },
  watch: {
    bookId: { immediate: true, handler(v) { if (v) this.loadMembers() } },
  },
  mounted() { this.loadCurrentUser() },
  computed: {
    formattedSharees() { return this.sharees },
  },
}
</script>

<style>
.sharing { display: grid; gap: 8px; }
.divider { text-align: center; color: var(--color-text-lighter); font-size: 12px; }
.manual { display: grid; gap: 8px; }
.member-list { list-style: none; padding: 0; margin: 8px 0 0; display: grid; gap: 4px; }
.member-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 8px; border: 1px solid var(--color-border); border-radius: 6px; background: var(--color-background-dark); }
.member-row .uid { font-weight: 500; }
.member-row .role { color: var(--color-text-lighter); font-size: 12px; }
</style>
