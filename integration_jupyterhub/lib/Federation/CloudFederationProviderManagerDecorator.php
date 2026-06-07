<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Federation;

use OC\Federation\CloudFederationProviderManager;
use OCA\Jupyter\AppInfo\Application;
use OCP\Federation\ICloudFederationNotification;
use OCP\Federation\ICloudFederationProvider;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Federation\ICloudFederationShare;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Wraps NC's {@see CloudFederationProviderManager} so we can rewrite
 * outbound OCM share payloads that this app's controller announced as
 * webapp shares.
 *
 * Flow:
 *   1. {@see \OCA\Jupyter\Controller\WebappShareController} calls
 *      {@see WebappShareIntent::announce()} with the recipient cloud-id
 *      and the chosen viewMode, then dispatches a regular
 *      {@see \OCP\Share\IShare::TYPE_REMOTE} share via
 *      {@see \OCP\Share\IManager::createShare()}.
 *   2. NC's `FederatedShareProvider::create` mints a token, persists
 *      the row, then asks `Notifications::tryOCMEndPoint('share', …)`
 *      to dispatch an OCM share. That call ends up in our
 *      `sendShare()` / `sendCloudShare()`.
 *   3. We call {@see WebappShareIntent::pickup()} keyed on the
 *      outgoing share's `shareWith` field. If the intent registry has
 *      a matching announcement, we substitute the payload with a
 *      {@see WebappCloudFederationShare} carrying both a `webdav`
 *      entry (the token NC just minted) and a `webapp` entry (the
 *      announced targets). One outbound payload, multi-protocol,
 *      resourceType = folder (per OCM-API#368: `webapp` is a protocol
 *      name, the underlying resource type is what's shared).
 *
 * Shares without a matching intent pass through unchanged so this app
 * stays out of the way of every other federated share on the box.
 *
 * Implements {@see ICloudFederationProviderManager} so DI can swap it
 * in globally; every method that isn't `sendShare`/`sendCloudShare` is
 * a straight delegation to the wrapped instance.
 */
class CloudFederationProviderManagerDecorator implements ICloudFederationProviderManager
{
  public function __construct(
    private CloudFederationProviderManager $inner,
    private WebappShareIntent $intent,
    private IURLGenerator $urlGenerator,
    private IConfig $config,
    private OCMHubBackChannel $hubBackChannel,
    private LoggerInterface $logger,
  ) {
  }

  #[\Override]
  public function sendCloudShare(ICloudFederationShare $share): IResponse
  {
    return $this->inner->sendCloudShare($this->maybeRewrite($share));
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
    return $this->inner->sendShare($this->maybeRewrite($share));
  }

  /**
   * If the share's recipient matches a pending webapp intent, return a
   * new {@see WebappCloudFederationShare} reflecting the same
   * recipient/owner data but with multi-protocol payload. Otherwise
   * return the original share unchanged.
   */
  private function maybeRewrite(ICloudFederationShare $share): ICloudFederationShare
  {
    if ($share->getResourceType() !== 'file') {
      return $share;
    }
    if ($this->config->getAppValue(Application::APP_ID, 'webapp_sharing_enabled', 'no') !== 'yes') {
      return $share;
    }
    $intent = $this->intent->pickup($share->getShareWith());
    if ($intent === null || $intent['targets'] === []) {
      return $share;
    }
    $targets = $intent['targets'];
    $permissions = $intent['permissions'] === [] ? ['read'] : $intent['permissions'];

    $token = $this->extractWebdavToken($share->getProtocol());
    if ($token === '') {
      $this->logger->warning('Webapp share intent matched but no webdav token in outgoing payload; passing through unchanged');
      return $share;
    }

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
    $webappUri = $this->buildHubOpenerUri();
    if ($webappUri === '') {
      $this->logger->warning('jupyter_url is not configured; cannot build webapp URI, passing share through unchanged');
      return $share;
    }
    $multi->setWebappShare(
      webdavUri: $this->urlGenerator->getAbsoluteURL('/public.php/webdav/'),
      webappUri: $webappUri,
      sharedSecret: $token,
      target: $targets,
      permissions: $permissions,
      appName: $share->getResourceName(),
      mimeType: 'application/vnd.jupyter',
      mustExchangeToken: true,
    );

    $this->logger->info('Rewrote OCM share to {recipient} into multi-protocol webapp share', [
      'recipient' => $share->getShareWith(),
    ]);
    $this->hubBackChannel->push($multi, $token);
    return $multi;
  }

  /**
   * NC's CloudFederationShare puts the webdav token at
   * `protocol.options.sharedSecret` in the single-protocol shape.
   *
   * @param array<mixed> $protocol
   */
  private function extractWebdavToken(array $protocol): string
  {
    if (is_array($protocol['options'] ?? null) && is_string($protocol['options']['sharedSecret'] ?? null)) {
      return $protocol['options']['sharedSecret'];
    }
    if (is_array($protocol['webdav'] ?? null) && is_string($protocol['webdav']['sharedSecret'] ?? null)) {
      return $protocol['webdav']['sharedSecret'];
    }
    return '';
  }

  /**
   * The webapp URI advertised to recipients points at this app's
   * configured JupyterHub's OCM open endpoint. Recipients run the
   * companion ocmremotewebapp app, which form-POSTs the exchanged
   * access_token there to launch the share.
   */
  private function buildHubOpenerUri(): string
  {
    $jupyterUrl = $this->config->getAppValue(Application::APP_ID, 'jupyter_url', '');
    if ($jupyterUrl === '') {
      return '';
    }
    return rtrim($jupyterUrl, '/') . '/services/ocm/open';
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
