# SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Offline compatibility and security regression checks for the Hub package."""

import base64
import hashlib
import os
import tempfile
import time
import unittest
from dataclasses import replace
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

import jwt
from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import ec, ed25519, padding, rsa
from cryptography.hazmat.primitives.asymmetric.utils import decode_dss_signature
from jupyterhub.app import JupyterHub
from tornado.httputil import HTTPHeaders, HTTPServerRequest
from tornado.web import HTTPError
from traitlets.config import Config

from nextcloud_ocm_jupyterhub import config, jwt_verify
from nextcloud_ocm_jupyterhub.authenticator import NextcloudOAuthenticator
from nextcloud_ocm_jupyterhub.handlers import _refresh_target
from nextcloud_ocm_jupyterhub.services import ocm, refresh_token


class ConfigTest(unittest.TestCase):
    def test_packaged_defaults_use_supported_hub_traits(self) -> None:
        environment = {
            "NEXTCLOUD_HOST": "cloud.example",
            "JUPYTER_HOST": "hub.example",
            "NEXTCLOUD_CLIENT_ID": "test-client",
            "NEXTCLOUD_CLIENT_SECRET": "test-only-secret",
            "JUPYTERHUB_API_KEY": "test-refresh-token",
            "JUPYTERHUB_OCM_API_KEY": "test-ocm-token",
            "OCM_TRUSTED_BACK_CHANNEL_DOMAINS": "cloud.example",
        }
        with patch.dict(os.environ, environment, clear=True):
            settings = Config()
            config.apply_defaults(settings)
        hub = JupyterHub(config=settings)
        self.assertIs(hub.authenticator_class, NextcloudOAuthenticator)
        self.assertTrue(set(settings.JupyterHub).issubset(hub.traits()))
        authenticator = NextcloudOAuthenticator(config=settings)
        self.assertEqual(authenticator.login_url("/hub/"), "/hub/ocm-gateway")
        self.assertEqual(
            authenticator.oauth_callback_url, "https://hub.example/hub/oauth_callback"
        )
        services = {service["name"]: service for service in hub.services}
        self.assertEqual(set(services), {"ocm", "refresh-token"})
        self.assertEqual(
            services["ocm"]["environment"]["OCM_TRUSTED_BACK_CHANNEL_DOMAINS"],
            "cloud.example",
        )
        self.assertTrue((Path(hub.template_paths[0]) / "home.html").is_file())
        self.assertTrue(callable(refresh_token.main))

    def test_refresh_redirect_must_stay_on_receiver(self) -> None:
        self.assertEqual(
            _refresh_target("https://receiver.example/refresh", "bob@receiver.example"),
            "https://receiver.example/refresh",
        )
        for unsafe in (
            "https://evil.example/",
            "http://receiver.example/",
            "//evil.example/",
        ):
            with self.subTest(unsafe=unsafe):
                self.assertEqual(
                    _refresh_target(unsafe, "bob@receiver.example"),
                    "https://receiver.example/",
                )


class JWTTest(unittest.TestCase):
    def setUp(self) -> None:
        self.key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
        self.claims: dict[str, object] = {
            "iss": "https://cloud.example",
            "sub": "alice@cloud.example",
            "aud": "bob@receiver.example",
            "exp": int(time.time()) + 300,
            "client_id": "share-123",
        }
        client = SimpleNamespace(
            get_signing_key_from_jwt=lambda token: SimpleNamespace(
                key=self.key.public_key()
            )
        )
        patcher = patch.object(jwt_verify, "get_jwks_client", return_value=client)
        patcher.start()
        self.addCleanup(patcher.stop)

    def token(self, *, typ: str = "at+jwt") -> str:
        return jwt.encode(
            self.claims, self.key, algorithm="RS256", headers={"typ": typ}
        )

    def verify(self, token: str) -> dict:
        return jwt_verify.verify_ocm_jwt(
            token, trusted_issuer_domains=["cloud.example"]
        )

    def test_valid_access_token_and_media_type(self) -> None:
        for typ in ("at+jwt", "application/at+jwt; charset=utf-8"):
            with self.subTest(typ=typ):
                self.assertEqual(self.verify(self.token(typ=typ)), self.claims)

    def test_empty_allowlist_rejects_even_a_valid_signature(self) -> None:
        with self.assertRaisesRegex(ValueError, "allowlist"):
            jwt_verify.verify_ocm_jwt(self.token(), trusted_issuer_domains=[])

    def test_regular_jwt_is_not_an_access_token(self) -> None:
        with self.assertRaisesRegex(ValueError, "typ"):
            self.verify(self.token(typ="JWT"))

    def test_expired_token_is_rejected(self) -> None:
        self.claims["exp"] = int(time.time()) - 60
        with self.assertRaises(jwt.ExpiredSignatureError):
            self.verify(self.token())

    def test_audience_must_be_a_single_ocm_identity(self) -> None:
        self.claims["aud"] = ["bob@receiver.example"]
        with self.assertRaisesRegex(ValueError, "single OCM address"):
            self.verify(self.token())

    def test_provider_id_claim_is_required(self) -> None:
        del self.claims["client_id"]
        with self.assertRaises(jwt.MissingRequiredClaimError):
            self.verify(self.token())


class SignatureTest(unittest.TestCase):
    def test_structured_fields_round_trip(self) -> None:
        body = b'{"providerId":"share-123"}'
        key = ed25519.Ed25519PrivateKey.generate()
        line = (
            '("@method" "@target-uri" "content-digest" "content-length")'
            f';created={int(time.time())};keyid="cloud.example#key";alg="ed25519";tag="ocm"'
        )
        headers = HTTPHeaders(
            {
                "X-Forwarded-Proto": "https",
                "X-Forwarded-Host": "hub.example",
                "Content-Digest": "sha-256=:"
                + base64.b64encode(hashlib.sha256(body).digest()).decode("ascii")
                + ":",
                "Content-Length": str(len(body)),
                "Signature-Input": "sig1=" + line,
            }
        )
        base = ocm.build_signature_base(
            "POST",
            "https://hub.example/services/ocm/shares",
            dict(headers.get_all()),
            list(ocm.REQUIRED_COVERED),
            line,
        )
        headers["Signature"] = (
            "sig1=:" + base64.b64encode(key.sign(base)).decode("ascii") + ":"
        )
        request = HTTPServerRequest(
            method="POST",
            uri="/services/ocm/shares",
            headers=headers,
            host="internal:8083",
        )
        handler = SimpleNamespace(request=request)
        jwk = SimpleNamespace(key=key.public_key(), algorithm_name="EdDSA")
        with patch.object(ocm, "get_jwk_by_kid", return_value=jwk):
            ocm.verify_ocm_signature(handler, body, "cloud.example")
            with self.assertRaises(HTTPError):
                ocm.verify_ocm_signature(handler, body + b" ", "cloud.example")
            with self.assertRaises(HTTPError):
                ocm.verify_ocm_signature(handler, body, "untrusted.example")

    def test_digest_rejects_modified_body(self) -> None:
        body = b'{"providerId":"share-123"}'
        digest = base64.b64encode(hashlib.sha256(body).digest()).decode("ascii")
        self.assertTrue(ocm.verify_content_digest("sha-256=:" + digest + ":", body))
        self.assertFalse(
            ocm.verify_content_digest("sha-256=:" + digest + ":", body + b" ")
        )
        self.assertFalse(ocm.verify_content_digest("unknown=:YWJj:", body))

    def test_signature_algorithms_verify_and_reject_tampering(self) -> None:
        data = (
            b'"@method": POST\n"@target-uri": https://hub.example/services/ocm/shares'
        )
        ed_key = ed25519.Ed25519PrivateKey.generate()
        rsa_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
        ec_key = ec.generate_private_key(ec.SECP256R1())
        r, s = decode_dss_signature(ec_key.sign(data, ec.ECDSA(hashes.SHA256())))
        ec_signature = r.to_bytes(32, "big") + s.to_bytes(32, "big")
        signatures = [
            ("ed25519", ed_key.public_key(), ed_key.sign(data)),
            (
                "rsa-v1_5-sha256",
                rsa_key.public_key(),
                rsa_key.sign(data, padding.PKCS1v15(), hashes.SHA256()),
            ),
            ("ecdsa-p256-sha256", ec_key.public_key(), ec_signature),
        ]
        for algorithm, key, signature in signatures:
            with self.subTest(algorithm=algorithm):
                ocm.verify_signature_primitive(algorithm, key, signature, data)
                with self.assertRaises(InvalidSignature):
                    ocm.verify_signature_primitive(
                        algorithm, key, signature, data + b"modified"
                    )


class ShareStoreTest(unittest.TestCase):
    def setUp(self) -> None:
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.path = str(Path(directory.name) / "shares.sqlite3")
        self.store = ocm.ShareStore(self.path)
        self.record = ocm.ShareRecord(
            sender_domain="cloud.example",
            sender="alice@cloud.example",
            owner="alice@cloud.example",
            share_with="bob@receiver.example",
            name="notebooks",
            provider_id="share-123",
            share_type="user",
            resource_type="folder",
            protocol={"name": "multi", "webapp": {"targets": ["blank"]}},
            created_at=time.time(),
            expiration=None,
        )

    def test_records_survive_restart_and_sender_domains_are_isolated(self) -> None:
        self.store.put(self.record)
        reopened = ocm.ShareStore(self.path)
        self.assertEqual(reopened.get("cloud.example", "share-123"), self.record)
        self.assertIsNone(reopened.get("other.example", "share-123"))

    def test_expired_share_is_removed(self) -> None:
        self.store.put(replace(self.record, expiration=time.time() - 1))
        self.assertIsNone(self.store.get("cloud.example", "share-123"))

    def test_revocation_is_idempotent(self) -> None:
        self.store.put(self.record)
        self.store.delete("cloud.example", "share-123")
        self.store.delete("cloud.example", "share-123")
        self.assertIsNone(self.store.get("cloud.example", "share-123"))
