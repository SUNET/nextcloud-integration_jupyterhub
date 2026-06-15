# SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""JupyterHub-side handlers for the OCM share-launch handoff.

`OCMLoginHandler` accepts the cross-origin POST minted by the OCM service's
launcher and exchanges it for a hub session cookie. `OCMGatewayHandler`
replaces the default OAuth login route so a lapsed OCM user is bounced back
to their own receiver rather than landing in alice's OAuth screen.
"""

from urllib.parse import urlparse

import jwt
from jupyterhub.handlers.base import BaseHandler
from jupyterhub.utils import url_path_join
from tornado.httputil import url_concat

from .jwt_verify import (
    receiver_host_from_aud,
    receiver_root_from_aud,
    verify_ocm_jwt,
)


def _refresh_target(redirect_uri: str, aud: str) -> str | None:
    """Where to bounce a lapsed OCM user.

    Prefer the receiver-supplied redirect_uri (OCM-API#368) but only if it's
    an https URL on the same host as the token's audience (the receiver) —
    otherwise a valid token could aim the redirect anywhere. Fall back to
    the receiver root.
    """
    if redirect_uri:
        p = urlparse(redirect_uri)
        if (
            p.scheme == "https"
            and p.netloc.lower() == receiver_host_from_aud(aud).lower()
        ):
            return redirect_uri
    return receiver_root_from_aud(aud)


class OCMLoginHandler(BaseHandler):
    """Cross-origin handoff target for the OCM service's launcher.

    The launcher POSTs the access_token JWT (and a server-relative `next`
    URL) here. Verifying the JWT establishes identity, so the usual
    JupyterHub _xsrf cookie check is redundant — and disabled, since the
    POST cannot carry one across the origin boundary.
    """

    def check_xsrf_cookie(self):  # noqa: D401 - tornado hook
        return

    async def post(self):
        access_token = self.get_argument("access_token", "")
        next_url = self.get_argument("next", "/hub/home")
        if not access_token:
            self.set_status(400)
            self.write("access_token missing")
            return
        try:
            claims = verify_ocm_jwt(access_token)
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
        # Remember where to bounce the user once the token lapses: the
        # receiver-supplied redirect_uri, else the receiver root. Signed
        # cookie — auth_state is unreachable once the user is de-authed.
        target = _refresh_target(self.get_argument("redirect_uri", ""), claims["aud"])
        if target:
            self.set_secure_cookie("ocm_redirect_uri", target)
        self.redirect(next_url)


class OCMGatewayHandler(BaseHandler):
    """`login_url` lands here. Lapsed OCM users (signed redirect cookie) go
    back to their receiver to re-mint; everyone else proceeds to OAuth."""

    def get(self):
        target = self.get_secure_cookie("ocm_redirect_uri")
        if target:
            self.redirect(target.decode())
            return
        next_url = self.get_argument("next", "")
        self.redirect(
            url_concat(
                url_path_join(self.hub.base_url, "oauth_login"),
                {"next": next_url} if next_url else {},
            )
        )
