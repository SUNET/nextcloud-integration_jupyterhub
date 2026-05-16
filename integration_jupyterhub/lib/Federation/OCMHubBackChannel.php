<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Federation;

use OC\Authentication\Token\IProvider;
use OC\OCM\OCMSignatoryManager;
use OC\OCM\Rfc9421SignatoryManager;
use OCA\Jupyter\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\Security\Signature\ISignatureManager;
use Psr\Log\LoggerInterface;

/**
 * Pushes a webapp share envelope to the paired JupyterHub's
 * /services/ocm/shares back channel. Same RFC 9421 "ocm" signature
 * the receiver-bound OCM share uses; sharedSecret in each protocol
 * entry is replaced by clientId (the id of the PublicKeyToken NC
 * minted for the share at FederatedShareProvider::createFederatedShare).
 */
class OCMHubBackChannel
{
  public function __construct(
    private IClientService $clientService,
    private ISignatureManager $signatureManager,
    private OCMSignatoryManager $signatoryManager,
    private IProvider $tokenProvider,
    private IConfig $config,
    private LoggerInterface $logger,
  ) {
  }

  public function push(WebappCloudFederationShare $share, string $sharedSecret): void
  {
    $hubBase = rtrim((string)$this->config->getAppValue(Application::APP_ID, 'jupyter_url', ''), '/');
    if ($hubBase === '') {
      $this->logger->debug('OCM back channel: no jupyter_url configured, skipping');
      return;
    }

    try {
      $token = $this->tokenProvider->getToken($sharedSecret);
    } catch (\Throwable $e) {
      $this->logger->warning('OCM back channel: cannot resolve clientId from sharedSecret', [
        'exception' => $e,
      ]);
      return;
    }
    $clientId = (string)$token->getId();

    $protocol = $share->getProtocol();
    foreach (['webdav', 'webapp'] as $entry) {
      if (isset($protocol[$entry]['sharedSecret'])) {
        unset($protocol[$entry]['sharedSecret']);
      }
    }
    $protocol['webapp']['clientId'] = $clientId;

    $payload = [
      'shareWith' => $share->getShareWith(),
      'name' => $share->getResourceName(),
      'description' => $share->getDescription(),
      'providerId' => $share->getProviderId(),
      'owner' => $share->getOwner(),
      'ownerDisplayName' => $share->getOwnerDisplayName(),
      'sender' => $share->getSharedBy() ?: $share->getOwner(),
      'senderDisplayName' => $share->getSharedByDisplayName() ?: $share->getOwnerDisplayName(),
      'shareType' => $share->getShareType(),
      'resourceType' => $share->getResourceType(),
      'protocol' => $protocol,
    ];
    $body = (string)json_encode($payload, JSON_UNESCAPED_SLASHES);
    $url = $hubBase . '/services/ocm/shares';

    try {
      $signed = $this->signatureManager->signOutgoingRequestIClientPayload(
        new Rfc9421SignatoryManager($this->signatoryManager),
        ['body' => $body, 'headers' => ['Content-Type' => 'application/json']],
        'POST',
        $url,
      );
      $response = $this->clientService->newClient()->post($url, [
        'headers' => $signed['headers'],
        'body' => $signed['body'],
        'timeout' => 15,
      ]);
      $this->logger->debug('OCM back channel pushed', [
        'url' => $url,
        'status' => $response->getStatusCode(),
        'providerId' => $share->getProviderId(),
        'clientId' => $clientId,
      ]);
    } catch (\Throwable $e) {
      $this->logger->warning('OCM back channel push failed', [
        'exception' => $e,
        'url' => $url,
      ]);
    }
  }
}
