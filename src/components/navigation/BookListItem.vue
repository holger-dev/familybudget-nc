<template>
  <NcAppNavigationItem
    :name="book.name"
    :active="active"
    @click="$emit('select', book.id)">
    <template #subtitle>
      <span v-if="book.role === 'owner'">Owner</span>
    </template>
    <template #actions>
      <NcActionButton :close-after-click="true" :ariaLabel="'Einstellungen'" @click.stop="$emit('open-details', book)">
        <template #icon>
          <Pencil :size="20" />
        </template>
      </NcActionButton>
    </template>
  </NcAppNavigationItem>
  
</template>

<script>
import NcAppNavigationItem from '@nextcloud/vue/dist/Components/NcAppNavigationItem.js'
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'
import Pencil from 'vue-material-design-icons/Pencil.vue'

export default {
  name: 'BookListItem',
  components: { NcAppNavigationItem, NcActionButton, Pencil },
  props: {
    book: { type: Object, required: true },
    active: { type: Boolean, default: false },
  },
}
</script>

<style>
/* keep styles minimal; inherit Nextcloud styles */
</style>
