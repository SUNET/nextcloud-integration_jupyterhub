// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later
const path = require("path");

const webpackConfig = require("@nextcloud/webpack-vue-config");

webpackConfig.entry = {
  adminSettings: {
    import: path.join(__dirname, "src", "settings-admin.js"),
    filename: "settings-admin.js",
  },
  main: { import: path.join(__dirname, "src", "main.js"), filename: "main.js" },
};

module.exports = webpackConfig;
