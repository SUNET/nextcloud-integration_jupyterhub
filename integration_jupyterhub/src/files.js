/**
 * SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { registerWebappFileAction } from './files/registerWebappFileAction.js'
import { registerWebappOpenAction } from './files/registerWebappOpenAction.js'

registerWebappFileAction()
registerWebappOpenAction()
