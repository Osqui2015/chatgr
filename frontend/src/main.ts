import { createPinia } from 'pinia'
import { createApp } from 'vue'

import App from './App.vue'
import router from './router'
import { bootstrapCsrf } from './services/http'
import './styles/main.css'

const app = createApp(App)

app.use(createPinia())
app.use(router)

app.mount('#app')

// Prime the XSRF-TOKEN cookie before the first form submission.
bootstrapCsrf()
