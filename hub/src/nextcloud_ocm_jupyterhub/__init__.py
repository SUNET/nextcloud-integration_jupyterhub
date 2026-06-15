# SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
# SPDX-License-Identifier: AGPL-3.0-or-later

from ._version import __version__

# Re-exports done via PEP 562 __getattr__ so that `python -m
# nextcloud_ocm_jupyterhub.services.{ocm,refresh_token}` does NOT pull in
# the authenticator/handlers (and their transitive jupyterhub.handlers,
# oauthenticator imports) just to launch a managed-service subprocess.
# Pulling them eagerly here can leave the IOLoop in a state where
# `IOLoop.current().start()` no longer accepts on the bound port.

__all__ = [
    "NextcloudOAuthenticator",
    "OCMGatewayHandler",
    "OCMLoginHandler",
    "__version__",
]


def __getattr__(name: str):
    if name == "NextcloudOAuthenticator":
        from .authenticator import NextcloudOAuthenticator

        return NextcloudOAuthenticator
    if name in ("OCMLoginHandler", "OCMGatewayHandler"):
        from . import handlers

        return getattr(handlers, name)
    raise AttributeError(f"module {__name__!r} has no attribute {name!r}")
