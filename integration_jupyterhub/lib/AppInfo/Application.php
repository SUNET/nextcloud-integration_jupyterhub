<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Jupyter\Federation\CloudFederationProviderManagerDecorator;
use OCA\Jupyter\Federation\OCMHubBackChannel;
use OCA\Jupyter\Federation\WebappCloudFederationProvider;
use OCA\Jupyter\Federation\WebappShareIntent;
use OCA\Jupyter\Listener\CSPListener;
use OCA\Jupyter\Listener\HasNotebookMetadataListener;
use OCA\Jupyter\Listener\LoadFilesScriptListener;
use OCA\Jupyter\Listener\LocalOCMDiscoveryListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Federation\Exceptions\ProviderAlreadyExistsException;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\FilesMetadata\Event\MetadataLiveEvent;
use OCP\IURLGenerator;
use OCP\OCM\Events\LocalOCMDiscoveryEvent;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap
{
  public const APP_ID = 'integration_jupyterhub';

  /** OCM resource type advertised via /.well-known/ocm for webapp shares. */
  public const WEBAPP_RESOURCE_TYPE = 'webapp';

  /** Path component appended to .well-known/ocm endPoint where senders POST webapp shares. */
  public const WEBAPP_PROTOCOL_PATH = '/index.php/apps/integration_jupyterhub/ocm/open';

  /** User-facing label in NC's federation provider registry. */
  public const WEBAPP_DISPLAY_NAME = 'JupyterHub Webapp';

  public function __construct()
  {
    parent::__construct(self::APP_ID);
  }

  public function register(IRegistrationContext $context): void
  {
    $context->registerEventListener(AddContentSecurityPolicyEvent::class, CSPListener::class);
    $context->registerEventListener(LocalOCMDiscoveryEvent::class, LocalOCMDiscoveryListener::class);
    $context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesScriptListener::class);

    // FilesMetadata: compute the boolean "has notebook?" for folders so
    // the Files-app action can decide synchronously, and keep the
    // cached value fresh as .ipynb files come and go.
    $context->registerEventListener(MetadataLiveEvent::class, HasNotebookMetadataListener::class);
    $context->registerEventListener(NodeCreatedEvent::class, HasNotebookMetadataListener::class);
    $context->registerEventListener(NodeWrittenEvent::class, HasNotebookMetadataListener::class);
    $context->registerEventListener(NodeRenamedEvent::class, HasNotebookMetadataListener::class);
    $context->registerEventListener(NodeDeletedEvent::class, HasNotebookMetadataListener::class);

    // Globally swap NC's ICloudFederationProviderManager for our decorator.
    // The decorator forwards every method to the wrapped instance and only
    // rewrites the outbound payload when an originating IShare carries our
    // webapp attribute — all other apps' federated traffic passes through
    // unchanged. See CloudFederationProviderManagerDecorator for details.
    $context->registerService(
      ICloudFederationProviderManager::class,
      function ($c) {
        return new CloudFederationProviderManagerDecorator(
          $c->get(\OC\Federation\CloudFederationProviderManager::class),
          $c->get(WebappShareIntent::class),
          $c->get(IURLGenerator::class),
          $c->get(OCMHubBackChannel::class),
          $c->get(LoggerInterface::class),
        );
      },
    );
  }

  public function boot(IBootContext $context): void
  {
    $context->injectFn(function (ICloudFederationProviderManager $manager): void {
      try {
        $manager->addCloudFederationProvider(
          self::WEBAPP_RESOURCE_TYPE,
          self::WEBAPP_DISPLAY_NAME,
          fn () => \OC::$server->get(WebappCloudFederationProvider::class),
        );
      } catch (ProviderAlreadyExistsException) {
        // already registered (e.g. double-boot in tests)
      }
    });
  }
}
