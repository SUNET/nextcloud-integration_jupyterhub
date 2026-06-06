import os
import sys

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
            # manage the 'ocm' group: hub_ensure_user creates it and adds the
            # share recipient so the hub can mark OCM accounts (gate self-spawn,
            # hide spawn UI) without a name-prefix heuristic.
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
        # The refresh-token service runs as a managed subprocess of the
        # hub, so it shares the pod's network namespace — keep the URL
        # on localhost. Going via the hub Service ClusterIP would be
        # blocked by the hub NetworkPolicy (which only allows 8082
        # ingress from pods labelled hub.jupyter.org/network-access-hub).
        "name": "refresh-token",
        "url": "http://127.0.0.1:"
        + os.environ.get("HUB_SERVICE_PORT_REFRESH_TOKEN", "8082"),
        "display": False,
        "oauth_no_confirm": True,
        "api_token": os.environ["JUPYTERHUB_API_KEY"],
        "command": [sys.executable, "/etc/jupyterhub/code/refresh-token.py"],
    },
    {
        "name": "ocm",
        "url": "http://127.0.0.1:" + os.environ.get("HUB_SERVICE_PORT_OCM", "8083"),
        "display": False,
        "api_token": os.environ["JUPYTERHUB_OCM_API_KEY"],
        "command": [sys.executable, "/etc/jupyterhub/code/ocm-service.py"],
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
        },
    },
]
# The refresh-token and ocm services get their privileges from the roles
# defined in load_roles above (RBAC), so JupyterHub.admin_users — deprecated
# since 0.7.2 in favour of Authenticator.admin_users, and meant for human
# admins, not services — is not needed and has been dropped (issue #10).
#
# service_tokens replaces the pending-deprecated JupyterHub.api_tokens
# (deprecated since 0.8) for assigning API tokens to services (issue #10).
c.JupyterHub.service_tokens = {
    os.environ["JUPYTERHUB_API_KEY"]: "refresh-token",
    os.environ["JUPYTERHUB_OCM_API_KEY"]: "ocm",
}
