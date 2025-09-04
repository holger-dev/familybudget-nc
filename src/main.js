import Vue from 'vue'
import App from './App.vue'

// Ensure Nextcloud global app name is set for libraries expecting it
if (typeof window !== 'undefined') {
  window.appName = 'familybudget'
  // Server app id used for API base
  window.familyAppId = 'familybudget'
}

// Simple global store (reactive in Vue 2)
const store = Vue.observable({
  books: [],
  currentBookId: null,
  expenses: [],
})

new Vue({
  provide: { store },
  render: h => h(App),
}).$mount('.app-familybudget')
