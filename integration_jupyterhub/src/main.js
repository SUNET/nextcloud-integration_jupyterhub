/**
 * SPDX-FileCopyrightText: 2025 Micke Nordin <kano@sunet.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { generateFilePath } from '@nextcloud/router'

import { createApp} from 'vue'
import App from './App'

// eslint-disable-next-line
__webpack_public_path__ = generateFilePath(appName, '', 'js/')

const app = createApp(App)

app.mixin({
    methods: {
        t,
        n
    }
})

app.mount('#content')
