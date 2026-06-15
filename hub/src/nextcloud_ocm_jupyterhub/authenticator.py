# SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""NextcloudOAuthenticator — GenericOAuthenticator wired up for Nextcloud OCS
plus the OCM share-launch flow.

Operators wire this in via the `jupyterhub.authenticators` entry point
(`c.JupyterHub.authenticator_class = "nextcloud-ocm"`) or by importing the
class directly. The matching default config (Nextcloud OAuth URLs, the
post-auth hook that records token expiry, the OCM handlers, the gateway
login_url) is applied by `nextcloud_ocm_jupyterhub.config.apply_defaults`.
"""

import json
import os
import time
from datetime import datetime
from urllib.parse import urlencode

import jwt
from jupyterhub.utils import maybe_future, url_path_join
from oauthenticator.generic import GenericOAuthenticator
from tornado.httpclient import AsyncHTTPClient, HTTPRequest

from .handlers import OCMGatewayHandler, OCMLoginHandler
from .jwt_verify import verify_ocm_jwt


def _debug() -> bool:
    return os.environ.get("NEXTCLOUD_DEBUG_OAUTH", "false").lower() in (
        "true",
        "1",
        "yes",
    )


def _token_url() -> str:
    return (
        "https://"
        + os.environ["NEXTCLOUD_HOST"]
        + "/index.php/apps/oauth2/api/v1/token"
    )


def _is_ocm_user(user) -> bool:
    """OCM accounts are tagged by membership in the 'ocm' group (set by the
    OCM service at user creation, before any spawn). This is the robust marker
    used to gate self-spawn and the spawn UI — not a name prefix."""
    try:
        return any(g.name == "ocm" for g in user.orm_user.groups)
    except Exception:
        return False


async def _refresh_nextcloud_access_token(refresh_token: str) -> dict:
    # AsyncHTTPClient + explicit timeouts: a slow Nextcloud must not wedge
    # the hub event loop (sync requests.post here previously caused chp to
    # time out user-server requests with 503 ETIMEDOUT).
    body = urlencode(
        {
            "grant_type": "refresh_token",
            "code": refresh_token,
            "refresh_token": refresh_token,
            "client_id": os.environ["NEXTCLOUD_CLIENT_ID"],
            "client_secret": os.environ["NEXTCLOUD_CLIENT_SECRET"],
        }
    )
    req = HTTPRequest(
        _token_url(),
        method="POST",
        headers={"Content-Type": "application/x-www-form-urlencoded"},
        body=body,
        connect_timeout=10,
        request_timeout=15,
    )
    response = await AsyncHTTPClient().fetch(req)
    if _debug():
        print(response.body.decode())
    return json.loads(response.body)


def post_auth_hook(authenticator, handler, authentication):
    """Record the OAuth token's absolute expiry on `auth_state` so
    `refresh_user` can decide when to renew without re-decoding the response."""
    auth_state = authentication["auth_state"]
    auth_state["token_expires"] = (
        time.time() + auth_state["token_response"]["expires_in"]
    )
    authentication["auth_state"] = auth_state
    return authentication


class NextcloudOAuthenticator(GenericOAuthenticator):
    """OCS-aware GenericOAuthenticator plus the OCM share-launch hooks.

    Drop-in for `oauthenticator.generic.GenericOAuthenticator`: configure
    `client_id` / `client_secret` / `userdata_url` / `authorize_url` /
    `token_url` / `oauth_callback_url` as usual. `enable_auth_state` is
    required (the spawner needs the upstream access_token).
    """

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.user_dict = {}

    def get_handlers(self, app):
        return super().get_handlers(app) + [
            (r"/ocm-login", OCMLoginHandler),
            (r"/ocm-gateway", OCMGatewayHandler),
        ]

    def login_url(self, base_url):
        # Route unauthenticated users through the gateway so lapsed OCM users
        # are sent back to their receiver instead of alice's OAuth.
        return url_path_join(base_url, "ocm-gateway")

    async def pre_spawn_start(self, user, spawner):
        # The base Authenticator.pre_spawn_start is sync in current jupyterhub
        # (returns None); maybe_future tolerates both, so subclassers may make
        # theirs async without breaking us.
        await maybe_future(super().pre_spawn_start(user, spawner))
        ocm = (spawner.user_options or {}).get("ocm_share")
        if ocm:
            # The bearer is the OCM share access_token; re-validate it
            # (signature + exp) before granting the server — this is the
            # authoritative check for the initial, OCM-service-initiated spawn.
            try:
                verify_ocm_jwt(ocm["bearer"])
            except (ValueError, jwt.InvalidTokenError) as e:
                raise Exception(f"OCM share access_token invalid: {e}")
            spawner.environment["OCM_WEBDAV_URI"] = ocm["webdav_uri"]
            spawner.environment["OCM_BEARER"] = ocm["bearer"]
            spawner.environment["OCM_PERMISSIONS"] = ",".join(
                ocm.get("permissions", ["read"])
            )
            spawner.environment["OCM_RESOURCE_NAME"] = ocm.get("resource_name", "")
            spawner.environment["OCM_SHARER"] = ocm.get("sharer", "")
            spawner.environment["OCM_PROVIDER_ID"] = ocm.get("provider_id", "")
            # ocm-sync (in the lab image) pulls the share over WebDAV in pure
            # userspace, so no extra capabilities are needed. (SYS_ADMIN was
            # added here for a FUSE mount, but it is ineffective for the
            # non-root jovyan user anyway — it lands in the bounding set, not
            # the effective set — and there is no /dev/fuse in the pod.)
            return
        if _is_ocm_user(user):
            # An OCM account reached pre_spawn without ocm_share, i.e. it tried
            # to start a server itself — "Start My Server" / a self-named server
            # from the hub home panel, or re-accessing a culled share server
            # without re-opening. OCM accounts may ONLY run servers created by
            # the OCM share-launch flow (which always carries ocm_share), so
            # refuse. Raising here aborts the spawn before any pod is created.
            raise Exception(
                "OCM accounts cannot start servers directly; "
                "open the share from the OCM Remote WebApp."
            )
        auth_state = await user.get_auth_state()
        if not auth_state:
            return
        # OCM users only carry 'ocm_access_token'; only OAuth users have a
        # Nextcloud 'access_token' to forward to the singleuser server.
        access_token = auth_state.get("access_token")
        if access_token:
            spawner.environment["NEXTCLOUD_ACCESS_TOKEN"] = access_token

    async def refresh_user(self, user, handler=None):
        # OCM synthetic users authenticate with the share access_token, not
        # the Nextcloud OAuth flow, so they have no OAuth refresh token. Keep
        # the session only while that access_token still verifies (signature
        # + exp); when it lapses, drop the session rather than bouncing them
        # into alice's OAuth login (which they cannot complete).
        if _is_ocm_user(user):
            auth_state = await user.get_auth_state()
            token = (auth_state or {}).get("ocm_access_token")
            if not token:
                # No session token yet: this is the initial OCM-service-driven
                # spawn (the handoff login runs afterwards). The OCM service
                # already verified the access_token and pre_spawn_start
                # re-validates the share bearer, so allow it here.
                return True
            try:
                verify_ocm_jwt(token)
                return True
            except (ValueError, jwt.InvalidTokenError) as e:
                # Stored session token has lapsed. Allow only when this refresh
                # is driven by the OCM admin service starting a fresh share
                # open (pre_spawn_start re-validates that share's bearer); a
                # day-old token must not block a new, freshly-verified launch.
                # For the user's own ongoing access, end the session.
                requester = getattr(
                    getattr(handler, "current_user", None), "name", None
                )
                if requester is not None and requester != user.name:
                    return True
                if _debug():
                    print(f"OCM access_token no longer valid for {user}: {e}")
                return False
        auth_state = await user.get_auth_state()
        if not auth_state:
            if _debug():
                print(f"auth_state missing for {user}")
            return False
        refresh_token = auth_state["refresh_token"]
        now = time.time()
        now_hr = datetime.fromtimestamp(now)
        expires = auth_state["token_expires"]
        expires_hr = datetime.fromtimestamp(expires)
        if _debug():
            print(f"auth_state for {user}: {auth_state}")
        if now >= expires:
            if _debug():
                print(f"Time is: {now_hr}, token expired: {expires_hr}")
                print(f"Refreshing token for {user}")
            try:
                token_response = await _refresh_nextcloud_access_token(refresh_token)
                auth_state["access_token"] = token_response["access_token"]
                auth_state["refresh_token"] = token_response["refresh_token"]
                auth_state["token_expires"] = now + token_response["expires_in"]
                auth_state["token_response"] = token_response
                if _debug():
                    print(f"Successfully refreshed token for {user.name}")
                    print(f"auth_state for {user.name}: {auth_state}")
                return {"name": user.name, "auth_state": auth_state}
            except Exception:
                if _debug():
                    print(f"Failed to refresh token for {user}")
                return False
        if _debug():
            print(f"Time is: {now_hr}, token expires: {expires_hr}")
        return True
