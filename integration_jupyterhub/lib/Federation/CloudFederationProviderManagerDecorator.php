<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Federation;

use OC\Federation\CloudFederationProviderManager;
use OCA\Jupyter\AppInfo\Application;
use OCP\Federation\ICloudFederationNotification;
use OCP\Federation\ICloudFederationProvider;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Federation\ICloudFederationShare;
use OCP\Http\Client\IResponse;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Wraps NC's {@see CloudFederationProviderManager} so we can rewrite
 * outbound OCM share payloads that carry our webapp attribute.
 *
 * Flow:
 *   1. App's controller calls {@see IManager::createShare()} with a
 *      `IShare::TYPE_REMOTE` share whose attributes carry the marker
 *      `(integration_jupyterhub, webapp) = true` and the chosen viewMode.
 *   2. NC's `FederatedShareProvider::create` mints a token, persists the
 *      row, then asks NC's `Notifications::tryOCMEndPoint('share', …)`
 *      to dispatch an OCM share. That call ultimately reaches our
 *      `sendShare()` / `sendCloudShare()`.
 *   3. We resolve the originating `IShare` by the provider id encoded
 *      in the outgoing OCM share (the federated share row's database id
 *      under the `ocFederatedSharing` provider). If the share has our
 *      attribute set, we substitute the payload with a
 *      {@see WebappCloudFederationShare} carrying both a `webdav` entry
 *      (the token NC just minted) and a `webapp` entry (our launcher
 *      options). One outbound payload, multi-protocol, resourceType = webapp.
 *
 * Shares without our attribute pass through unchanged so this app stays
 * out of the way of every other federated share on the box.
 *
 * Implements {@see ICloudFederationProviderManager} so DI can swap it in
 * globally; every method that isn't `sendShare`/`sendCloudShare` is a
 * straight delegation to the wrapped instance.
 */
class CloudFederationProviderManagerDecorator implements ICloudFederationProviderManager
{
  public function __construct(
    private CloudFederationProviderManager $inner,
    private IManager $shareManager,
    private LoggerInterface $logger,
  ) {
  }

  /**
   * Intercept the outbound share if the originating IShare carries our
   * webapp marker; otherwise pass through.
   */
  #[\Override]
  public function sendCloudShare(ICloudFederationShare $share): IResponse
  {
    $rewritten = $this->maybeRewrite($share);
    return $this->inner->sendCloudShare($rewritten);
  }

  /**
   * @return mixed
   * @deprecated Mirrors the deprecated method on the inner manager; NC's
   *             federatedfilesharing still calls this for the initial
   *             share send so we must keep it functional.
   */
  #[\Override]
  public function sendShare(ICloudFederationShare $share)
  {
    $rewritten = $this->maybeRewrite($share);
    return $this->inner->sendShare($rewritten);
  }

  /**
   * If the share belongs to a {@see IShare} carrying our webapp marker,
   * return a new {@see WebappCloudFederationShare} reflecting the same
   * recipient/owner data but with multi-protocol payload. Otherwise return
   * the original share unchanged.
   */
  private function maybeRewrite(ICloudFederationShare $share): ICloudFederationShare
  {
    if ($share->getResourceType() !== 'file') {
      return $share;
    }

    $ishare = $this->loadOriginatingShare($share->getProviderId());
    if ($ishare === null) {
      return $share;
    }
    $attrs = $ishare->getAttributes();
    if ($attrs === null || $attrs->getAttribute(Application::APP_ID, 'webapp') !== true) {
      return $share;
    }

    $viewMode = (string)($attrs->getAttribute(Application::APP_ID, 'viewMode') ?? WebappCloudFederationShare::VIEW_IFRAME);
    $token = $ishare->getToken();
    if (!is_string($token) || $token === '') {
      $this->logger->warning('Webapp share is missing a token; skipping multi-protocol rewrite');
      return $share;
    }

    $opener = $this->buildOpenerUri($token);
    $multi = new WebappCloudFederationShare(
      shareWith: $share->getShareWith(),
      name: $share->getResourceName(),
      description: $share->getDescription(),
      providerId: $share->getProviderId(),
      owner: $share->getOwner(),
      ownerDisplayName: $share->getOwnerDisplayName(),
      sharedBy: $share->getSharedBy(),
      sharedByDisplayName: $share->getSharedByDisplayName(),
      shareType: $share->getShareType(),
    );
    $multi->setWebdavProtocol($token);
    $multi->setWebappProtocol(
      uri: $opener,
      sharedSecret: $token,
      viewMode: $viewMode,
      permissions: ['read'],
      appName: $share->getResourceName(),
      mimeType: 'application/vnd.jupyter',
    );

    $this->logger->info('Rewrote OCM share {id} to multi-protocol webapp share', [
      'id' => $share->getProviderId(),
    ]);
    return $multi;
  }

  private function loadOriginatingShare(string $providerId): ?IShare
  {
    try {
      return $this->shareManager->getShareById('ocFederatedSharing:' . $providerId);
    } catch (ShareNotFound) {
      return null;
    } catch (\Throwable $e) {
      $this->logger->debug('Could not resolve outgoing share by providerId: {msg}', ['msg' => $e->getMessage()]);
      return null;
    }
  }

  private function buildOpenerUri(string $token): string
  {
    // Constructed lazily because IURLGenerator isn't available here as a
    // DI dep — this decorator is in the OCM send path which runs before
    // routing context exists in some paths. Resolve via the server.
    $urlGenerator = \OC::$server->get(\OCP\IURLGenerator::class);
    return $urlGenerator->getAbsoluteURL(
      $urlGenerator->linkToRoute(Application::APP_ID . '.page.index') . '?webapp=' . urlencode($token),
    );
  }

  // ----- straight-through delegations -----

  #[\Override]
  public function addCloudFederationProvider($resourceType, $displayName, callable $callback): void
  {
    $this->inner->addCloudFederationProvider($resourceType, $displayName, $callback);
  }

  #[\Override]
  public function removeCloudFederationProvider($resourceType): void
  {
    $this->inner->removeCloudFederationProvider($resourceType);
  }

  #[\Override]
  public function getAllCloudFederationProviders(): array
  {
    return $this->inner->getAllCloudFederationProviders();
  }

  #[\Override]
  public function getCloudFederationProvider($resourceType): ICloudFederationProvider
  {
    return $this->inner->getCloudFederationProvider($resourceType);
  }

  #[\Override]
  public function sendNotification($url, ICloudFederationNotification $notification)
  {
    return $this->inner->sendNotification($url, $notification);
  }

  #[\Override]
  public function sendCloudNotification(string $url, ICloudFederationNotification $notification): IResponse
  {
    return $this->inner->sendCloudNotification($url, $notification);
  }

  #[\Override]
  public function isReady(): bool
  {
    return $this->inner->isReady();
  }
}
