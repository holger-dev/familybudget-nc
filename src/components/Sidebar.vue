<template>
  <NcAppSidebar :open="open" @update:open="v => { if(!v) $emit('close') }" @close="$emit('close')">
    <NcAppSidebarTab id="sharing" name="Teilen">
      <div class="pad">
        <Sharing :book-id="bookId" @invited="$emit('close')" />
      </div>
    </NcAppSidebarTab>
    <NcAppSidebarTab id="settings" name="Einstellungen">
      <SettingsTabSidebar :book="book" @rename="$emit('rename', $event)" @delete="$emit('delete')" />
    </NcAppSidebarTab>
  </NcAppSidebar>
</template>

<script>
import NcAppSidebar from '@nextcloud/vue/dist/Components/NcAppSidebar.js'
import NcAppSidebarTab from '@nextcloud/vue/dist/Components/NcAppSidebarTab.js'
import Sharing from './sidebar/Sharing.vue'
import SettingsTabSidebar from './sidebar/SettingsTabSidebar.vue'

export default {
  name: 'Sidebar',
  components: { NcAppSidebar, NcAppSidebarTab, Sharing, SettingsTabSidebar },
  props: {
    open: { type: Boolean, default: false },
    bookId: { type: [String, Number], required: false },
    book: { type: Object, required: false, default: null },
  },
  methods: {},
}
</script>

<style>
.pad { padding: 8px 16px; }
</style>
