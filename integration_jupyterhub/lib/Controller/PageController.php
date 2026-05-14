<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Controller;

use OCA\Jupyter\AppInfo\Application;
use OCA\Jupyter\Federation\WebappAppDataStore;
use OCA\Jupyter\Federation\WebappCloudFederationShare;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

class PageController extends Controller
{
  public function __construct(
    IRequest $request,
    private IConfig $config,
    private IUserSession $userSession,
    private WebappAppDataStore $store,
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
    if (!empty($jupyterUrl)) {
      // Local hub configured — keep the existing iframe view.
      return new TemplateResponse(Application::APP_ID, 'main', [
        'jupyter_url' => $jupyterUrl . '/hub/home',
      ]);
    }
    // No local hub: render the list of inbound webapp shares so the
    // user still has something to launch (each share carries its
    // sender's hub URI in the stored webapp options).
    $user = $this->userSession->getUser();
    $shares = $user === null ? [] : array_values(array_filter(
      $this->store->listForUser($user->getUID()),
      fn ($r) => is_array($r) && !str_starts_with((string)($r['token'] ?? ''), 'file-share-'),
    ));
    return new TemplateResponse(Application::APP_ID, 'shareList', [
      'shares' => $shares,
    ]);
  }

  /**
   * Receiver-side launcher for an inbound OCM webapp share.
   *
   * Looks up the webapp record by token (scoped to the logged-in user)
   * and renders the launcher in the viewMode stored at receive time:
   * iframe → embed local JupyterHub, redirect → 302, new-window →
   * intermediate page with a user-initiated open-link.
   *
   * @NoAdminRequired
   * @NoCSRFRequired
   */
  public function ocmOpen(string $token): Http\Response
  {
    $user = $this->userSession->getUser();
    if ($user === null) {
      return $this->errorPage('You must be signed in to open a shared webapp.');
    }

    $record = $this->store->get($user->getUID(), $token);
    if ($record === null) {
      return $this->errorPage('Webapp share not found for this account.');
    }

    // Two launch destinations:
    //   - local hub (when this NC has jupyter_url configured): the user
    //     has their own JupyterHub, and the federated mount has already
    //     surfaced the shared files; open the local hub home.
    //   - sender's webapp URI (otherwise): we honour the OCM webapp draft
    //     and consume the sender's hub via the URI they advertised.
    $jupyterUrl = $this->config->getAppValue(Application::APP_ID, 'jupyter_url');
    $launchUrl = !empty($jupyterUrl)
      ? rtrim($jupyterUrl, '/') . '/hub/home'
      : (string)($record['webapp']['uri'] ?? '');
    if ($launchUrl === '') {
      return $this->errorPage('Neither a local JupyterHub URL nor a sender-provided URI is available.');
    }

    // `target` is the list of view targets the sender advertised as
    // acceptable. We pick the first one (prefer iframe), then render.
    $advertised = (array)($record['webapp']['target'] ?? [WebappCloudFederationShare::TARGET_IFRAME]);
    $target = $this->pickTarget($advertised);

    return match ($target) {
      WebappCloudFederationShare::TARGET_REDIRECT => new RedirectResponse($launchUrl),
      WebappCloudFederationShare::TARGET_BLANK => new TemplateResponse(Application::APP_ID, 'launcherNewWindow', [
        'launch_url' => $launchUrl,
        'name' => $record['name'] ?? 'Shared notebook',
      ]),
      default => new TemplateResponse(Application::APP_ID, 'main', [
        'jupyter_url' => $launchUrl,
      ]),
    };
  }

  /**
   * @param list<string> $advertised
   */
  private function pickTarget(array $advertised): string
  {
    $preference = [
      WebappCloudFederationShare::TARGET_IFRAME,
      WebappCloudFederationShare::TARGET_REDIRECT,
      WebappCloudFederationShare::TARGET_BLANK,
    ];
    foreach ($preference as $candidate) {
      if (in_array($candidate, $advertised, true)) {
        return $candidate;
      }
    }
    return WebappCloudFederationShare::TARGET_IFRAME;
  }

  private function errorPage(string $message): TemplateResponse
  {
    $response = new TemplateResponse(Application::APP_ID, 'error', ['message' => $message], TemplateResponse::RENDER_AS_ERROR);
    $response->setStatus(Http::STATUS_BAD_REQUEST);
    return $response;
  }
}
