<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Controller;

use OCA\Jupyter\AppInfo\Application;
use OCA\Jupyter\Db\WebappShare;
use OCA\Jupyter\Db\WebappShareMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

class PageController extends Controller
{
  public function __construct(
    IRequest $request,
    private IConfig $config,
    private WebappShareMapper $mapper,
    private IUserSession $userSession,
    private IRootFolder $rootFolder,
  ) {
    parent::__construct(Application::APP_ID, $request);
  }

  /**
   * @NoAdminRequired
   * @NoCSRFRequired
   */
  public function index(): TemplateResponse
  {
    $jupyterUrl = $this->config->getAppValue(Application::APP_ID, 'jupyter_url');
    return new TemplateResponse(Application::APP_ID, 'main', [
      'jupyter_url' => $jupyterUrl . '/hub/home',
    ]);
  }

  /**
   * Open a received OCM webapp share. Honours the stored view mode —
   * iframe (default), redirect or new-window — to satisfy the OCM
   * webapp-sharing draft's three target options.
   *
   * @NoAdminRequired
   * @NoCSRFRequired
   */
  public function ocmOpen(string $token): Http\Response
  {
    try {
      $share = $this->mapper->findByToken($token);
    } catch (DoesNotExistException $e) {
      return $this->errorPage('Share not found');
    }
    if ($share->getDirection() !== WebappShare::DIRECTION_IN) {
      return $this->errorPage('Not a received share');
    }

    $user = $this->userSession->getUser();
    if ($user === null || $user->getUID() !== $share->getLocalUser()) {
      return $this->errorPage('You are not the recipient of this share');
    }

    $jupyterUrl = $this->config->getAppValue(Application::APP_ID, 'jupyter_url');
    if (empty($jupyterUrl)) {
      return $this->errorPage('JupyterHub URL not configured on this instance');
    }
    // Inbound shares don't carry a local notebook path — drop the user
    // into their JupyterHub home so they can clone or browse the
    // shared notebook. A future revision will wire the remote URI
    // through to a launcher endpoint.
    $launchUrl = rtrim($jupyterUrl, '/') . '/hub/home';

    return match ($share->getViewMode()) {
      WebappShare::VIEW_REDIRECT => new RedirectResponse($launchUrl),
      WebappShare::VIEW_NEW_WINDOW => new TemplateResponse(Application::APP_ID, 'launcherNewWindow', [
        'launch_url' => $launchUrl,
        'name' => $share->getName() ?? 'Shared notebook',
      ]),
      default => new TemplateResponse(Application::APP_ID, 'main', [
        'jupyter_url' => $launchUrl,
      ]),
    };
  }

  /**
   * @NoAdminRequired
   */
  public function hasNotebooks(string $path): DataResponse
  {
    $user = $this->userSession->getUser();
    if ($user === null) {
      return new DataResponse(['hasNotebook' => false], Http::STATUS_UNAUTHORIZED);
    }
    try {
      $node = $this->rootFolder->getUserFolder($user->getUID())->get($path);
    } catch (NotFoundException $e) {
      return new DataResponse(['hasNotebook' => false], Http::STATUS_NOT_FOUND);
    }
    if (!($node instanceof \OCP\Files\Folder)) {
      return new DataResponse(['hasNotebook' => false]);
    }
    foreach ($node->getDirectoryListing() as $child) {
      if ($child instanceof \OCP\Files\File && str_ends_with(strtolower($child->getName()), '.ipynb')) {
        return new DataResponse(['hasNotebook' => true]);
      }
    }
    return new DataResponse(['hasNotebook' => false]);
  }

  private function errorPage(string $message): TemplateResponse
  {
    $response = new TemplateResponse(Application::APP_ID, 'error', ['message' => $message], TemplateResponse::RENDER_AS_ERROR);
    $response->setStatus(Http::STATUS_BAD_REQUEST);
    return $response;
  }
}
