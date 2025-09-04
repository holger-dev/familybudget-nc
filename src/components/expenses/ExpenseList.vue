<template>
  <section class="expense-months" v-if="groups.length">
    <div v-for="m in groups" :key="m.key" class="month">
      <div class="month-header" @click="toggle(m.key)" :aria-expanded="isOpen(m.key)" role="button" tabindex="0" @keydown.enter.prevent="toggle(m.key)" @keydown.space.prevent="toggle(m.key)">
        <h3 class="month-title">{{ m.label }}</h3>
        <div class="summary">
          <span class="total">Gesamt: <strong>{{ centsToEuro(m.total) }} €</strong></span>
          <span
            v-for="u in m.perUser"
            :key="u.user"
            class="per-user"
            :class="{ me: currentUserUid && u.user === currentUserUid }"
            :title="(currentUserUid && u.user === currentUserUid) ? 'Dein Anteil' : ''">
            {{ u.user }}: {{ centsToEuro(u.total) }} €
          </span>
          <span
            v-for="s in m.settlements"
            :key="s.from + '-' + s.to"
            class="settle"
            :class="settleClass(s)"
            :title="settlementTitle(s)">
            <span class="names">{{ s.from }} → {{ s.to }}</span>
            <span class="sep">—</span>
            <span class="amt">{{ centsToEuro(s.amount) }} €</span>
          </span>
        </div>
        <div class="chev">
          <ChevronDown v-if="isOpen(m.key)" :size="18" />
          <ChevronRight v-else :size="18" />
        </div>
      </div>

      <ul class="expense-list cards" v-show="isOpen(m.key)">
        <li v-for="e in m.items" :key="e.id" class="expense-row card">
          <div class="col date">{{ formatDateYMDToDMY(e.occurred_at) }}</div>
          <div class="col desc">
            <div class="name">{{ e.description || '—' }}</div>
            <div class="by">von {{ e.user_uid }}</div>
          </div>
          <div class="col amount">
            <span class="amount-badge">{{ centsToEuro(e.amount_cents) }} €</span>
            <NcButton class="icon-btn" variant="tertiary" :ariaLabel="'Bearbeiten'" @click="$emit('edit-expense', e)">
              <template #icon><Pencil :size="18" /></template>
            </NcButton>
            <NcButton class="icon-btn" variant="tertiary" :ariaLabel="'Löschen'" @click="$emit('delete-expense', e)">
              <template #icon><Delete :size="18" /></template>
            </NcButton>
          </div>
        </li>
      </ul>
    </div>
  </section>
  <NcEmptyContent v-else name="Keine Ausgaben">
    <template #description>
      <slot name="empty" />
    </template>
  </NcEmptyContent>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'

export default {
  name: 'ExpenseList',
  components: { NcEmptyContent, NcButton, Pencil, Delete, ChevronDown, ChevronRight },
  props: {
    expenses: { type: Array, default: () => [] },
    currentUserUid: { type: String, default: null },
  },
  computed: {
    groups() {
      const map = new Map()
      for (const e of this.expenses) {
        const ym = e.occurred_at.substring(0, 7)
        if (!map.has(ym)) map.set(ym, [])
        map.get(ym).push(e)
      }
      const res = []
      const sorted = Array.from(map.entries()).sort((a, b) => b[0].localeCompare(a[0]))
      for (const [ym, items] of sorted) {
        const total = items.reduce((s, x) => s + x.amount_cents, 0)
        const perUserMap = new Map()
        for (const x of items) perUserMap.set(x.user_uid, (perUserMap.get(x.user_uid) || 0) + x.amount_cents)
        const perUser = Array.from(perUserMap.entries()).map(([user, total]) => ({ user, total }))
        const settlements = this.computeSettlements(perUser, total)
        res.push({ key: ym, label: this.formatMonthYear(ym), items, total, perUser, settlements })
      }
      return res
    },
  },
  methods: {
    centsToEuro(cents) { return (cents / 100).toFixed(2) },
    formatDateYMDToDMY(dateStr) {
      const s = (dateStr || '').slice(0, 10)
      const [y, m, d] = s.split('-')
      if (!y || !m || !d) return dateStr
      return `${d}.${m}.${y}`
    },
    formatMonthYear(ym) {
      const [y, m] = (ym || '').split('-')
      const names = ['Jan.', 'Feb.', 'Mär.', 'Apr.', 'Mai', 'Jun.', 'Jul.', 'Aug.', 'Sep.', 'Okt.', 'Nov.', 'Dez.']
      const mi = Math.max(1, Math.min(12, parseInt(m, 10) || 1))
      return `${names[mi - 1]} ${y}`
    },
    isOpen(key) { return !!this.openMonths[key] },
    toggle(key) {
      const next = !this.isOpen(key)
      this.$set(this.openMonths, key, next)
    },
    computeSettlements(perUser, total) {
      if (!perUser.length || total === 0) return []
      const n = perUser.length; const share = Math.round(total / n)
      const creditors = []; const debtors = []
      for (const { user, total: t } of perUser) { const net = t - share; if (net > 0) creditors.push({ user, amount: net }); else if (net < 0) debtors.push({ user, amount: -net }) }
      creditors.sort((a, b) => b.amount - a.amount); debtors.sort((a, b) => b.amount - a.amount)
      const settlements = []; let i = 0, j = 0
      while (i < debtors.length && j < creditors.length) { const pay = Math.min(debtors[i].amount, creditors[j].amount); settlements.push({ from: debtors[i].user, to: creditors[j].user, amount: pay }); debtors[i].amount -= pay; creditors[j].amount -= pay; if (debtors[i].amount === 0) i++; if (creditors[j].amount === 0) j++ }
      return settlements
    },
    settleClass(s) {
      if (!s || !this.currentUserUid) return {}
      if (s.from === this.currentUserUid) return { debtor: true }
      if (s.to === this.currentUserUid) return { creditor: true }
      return {}
    },
    settlementTitle(s) {
      if (!this.currentUserUid) return `${s.from} zahlt ${s.to}`
      const amt = `${this.centsToEuro(s.amount)} €`
      if (s.from === this.currentUserUid) return `Du zahlst ${amt} an ${s.to}`
      if (s.to === this.currentUserUid) return `Du bekommst ${amt} von ${s.from}`
      return `${s.from} zahlt ${amt} an ${s.to}`
    },
  },
  data() {
    return { openMonths: {} }
  },
  watch: {
    groups: {
      immediate: true,
      handler(g) {
        if (!g || !g.length) return
        const existing = this.openMonths || {}
        const newOpen = {}
        const keys = g.map(x => x.key)
        for (const k of keys) {
          if (existing[k]) newOpen[k] = true
        }
        if (Object.keys(newOpen).length === 0 && keys.length) newOpen[keys[0]] = true
        this.openMonths = newOpen
      },
    },
  },
}
</script>

<style>
.expense-months { display: grid; gap: 16px; max-width: 900px; margin: 0 auto; padding: 0 8px; }
.expense-months .month-header { display: flex; gap: 16px; align-items: baseline; flex-wrap: wrap; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-background-darker); position: relative; cursor: pointer; }
.expense-months .month-header::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: var(--color-primary); border-top-left-radius: 8px; border-bottom-left-radius: 8px; }
.expense-months .month-title { margin: 0; }
.expense-months .summary { display: flex; gap: 12px; flex-wrap: wrap; color: var(--color-text-lighter); align-items: center; }
.expense-months .summary .total { font-weight: 700; color: var(--color-main-text); font-size: 1.1rem; }
.expense-months .summary .per-user { font-size: 12px; opacity: 0.9; }
.expense-months .summary .per-user.me { font-weight: 700; color: var(--color-primary); }
.expense-months .summary .settle { display: inline-flex; align-items: center; gap: 6px; padding: 2px 8px; border-radius: 9999px; background: var(--color-primary-element-light); color: var(--color-main-text); border: 1px solid var(--color-primary-element); }
.expense-months .summary .settle .amt { font-weight: 700; letter-spacing: .1px; }
.expense-months .summary .settle.creditor { border-color: var(--color-success); color: var(--color-success); background: var(--color-background-hover); }
.expense-months .summary .settle.debtor { border-color: var(--color-error); color: var(--color-error); background: var(--color-background-hover); }
.expense-months .chev { margin-left: auto; display: flex; align-items: center; }
.expense-months .expense-list { list-style: none; padding: 0; margin: 8px 0 0; width: 100%; }
.expense-months .expense-list.cards { display: grid; gap: 8px; }
.expense-months .expense-row { display: grid; grid-template-columns: 110px 1fr 220px; gap: 12px; padding: 12px 16px; align-items: center; }
.expense-months .expense-row.card { background: var(--color-background-dark); border: 1px solid var(--color-border); border-radius: 8px; }
.expense-months .expense-row.card:nth-child(even) { background: var(--color-background-hover); }
.expense-months .expense-row .desc .name { font-weight: 500; }
.expense-months .expense-row .desc .by { color: var(--color-text-lighter); font-size: 12px; }
.expense-months .amount { display: flex; align-items: center; justify-content: flex-end; gap: 6px; text-align: right; font-variant-numeric: tabular-nums; flex-wrap: nowrap; }
.expense-months .amount-badge { display: inline-block; padding: 2px 8px; border-radius: 9999px; background: var(--color-primary-element-light); color: var(--color-main-text); white-space: nowrap; }
.expense-months .amount .icon-btn .button-vue { padding: 4px; }
.expense-row .desc .name { font-weight: 500; }
.expense-row .desc .by { color: #6b7280; font-size: 12px; }
.amount { text-align: right; font-variant-numeric: tabular-nums; }
</style>
