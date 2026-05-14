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
 * the serializer and wire format. Specialises it for the OCM webapp
 * draft:
 *
 *  - Forces resourceType = "webapp".
 *  - Replaces the parent's single-protocol setter with a multi-protocol
 *    envelope:
 *
 *        protocol:
 *          name: "multi"
 *          webdav: {sharedSecret, permissions, ...}
 *          webapp: {uri, sharedSecret, target, permissions, ...}
 *
 *    Each protocol's options are a flat object under the protocol-name
 *    key — `options` is deprecated in the new shape NC already uses
 *    for exchange-token webdav. The webapp `target` field is a list of
 *    view targets the sender supports — receiver picks one.
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
    // Parent constructor wires a default single-protocol webdav entry.
    // We own the protocol field for webapp shares — start clean.
    $this->setProtocol(['name' => 'multi']);
  }

  /**
   * Add a webapp protocol entry. Idempotent: replaces the existing
   * `webapp` block in place.
   *
   * @param list<string>|string $target one or more view targets the
   *                                    sender supports (iframe /
   *                                    redirect / new-window). Receiver
   *                                    picks one when rendering.
   * @param list<string> $permissions
   */
  public function setWebappProtocol(
    string $uri,
    string $sharedSecret,
    array|string $target,
    array $permissions = ['read'],
    ?string $appName = null,
    ?string $mimeType = null,
  ): void {
    $entry = [
      'uri' => $uri,
      'sharedSecret' => $sharedSecret,
      'target' => $this->normaliseTargets($target),
      'permissions' => $permissions,
    ];
    if ($appName !== null) {
      $entry['name'] = $appName;
    }
    if ($mimeType !== null) {
      $entry['mimeType'] = $mimeType;
    }
    $this->upsertProtocolEntry(Application::WEBAPP_RESOURCE_TYPE, $entry);
  }

  /**
   * Add a webdav protocol entry alongside webapp so the receiver can
   * mount and browse the underlying directory through standard
   * federated sharing.
   *
   * @param list<string> $permissions
   */
  public function setWebdavProtocol(
    string $sharedSecret,
    array $permissions = ['{http://open-cloud-mesh.org/ns}share-permissions'],
  ): void {
    $this->upsertProtocolEntry('webdav', [
      'sharedSecret' => $sharedSecret,
      'permissions' => $permissions,
    ]);
  }

  /**
   * Return the webapp protocol entry's data, or null if none set.
   *
   * @return array<string, mixed>|null
   */
  public function getWebappEntry(): ?array
  {
    $protocol = $this->getProtocol();
    $entry = $protocol[Application::WEBAPP_RESOURCE_TYPE] ?? null;
    return is_array($entry) ? $entry : null;
  }

  /**
   * Upsert one named protocol entry into the multi-protocol envelope.
   *
   * @param array<string, mixed> $entry
   */
  private function upsertProtocolEntry(string $name, array $entry): void
  {
    $protocol = $this->getProtocol();
    $protocol['name'] = 'multi';
    $protocol[$name] = $entry;
    $this->setProtocol($protocol);
  }

  /**
   * @param list<string>|string $target
   * @return list<string>
   */
  private function normaliseTargets(array|string $target): array
  {
    $raw = is_array($target) ? $target : [$target];
    $out = [];
    foreach ($raw as $entry) {
      $value = match (strtolower((string)$entry)) {
        self::TARGET_REDIRECT => self::TARGET_REDIRECT,
        self::TARGET_BLANK, 'new-window', 'newwindow', 'new_window' => self::TARGET_BLANK,
        default => self::TARGET_IFRAME,
      };
      if (!in_array($value, $out, true)) {
        $out[] = $value;
      }
    }
    return $out === [] ? [self::TARGET_IFRAME] : $out;
  }
}
