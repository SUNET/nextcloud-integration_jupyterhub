<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Controller;

use OCA\Jupyter\AppInfo\Application;
use OCA\Jupyter\Db\WebappShare;
use OCA\Jupyter\Db\WebappShareMapper;
use OCA\Jupyter\Federation\DiscoveryService;
use OCA\Jupyter\Federation\WebappDiscoveryException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Federation\ICloudIdManager;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\OCM\Exceptions\OCMProviderException;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class WebappShareController extends Controller
{
  public function __construct(
    \OCP\IRequest $request,
    private IUserSession $userSession,
    private IRootFolder $rootFolder,
    private WebappShareMapper $mapper,
    private DiscoveryService $discovery,
    private ICloudFederationFactory $federationFactory,
    private ICloudFederationProviderManager $federationManager,
    private ICloudIdManager $cloudIdManager,
    private ISecureRandom $random,
    private IURLGenerator $urlGenerator,
    private LoggerInterface $logger,
  ) {
    parent::__construct(Application::APP_ID, $request);
  }

  /**
   * @NoAdminRequired
   */
  public function create(string $path, string $shareWith, string $viewMode = WebappShare::VIEW_IFRAME): DataResponse
  {
    $user = $this->userSession->getUser();
    if ($user === null) {
      return new DataResponse(['error' => 'not authenticated'], Http::STATUS_UNAUTHORIZED);
    }

    $viewMode = $this->normaliseViewMode($viewMode);

    [$remoteUser, $remoteHost] = $this->splitCloudId($shareWith);
    if ($remoteHost === null) {
      return new DataResponse(['error' => 'shareWith must be of the form user@host'], Http::STATUS_BAD_REQUEST);
    }

    try {
      $userFolder = $this->rootFolder->getUserFolder($user->getUID());
      $node = $userFolder->get($path);
    } catch (NotFoundException $e) {
      return new DataResponse(['error' => 'folder not found'], Http::STATUS_NOT_FOUND);
    }
    if (!($node instanceof \OCP\Files\Folder)) {
      return new DataResponse(['error' => 'path is not a folder'], Http::STATUS_BAD_REQUEST);
    }
    if (!$this->folderHasNotebook($node)) {
      return new DataResponse(['error' => 'folder does not contain any .ipynb file'], Http::STATUS_BAD_REQUEST);
    }

    try {
      $targets = $this->discovery->discoverWebapp($remoteHost);
    } catch (WebappDiscoveryException $e) {
      return new DataResponse(['error' => 'discovery failed: ' . $e->getMessage()], Http::STATUS_BAD_GATEWAY);
    }

    $chosen = $targets->pickPreferred($viewMode);
    if ($chosen === null) {
      return new DataResponse(['error' => 'remote does not support any webapp view mode'], Http::STATUS_BAD_GATEWAY);
    }

    $token = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
    $secret = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);

    $entity = new WebappShare();
    $entity->setDirection(WebappShare::DIRECTION_OUT);
    $entity->setToken($token);
    $entity->setResourcePath($node->getPath());
    $entity->setRemoteUri($shareWith);
    $entity->setRemoteUser($remoteUser);
    $entity->setLocalUser($user->getUID());
    $entity->setSharedSecret($secret);
    $entity->setViewMode($chosen);
    $entity->setPermissions('read');
    $entity->setName($node->getName());
    $entity->setMimeType('application/vnd.jupyter');
    $entity->setState('sent');
    $entity->setCreatedAt(time());
    $saved = $this->mapper->insert($entity);

    $openerUri = $this->urlGenerator->getAbsoluteURL(
      $this->urlGenerator->linkToRoute(Application::APP_ID . '.page.ocmOpen', ['token' => $token]),
    );

    // owner/sharedBy must be a fully-qualified cloud-id (user@host), not
    // a bare uid — bob's cloud_federation_api parses them through
    // getHostFromFederationId() and rejects the share without the '@'.
    $ownerCloudId = $this->cloudIdManager->getCloudId($user->getUID(), null)->getId();
    $share = $this->federationFactory->getCloudFederationShare(
      $shareWith,
      $node->getName(),
      '',
      (string)$saved->getId(),
      $ownerCloudId,
      $user->getDisplayName(),
      $ownerCloudId,
      $user->getDisplayName(),
      $secret,
      'user',
      Application::WEBAPP_RESOURCE_TYPE,
    );
    $share->setProtocol([
      'name' => 'webapp',
      'options' => [
        'uri' => $openerUri,
        'sharedSecret' => $secret,
        'viewMode' => $chosen,
        'permissions' => ['read'],
        'name' => $node->getName(),
        'mimeType' => 'application/vnd.jupyter',
      ],
    ]);

    try {
      $this->federationManager->sendCloudShare($share);
    } catch (OCMProviderException $e) {
      $this->logger->warning('OCM webapp share send failed', ['exception' => $e]);
      $entity->setState('failed');
      $this->mapper->update($entity);
      return new DataResponse(['error' => 'send failed: ' . $e->getMessage()], Http::STATUS_BAD_GATEWAY);
    }

    return new DataResponse([
      'id' => $saved->getId(),
      'token' => $token,
      'viewMode' => $chosen,
      'shareWith' => $shareWith,
    ]);
  }

  /**
   * @NoAdminRequired
   */
  public function listSent(): DataResponse
  {
    $user = $this->userSession->getUser();
    if ($user === null) {
      return new DataResponse([], Http::STATUS_UNAUTHORIZED);
    }
    return new DataResponse($this->serialise(
      $this->mapper->findForUser($user->getUID(), WebappShare::DIRECTION_OUT),
    ));
  }

  /**
   * @NoAdminRequired
   */
  public function listReceived(): DataResponse
  {
    $user = $this->userSession->getUser();
    if ($user === null) {
      return new DataResponse([], Http::STATUS_UNAUTHORIZED);
    }
    return new DataResponse($this->serialise(
      $this->mapper->findForUser($user->getUID(), WebappShare::DIRECTION_IN),
    ));
  }

  /** @param WebappShare[] $rows */
  private function serialise(array $rows): array
  {
    return array_map(fn (WebappShare $r) => [
      'id' => $r->getId(),
      'token' => $r->getToken(),
      'direction' => $r->getDirection(),
      'name' => $r->getName(),
      'viewMode' => $r->getViewMode(),
      'remote' => $r->getRemoteUri(),
      'state' => $r->getState(),
      'createdAt' => $r->getCreatedAt(),
    ], $rows);
  }

  private function folderHasNotebook(\OCP\Files\Folder $folder): bool
  {
    foreach ($folder->getDirectoryListing() as $child) {
      if (!($child instanceof \OCP\Files\File)) {
        continue;
      }
      if (str_ends_with(strtolower($child->getName()), '.ipynb')) {
        return true;
      }
    }
    return false;
  }

  /**
   * @return array{0:string,1:?string}
   */
  private function splitCloudId(string $cloudId): array
  {
    $cloudId = trim($cloudId);
    $at = strrpos($cloudId, '@');
    if ($at === false) {
      return [$cloudId, null];
    }
    return [substr($cloudId, 0, $at), substr($cloudId, $at + 1)];
  }

  private function normaliseViewMode(string $mode): string
  {
    return match (strtolower($mode)) {
      'iframe' => WebappShare::VIEW_IFRAME,
      'redirect' => WebappShare::VIEW_REDIRECT,
      'new-window', 'newwindow', 'new_window' => WebappShare::VIEW_NEW_WINDOW,
      default => WebappShare::VIEW_IFRAME,
    };
  }
}
