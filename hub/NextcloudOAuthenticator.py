import os
import time
import requests
from datetime import datetime
from urllib.parse import urlparse

import jwt
from jwt import PyJWKClient
from jupyterhub.handlers.base import BaseHandler
from oauthenticator.generic import GenericOAuthenticator

token_url = 'https://' + os.environ[
    'NEXTCLOUD_HOST'] + '/index.php/apps/oauth2/api/v1/token'
debug = os.environ.get('NEXTCLOUD_DEBUG_OAUTH',
                       'false').lower() in ['true', '1', 'yes']


def get_nextcloud_access_token(refresh_token):
    client_id = os.environ['NEXTCLOUD_CLIENT_ID']
    client_secret = os.environ['NEXTCLOUD_CLIENT_SECRET']

    code = refresh_token
    data = {
        'grant_type': 'refresh_token',
        'code': code,
        'refresh_token': refresh_token,
        'client_id': client_id,
        'client_secret': client_secret
    }
    response = requests.post(token_url, data=data)
    if debug:
        print(response.text)
    return response.json()


def post_auth_hook(authenticator, handler, authentication):
    # user = authentication['auth_state']['oauth_user']['ocs']['data']['id']
    auth_state = authentication['auth_state']
    auth_state['token_expires'] = time.time(
    ) + auth_state['token_response']['expires_in']
    authentication['auth_state'] = auth_state
    return authentication


# Comma-separated allowlist of OCM token issuer domains. The OCMLoginHandler
# rejects JWTs whose iss-host isn't in this set.
_OCM_TRUSTED_ISSUER_DOMAINS = frozenset(
    d.strip() for d in os.environ.get('OCM_TRUSTED_ISSUER_DOMAINS', '').split(',') if d.strip()
)

_ocm_jwks_clients: dict[str, PyJWKClient] = {}


def _ocm_jwks(domain):
    client = _ocm_jwks_clients.get(domain)
    if client is None:
        client = PyJWKClient(f'https://{domain}/.well-known/jwks.json', cache_keys=True)
        _ocm_jwks_clients[domain] = client
    return client


def _verify_ocm_jwt(token):
    """Verify an OCM webapp access_token and return its claims."""
    try:
        unverified = jwt.decode(token, options={'verify_signature': False})
    except jwt.InvalidTokenError as e:
        raise ValueError(f'malformed token: {e}')
    issuer = unverified.get('iss')
    if not issuer:
        raise ValueError('token missing iss claim')
    parsed = urlparse(issuer)
    if parsed.scheme != 'https' or not parsed.netloc:
        raise ValueError('iss must be an https URL')
    if _OCM_TRUSTED_ISSUER_DOMAINS and parsed.netloc not in _OCM_TRUSTED_ISSUER_DOMAINS:
        raise ValueError(f'issuer {parsed.netloc} not in trusted issuer allowlist')
    signing_key = _ocm_jwks(parsed.netloc).get_signing_key_from_jwt(token).key
    return jwt.decode(
        token,
        signing_key,
        algorithms=['RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'EdDSA'],
        issuer=issuer,
        options={'require': ['iss', 'sub', 'aud', 'exp', 'client_id'], 'verify_aud': False},
    )


class OCMLoginHandler(BaseHandler):
    """Logs in a synthetic ocm:<aud> user given a valid OCM access_token.

    The browser arrives here via auto-submit form from /services/ocm/open.
    We verify the JWT, ensure the synthetic user, set the hub session
    cookie, and redirect to next (which is the spawned server URL).
    """

    async def post(self):
        access_token = self.get_argument('access_token', '')
        next_url = self.get_argument('next', '/hub/home')
        if not access_token:
            self.set_status(400)
            self.write('access_token missing')
            return
        try:
            claims = _verify_ocm_jwt(access_token)
        except (ValueError, jwt.InvalidTokenError) as e:
            self.set_status(401)
            self.write(f'OCM token verification failed: {e}')
            return
        username = f'ocm:{claims["aud"]}'
        user = await self.auth_to_user({'name': username})
        self.set_login_cookie(user)
        self.redirect(next_url)


class NextcloudOAuthenticator(GenericOAuthenticator):

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.user_dict = {}

    def get_handlers(self, app):
        return super().get_handlers(app) + [(r'/ocm-login', OCMLoginHandler)]

    async def pre_spawn_start(self, user, spawner):
        super().pre_spawn_start(user, spawner)
        # OCM share spawn: user_options carries the share details. We inject
        # the webdav URI + bearer token as env so the singleuser image can
        # mount it (rclone/davfs2). FUSE needs SYS_ADMIN.
        ocm = (spawner.user_options or {}).get('ocm_share')
        if ocm:
            spawner.environment['OCM_WEBDAV_URI'] = ocm['webdav_uri']
            spawner.environment['OCM_BEARER'] = ocm['bearer']
            spawner.environment['OCM_PERMISSIONS'] = ','.join(ocm.get('permissions', ['read']))
            spawner.environment['OCM_RESOURCE_NAME'] = ocm.get('resource_name', '')
            spawner.environment['OCM_SHARER'] = ocm.get('sharer', '')
            spawner.environment['OCM_PROVIDER_ID'] = ocm.get('provider_id', '')
            extra = spawner.extra_container_config or {}
            sec = dict(extra.get('securityContext') or {})
            caps = dict(sec.get('capabilities') or {})
            caps['add'] = list({*caps.get('add', []), 'SYS_ADMIN'})
            sec['capabilities'] = caps
            extra['securityContext'] = sec
            spawner.extra_container_config = extra
            return
        auth_state = await user.get_auth_state()
        if not auth_state:
            return
        access_token = auth_state['access_token']
        spawner.environment['NEXTCLOUD_ACCESS_TOKEN'] = access_token

    async def refresh_user(self, user, handler=None):
        auth_state = await user.get_auth_state()
        if not auth_state:
            if debug:
                print(f'auth_state missing for {user}')
            return False
        access_token = auth_state['access_token']
        refresh_token = auth_state['refresh_token']
        token_response = auth_state['token_response']
        now = time.time()
        now_hr = datetime.fromtimestamp(now)
        expires = auth_state['token_expires']
        expires_hr = datetime.fromtimestamp(expires)
        expires = 0
        if debug:
            print(f'auth_state for {user}: {auth_state}')
        if now >= expires:
            if debug:
                print(f'Time is: {now_hr}, token expired: {expires_hr}')
                print(f'Refreshing token for {user}')
            try:
                token_response = get_nextcloud_access_token(refresh_token)
                auth_state['access_token'] = token_response['access_token']
                auth_state['refresh_token'] = token_response['refresh_token']
                auth_state[
                    'token_expires'] = now + token_response['expires_in']
                auth_state['token_response'] = token_response
                if debug:
                    print(f'Successfully refreshed token for {user.name}')
                    print(f'auth_state for {user.name}: {auth_state}')
                return {'name': user.name, 'auth_state': auth_state}
            except Exception as e:
                if debug:
                    print(f'Failed to refresh token for {user}')
                return False
        if debug:
            print(f'Time is: {now_hr}, token expires: {expires_hr}')
        return True


c.JupyterHub.authenticator_class = NextcloudOAuthenticator
c.NextcloudOAuthenticator.client_id = os.environ['NEXTCLOUD_CLIENT_ID']
c.NextcloudOAuthenticator.client_secret = os.environ['NEXTCLOUD_CLIENT_SECRET']
c.NextcloudOAuthenticator.login_service = 'Sunet Drive'
c.NextcloudOAuthenticator.username_claim = lambda r: r.get('ocs', {}).get(
    'data', {}).get('id')
c.NextcloudOAuthenticator.userdata_url = 'https://' + os.environ[
    'NEXTCLOUD_HOST'] + '/ocs/v2.php/cloud/user?format=json'
c.NextcloudOAuthenticator.authorize_url = 'https://' + os.environ[
    'NEXTCLOUD_HOST'] + '/index.php/apps/oauth2/authorize'
c.NextcloudOAuthenticator.token_url = token_url
c.NextcloudOAuthenticator.oauth_callback_url = 'https://' + os.environ[
    'JUPYTER_HOST'] + '/hub/oauth_callback'
c.NextcloudOAuthenticator.allow_all = True
c.NextcloudOAuthenticator.refresh_pre_spawn = True
c.NextcloudOAuthenticator.enable_auth_state = True
c.NextcloudOAuthenticator.auth_refresh_age = 3600
c.NextcloudOAuthenticator.post_auth_hook = post_auth_hook
