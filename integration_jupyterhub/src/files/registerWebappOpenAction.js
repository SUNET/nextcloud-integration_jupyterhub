/**
 * SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { registerFileAction, FileType } from '@nextcloud/files'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

const ACTION_ID = 'jupyter-open-webapp'

/**
 * Two synchronous signals come off every node's DAV attributes:
 *  - `metadata-jupyter-has-notebook` — set by the backend metadata listener,
 *    true when the folder contains at least one `.ipynb` child.
 *  - `mount-type` — set by NC core, populated for shares (incoming/received),
 *    federated mounts, and group folders. Empty for owned content.
 *
 * The "Open in JupyterHub" action fires when both are true. Notably it
 * doesn't require this Nextcloud to have a JupyterHub URL configured —
 * the launcher itself falls back to the sender's URI from the stored
 * webapp record when there's no local hub.
 */
function hasNotebook(node) {
  const raw = node?.attributes?.['metadata-jupyter-has-notebook']
  if (raw === undefined || raw === null) {
    return false
  }
  if (typeof raw === 'boolean') {
    return raw
  }
  const s = String(raw).toLowerCase()
  return s === '1' || s === 'true' || s === 'yes'
}

function isShare(node) {
  // Any non-empty mount-type counts: shared/external/group/shared-root all
  // mean "this isn't content the current user owns at the root".
  return Boolean(node?.attributes?.['mount-type'])
}

export function registerWebappOpenAction() {
  registerFileAction({
    id: ACTION_ID,
    displayName: () => t('integration_jupyterhub', 'Open in JupyterHub'),
    iconSvgInline: () =>
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
      + '<path d="M5 3h14v18H5z" fill="none" stroke="currentColor" stroke-width="1.5"/>'
      + '<path d="M9 8h6M9 12h6M9 16h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>'
      + '</svg>',

    enabled: ({ nodes }) => {
      if (!nodes || nodes.length !== 1) {
        return false
      }
      const node = nodes[0]
      return node.type === FileType.Folder && hasNotebook(node) && isShare(node)
    },

    async exec({ nodes }) {
      const node = nodes[0]
      try {
        const response = await axios.get(
          generateUrl('/apps/integration_jupyterhub/api/v1/webapp-share/at'),
          { params: { path: node.path } },
        )
        const token = response.data?.token
        const targets = Array.isArray(response.data?.webapp?.target)
          ? response.data.webapp.target
          : ['iframe']
        if (token) {
          const launchUrl = generateUrl('/apps/integration_jupyterhub/ocm/open/{token}', { token })
          if (targets.includes('blank') && !targets.includes('iframe')) {
            window.open(launchUrl, '_blank', 'noopener,noreferrer')
          } else {
            window.location.href = launchUrl
          }
          return null
        }
      } catch (e) {
        // No webapp annotation on this share — fall through to the local
        // hub home if configured, else show a friendly note.
      }

      // No OCM webapp record. Open the local hub if configured; the
      // launcher route shows an error otherwise.
      window.location.href = generateUrl('/apps/integration_jupyterhub/')
      return null
    },

    order: 26,
  })
}
