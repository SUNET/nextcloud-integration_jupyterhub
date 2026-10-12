/**
 * SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { FileType, registerFileAction } from '@nextcloud/files'
import { generateUrl } from '@nextcloud/router'
import { createApp, h } from 'vue'
import WebappShareDialog from '../components/WebappShareDialog.vue'
import logger from '../logger.js'

const ACTION_ID = 'jupyter-share-webapp'

/**
 * Mount a sharing dialog and dispose it when closed.
 *
 * @param {import('@nextcloud/files').Node} node The folder to share.
 * @return {Promise<null>} Resolves when the dialog closes.
 */
function openShareDialog(node) {
	return new Promise((resolve) => {
		const mount = document.createElement('div')
		document.body.appendChild(mount)
		const app = createApp({
			render: () => h(WebappShareDialog, {
				path: node.path,
				name: node.basename,
				onClose: () => {
					app.unmount()
					mount.remove()
					resolve(null)
				},
			}),
		})
		app.mixin({ methods: { t, n } })
		app.mount(mount)
	})
}

/**
 * Register the notebook folder sharing action with Files.
 */
export function registerWebappFileAction() {
	registerFileAction({
		id: ACTION_ID,
		displayName: () => t('integration_jupyterhub', 'Share as JupyterHub webapp'),
		iconSvgInline: () => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
			+ '<path d="M12 2 4 6v6c0 5 3.5 9.5 8 10 4.5-.5 8-5 8-10V6l-8-4z" fill="none" stroke="currentColor" stroke-width="1.5"/>'
			+ '<circle cx="12" cy="11" r="2" fill="currentColor"/>'
			+ '</svg>',

		enabled: ({ nodes }) => {
			if (!nodes || nodes.length !== 1) {
				return false
			}
			return nodes[0].type === FileType.Folder
		},

		async exec({ nodes }) {
			const node = nodes[0]
			try {
				const { data } = await axios.get(generateUrl('/apps/integration_jupyterhub/api/v1/webapp-share/check'), {
					params: { path: node.path },
				})
				if (!data?.hasNotebook) {
					showError(t('integration_jupyterhub', 'This folder contains no Jupyter notebook (.ipynb) and cannot be shared as a JupyterHub webapp.'))
					return null
				}
			} catch (e) {
				logger.error('Failed to check notebook metadata', { error: e })
				showError(t('integration_jupyterhub', 'Could not check this folder for Jupyter notebooks. Please try again.'))
				return null
			}
			await openShareDialog(node)
			return null
		},

		order: 25,
	})
}
