<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Federation;

use OC\Federation\CloudFederationShare;
use OCA\Jupyter\AppInfo\Application;

/**
 * OCM federation share of resource type {@see Application::WEBAPP_RESOURCE_TYPE}.
 *
 * Extends Nextcloud's {@see CloudFederationShare} (which already
 * implements {@see \OCP\Federation\ICloudFederationShare}) so we reuse
 * the serializer and all the field setters. Specialises it for the OCM
 * webapp draft:
 *
 *  - resourceType is fixed to "webapp".
 *  - The `protocol` field is a multi-protocol envelope with top-level
 *    keys (no nested `options`) that matches the new shape NC already
 *    uses for exchange-token webdav:
 *
 *        "protocol": {
 *          "name":   "multi",
 *          "webdav": { uri, sharedSecret, permissions, requirements? },
 *          "webapp": { uri, sharedSecret, target, permissions, … }
 *        }
 *
 *    `target` is a list of view targets the sender will accept the
 *    receiver to render in (intersection of both ends' caps).
 *
 * Building the envelope is done in one shot via {@see setWebappShare()}.
 * NC's {@see CloudFederationShare::setProtocol()} is a whole-blob
 * setter (it just assigns `$this->share['protocol'] = $protocol`), so
 * there's nothing to gain from a multi-call upsert dance — we build
 * the final structure as a literal and hand it over once.
 *
 * See https://github.com/cs3org/OCM-API/blob/develop/work/webapps/webapp-sharing.md
 */
class WebappCloudFederationShare extends CloudFederationShare
{
  public const TARGET_IFRAME = 'iframe';
  public const TARGET_REDIRECT = 'redirect';
  /**
   * `target=_blank` semantics — opens in a new window/tab.
   * Wire value is `blank` per the OCM webapp draft.
   */
  public const TARGET_BLANK = 'blank';

  public function __construct(
    string $shareWith = '',
    string $name = '',
    string $description = '',
    string $providerId = '',
    string $owner = '',
    string $ownerDisplayName = '',
    string $sharedBy = '',
    string $sharedByDisplayName = '',
    string $shareType = 'user',
  ) {
    parent::__construct(
      $shareWith,
      $name,
      $description,
      $providerId,
      $owner,
      $ownerDisplayName,
      $sharedBy,
      $sharedByDisplayName,
      $shareType,
      Application::WEBAPP_RESOURCE_TYPE,
    );
    // Protocol is intentionally not initialised here. Callers must
    // invoke setWebappShare() before the share is sent — that single
    // call writes the full multi-protocol envelope via setProtocol().
  }

  /**
   * Build the entire multi-protocol envelope in one call.
   *
   * @param string $webdavUri          sender's federated webdav endpoint
   *                                   (e.g. https://alice/public.php/webdav/)
   * @param string $webdavSharedSecret token / pre-bearer for the webdav handle
   * @param string $webappUri          sender's launcher endpoint (the URL the
   *                                   receiver navigates to when opening the
   *                                   share in their JupyterHub)
   * @param string $webappSharedSecret token for the webapp launcher
   * @param list<string>|string $target view target(s) — one or more of
   *                                   iframe / redirect / blank
   * @param list<string> $permissions  OCM permissions list, e.g. ['read'] or
   *                                   ['read','write']. Applies to both
   *                                   protocol entries (the share grants
   *                                   the same level of access in either
   *                                   transport).
   * @param string|null $appName       display name to surface in the
   *                                   receiver UI when launching the webapp
   * @param string|null $mimeType      mime-type hint for the receiver
   * @param bool $mustExchangeToken    require the receiver to swap the
   *                                   webdav sharedSecret for a bearer
   *                                   token via the exchange-token flow
   *                                   before using it
   */
  public function setWebappShare(
    string $webdavUri,
    string $webdavSharedSecret,
    string $webappUri,
    string $webappSharedSecret,
    array|string $target,
    array $permissions = ['read'],
    ?string $appName = null,
    ?string $mimeType = 'application/vnd.jupyter',
    bool $mustExchangeToken = true,
  ): void {
    $webdav = [
      'uri' => $webdavUri,
      'sharedSecret' => $webdavSharedSecret,
      'permissions' => $permissions,
    ];
    if ($mustExchangeToken) {
      $webdav['requirements'] = ['must-exchange-token'];
    }

    $webapp = [
      'uri' => $webappUri,
      'sharedSecret' => $webappSharedSecret,
      'target' => is_array($target) ? array_values($target) : [$target],
      'permissions' => $permissions,
    ];
    if ($appName !== null) {
      $webapp['name'] = $appName;
    }
    if ($mimeType !== null) {
      $webapp['mimeType'] = $mimeType;
    }

    $this->setProtocol([
      'name' => 'multi',
      'webdav' => $webdav,
      'webapp' => $webapp,
    ]);
  }

}
