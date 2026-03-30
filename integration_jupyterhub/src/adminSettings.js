/**
 * SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import AdminSettings from './components/AdminSettings.vue'

const app = createApp(AdminSettings)
app.mixin({ methods: { t, n } })
app.mount('#jupyterhub_prefs')
