<!--
SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
SPDX-License-Identifier: CC0-1.0
-->

# Jupyter
Place this app in **nextcloud/custom_apps/**

## Configuring Nextcloud
Install this app and configure it with OCC:
```
occ integration_jupyterhub:set-url <URL to Your JupyterHub>
```
Or under Administration -> Additional settings

Create an OAuth2 Client under Administration -> Security and note Client identifier and secret for use later. 

## Configuring JupyterHub
You need to configure a bunch of stuff in Jupyter Hub.
A full configuration guide can be found here: https://wiki.sunet.se/pages/viewpage.action?pageId=178290700
