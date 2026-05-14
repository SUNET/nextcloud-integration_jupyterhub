/**
 * SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp, h } from 'vue'
import { registerFileAction, FileType } from '@nextcloud/files'
import { registerDavProperty } from '@nextcloud/files/dav'
import WebappShareDialog from '../components/WebappShareDialog.vue'

const ACTION_ID = 'jupyter-share-webapp'

// Opt in to NC's custom DAV property so PROPFIND responses include the
// metadata key our backend computes via IFilesMetadataManager. After
// this, every folder's attributes carries `metadata-jupyter-has-notebook`
// (a boolean) and we can decide synchronously whether to show the
// action — no extra HTTP probes.
registerDavProperty('nc:metadata-jupyter-has-notebook', { nc: 'http://nextcloud.org/ns' })

function hasNotebook(node) {
  // node.attributes maps the DAV property local-name -> value. The
  // value comes back as a string "0"/"1" off the wire; coerce.
  const raw = node?.attributes?.['metadata-jupyter-has-notebook']
  if (raw === undefined || raw === null) {
    return false
  }
  if (typeof raw === 'boolean') {
    return raw
  }
  if (typeof raw === 'number') {
    return raw !== 0
  }
  const s = String(raw).toLowerCase()
  return s === '1' || s === 'true' || s === 'yes'
}

function openShareDialog(node) {
  return new Promise((resolve) => {
    const mount = document.createElement('div')
    document.body.appendChild(mount)
    let app
    const close = () => {
      app?.unmount()
      mount.remove()
      resolve(null)
    }
    app = createApp({
      render: () => h(WebappShareDialog, {
        path: node.path,
        name: node.basename,
        onClose: close,
      }),
    })
    app.mixin({ methods: { t, n } })
    app.mount(mount)
  })
}

export function registerWebappFileAction() {
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
      const node = nodes[0]
      return node.type === FileType.Folder && hasNotebook(node)
    },

    async exec({ nodes }) {
      await openShareDialog(nodes[0])
      return null
    },

    order: 25,
  })
}
