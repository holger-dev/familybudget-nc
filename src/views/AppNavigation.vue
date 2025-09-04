<template>
  <NcAppNavigation>
    <template #list>
      <NewBookForm @book-created="onBookCreated" />

      <BookListItem
        v-for="b in store.books"
        :key="b.id"
        :book="b"
        :active="b.id === store.currentBookId"
        @select="selectBook"
        @open-details="openDetails"
      />
    </template>

    
  </NcAppNavigation>
</template>

<script>
import NcAppNavigation from '@nextcloud/vue/dist/Components/NcAppNavigation.js'
// no action menu components needed here
import BookListItem from '../components/navigation/BookListItem.vue'
import NewBookForm from '../components/navigation/NewBookForm.vue'

export default {
  name: 'AppNavigation',
  components: { NcAppNavigation, BookListItem, NewBookForm },
  inject: ['store'],
  data() {
    return {
    }
  },
  methods: {
    async selectBook(id) { this.store.currentBookId = id; this.$emit('select-book', id) },
    openDetails(book) { this.$emit('open-details', book) },
    onBookCreated(book) { this.store.books.push(book); this.selectBook(book.id) },
  },
}
</script>

<style>
/* no extra styles needed; NewBookForm encapsulates its styles */
</style>
