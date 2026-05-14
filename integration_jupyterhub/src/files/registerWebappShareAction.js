/**
 * SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp, h } from 'vue'
import { registerFileAction, FileType } from '@nextcloud/files'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import WebappShareDialog from '../components/WebappShareDialog.vue'

const ACTION_ID = 'jupyter-share-webapp'

// Memoised per-page-load lookups: avoid re-asking the server about the
// same folder when the user opens the action menu repeatedly.
const notebookCache = new Map()

async function folderHasNotebook(node) {
  const path = node.path
  if (notebookCache.has(path)) {
    return notebookCache.get(path)
  }
  const probe = (async () => {
    try {
      const response = await axios.get(
        generateUrl('/apps/integration_jupyterhub/api/v1/has-notebooks'),
        { params: { path } },
      )
      return Boolean(response.data?.hasNotebook)
    } catch (e) {
      console.warn('integration_jupyterhub: hasNotebooks probe failed', e)
      return false
    }
  })()
  notebookCache.set(path, probe)
  const result = await probe
  notebookCache.set(path, result)
  return result
}

function openShareDialog(node) {
  return new Promise((resolve) => {
    const mount = document.createElement('div')
    document.body.appendChild(mount)
    let app
    const close = (result) => {
      app?.unmount()
      mount.remove()
      resolve(result)
    }
    app = createApp({
      render: () => h(WebappShareDialog, {
        path: node.path,
        name: node.basename,
        onClose: () => close(true),
        onError: () => close(false),
      }),
    })
    app.mixin({ methods: { t, n } })
    app.mount(mount)
  })
}

export function registerWebappShareAction() {
  registerFileAction({
    id: ACTION_ID,
    displayName: () => t('integration_jupyterhub', 'Share as JupyterHub webapp'),
    iconSvgInline: () =>
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
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
      if (!(await folderHasNotebook(node))) {
        const { showWarning } = await import('@nextcloud/dialogs')
        showWarning(t(
          'integration_jupyterhub',
          'This folder does not contain a Jupyter notebook.',
        ))
        return null
      }
      await openShareDialog(node)
      return null
    },

    order: 25,
  })
}
