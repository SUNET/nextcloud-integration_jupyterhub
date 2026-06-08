<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
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
 *  - resourceType is fixed to "folder" (the underlying resource type;
 *    `webapp` is a protocol name, not a resource type, per OCM-API#368).
 *  - The `protocol` field is a multi-protocol envelope with top-level
 *    keys (no nested `options`):
 *
 *        "protocol": {
 *          "name":   "multi",
 *          "webdav": { uri, sharedSecret, permissions, requirements? },
 *          "webapp": { uri, sharedSecret, targets, permissions, … }
 *        }
 *
 *    `targets` is the list of view targets (blank/redirect/iframe) the
 *    sender will accept the receiver to render in (intersection of both
 *    ends' caps).
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
   * Build the multi-protocol envelope. One sharedSecret covers both
   * webdav and webapp per the OCM webapp-sharing draft.
   */
  public function setWebappShare(
    string $webdavUri,
    string $webappUri,
    string $sharedSecret,
    array|string $target,
    array $permissions = ['read'],
    ?string $appName = null,
    ?string $mediaType = null,
    bool $mustExchangeToken = true,
  ): void {
    $webdav = [
      'uri' => $webdavUri,
      'sharedSecret' => $sharedSecret,
      'permissions' => $permissions,
    ];
    if ($mustExchangeToken) {
      $webdav['requirements'] = ['must-exchange-token'];
    }

    $webapp = [
      'uri' => $webappUri,
      'sharedSecret' => $sharedSecret,
      'targets' => is_array($target) ? array_values($target) : [$target],
      'permissions' => $permissions,
    ];
    if ($appName !== null) {
      $webapp['appName'] = $appName;
    }
    if ($mediaType !== null) {
      // Media (MIME) type of the share; the receiver picks a themed icon
      // from it (OCM-API#368). e.g. application/vnd.jupyter.
      $webapp['mediaType'] = $mediaType;
    }

    $this->setProtocol([
      'name' => 'multi',
      'webdav' => $webdav,
      'webapp' => $webapp,
    ]);
  }

}
