import sys

c.JupyterHub.load_roles = [{
    "name": "refresh-token",
    "services": ["refresh-token"],
    "scopes": ["read:users", "admin:auth_state"]
}, {
    "name": "ocm",
    "services": ["ocm"],
    "scopes": ["admin:users", "admin:servers"]
}, {
    "name":
    "user",
    "scopes": [
        "access:services!service=refresh-token",
        "read:services!service=refresh-token",
        "self",
    ],
}, {
    "name":
    "server",
    "scopes": [
        "access:services!service=refresh-token",
        "read:services!service=refresh-token",
        "inherit",
    ],
}]
c.JupyterHub.services = [{
    'name':
    'refresh-token',
    'url':
    'http://' + os.environ.get('HUB_SERVICE_HOST', 'hub') + ':' +
    os.environ.get('HUB_SERVICE_PORT_REFRESH_TOKEN', '8082'),
    'display':
    False,
    'oauth_no_confirm':
    True,
    'api_token':
    os.environ['JUPYTERHUB_API_KEY'],
    'command': [sys.executable, '/usr/local/etc/jupyterhub/refresh-token.py']
}, {
    'name':
    'ocm',
    'url':
    'http://' + os.environ.get('HUB_SERVICE_HOST', 'hub') + ':' +
    os.environ.get('HUB_SERVICE_PORT_OCM', '8083'),
    'display':
    False,
    'api_token':
    os.environ['JUPYTERHUB_OCM_API_KEY'],
    'command': [sys.executable, '/usr/local/etc/jupyterhub/ocm-service.py']
}]
c.JupyterHub.admin_users = {"refresh-token", "ocm"}
c.JupyterHub.api_tokens = {
    os.environ['JUPYTERHUB_API_KEY']: "refresh-token",
    os.environ['JUPYTERHUB_OCM_API_KEY']: "ocm",
}
