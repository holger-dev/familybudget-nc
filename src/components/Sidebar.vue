<template>
  <NcAppSidebar :open="open" :no-toggle="true" @update:open="v => { if(!v) $emit('close') }" @close="$emit('close')">
    <NcAppSidebarTab id="settings" name="Einstellungen">
      <SettingsTabSidebar :book="book" @rename="$emit('rename', $event)" @delete="$emit('delete')" @imported="$emit('imported')" @exported="$emit('exported')" />
    </NcAppSidebarTab>
    <NcAppSidebarTab id="sharing" name="Teilen">
      <div class="pad">
        <Sharing :book-id="bookId" @invited="$emit('close')" />
      </div>
    </NcAppSidebarTab>
    <NcAppSidebarTab id="importexport" name="Import/Export">
      <ImportExportTab :book="book" @imported="$emit('imported')" @exported="$emit('exported')" />
    </NcAppSidebarTab>
  </NcAppSidebar>
</template>

<script>
import NcAppSidebar from '@nextcloud/vue/dist/Components/NcAppSidebar.js'
import NcAppSidebarTab from '@nextcloud/vue/dist/Components/NcAppSidebarTab.js'
import Sharing from './sidebar/Sharing.vue'
import SettingsTabSidebar from './sidebar/SettingsTabSidebar.vue'
import ImportExportTab from './sidebar/ImportExportTab.vue'

export default {
  name: 'Sidebar',
  components: { NcAppSidebar, NcAppSidebarTab, Sharing, SettingsTabSidebar, ImportExportTab },
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
