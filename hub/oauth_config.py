import json
import os
import re
import time
from datetime import datetime
from urllib.parse import urlencode, urlparse

import jwt
from jupyterhub.handlers.base import BaseHandler
from jupyterhub.utils import url_path_join
from jwt import PyJWKClient
from oauthenticator.generic import GenericOAuthenticator
from tornado.httpclient import AsyncHTTPClient, HTTPRequest
from tornado.httputil import url_concat

token_url = (
    "https://" + os.environ["NEXTCLOUD_HOST"] + "/index.php/apps/oauth2/api/v1/token"
)
debug = os.environ.get("NEXTCLOUD_DEBUG_OAUTH", "false").lower() in ["true", "1", "yes"]

_OCM_TRUSTED_ISSUER_DOMAINS = frozenset(
    d.strip()
    for d in os.environ.get("OCM_TRUSTED_ISSUER_DOMAINS", "").split(",")
    if d.strip()
)
_ocm_jwks_clients = {}


def _ocm_jwks(domain):
    client = _ocm_jwks_clients.get(domain)
    if client is None:
        client = PyJWKClient(f"https://{domain}/.well-known/jwks.json", cache_keys=True)
        _ocm_jwks_clients[domain] = client
    return client


def _verify_ocm_jwt(token):
    try:
        unverified = jwt.decode(token, options={"verify_signature": False})
    except jwt.InvalidTokenError as e:
        raise ValueError(f"malformed token: {e}")
    issuer = unverified.get("iss")
    if not issuer:
        raise ValueError("token missing iss claim")
    parsed = urlparse(issuer)
    if parsed.scheme != "https" or not parsed.netloc:
        raise ValueError("iss must be an https URL")
    if _OCM_TRUSTED_ISSUER_DOMAINS and parsed.netloc not in _OCM_TRUSTED_ISSUER_DOMAINS:
        raise ValueError(f"issuer {parsed.netloc} not in trusted issuer allowlist")
    signing_key = _ocm_jwks(parsed.netloc).get_signing_key_from_jwt(token).key
    return jwt.decode(
        token,
        signing_key,
        algorithms=["RS256", "RS384", "RS512", "ES256", "ES384", "EdDSA"],
        issuer=issuer,
        options={
            "require": ["iss", "sub", "aud", "exp", "client_id"],
            "verify_aud": False,
        },
    )


def _receiver_root_from_aud(aud):
    # aud is the shareWith cloud id (e.g. "bob@bob.example" or
    # "bob@https://bob.example"); the receiver root is its host.
    host = aud.rsplit("@", 1)[-1] if "@" in aud else aud
    host = re.sub(r"^https?://", "", host).strip().rstrip("/")
    return f"https://{host}/" if host else None


def _is_ocm_user(user):
    # OCM accounts are tagged by membership in the 'ocm' group (set by the
    # OCM service at user creation, before any spawn). This is the robust
    # marker used to gate self-spawn and the spawn UI — not a name prefix.
    try:
        return any(g.name == "ocm" for g in user.orm_user.groups)
    except Exception:
        return False


class OCMLoginHandler(BaseHandler):
    # The cross-origin handoff form (from the receiver's launcher) carries
    # no JupyterHub _xsrf token. The access_token JWT it posts is itself
    # the credential — verified below — so XSRF protection is redundant.
    def check_xsrf_cookie(self):
        return

    async def post(self):
        access_token = self.get_argument("access_token", "")
        next_url = self.get_argument("next", "/hub/home")
        if not access_token:
            self.set_status(400)
            self.write("access_token missing")
            return
        try:
            claims = _verify_ocm_jwt(access_token)
        except (ValueError, jwt.InvalidTokenError) as e:
            self.set_status(401)
            self.write(f"OCM token verification failed: {e}")
            return
        username = f'ocm:{claims["aud"]}'
        # Stash the access_token so refresh_user can re-validate it (it is
        # the only credential these synthetic users have).
        user = await self.auth_to_user(
            {"name": username, "auth_state": {"ocm_access_token": access_token}}
        )
        self.set_login_cookie(user)
        # Remember the receiver root (from shareWith/aud) in a signed cookie so
        # the gateway can bounce the user back there once the token lapses.
        receiver_root = _receiver_root_from_aud(claims["aud"])
        if receiver_root:
            self.set_secure_cookie("ocm_receiver_root", receiver_root)
        self.redirect(next_url)


class OCMGatewayHandler(BaseHandler):
    # login_url points here. A lapsed OCM user (signed receiver cookie) is sent
    # back to their receiver to re-mint; everyone else goes to OAuth login.
    def get(self):
        root = self.get_secure_cookie("ocm_receiver_root")
        if root:
            self.redirect(root.decode())
            return
        next_url = self.get_argument("next", "")
        self.redirect(
            url_concat(
                url_path_join(self.hub.base_url, "oauth_login"),
                {"next": next_url} if next_url else {},
            )
        )


async def get_nextcloud_access_token(refresh_token):
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
        token_url,
        method="POST",
        headers={"Content-Type": "application/x-www-form-urlencoded"},
        body=body,
        connect_timeout=10,
        request_timeout=15,
    )
    response = await AsyncHTTPClient().fetch(req)
    if debug:
        print(response.body.decode())
    return json.loads(response.body)


def post_auth_hook(authenticator, handler, authentication):
    user = authentication["auth_state"]["oauth_user"]["ocs"]["data"]["id"]
    auth_state = authentication["auth_state"]
    auth_state["token_expires"] = (
        time.time() + auth_state["token_response"]["expires_in"]
    )
    authentication["auth_state"] = auth_state
    return authentication


class NextcloudOAuthenticator(GenericOAuthenticator):
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
        super().pre_spawn_start(user, spawner)
        ocm = (spawner.user_options or {}).get("ocm_share")
        if ocm:
            # The bearer is the OCM share access_token; re-validate it
            # (signature + exp) before granting the server — this is the
            # authoritative check for the initial, OCM-service-initiated spawn.
            try:
                _verify_ocm_jwt(ocm["bearer"])
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
                "OCM accounts cannot start servers directly; open the share from the OCM Remote WebApp."
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
                _verify_ocm_jwt(token)
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
                if debug:
                    print(f"OCM access_token no longer valid for {user}: {e}")
                return False
        auth_state = await user.get_auth_state()
        if not auth_state:
            if debug:
                print(f"auth_state missing for {user}")
            return False
        access_token = auth_state["access_token"]
        refresh_token = auth_state["refresh_token"]
        token_response = auth_state["token_response"]
        now = time.time()
        now_hr = datetime.fromtimestamp(now)
        expires = auth_state["token_expires"]
        expires_hr = datetime.fromtimestamp(expires)
        if debug:
            print(f"auth_state for {user}: {auth_state}")
        if now >= expires:
            if debug:
                print(f"Time is: {now_hr}, token expired: {expires_hr}")
                print(f"Refreshing token for {user}")
            try:
                token_response = await get_nextcloud_access_token(refresh_token)
                auth_state["access_token"] = token_response["access_token"]
                auth_state["refresh_token"] = token_response["refresh_token"]
                auth_state["token_expires"] = now + token_response["expires_in"]
                auth_state["token_response"] = token_response
                if debug:
                    print(f"Successfully refreshed token for {user.name}")
                    print(f"auth_state for {user.name}: {auth_state}")
                return {"name": user.name, "auth_state": auth_state}
            except Exception as e:
                if debug:
                    print(f"Failed to refresh token for {user}")
                return False
            return False
        if debug:
            print(f"Time is: {now_hr}, token expires: {expires_hr}")
        return True


c.JupyterHub.authenticator_class = NextcloudOAuthenticator
c.NextcloudOAuthenticator.client_id = os.environ["NEXTCLOUD_CLIENT_ID"]
c.NextcloudOAuthenticator.client_secret = os.environ["NEXTCLOUD_CLIENT_SECRET"]
c.NextcloudOAuthenticator.login_service = "alice (ocm-testrig)"
c.NextcloudOAuthenticator.username_claim = (
    lambda r: r.get("ocs", {}).get("data", {}).get("id")
)
c.NextcloudOAuthenticator.userdata_url = (
    "https://" + os.environ["NEXTCLOUD_HOST"] + "/ocs/v2.php/cloud/user?format=json"
)
c.NextcloudOAuthenticator.authorize_url = (
    "https://" + os.environ["NEXTCLOUD_HOST"] + "/index.php/apps/oauth2/authorize"
)
c.NextcloudOAuthenticator.token_url = token_url
c.NextcloudOAuthenticator.oauth_callback_url = (
    "https://" + os.environ["JUPYTER_HOST"] + "/hub/oauth_callback"
)
c.NextcloudOAuthenticator.allow_all = True
c.NextcloudOAuthenticator.refresh_pre_spawn = True
c.NextcloudOAuthenticator.enable_auth_state = True
c.NextcloudOAuthenticator.auth_refresh_age = 3600
c.NextcloudOAuthenticator.post_auth_hook = post_auth_hook
