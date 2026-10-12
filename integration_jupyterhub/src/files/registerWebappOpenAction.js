/**
 * SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { FileType, registerFileAction } from '@nextcloud/files'
import { generateUrl } from '@nextcloud/router'

const ACTION_ID = 'jupyter-open-webapp'

/**
 * Register the notebook launch action with Files.
 */
export function registerWebappOpenAction() {
	registerFileAction({
		id: ACTION_ID,
		displayName: () => t('integration_jupyterhub', 'Open in JupyterHub'),
		iconSvgInline: () => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
			+ '<path d="M5 3h14v18H5z" fill="none" stroke="currentColor" stroke-width="1.5"/>'
			+ '<path d="M9 8h6M9 12h6M9 16h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>'
			+ '</svg>',

		enabled: ({ nodes }) => {
			if (!nodes || nodes.length !== 1) {
				return false
			}
			return nodes[0].type === FileType.Folder
		},

		async exec() {
			window.location.href = generateUrl('/apps/integration_jupyterhub/')
			return null
		},

		order: 26,
	})
}
