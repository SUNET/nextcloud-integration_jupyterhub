import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { FileType, registerFileAction } from '@nextcloud/files'
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h } from 'vue'
import { registerWebappFileAction } from '../src/files/registerWebappFileAction.js'
import { registerWebappOpenAction } from '../src/files/registerWebappOpenAction.js'
import logger from '../src/logger.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@nextcloud/files', () => ({
	FileType: { Folder: 'folder', File: 'file' },
	registerFileAction: vi.fn(),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('../src/logger.js', () => ({ default: { error: vi.fn() } }))
vi.mock('../src/components/WebappShareDialog.vue', () => ({ default: {} }))
vi.mock('vue', () => ({ createApp: vi.fn(), h: vi.fn() }))

let actions
let app
let mount

beforeEach(() => {
	vi.clearAllMocks()
	vi.stubGlobal('t', (_app, text) => text)
	vi.stubGlobal('n', (_app, text) => text)
	mount = { remove: vi.fn() }
	vi.stubGlobal('document', {
		body: { appendChild: vi.fn() },
		createElement: vi.fn(() => mount),
	})
	vi.stubGlobal('window', { location: { href: '' }, open: vi.fn() })
	app = {
		mixin: vi.fn(),
		mount: vi.fn(),
		unmount: vi.fn(),
	}
	createApp.mockReturnValue(app)
	registerWebappFileAction()
	registerWebappOpenAction()
	actions = Object.fromEntries(registerFileAction.mock.calls.map(([action]) => [action.id, action]))
})

afterEach(() => vi.unstubAllGlobals())

describe.each(['jupyter-share-webapp', 'jupyter-open-webapp'])('%s visibility', (id) => {
	it.each([undefined, false, true, '', 0, 'false', 'true'])('shows unvisited folders with metadata %s', (metadata) => {
		const folder = { type: FileType.Folder, attributes: { 'metadata-jupyter-has-notebook': metadata } }
		expect(actions[id].enabled({ nodes: [folder] })).toBe(true)
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('does not require DAV attributes or a shared mount', () => {
		expect(actions[id].enabled({ nodes: [{ type: FileType.Folder }] })).toBe(true)
	})

	it('does not offer the action for files, empty selection, or multiple folders', () => {
		const folder = { type: FileType.Folder }
		expect(actions[id].enabled({ nodes: [{ type: FileType.File }] })).toBe(false)
		expect(actions[id].enabled({ nodes: [] })).toBe(false)
		expect(actions[id].enabled({ nodes: [folder, folder] })).toBe(false)
	})
})

describe('sharing on click', () => {
	it('checks the server even if cached metadata says false, then disposes the dialog', async () => {
		axios.get.mockResolvedValue({ data: { hasNotebook: true } })
		const execution = actions['jupyter-share-webapp'].exec({ nodes: [{
			type: FileType.Folder,
			path: '/unvisited',
			basename: 'unvisited',
			attributes: { 'metadata-jupyter-has-notebook': false },
		}] })
		await vi.waitFor(() => expect(app.mount).toHaveBeenCalledWith(mount))
		expect(axios.get).toHaveBeenCalledWith('/apps/integration_jupyterhub/api/v1/webapp-share/check', { params: { path: '/unvisited' } })
		createApp.mock.calls[0][0].render()
		h.mock.calls[0][1].onClose()
		await expect(execution).resolves.toBeNull()
		expect(app.unmount).toHaveBeenCalledOnce()
		expect(mount.remove).toHaveBeenCalledOnce()
	})

	it('rejects a folder without notebooks without opening a dialog', async () => {
		axios.get.mockResolvedValue({ data: { hasNotebook: false } })
		await actions['jupyter-share-webapp'].exec({ nodes: [{ path: '/empty' }] })
		expect(createApp).not.toHaveBeenCalled()
		expect(showError).toHaveBeenCalledWith(expect.stringContaining('contains no Jupyter notebook'))
	})

	it('reports a failed probe as an error rather than as an empty folder', async () => {
		axios.get.mockRejectedValue(new Error('unavailable'))
		await actions['jupyter-share-webapp'].exec({ nodes: [{ path: '/unvisited' }] })
		expect(createApp).not.toHaveBeenCalled()
		expect(logger.error).toHaveBeenCalledOnce()
		expect(showError).toHaveBeenCalledWith(expect.stringContaining('Could not check this folder'))
	})
})

describe('opening on click', () => {
	it('opens the configured hub for an unvisited folder without using removed routes', async () => {
		await actions['jupyter-open-webapp'].exec({ nodes: [{ path: '/unvisited' }] })
		expect(axios.get).not.toHaveBeenCalled()
		expect(window.location.href).toBe('/apps/integration_jupyterhub/')
	})
})
