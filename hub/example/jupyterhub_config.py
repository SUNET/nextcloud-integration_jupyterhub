# SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Reference jupyterhub_config.py for the nextcloud-ocm-jupyterhub package.

Pip-install `nextcloud-ocm-jupyterhub` into the hub's Python environment
and drop a config that looks like this. Everything outside the
`apply_defaults(c)` call is project-specific; trim or replace freely.

Required environment variables (read at hub startup):
    NEXTCLOUD_HOST              e.g. nextcloud.example
    NEXTCLOUD_CLIENT_ID         OAuth client id registered in Nextcloud
    NEXTCLOUD_CLIENT_SECRET     paired secret
    JUPYTER_HOST                public hostname of the hub (oauth_callback_url)
    JUPYTERHUB_CRYPT_KEY        32-byte hex; required for enable_auth_state
    JUPYTERHUB_API_KEY          API token for the refresh-token service
    JUPYTERHUB_OCM_API_KEY      API token for the OCM service
    OCM_TRUSTED_BACK_CHANNEL_DOMAINS  comma-separated allowlist of paired NCs
    OCM_TRUSTED_ISSUER_DOMAINS  comma-separated allowlist of access_token issuers
"""

import os

from nextcloud_ocm_jupyterhub.config import apply_defaults

apply_defaults(c)  # noqa: F821 — c is injected by JupyterHub

# Hub-specific knobs below are not provided by the package. Adjust as needed.

c.JupyterHub.spawner_class = "simple"  # noqa: F821
c.Spawner.default_url = "/lab"  # noqa: F821
c.Spawner.args = ["--allow-root"]  # noqa: F821

c.Authenticator.auto_login = True  # noqa: F821
c.JupyterHub.bind_url = "http://:8000"  # noqa: F821
c.JupyterHub.allow_named_servers = True  # noqa: F821
c.JupyterHub.public_url = "https://" + os.environ["JUPYTER_HOST"]  # noqa: F821

# Hub is iframed inside nextcloud.local by integration_jupyterhub's
# templates/main.php. SameSite=Lax cookies (the default) are dropped on the
# cross-site OAuth callback redirect that lands back in the iframe.
c.JupyterHub.tornado_settings = {  # noqa: F821
    "headers": {"Content-Security-Policy": "frame-ancestors *;"},
    "cookie_options": {"samesite": "None", "secure": True},
}

c.CryptKeeper.keys = [os.environ["JUPYTERHUB_CRYPT_KEY"]]  # noqa: F821
