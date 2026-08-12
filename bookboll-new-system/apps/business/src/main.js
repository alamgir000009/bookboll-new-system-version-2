import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import { createHead } from '@unhead/vue'
import './style.css'
import App from './App.vue'
import { installUnsavedGuards } from './lib/unsavedGuard.js'
import { initAuth, authUser, authLoading } from './lib/auth.js'
import { i18n } from './lib/i18n.js'
import { initAutoTranslate } from './lib/autoTranslate.js'
import { adminDashboardUrl } from './lib/crossApp.js'
import { loadAdminBrand } from './lib/adminBrand.js'

import ForBusiness from './pages/ForBusiness.vue'
import ForBusinessPricing from './pages/ForBusinessPricing.vue'
import ForBusinessBlog from './pages/ForBusinessBlog.vue'
import ForBusinessBlogPost from './pages/ForBusinessBlogPost.vue'
import ProviderTerms from './pages/ProviderTerms.vue'
import Signup from './pages/Signup.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior() { return { top: 0 } },
  routes: [
    { path: '/', component: ForBusiness, meta: { indexable: true, allowAuthed: true } },
    { path: '/pricing', component: ForBusinessPricing, meta: { indexable: true, allowAuthed: true } },
    { path: '/blog', component: ForBusinessBlog, meta: { indexable: true, allowAuthed: true } },
    { path: '/blog/:slug', component: ForBusinessBlogPost, meta: { indexable: true, allowAuthed: true } },
    { path: '/terms', component: ProviderTerms, meta: { indexable: true, allowAuthed: true } },
    { path: '/signup', component: Signup },

    // Legacy path aliases (old bookboll.com/for-business/* bookmarks)
    { path: '/for-business', redirect: '/' },
    { path: '/for-business/pricing', redirect: '/pricing' },
    { path: '/for-business/blog', redirect: '/blog' },
    { path: '/for-business/blog/:slug', redirect: to => `/blog/${to.params.slug}` },
    { path: '/for-business/terms', redirect: '/terms' },
  ],
})

router.beforeEach(async (to) => {
  if (authLoading.value) await initAuth()
  // Signed-in providers opening /signup go to the admin dashboard
  if (to.path === '/signup' && authUser.value && !to.meta?.allowAuthed) {
    window.location.replace(adminDashboardUrl('/admin'))
    return false
  }
})

installUnsavedGuards(router)
loadAdminBrand()
createApp(App).use(router).use(i18n).use(createHead()).mount('#app')
initAutoTranslate()
