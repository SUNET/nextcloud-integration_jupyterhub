<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Controller;

use OCA\Jupyter\AppInfo\Application;
use OCA\Jupyter\Federation\WebappCapabilityDiscovery;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

class ConfigController extends Controller
{
  public function __construct(
    IRequest $request,
    private IConfig $config,
  ) {
    parent::__construct(Application::APP_ID, $request);
  }

  /**
   * @param list<string>|null $webapp_allowed_targets the view targets an
   *   admin permits this instance to offer. Stored as a comma-separated
   *   list; every webapp share sends this set intersected with what the
   *   recipient advertises in OCM discovery.
   * @param list<string>|null $webapp_media_types MIME types this webapp can handle. Stored CSV.
   */
  public function update(?string $jupyter_url = null, ?bool $webapp_sharing_enabled = null, ?array $webapp_allowed_targets = null, ?array $webapp_media_types = null, ?int $ocm_access_token_ttl = null): DataResponse
  {
    if ($jupyter_url !== null) {
      $jupyter_url = rtrim($jupyter_url, '/');
      $this->config->setAppValue(Application::APP_ID, 'jupyter_url', $jupyter_url);
    }
    if ($webapp_sharing_enabled !== null) {
      $this->config->setAppValue(Application::APP_ID, 'webapp_sharing_enabled', $webapp_sharing_enabled ? 'yes' : 'no');
    }
    if ($webapp_allowed_targets !== null) {
      $this->config->setAppValue(
        Application::APP_ID,
        'webapp_allowed_targets',
        implode(',', WebappCapabilityDiscovery::normaliseTargets($webapp_allowed_targets)),
      );
    }
    if ($webapp_media_types !== null) {
      $this->config->setAppValue(
        Application::APP_ID,
        'webapp_media_types',
        implode(',', WebappCapabilityDiscovery::normaliseMediaTypes($webapp_media_types)),
      );
    }
    if ($ocm_access_token_ttl !== null) {
      $ttl = max(300, min(86400, $ocm_access_token_ttl)); // clamp 300..86400
      $this->config->setAppValue(Application::APP_ID, 'ocm_access_token_ttl', (string)$ttl);
    }
    return new DataResponse(['status' => 'success']);
  }
}
