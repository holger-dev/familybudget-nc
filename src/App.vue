<template>
  <NcContent app-name="familybudget">
    <AppNavigation @select-book="onSelectBook" @open-details="openDetails" />

    <NcAppContent>
      <div class="content-header">
        <h2 v-if="currentBook" class="title">{{ currentBook.name }}</h2>
        <div class="actions">
          <NcButton type="primary" @click="showCreateModal = true">Neue Ausgabe</NcButton>
        </div>
      </div>
      <ExpenseFilters v-model="filters" :expenses="store.expenses" />
        <ExpenseList :expenses="filteredExpenses" :current-user-uid="currentUid" @delete-expense="deleteExpense" @edit-expense="openEditModal">
          <template #empty>
            <span v-if="!store.currentBookId">Wähle links ein Buch oder lege eines an.</span>
            <span v-else>Keine Ausgaben in diesem Buch.</span>
          </template>
        </ExpenseList>
    </NcAppContent>

    <!-- Sidebar should be a sibling of NcAppContent like in Cospend -->
    <Sidebar
      :open="sidebarOpen"
      :book-id="store.currentBookId"
      :book="currentBook"
      @close="sidebarOpen = false"
      @rename="onRenameBook"
      @delete="onDeleteBook"
      @imported="onImported"
    />
    <ExpenseCreateModal v-if="showCreateModal" :expense="editingExpense" @close="closeExpenseModal" @save="saveExpense" />
  </NcContent>
</template>

<script>
import NcContent from '@nextcloud/vue/dist/Components/NcContent.js'
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import AppNavigation from './views/AppNavigation.vue'
// no NcActions/NcSelectUsers here; they live in dedicated components
import Sidebar from './components/Sidebar.vue'
// duplicates removed below
import ExpenseCreateModal from './components/expenses/ExpenseCreateModal.vue'
import ExpenseList from './components/expenses/ExpenseList.vue'
import ExpenseFilters from './components/expenses/ExpenseFilters.vue'
import { showSuccess, showError } from './utils/notify'
import { api, apiFetch } from './utils/api.js'
// (no duplicate imports)

export default {
  name: 'App',
  components: { NcContent, NcAppContent, NcEmptyContent, NcButton, AppNavigation, Sidebar, ExpenseCreateModal, ExpenseList, ExpenseFilters },
  inject: ['store'],
  data() {
    return {
      showCreateModal: false,
      filters: { query: '', user: null, from: '', to: '' },
      sidebarOpen: false,
      shareUsers: [],
      editingExpense: null,
      currentUid: null,
    }
  },
  computed: {
    currentBook() {
      return this.store.books.find(b => b.id === this.store.currentBookId)
    },
    filteredExpenses() {
      const q = (this.filters.query || '').toLowerCase()
      const u = this.filters.user?.value || this.filters.user || null
      const from = this.filters.from ? this.filters.from + ' 00:00:00' : null
      const to = this.filters.to ? this.filters.to + ' 23:59:59' : null
      return this.store.expenses
        .filter(e => (q ? ((e.description || '').toLowerCase().includes(q) || (e.user_uid || '').toLowerCase().includes(q)) : true))
        .filter(e => (u ? e.user_uid === u : true))
        .filter(e => (from ? e.occurred_at >= from : true))
        .filter(e => (to ? e.occurred_at <= to : true))
    },
  },
  methods: {
    centsToEuro(cents) { return (cents / 100).toFixed(2) },
    onSelectBook() { this.loadExpenses() },
    async createBook() { /* legacy, unused here */ },
    async invite() { /* legacy, unused here */ },
    async loadBooks() {
      const res = await apiFetch('/books')
      if (res.ok) { const j = await res.json(); this.store.books = j.books; if (!this.store.currentBookId && this.store.books.length) this.store.currentBookId = this.store.books[0].id }
    },
    async loadExpenses() {
      if (!this.store.currentBookId) return
      const res = await apiFetch(`/books/${this.store.currentBookId}/expenses`)
      if (res.ok) { const j = await res.json(); this.store.expenses = j.expenses }
    },
    async saveExpense(payload) {
      if (!this.store.currentBookId) return
      try {
        if (payload.id) {
          const { id, ...rest } = payload
          const res = await apiFetch(`/books/${this.store.currentBookId}/expenses/${id}`, { method:'PATCH', body: rest })
          if (!res.ok) throw new Error('update')
          showSuccess('Ausgabe aktualisiert')
        } else {
          const res = await apiFetch(`/books/${this.store.currentBookId}/expenses`, { method:'POST', body: payload })
          if (!res.ok) throw new Error('create')
          showSuccess('Ausgabe gespeichert')
        }
        this.showCreateModal = false
        this.editingExpense = null
        await this.loadExpenses()
      } catch (e) { showError('Speichern fehlgeschlagen') }
    },
    closeExpenseModal() { this.showCreateModal = false; this.editingExpense = null },
    openEditModal(e) { this.editingExpense = e; this.showCreateModal = true },
    async deleteExpense(e) {
      if (!this.store.currentBookId || !e?.id) return
      if (!window.confirm('Ausgabe wirklich löschen?')) return
      try {
        const res = await apiFetch(`/books/${this.store.currentBookId}/expenses/${e.id}`, { method:'DELETE' })
        if (!res.ok) throw new Error('delete')
        showSuccess('Ausgabe gelöscht')
        await this.loadExpenses()
      } catch (_) { showError('Löschen fehlgeschlagen') }
    },
    sendInvites() {
      if (!this.store.currentBookId || !this.shareUsers.length) return
      const invites = this.shareUsers.map(u => (typeof u === 'string' ? u : (u?.id || u?.uid || u?.value))).filter(Boolean)
      Promise.all(invites.map(uid => apiFetch(`/books/${this.store.currentBookId}/invite`, { method:'POST', body: { user: uid } })))
        .then(() => { this.shareUsers = []; this.sidebarOpen = false })
    },
    openDetails(book) {
      if (book && book.id) {
        // When opening from pencil in the nav, ensure the clicked book is selected
        this.store.currentBookId = book.id
      }
      this.sidebarOpen = true
    },
    onRenameBook(newName) {
      const b = this.currentBook
      if (!b) return
      const name = (newName || '').trim()
      if (!name || name === b.name) {
        this.sidebarOpen = false
        return
      }
      // Persist to backend, then update local store
      apiFetch(`/books/${b.id}/rename`, { method: 'POST', body: { name } }).then(async (res) => {
        if (!res.ok) throw new Error('rename failed')
        b.name = name
        this.sidebarOpen = false
        showSuccess('Name aktualisiert')
      }).catch(() => {
        showError('Aktualisieren fehlgeschlagen')
        // keep sidebar open on error to allow retry
      })
    },
    async onDeleteBook() {
      if (!this.store.currentBookId) return
      try {
        const id = this.store.currentBookId
        const res = await apiFetch(`/books/${id}`, { method:'DELETE' })
        if (!res.ok) throw new Error('delete book')
        // remove from store
        this.store.books = this.store.books.filter(b => b.id !== id)
        this.store.currentBookId = this.store.books.length ? this.store.books[0].id : null
        this.sidebarOpen = false
        await this.loadExpenses()
        showSuccess('Buch gelöscht')
      } catch (_) { showError('Buch löschen fehlgeschlagen') }
    },
    async onImported() {
      await this.loadExpenses()
    },
  },
  async mounted() {
    await this.loadBooks();
    if (this.store.currentBookId) await this.loadExpenses();
    try {
      const res = await fetch('/ocs/v2.php/cloud/user?format=json', { headers: { 'OCS-APIREQUEST': 'true', 'Accept': 'application/json' } })
      if (res.ok) { const j = await res.json(); this.currentUid = j?.ocs?.data?.id || null }
    } catch (_) {}
  },
}
</script>

<style>
 .content-header { display:flex; align-items:center; justify-content: space-between; gap: 12px; margin: 6px auto 12px; max-width: 900px; padding: 0 8px; }
 .content-header .title { margin: 0; }
 .content-header .actions { display: flex; gap: 8px; margin-top: 6px; }
.add-book { display: flex; gap: 8px; margin-top: 8px; }
.add-book input { flex: 1; }
.toolbar { display:flex; align-items:center; justify-content: space-between; margin-bottom:12px; }
.empty { color:#6b7280; padding: 24px; }

.share { display: flex; gap: 8px; align-items: center; margin: 8px 0 16px; }
</style>
