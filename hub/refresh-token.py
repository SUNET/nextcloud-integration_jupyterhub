"""A token refresh service authenticating with the Hub.

This service serves `/services/refresh-token/`,
authenticated with the Hub,
showing the user their own info.
"""

import json
import os
import socket
from urllib.parse import urlparse

import requests
from jupyterhub.services.auth import HubAuthenticated
from jupyterhub.utils import url_path_join
from tornado.httpserver import HTTPServer
from tornado.ioloop import IOLoop
from tornado.web import Application, HTTPError, RequestHandler, authenticated

debug = os.environ.get("NEXTCLOUD_DEBUG_OAUTH", "false").lower() in ["true", "1", "yes"]


def my_debug(s):
    if debug:
        with open("/proc/1/fd/1", "a") as stdout:
            print(s, file=stdout)


class RefreshHandler(HubAuthenticated, RequestHandler):
    def api_request(self, method, url, **kwargs):
        my_debug(f"{self.hub_auth}")
        url = url_path_join(self.hub_auth.api_url, url)
        allow_404 = kwargs.pop("allow_404", False)
        headers = kwargs.setdefault("headers", {})
        headers.setdefault("Authorization", f"token {self.hub_auth.api_token}")
        try:
            r = requests.request(method, url, **kwargs)
        except requests.ConnectionError as e:
            my_debug(f"Error connecting to {url}: {e}")
            msg = f"Failed to connect to Hub API at {url}."
            msg += f"  Is the Hub accessible at this URL (from host: {socket.gethostname()})?"

            if "127.0.0.1" in url:
                msg += (
                    "  Make sure to set c.JupyterHub.hub_ip to an IP accessible to"
                    + " single-user servers if the servers are not on the same host as the Hub."
                )
            raise HTTPError(500, msg)

        data = None
        if r.status_code == 404 and allow_404:
            pass
        elif r.status_code == 403:
            my_debug(
                "Lacking permission to check authorization with JupyterHub,"
                + f" my auth token may have expired: [{r.status_code}] {r.reason}"
            )
            my_debug(r.text)
            raise HTTPError(
                500, "Permission failure checking authorization, I may need a new token"
            )
        elif r.status_code >= 500:
            my_debug(
                f"Upstream failure verifying auth token: [{r.status_code}] {r.reason}"
            )
            my_debug(r.text)
            raise HTTPError(502, "Failed to check authorization (upstream problem)")
        elif r.status_code >= 400:
            my_debug(f"Failed to check authorization: [{r.status_code}] {r.reason}")
            my_debug(r.text)
            raise HTTPError(500, "Failed to check authorization")
        else:
            data = r.json()
        return data

    @authenticated
    def get(self):
        user_model = self.get_current_user()
        user_data = self.api_request("GET", url_path_join("users", user_model["name"]))
        auth_state = user_data["auth_state"]
        access_token = auth_state["access_token"]
        token_expires = auth_state["token_expires"]

        self.set_header("content-type", "application/json")
        self.write(
            json.dumps(
                {"access_token": access_token, "token_expires": token_expires},
                indent=1,
                sort_keys=True,
            )
        )


class PingHandler(RequestHandler):

    def get(self):
        my_debug(f"DEBUG: In ping get")
        self.set_header("content-type", "application/json")
        self.write(json.dumps({"ping": 1}))


def main():
    app = Application(
        [
            (os.environ["JUPYTERHUB_SERVICE_PREFIX"] + "tokens", RefreshHandler),
            (os.environ["JUPYTERHUB_SERVICE_PREFIX"] + "/?", PingHandler),
        ]
    )

    http_server = HTTPServer(app)
    url = urlparse(os.environ["JUPYTERHUB_SERVICE_URL"])

    http_server.listen(url.port)

    IOLoop.current().start()


if __name__ == "__main__":
    main()
