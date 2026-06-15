# SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Default-config helpers for `jupyterhub_config.py`.

These reproduce what the legacy `oauth_config.py` and `service_config.py`
files used to set when exec()'d into the hub config. Operators who want
finer control can ignore the helpers and configure the authenticator,
services, roles and template path directly — nothing in the package mutates
the global `c` at import time.
"""

import os
import sys
from importlib import resources
from pathlib import Path

from .authenticator import NextcloudOAuthenticator, post_auth_hook


def _nextcloud_host() -> str:
    return os.environ["NEXTCLOUD_HOST"]


def _jupyter_host() -> str:
    return os.environ["JUPYTER_HOST"]


def apply_authenticator_defaults(c, *, login_service: str = "Nextcloud (OCM)") -> None:
    """Wire `NextcloudOAuthenticator` into `c` with Nextcloud OCS endpoints.

    Reads `NEXTCLOUD_HOST`, `JUPYTER_HOST`, `NEXTCLOUD_CLIENT_ID`,
    `NEXTCLOUD_CLIENT_SECRET` from the environment.
    """
    c.JupyterHub.authenticator_class = NextcloudOAuthenticator
    nc = _nextcloud_host()
    c.NextcloudOAuthenticator.client_id = os.environ["NEXTCLOUD_CLIENT_ID"]
    c.NextcloudOAuthenticator.client_secret = os.environ["NEXTCLOUD_CLIENT_SECRET"]
    c.NextcloudOAuthenticator.login_service = login_service
    c.NextcloudOAuthenticator.username_claim = (
        lambda r: r.get("ocs", {}).get("data", {}).get("id")
    )
    c.NextcloudOAuthenticator.userdata_url = (
        f"https://{nc}/ocs/v2.php/cloud/user?format=json"
    )
    c.NextcloudOAuthenticator.authorize_url = (
        f"https://{nc}/index.php/apps/oauth2/authorize"
    )
    c.NextcloudOAuthenticator.token_url = (
        f"https://{nc}/index.php/apps/oauth2/api/v1/token"
    )
    c.NextcloudOAuthenticator.oauth_callback_url = (
        f"https://{_jupyter_host()}/hub/oauth_callback"
    )
    c.NextcloudOAuthenticator.allow_all = True
    c.NextcloudOAuthenticator.refresh_pre_spawn = True
    c.NextcloudOAuthenticator.enable_auth_state = True
    c.NextcloudOAuthenticator.auth_refresh_age = 3600
    c.NextcloudOAuthenticator.post_auth_hook = post_auth_hook


def apply_services_defaults(c) -> None:
    """Register the `refresh-token` and `ocm` managed services and their RBAC.

    The two services run as managed subprocesses of the hub, so they share
    the pod's network namespace — URLs stay on 127.0.0.1. Going via the hub
    Service ClusterIP would be blocked by the hub NetworkPolicy (which only
    allows the service port ingress from pods labelled
    `hub.jupyter.org/network-access-hub`).

    Reads `JUPYTERHUB_API_KEY`, `JUPYTERHUB_OCM_API_KEY`. Optional ports
    come from `HUB_SERVICE_PORT_REFRESH_TOKEN` (8082) and
    `HUB_SERVICE_PORT_OCM` (8083).
    """
    refresh_port = os.environ.get("HUB_SERVICE_PORT_REFRESH_TOKEN", "8082")
    ocm_port = os.environ.get("HUB_SERVICE_PORT_OCM", "8083")
    refresh_token_api_key = os.environ["JUPYTERHUB_API_KEY"]
    ocm_api_key = os.environ["JUPYTERHUB_OCM_API_KEY"]

    # Direct assignment: c.JupyterHub.* defaults to traitlets' LazyConfigValue,
    # which doesn't support `+ list`. Operators who need to compose with their
    # own roles/services should call apply_authenticator_defaults +
    # apply_template_path and write the service block by hand.
    c.JupyterHub.load_roles = [
        {
            "name": "refresh-token",
            "services": ["refresh-token"],
            "scopes": ["read:users", "admin:auth_state"],
        },
        {
            "name": "ocm",
            "services": ["ocm"],
            "scopes": [
                "admin:users",
                "admin:servers",
                # manage the 'ocm' group: hub_ensure_user creates it and adds
                # the share recipient so the hub can mark OCM accounts (gate
                # self-spawn, hide spawn UI) without a name-prefix heuristic.
                "admin:groups",
            ],
        },
        {
            "name": "user",
            "scopes": [
                "access:services!service=refresh-token",
                "read:services!service=refresh-token",
                "self",
            ],
        },
        {
            "name": "server",
            "scopes": [
                "access:services!service=refresh-token",
                "read:services!service=refresh-token",
                "inherit",
            ],
        },
    ]

    c.JupyterHub.services = [
        {
            "name": "refresh-token",
            "url": f"http://127.0.0.1:{refresh_port}",
            "display": False,
            "oauth_no_confirm": True,
            "api_token": refresh_token_api_key,
            "command": [sys.executable, "-m",
                        "nextcloud_ocm_jupyterhub.services.refresh_token"],
        },
        {
            "name": "ocm",
            "url": f"http://127.0.0.1:{ocm_port}",
            "display": False,
            "api_token": ocm_api_key,
            "command": [sys.executable, "-m",
                        "nextcloud_ocm_jupyterhub.services.ocm"],
            # JupyterHub does NOT pass the hub pod's custom env to managed
            # service subprocesses (only JUPYTERHUB_* are injected). The OCM
            # service's allowlists and login URL must be forwarded explicitly,
            # or TRUSTED_BACK_CHANNEL_DOMAINS ends up empty and every
            # back-channel push is rejected with 403.
            "environment": {
                "OCM_TRUSTED_BACK_CHANNEL_DOMAINS": os.environ.get(
                    "OCM_TRUSTED_BACK_CHANNEL_DOMAINS", ""
                ),
                "OCM_TRUSTED_ISSUER_DOMAINS": os.environ.get(
                    "OCM_TRUSTED_ISSUER_DOMAINS", ""
                ),
                "OCM_LOGIN_URL": os.environ.get("OCM_LOGIN_URL", "/hub/ocm-login"),
                "OCM_JWKS_TTL": os.environ.get("OCM_JWKS_TTL", "300"),
                # Debug flag: tap the [ocm] log lines in the hub's stdout.
                "NEXTCLOUD_DEBUG_OAUTH": os.environ.get(
                    "NEXTCLOUD_DEBUG_OAUTH", ""
                ),
            },
        },
    ]
    # service_tokens replaces the pending-deprecated JupyterHub.api_tokens
    # (deprecated since 0.8) for assigning API tokens to services (issue #10).
    c.JupyterHub.service_tokens = {
        refresh_token_api_key: "refresh-token",
        ocm_api_key: "ocm",
    }


def _templates_dir() -> str:
    """Return the on-disk path to the packaged templates directory.

    JupyterHub's template loader wants an actual filesystem path (it's a
    `FileSystemLoader`), so we materialise the packaged templates via
    `importlib.resources.as_file` when needed. For a normal pip install
    the package is unpacked on disk and this is a no-op resolution; for
    a zipapp deployment the caller would need to copy them out first.
    """
    ref = resources.files("nextcloud_ocm_jupyterhub") / "templates"
    path = Path(str(ref))
    if not path.is_dir():
        raise RuntimeError(
            f"templates directory missing or not on disk: {path}. "
            "Install the package via pip (not zipapp) for templates to resolve."
        )
    return str(path)


def apply_template_path(c) -> None:
    """Prepend the packaged template path so JupyterHub picks up `home.html`.

    The override hides server-creation affordances for OCM accounts (members
    of the `ocm` group) — they may only launch via the OCM share flow,
    enforced in `pre_spawn_start`.
    """
    c.JupyterHub.template_paths = [_templates_dir()]


def apply_defaults(c) -> None:
    """Apply authenticator, services and template-path defaults in one call."""
    apply_authenticator_defaults(c)
    apply_services_defaults(c)
    apply_template_path(c)
