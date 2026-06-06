// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later
const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const appId = 'integration_jupyterhub'

webpackConfig.entry = {
  adminSettings: { import: path.join(__dirname, 'src', 'adminSettings.js'), filename: appId + '-adminSettings.js' },
  files: { import: path.join(__dirname, 'src', 'files.js'), filename: appId + '-files.js' },
}

module.exports = webpackConfig
