# SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Shared OCM JWT verification helpers used by the authenticator handlers and
by the OCM managed service. The two callers want the same algorithm list,
issuer-domain allowlist behaviour and JWKS cache, so this module owns it.

Env vars read here are read lazily (on first use) so importing this module
in a process that does not actually verify tokens (e.g. linting, tests)
does not require the deployment env to be configured.
"""

import json
import os
import re
import time
import urllib.request
from typing import Iterable
from urllib.parse import urlparse

import jwt
from jwt import PyJWKClient

_OCM_ALGORITHMS = ["RS256", "RS384", "RS512", "ES256", "ES384", "EdDSA"]


_jwks_clients: dict[str, tuple[PyJWKClient, float]] = {}


def _trusted_issuer_domains() -> frozenset[str]:
    # Falls back to the back-channel allowlist: in Provisioned Integration the
    # token issuers are exactly the paired OCM Servers.
    issuers = frozenset(
        d.strip()
        for d in os.environ.get("OCM_TRUSTED_ISSUER_DOMAINS", "").split(",")
        if d.strip()
    )
    if issuers:
        return issuers
    return frozenset(
        d.strip()
        for d in os.environ.get("OCM_TRUSTED_BACK_CHANNEL_DOMAINS", "").split(",")
        if d.strip()
    )


def _resolve_jwks_uri(domain: str) -> str:
    """Fetch the OCM discovery and return the advertised jwksUri."""
    for discovery_url in (
        f"https://{domain}/.well-known/ocm",
        f"https://{domain}/ocm-provider",
    ):
        try:
            with urllib.request.urlopen(discovery_url, timeout=10) as resp:
                data = json.loads(resp.read())
            uri = data.get("jwksUri", "")
            if uri:
                return uri
        except Exception:
            continue
    raise ValueError(f"could not resolve jwksUri from discovery for {domain}")


def get_jwks_client(domain: str, ttl: int | None = None) -> PyJWKClient:
    """Return a cached PyJWKClient for `domain`, refreshing it past `ttl` seconds.

    The JWKS URL is resolved from the peer's OCM discovery `jwksUri` field.
    """
    if ttl is None:
        ttl = int(os.environ.get("OCM_JWKS_TTL", "300"))
    now = time.time()
    cached = _jwks_clients.get(domain)
    if cached and now - cached[1] < ttl:
        return cached[0]
    jwks_uri = _resolve_jwks_uri(domain)
    client = PyJWKClient(jwks_uri, cache_keys=True)
    _jwks_clients[domain] = (client, now)
    return client


def verify_ocm_jwt(
    token: str,
    *,
    trusted_issuer_domains: Iterable[str] | None = None,
) -> dict:
    """Verify an OCM-issued JWT and return its claims.

    Raises `ValueError` (and `jwt.InvalidTokenError` from PyJWT) on any failure
    — both the legacy oauth_config and the OCM service caught both, so callers
    keep the existing try/except.
    """
    try:
        unverified = jwt.decode(token, options={"verify_signature": False})
        header = jwt.get_unverified_header(token)
    except jwt.InvalidTokenError as e:
        raise ValueError(f"malformed token: {e}")

    # RFC 9068 §4: reject tokens whose JOSE typ is not at+jwt (the application/
    # prefix may be omitted, media type parameters are insignificant).
    typ = str(header.get("typ", "")).split(";")[0].strip().lower()
    typ = typ[len("application/") :] if typ.startswith("application/") else typ
    if typ != "at+jwt":
        raise ValueError('token typ must be "at+jwt"')

    issuer = unverified.get("iss")
    if not issuer:
        raise ValueError("token missing iss claim")
    parsed = urlparse(issuer)
    if parsed.scheme != "https" or not parsed.netloc:
        raise ValueError("iss must be an https URL")

    allowlist = (
        frozenset(trusted_issuer_domains)
        if trusted_issuer_domains is not None
        else _trusted_issuer_domains()
    )
    # An empty allowlist rejects every token: a valid signature from an
    # arbitrary internet issuer must never be honored.
    if parsed.netloc not in allowlist:
        raise ValueError(f"issuer {parsed.netloc} not in trusted issuer allowlist")

    signing_key = get_jwks_client(parsed.netloc).get_signing_key_from_jwt(token).key
    claims = jwt.decode(
        token,
        signing_key,
        algorithms=_OCM_ALGORITHMS,
        issuer=issuer,
        options={
            "require": ["iss", "sub", "aud", "exp", "client_id"],
            "verify_aud": False,
        },
    )
    if not isinstance(claims["aud"], str):
        raise ValueError("aud must be a single OCM address string")
    return claims


def norm_ocm(addr: str) -> str:
    """Canonicalise an OCM address: strip a stray scheme off the host, drop a
    trailing slash, lowercase the host. The identity portion is opaque."""
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


def receiver_host_from_aud(aud: str) -> str:
    """Extract the bare host of the receiver from an OCM `aud` claim.

    OCM cloud-ids come in two shapes — `bob@bob.example` and
    `bob@https://bob.example` — strip the scheme and any trailing path.
    """
    host = aud.rsplit("@", 1)[-1] if "@" in aud else aud
    return re.sub(r"^https?://", "", host).strip().rstrip("/").split("/")[0]


def receiver_root_from_aud(aud: str) -> str | None:
    host = receiver_host_from_aud(aud)
    return f"https://{host}/" if host else None
