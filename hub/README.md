<!--
SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# nextcloud-ocm-jupyterhub

JupyterHub-side authenticator and OCM webapp-sharing services that pair with
the `integration_jupyterhub` Nextcloud app. This package replaces the
exec()-based loader pattern that used to live in `hub/oauth_config.py`,
`hub/service_config.py`, `hub/ocm-service.py` and `hub/refresh-token.py`.

## Install

```bash
pip install nextcloud-ocm-jupyterhub
```

## Use

In your `jupyterhub_config.py`:

```python
from nextcloud_ocm_jupyterhub.config import apply_defaults

apply_defaults(c)
```

`apply_defaults(c)` does three things:

1. Wires the `NextcloudOAuthenticator` with the Nextcloud OCS endpoints,
   token refresh hook and OCM share-launch handlers.
2. Registers the `refresh-token` and `ocm` managed services and their RBAC
   roles, with launch commands `python -m
   nextcloud_ocm_jupyterhub.services.{refresh_token,ocm}`.
3. Prepends the packaged `templates/` path so JupyterHub picks up the
   OCM-aware `home.html` override (hides spawn affordances for members of
   the `ocm` group).

Operators who want finer control can call the individual helpers
(`apply_authenticator_defaults`, `apply_services_defaults`,
`apply_template_path`) or skip the helpers entirely and configure each piece
themselves — nothing in the package mutates the global `c` at import time.

You can also reach the authenticator via its `jupyterhub.authenticators`
entry point:

```python
c.JupyterHub.authenticator_class = "nextcloud-ocm"
```

A complete reference config sits at
[`example/jupyterhub_config.py`](example/jupyterhub_config.py).

## Required environment

| Variable | Purpose |
| --- | --- |
| `NEXTCLOUD_HOST` | Public hostname of the paired Nextcloud (OAuth URLs). |
| `NEXTCLOUD_CLIENT_ID`, `NEXTCLOUD_CLIENT_SECRET` | OAuth client registered in the Nextcloud admin UI. |
| `JUPYTER_HOST` | Public hostname of the hub; used for `oauth_callback_url`. |
| `JUPYTERHUB_API_KEY` | API token for the `refresh-token` service. |
| `JUPYTERHUB_OCM_API_KEY` | API token for the `ocm` service. |
| `OCM_TRUSTED_BACK_CHANNEL_DOMAINS` | Comma-separated allowlist of paired Nextcloud domains permitted to push to `/services/ocm/{shares,close}`. Empty → all rejected. |
| `OCM_TRUSTED_ISSUER_DOMAINS` | Comma-separated allowlist of OCM token issuers. Empty → any HTTPS issuer accepted. |

Optional:

| Variable | Default | Purpose |
| --- | --- | --- |
| `OCM_LOGIN_URL` | `/hub/ocm-login` | Where the OCM service points the auto-submitting handoff form. |
| `OCM_JWKS_TTL` | `300` | Seconds between JWKS re-fetches. |
| `OCM_SHARE_TTL` | `86400` | How long share records stay in the SQLite store. |
| `OCM_SIG_FRESHNESS` | `300` | Allowed clock skew for RFC 9421 `created` parameter. |
| `OCM_STORE_PATH` | `/srv/jupyterhub/ocm-shares.db` | SQLite share store. |
| `HUB_SERVICE_PORT_REFRESH_TOKEN` | `8082` | Internal port for the refresh-token service. |
| `HUB_SERVICE_PORT_OCM` | `8083` | Internal port for the OCM service. |
| `NEXTCLOUD_DEBUG_OAUTH` | `false` | If truthy, the OAuth flow and the services log to fd 1. |

## What's in the package

- `nextcloud_ocm_jupyterhub.NextcloudOAuthenticator` — drop-in replacement
  for `oauthenticator.generic.GenericOAuthenticator` with the OCM
  `pre_spawn_start` / `refresh_user` hooks.
- `nextcloud_ocm_jupyterhub.handlers` — `OCMLoginHandler`,
  `OCMGatewayHandler` (mounted automatically via `get_handlers`).
- `nextcloud_ocm_jupyterhub.services.ocm` — `python -m
  nextcloud_ocm_jupyterhub.services.ocm` or the
  `nextcloud-ocm-jupyterhub-service` console script.
- `nextcloud_ocm_jupyterhub.services.refresh_token` — `python -m
  nextcloud_ocm_jupyterhub.services.refresh_token` or
  `nextcloud-ocm-jupyterhub-refresh-token`.
- `nextcloud_ocm_jupyterhub.jwt_verify.verify_ocm_jwt` — re-usable OCM JWT
  verification (issuer-domain allowlist + JWKS cache).

## Migrating from the exec-based layout

| Old (exec) | New (import) |
| --- | --- |
| `exec(open("/etc/jupyterhub/code/oauth_config.py").read())` | `apply_authenticator_defaults(c)` |
| `exec(open("/etc/jupyterhub/code/service_config.py").read())` | `apply_services_defaults(c)` |
| `command: [sys.executable, "/.../ocm-service.py"]` | `command: [sys.executable, "-m", "nextcloud_ocm_jupyterhub.services.ocm"]` |
| `command: [sys.executable, "/.../refresh-token.py"]` | `command: [sys.executable, "-m", "nextcloud_ocm_jupyterhub.services.refresh_token"]` |
| `c.JupyterHub.template_paths = ["/.../hub"]` | `apply_template_path(c)` |

All three of the `apply_*` calls compose, so most operators only need
`apply_defaults(c)`.
