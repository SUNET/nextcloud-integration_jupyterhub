// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { getLoggerBuilder } from '@nextcloud/logger'

export default getLoggerBuilder().setApp('integration_jupyterhub').build()
