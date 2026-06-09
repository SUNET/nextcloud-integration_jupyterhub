"""OCM webapp-sharing endpoints for JupyterHub.

POST /services/ocm/shares
    Back channel from the paired Nextcloud's integration_jupyterhub app.
    Body is an OCM share-creation JSON with sharedSecret replaced by
    clientId inside each protocol entry. RFC 9421 signed via the "ocm"
    label using the sender's JWKS. Stored keyed by (sender-domain,
    clientId).

POST /services/ocm/open
    OCM webapp-sharing entrypoint. The receiver posts the spec-mandated
    form fields (access_token, access_token_ttl). The JWT is verified
    against the issuer's JWKS; the stored share is looked up by
    (iss-domain, JWT.client_id) and owner/shareWith identities are
    cross-checked.
"""

import base64
import hashlib
import hmac
import html as html_mod
import json
import os
import sqlite3
import threading
import time
import traceback
import urllib.parse
from dataclasses import dataclass
from urllib.parse import quote, urlparse

import http_sfv
import jwt
import requests
from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import ec, ed25519, padding, rsa
from cryptography.hazmat.primitives.asymmetric import utils as asym_utils
from jwt import PyJWKClient
from tornado.httpserver import HTTPServer
from tornado.ioloop import IOLoop
from tornado.web import Application, HTTPError, RequestHandler

DEBUG = os.environ.get("NEXTCLOUD_DEBUG_OAUTH", "false").lower() in ("true", "1", "yes")
JWKS_CLIENT_TTL = int(os.environ.get("OCM_JWKS_TTL", "300"))
SHARE_RECORD_TTL = int(os.environ.get("OCM_SHARE_TTL", "86400"))
SIG_FRESHNESS_WINDOW = int(os.environ.get("OCM_SIG_FRESHNESS", "300"))

REQUIRED_COVERED = (
    "@method",
    "@target-uri",
    "content-digest",
    "content-length",
    "date",
)

# Comma-separated allowlist of NC domains permitted to push via /shares.
# Required — empty allowlist means /shares rejects all requests.
TRUSTED_BACK_CHANNEL_DOMAINS = frozenset(
    d.strip()
    for d in os.environ.get("OCM_TRUSTED_BACK_CHANNEL_DOMAINS", "").split(",")
    if d.strip()
)

HUB_API_URL = os.environ.get("JUPYTERHUB_API_URL", "").rstrip("/")
HUB_API_TOKEN = os.environ.get("JUPYTERHUB_API_TOKEN", "")
OCM_LOGIN_URL = os.environ.get("OCM_LOGIN_URL", "/hub/ocm-login")


def log(msg):
    if DEBUG:
        with open("/proc/1/fd/1", "a") as stdout:
            print(f"[ocm] {msg}", file=stdout)


# ---------------------------------------------------------------------------
# JWKS cache
# ---------------------------------------------------------------------------

_jwks_clients: dict[str, tuple[PyJWKClient, float]] = {}


def get_jwks_client(domain: str) -> PyJWKClient:
    now = time.time()
    cached = _jwks_clients.get(domain)
    if cached and now - cached[1] < JWKS_CLIENT_TTL:
        return cached[0]
    jwks_url = f"https://{domain}/.well-known/jwks.json"
    client = PyJWKClient(jwks_url, cache_keys=True)
    _jwks_clients[domain] = (client, now)
    return client


def get_jwk_by_kid(domain: str, kid: str):
    client = get_jwks_client(domain)
    for k in client.get_jwk_set().keys:
        if k.key_id == kid:
            return k
    return None


def _norm_ocm(addr: str) -> str:
    """Canonicalise an OCM address: strip a stray scheme NC sometimes
    prefixes onto the host, drop a trailing slash, lowercase the host. The
    identity portion is left untouched."""
    i = addr.rfind("@")
    if i == -1:
        return addr
    user, host = addr[:i], addr[i + 1 :]
    low = host.lower()
    if low.startswith("https://"):
        host = host[8:]
    elif low.startswith("http://"):
        host = host[7:]
    return f'{user}@{host.rstrip("/").lower()}'


# ---------------------------------------------------------------------------
# Share store
# ---------------------------------------------------------------------------


@dataclass
class ShareRecord:
    sender_domain: str
    client_id: str
    sender: str  # OCM address: <user>@<host>
    owner: str  # OCM address
    share_with: str  # OCM address
    name: str
    provider_id: str
    share_type: str
    resource_type: str
    protocol: dict
    created_at: float


class ShareStore:
    """SQLite-backed so share records survive hub restarts. The DB lives on
    the hub's persistent volume (OCM_STORE_PATH, default /srv/jupyterhub)."""

    _COLS = (
        "sender_domain",
        "client_id",
        "sender",
        "owner",
        "share_with",
        "name",
        "provider_id",
        "share_type",
        "resource_type",
        "protocol",
        "created_at",
    )

    def __init__(self, path: str) -> None:
        self._lock = threading.Lock()
        self._path = path
        with self._lock, self._connect() as conn:
            conn.execute(
                "CREATE TABLE IF NOT EXISTS shares ("
                "sender_domain TEXT NOT NULL, client_id TEXT NOT NULL,"
                "sender TEXT, owner TEXT, share_with TEXT, name TEXT,"
                "provider_id TEXT, share_type TEXT, resource_type TEXT,"
                "protocol TEXT, created_at REAL,"
                "PRIMARY KEY (sender_domain, client_id))"
            )

    def _connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self._path, timeout=10)
        conn.row_factory = sqlite3.Row
        return conn

    def put(self, rec: ShareRecord) -> None:
        with self._lock, self._connect() as conn:
            conn.execute(
                "INSERT OR REPLACE INTO shares (" + ",".join(self._COLS) + ") "
                "VALUES (" + ",".join("?" * len(self._COLS)) + ")",
                (
                    rec.sender_domain,
                    rec.client_id,
                    rec.sender,
                    rec.owner,
                    rec.share_with,
                    rec.name,
                    rec.provider_id,
                    rec.share_type,
                    rec.resource_type,
                    json.dumps(rec.protocol),
                    rec.created_at,
                ),
            )

    def get(self, sender_domain: str, client_id: str) -> ShareRecord | None:
        now = time.time()
        with self._lock, self._connect() as conn:
            row = conn.execute(
                "SELECT * FROM shares WHERE sender_domain=? AND client_id=?",
                (sender_domain, client_id),
            ).fetchone()
            if row is None:
                return None
            if now - row["created_at"] > SHARE_RECORD_TTL:
                conn.execute(
                    "DELETE FROM shares WHERE sender_domain=? AND client_id=?",
                    (sender_domain, client_id),
                )
                return None
            return ShareRecord(
                sender_domain=row["sender_domain"],
                client_id=row["client_id"],
                sender=row["sender"],
                owner=row["owner"],
                share_with=row["share_with"],
                name=row["name"],
                provider_id=row["provider_id"],
                share_type=row["share_type"],
                resource_type=row["resource_type"],
                protocol=json.loads(row["protocol"]),
                created_at=row["created_at"],
            )

    def delete(self, sender_domain: str, client_id: str) -> None:
        with self._lock, self._connect() as conn:
            conn.execute(
                "DELETE FROM shares WHERE sender_domain=? AND client_id=?",
                (sender_domain, client_id),
            )


store = ShareStore(os.environ.get("OCM_STORE_PATH", "/srv/jupyterhub/ocm-shares.db"))


# ---------------------------------------------------------------------------
# RFC 9421 signature base + verification (mirror of nextcloud/server PR 60136)
# ---------------------------------------------------------------------------


def _derived(name: str, method: str, target_uri: str) -> str:
    parts = urlparse(target_uri)
    if name == "@method":
        return method.upper()
    if name == "@target-uri":
        return target_uri
    if name == "@authority":
        host = (parts.hostname or "").lower()
        port = parts.port
        scheme = (parts.scheme or "").lower()
        if port is not None and not (
            (scheme == "https" and port == 443) or (scheme == "http" and port == 80)
        ):
            return f"{host}:{port}"
        return host
    if name == "@scheme":
        return (parts.scheme or "").lower()
    if name == "@path":
        return parts.path or "/"
    if name == "@query":
        return f"?{parts.query}" if parts.query else ""
    if name == "@request-target":
        path = parts.path or "/"
        return path + (f"?{parts.query}" if parts.query else "")
    raise HTTPError(400, f"unsupported derived component: {name}")


def _normalize_field(value: str) -> str:
    return " ".join(value.strip().split())


def build_signature_base(
    method: str,
    target_uri: str,
    headers: dict[str, str],
    components: list[str],
    sig_params_line: str,
) -> bytes:
    lower_headers = {k.lower(): v for k, v in headers.items()}
    lines = []
    for comp in components:
        if comp.startswith("@"):
            value = _derived(comp, method, target_uri)
        else:
            if comp.lower() not in lower_headers:
                raise HTTPError(400, f"missing field for signature: {comp}")
            value = _normalize_field(lower_headers[comp.lower()])
        lines.append(f'"{comp}": {value}')
    lines.append(f'"@signature-params": {sig_params_line}')
    return "\n".join(lines).encode("ascii")


def verify_content_digest(header_value: str, body: bytes) -> bool:
    """At least one recognised algorithm matches; none mismatch."""
    matched = False
    for entry in header_value.split(","):
        entry = entry.strip()
        if not entry or "=" not in entry:
            continue
        alg, value = entry.split("=", 1)
        alg = alg.strip().lower()
        value = value.strip()
        if not (value.startswith(":") and value.endswith(":")):
            continue
        try:
            digest_bytes = base64.b64decode(value[1:-1], validate=True)
        except Exception:
            continue
        if alg == "sha-256":
            expected = hashlib.sha256(body).digest()
        elif alg == "sha-512":
            expected = hashlib.sha512(body).digest()
        else:
            continue
        if not hmac.compare_digest(expected, digest_bytes):
            return False
        matched = True
    return matched


_JOSE_TO_NATIVE = {
    "EdDSA": "ed25519",
    "ES256": "ecdsa-p256-sha256",
    "ES384": "ecdsa-p384-sha384",
    "RS256": "rsa-v1_5-sha256",
    "RS384": "rsa-v1_5-sha384",
    "RS512": "rsa-v1_5-sha512",
}
_NATIVE_ALGS = set(_JOSE_TO_NATIVE.values())


def normalize_alg(alg: str) -> str:
    lower = alg.lower()
    if lower in _NATIVE_ALGS:
        return lower
    if alg in _JOSE_TO_NATIVE:
        return _JOSE_TO_NATIVE[alg]
    raise HTTPError(401, f"unsupported signature algorithm: {alg}")


def _ecdsa_raw_to_der(raw: bytes, coord_size: int) -> bytes:
    if len(raw) != coord_size * 2:
        raise HTTPError(400, "ECDSA signature wrong length")
    r = int.from_bytes(raw[:coord_size], "big")
    s = int.from_bytes(raw[coord_size:], "big")
    return asym_utils.encode_dss_signature(r, s)


def verify_signature_primitive(
    native_alg: str, public_key, signature: bytes, base: bytes
) -> None:
    if native_alg == "ed25519":
        if not isinstance(public_key, ed25519.Ed25519PublicKey):
            raise HTTPError(401, "ed25519 alg requires an OKP/Ed25519 key")
        public_key.verify(signature, base)
        return
    if native_alg.startswith("rsa-v1_5-"):
        if not isinstance(public_key, rsa.RSAPublicKey):
            raise HTTPError(401, "rsa-v1_5 alg requires an RSA key")
        hash_alg = {
            "rsa-v1_5-sha256": hashes.SHA256(),
            "rsa-v1_5-sha384": hashes.SHA384(),
            "rsa-v1_5-sha512": hashes.SHA512(),
        }[native_alg]
        public_key.verify(signature, base, padding.PKCS1v15(), hash_alg)
        return
    if native_alg.startswith("ecdsa-"):
        if not isinstance(public_key, ec.EllipticCurvePublicKey):
            raise HTTPError(401, "ecdsa alg requires an EC key")
        coord = 32 if native_alg == "ecdsa-p256-sha256" else 48
        hash_alg = (
            hashes.SHA256() if native_alg == "ecdsa-p256-sha256" else hashes.SHA384()
        )
        public_key.verify(_ecdsa_raw_to_der(signature, coord), base, ec.ECDSA(hash_alg))
        return
    raise HTTPError(401, f"unsupported signature algorithm: {native_alg}")


def verify_ocm_signature(
    handler: RequestHandler, body: bytes, sender_domain: str
) -> None:
    """Verify the request's "ocm"-labeled RFC 9421 signature against sender_domain's JWKS."""
    sig_input_hdr = handler.request.headers.get("Signature-Input")
    sig_hdr = handler.request.headers.get("Signature")
    if not sig_input_hdr or not sig_hdr:
        raise HTTPError(401, "missing Signature-Input or Signature header")

    try:
        sig_input_dict = http_sfv.Dictionary()
        sig_input_dict.parse(sig_input_hdr.encode("ascii"))
        sig_dict = http_sfv.Dictionary()
        sig_dict.parse(sig_hdr.encode("ascii"))
    except Exception as e:
        raise HTTPError(400, f"malformed signature header: {e}")

    if "ocm" not in sig_input_dict or "ocm" not in sig_dict:
        raise HTTPError(401, 'no "ocm"-labeled signature')

    if sig_input_hdr.count("ocm=") > 1 or sig_hdr.count("ocm=") > 1:
        raise HTTPError(401, 'multiple "ocm" signatures present')

    inner_list_item = sig_input_dict["ocm"]
    covered = [item.value for item in inner_list_item]
    params = dict(inner_list_item.params)

    keyid = params.get("keyid")
    alg = params.get("alg")
    created = params.get("created")
    # `alg` is optional per RFC 9421 §3.3.7 (Nextcloud omits it by default);
    # the verifier resolves the algorithm from the JWK.
    if not keyid or created is None:
        raise HTTPError(401, "signature params must include keyid and created")

    for required in REQUIRED_COVERED:
        if required not in covered:
            raise HTTPError(401, f"signature must cover {required}")

    now = int(time.time())
    if abs(now - int(created)) > SIG_FRESHNESS_WINDOW:
        raise HTTPError(401, "signature created outside freshness window")

    # Nextcloud publishes keyId as a full URI "https://<host>/ocm#<fragment>"
    # and its JWKS `kid` equals that whole string. Simpler senders may use the
    # bare "<domain>#<kid>" form. Support both: derive the host to check
    # against the sender domain, and the value to match against the JWK `kid`.
    parsed_keyid = urlparse(keyid)
    if parsed_keyid.scheme and parsed_keyid.netloc:
        keyid_domain = parsed_keyid.netloc
        jwk_kid = keyid
    elif "#" in keyid:
        keyid_domain, jwk_kid = keyid.rsplit("#", 1)
    else:
        raise HTTPError(401, 'keyid must be a URI or "<domain>#<kid>"')
    if keyid_domain != sender_domain:
        raise HTTPError(
            401, f"keyid domain {keyid_domain} != sender domain {sender_domain}"
        )

    digest_hdr = handler.request.headers.get("Content-Digest")
    if not digest_hdr:
        raise HTTPError(400, "Content-Digest header missing")
    if not verify_content_digest(digest_hdr, body):
        raise HTTPError(400, "Content-Digest mismatch")

    jwk = get_jwk_by_kid(sender_domain, str(jwk_kid))
    if jwk is None:
        raise HTTPError(401, f"kid {jwk_kid} not found in {sender_domain} JWKS")
    if alg:
        native_alg = normalize_alg(str(alg))
        if jwk.algorithm_name and normalize_alg(jwk.algorithm_name) != native_alg:
            raise HTTPError(401, "algorithm sources disagree")
    elif jwk.algorithm_name:
        native_alg = normalize_alg(jwk.algorithm_name)
    else:
        raise HTTPError(401, "no alg param and JWK has no algorithm")

    sig_params_line = str(inner_list_item).strip()

    # TLS terminates at the gateway, so request.full_url() would give
    # http://…:<internal-port>. The sender signed the public https URL, so
    # reconstruct @target-uri from the X-Forwarded-* headers.
    fwd_proto = (
        (handler.request.headers.get("X-Forwarded-Proto") or handler.request.protocol)
        .split(",")[0]
        .strip()
    )
    fwd_host = (
        (handler.request.headers.get("X-Forwarded-Host") or handler.request.host)
        .split(",")[0]
        .strip()
    )
    target_uri = f"{fwd_proto}://{fwd_host}{handler.request.uri}"

    base = build_signature_base(
        method=handler.request.method,
        target_uri=target_uri,
        headers=dict(handler.request.headers.get_all()),
        components=list(covered),
        sig_params_line=sig_params_line,
    )

    sig_value = sig_dict["ocm"].value
    if not isinstance(sig_value, (bytes, bytearray)):
        raise HTTPError(400, "signature value must be a byte sequence")

    try:
        verify_signature_primitive(native_alg, jwk.key, bytes(sig_value), base)
    except InvalidSignature:
        raise HTTPError(401, f"signature verification failed (target_uri={target_uri})")


# ---------------------------------------------------------------------------
# JWT verification (front channel)
# ---------------------------------------------------------------------------


def verify_access_token(token: str) -> dict:
    try:
        unverified = jwt.decode(token, options={"verify_signature": False})
    except jwt.InvalidTokenError as e:
        raise HTTPError(401, f"malformed token: {e}")

    issuer = unverified.get("iss")
    if not issuer:
        raise HTTPError(401, "token missing iss claim")

    parsed = urlparse(issuer)
    if parsed.scheme != "https" or not parsed.netloc:
        raise HTTPError(401, "iss must be an https URL")

    try:
        signing_key = get_jwks_client(parsed.netloc).get_signing_key_from_jwt(token).key
    except Exception as e:
        log(f"JWKS lookup failed for {parsed.netloc}: {e}")
        raise HTTPError(401, f"could not resolve signing key: {type(e).__name__}: {e}")

    try:
        claims = jwt.decode(
            token,
            signing_key,
            algorithms=["RS256", "RS384", "RS512", "ES256", "ES384", "EdDSA"],
            issuer=issuer,
            options={
                "require": ["iss", "sub", "aud", "exp", "client_id"],
                "verify_aud": False,
            },
        )
    except jwt.InvalidTokenError as e:
        raise HTTPError(401, f"token verification failed: {e}")

    return claims


# ---------------------------------------------------------------------------
# Handlers
# ---------------------------------------------------------------------------

# ---------------------------------------------------------------------------
# JupyterHub API client
# ---------------------------------------------------------------------------


def _hub_request(method: str, path: str, **kwargs) -> requests.Response:
    headers = kwargs.pop("headers", {})
    headers["Authorization"] = f"token {HUB_API_TOKEN}"
    return requests.request(
        method, f"{HUB_API_URL}{path}", headers=headers, timeout=30, **kwargs
    )


def hub_ensure_user(name: str) -> None:
    r = _hub_request("POST", f'/users/{quote(name, safe="")}')
    if r.status_code not in (201, 409):
        raise HTTPError(
            502, f"failed to create hub user: {r.status_code} {r.text[:200]}"
        )
    # Mark this as an OCM account via membership in the 'ocm' group. The hub
    # uses the group (not a name-prefix heuristic) to block self-spawn and hide
    # the spawn UI. Set it here, at the single creation point, so it is in
    # place before the first spawn.
    _hub_request("POST", "/groups/ocm")  # 201 created or 409 exists
    rg = _hub_request("POST", "/groups/ocm/users", json={"users": [name]})
    if rg.status_code not in (200, 201):
        log(
            f"warning: could not add {name} to ocm group: {rg.status_code} {rg.text[:200]}"
        )


def hub_start_named_server(user: str, server: str, user_options: dict) -> None:
    r = _hub_request(
        "POST",
        f'/users/{quote(user, safe="")}/servers/{quote(server, safe="")}',
        json=user_options,
    )
    # 201 = started, 202 = starting, 400 = already running (idempotent path)
    if r.status_code not in (201, 202, 400):
        raise HTTPError(
            502, f"failed to start named-server: {r.status_code} {r.text[:200]}"
        )


def hub_delete_server(user: str, server: str) -> None:
    """Stop and remove a named server. Idempotent: a missing user/server or an
    already-stopped server is treated as success."""
    r = _hub_request(
        "DELETE",
        f'/users/{quote(user, safe="")}/servers/{quote(server, safe="")}',
        json={"remove": True},
    )
    # 202 = stopping then removed, 204 = removed, 400 = not running,
    # 404 = no such user/server — all fine for an idempotent reap.
    if r.status_code not in (202, 204, 400, 404):
        raise HTTPError(
            502, f"failed to delete named-server: {r.status_code} {r.text[:200]}"
        )


# ---------------------------------------------------------------------------
# Handlers
# ---------------------------------------------------------------------------


def _domain_of_ocm_address(addr: str) -> str:
    if "@" not in addr:
        raise HTTPError(400, f"malformed OCM address: {addr}")
    return addr.rsplit("@", 1)[1]


def _extract_client_id(protocol: dict) -> str:
    """Pull clientId from protocol.webapp; only multi+webapp is supported."""
    if protocol.get("name") != "multi":
        raise HTTPError(400, 'protocol.name must be "multi"')
    webapp = protocol.get("webapp")
    if not isinstance(webapp, dict) or "clientId" not in webapp:
        raise HTTPError(400, "protocol.webapp.clientId missing")
    return str(webapp["clientId"])


def _assert_share_token_identity(
    claims: dict, iss_domain: str, rec: ShareRecord
) -> None:
    """The presented access_token must belong to this share: its sub@iss must
    match the stored owner and its aud must match the stored shareWith. Used by
    /open (launch); /close authorises via the sender's signed back channel."""
    owner_from_jwt = f'{claims["sub"]}@{iss_domain}'
    if _norm_ocm(owner_from_jwt) != _norm_ocm(rec.owner):
        raise HTTPError(403, "JWT sub/iss does not match stored owner")
    if _norm_ocm(claims["aud"]) != _norm_ocm(rec.share_with):
        raise HTTPError(403, "JWT aud does not match stored shareWith")


class SharesHandler(RequestHandler):
    """Back channel: paired NC pushes share JSON (sharedSecret → clientId)."""

    def check_xsrf_cookie(self):
        return

    def write_error(self, status_code: int, **kwargs) -> None:
        # Surface the real cause of unexpected 500s: tornado swallows the
        # traceback into its own logger, which is easy to miss in the hub's
        # interleaved output. Log it explicitly.
        exc_info = kwargs.get("exc_info")
        if exc_info and not isinstance(exc_info[1], HTTPError):
            log(
                "SharesHandler unhandled exception:\n"
                + "".join(traceback.format_exception(*exc_info))
            )
        super().write_error(status_code, **kwargs)

    def post(self):
        body = self.request.body or b""
        try:
            payload = json.loads(body or b"{}")
        except json.JSONDecodeError:
            raise HTTPError(400, "invalid JSON body")

        sender = payload.get("sender")
        if not isinstance(sender, str) or "@" not in sender:
            raise HTTPError(400, "missing or malformed sender")
        sender_domain = _domain_of_ocm_address(sender)

        if sender_domain not in TRUSTED_BACK_CHANNEL_DOMAINS:
            raise HTTPError(403, f"sender domain not in trusted back-channel allowlist")

        verify_ocm_signature(self, body, sender_domain)

        try:
            protocol = payload["protocol"]
            client_id = _extract_client_id(protocol)
            rec = ShareRecord(
                sender_domain=sender_domain,
                client_id=client_id,
                sender=sender,
                owner=str(payload["owner"]),
                share_with=str(payload["shareWith"]),
                name=str(payload.get("name", "")),
                provider_id=str(payload["providerId"]),
                share_type=str(payload["shareType"]),
                resource_type=str(payload["resourceType"]),
                protocol=protocol,
                created_at=time.time(),
            )
        except KeyError as e:
            raise HTTPError(400, f"missing field: {e.args[0]}")

        store.put(rec)
        log(f"stored share ({sender_domain}, {client_id}) for {rec.share_with}")
        self.set_status(201)
        self.set_header("content-type", "application/json")
        self.write(json.dumps({"status": "stored"}))


class OpenHandler(RequestHandler):
    """Spec-mandated form POST entrypoint."""

    def check_xsrf_cookie(self):
        return

    def post(self):
        ct = self.request.headers.get("Content-Type", "")
        if not ct.startswith("application/x-www-form-urlencoded"):
            raise HTTPError(415, f"unsupported Content-Type: {ct}")

        token = self.get_body_argument("access_token", default=None)
        if not token:
            raise HTTPError(400, "access_token missing")
        # access_token_ttl: WOPI-compat, ignored; JWT.exp is authoritative.
        self.get_body_argument("access_token_ttl", default=None)
        # Where the receiver refreshes a lapsed token (OCM-API#368); passed
        # through to ocm-login so the gateway bounces lapsed users there.
        redirect_uri = self.get_body_argument("redirect_uri", default="")

        claims = verify_access_token(token)
        iss_domain = urlparse(claims["iss"]).netloc
        client_id = claims["client_id"]

        rec = store.get(iss_domain, client_id)
        if rec is None:
            raise HTTPError(404, "no share record for this token")

        _assert_share_token_identity(claims, iss_domain, rec)

        share_with = _norm_ocm(rec.share_with)
        log(f"opening share ({iss_domain}, {client_id}) for {share_with}")

        username = f"ocm:{share_with}"
        server_name = f"share-{client_id[:12]}"
        webdav = rec.protocol["webdav"]
        webapp = rec.protocol["webapp"]

        hub_ensure_user(username)
        hub_start_named_server(
            username,
            server_name,
            {
                "ocm_share": {
                    "webdav_uri": webdav["uri"],
                    "bearer": token,
                    "permissions": webdav.get("permissions", ["read"]),
                    "resource_name": rec.name,
                    "sharer": rec.owner,
                    "provider_id": rec.provider_id,
                },
            },
        )

        next_url = (
            f'/user/{quote(username, safe="@")}/{quote(server_name, safe="")}/lab'
        )
        self._render_handoff(token, next_url, rec.name, redirect_uri)

    def _render_handoff(
        self, access_token: str, next_url: str, share_name: str, redirect_uri: str = ""
    ) -> None:
        """Auto-submit a form to /hub/ocm-login so the hub sets a session cookie.

        The receiver (e.g. ocmremotewebapp) already placed this response in
        the container the user chose — an embedded iframe, the current tab,
        or a fresh tab. The handoff just logs the synthetic user in and lands
        on the notebook within that same frame, so it always targets _self.
        No second display dispatch (which previously nested another iframe or
        broke out to _top).
        """
        title = html_mod.escape(f"Opening {share_name}")
        action = html_mod.escape(OCM_LOGIN_URL, quote=True)
        at_attr = html_mod.escape(access_token, quote=True)
        next_attr = html_mod.escape(next_url, quote=True)
        redirect_attr = html_mod.escape(redirect_uri, quote=True)

        redirect_field = (
            f'<input type="hidden" name="redirect_uri" value="{redirect_attr}">'
            if redirect_uri
            else ""
        )
        body = (
            f'<form id="f" method="POST" action="{action}" target="_self">'
            f'<input type="hidden" name="access_token" value="{at_attr}">'
            f'<input type="hidden" name="next" value="{next_attr}">'
            f"{redirect_field}"
            "</form>"
            '<script>document.getElementById("f").submit()</script>'
        )

        self.set_header("Content-Type", "text/html; charset=utf-8")
        self.write(
            f"<!doctype html><html><head><title>{title}</title>"
            "<style>html,body{margin:0;padding:0}</style>"
            f"</head><body>{body}</body></html>"
        )


class CloseHandler(RequestHandler):
    """Reap a share's notebook server. Sender-driven: the paired NC signs a
    back-channel POST (sender + clientId), same auth as /shares. Idempotent —
    an already-gone share returns success so the sender can fire-and-forget."""

    def check_xsrf_cookie(self):
        return

    def post(self):
        body = self.request.body or b""
        try:
            payload = json.loads(body or b"{}")
        except json.JSONDecodeError:
            raise HTTPError(400, "invalid JSON body")

        sender = payload.get("sender")
        if not isinstance(sender, str) or "@" not in sender:
            raise HTTPError(400, "missing or malformed sender")
        sender_domain = _domain_of_ocm_address(sender)

        if sender_domain not in TRUSTED_BACK_CHANNEL_DOMAINS:
            raise HTTPError(403, "sender domain not in trusted back-channel allowlist")

        verify_ocm_signature(self, body, sender_domain)

        client_id = str(payload.get("clientId", ""))
        if not client_id:
            raise HTTPError(400, "clientId missing")

        rec = store.get(sender_domain, client_id)
        if rec is None:
            # Already reaped / never stored — nothing to do.
            self.set_status(200)
            self.set_header("content-type", "application/json")
            self.write(json.dumps({"status": "gone"}))
            return

        share_with = _norm_ocm(rec.share_with)
        username = f"ocm:{share_with}"
        server_name = f"share-{client_id[:12]}"
        hub_delete_server(username, server_name)
        store.delete(sender_domain, client_id)
        log(f"closed share ({sender_domain}, {client_id}) for {share_with}")

        self.set_status(200)
        self.set_header("content-type", "application/json")
        self.write(json.dumps({"status": "closed"}))


class PingHandler(RequestHandler):
    def get(self):
        self.set_header("content-type", "application/json")
        self.write(json.dumps({"ping": 1}))


def main():
    prefix = os.environ["JUPYTERHUB_SERVICE_PREFIX"]
    app = Application(
        [
            (urllib.parse.urljoin(prefix, "open"), OpenHandler),
            (urllib.parse.urljoin(prefix, "close"), CloseHandler),
            (urllib.parse.urljoin(prefix, "shares"), SharesHandler),
            (prefix + "/?", PingHandler),
        ]
    )
    url = urlparse(os.environ["JUPYTERHUB_SERVICE_URL"])
    HTTPServer(app).listen(url.port)
    IOLoop.current().start()


if __name__ == "__main__":
    main()
