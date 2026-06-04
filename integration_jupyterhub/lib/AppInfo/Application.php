<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Jupyter\Federation\CloudFederationProviderManagerDecorator;
use OCA\Jupyter\Federation\OCMHubBackChannel;
use OCA\Jupyter\Federation\WebappShareIntent;
use OCA\Jupyter\Listener\CSPListener;
use OCA\Jupyter\Listener\HasNotebookMetadataListener;
use OCA\Jupyter\Listener\LoadFilesScriptListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\IConfig;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\FilesMetadata\Event\MetadataLiveEvent;
use OCP\IURLGenerator;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap
{
  public const APP_ID = 'integration_jupyterhub';

  /**
   * OCM resource type used for webapp shares sent by this app. Per OCM
   * spec (cs3org/OCM-API#368) `webapp` is a protocol name, NOT a resource
   * type — the resource type is the underlying resource (`folder` here,
   * since we always share notebook folders). The constant name reflects
   * the role (the resource type used by the webapp-share flow), matching
   * the equivalent constant in ocmremotewebapp.
   */
  public const WEBAPP_RESOURCE_TYPE = 'folder';

  public function __construct()
  {
    parent::__construct(self::APP_ID);
  }

  public function register(IRegistrationContext $context): void
  {
    $context->registerEventListener(AddContentSecurityPolicyEvent::class, CSPListener::class);
    $context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesScriptListener::class);

    // FilesMetadata: compute the boolean "has notebook?" for folders so
    // the Files-app action can decide synchronously, and keep the
    // cached value fresh as .ipynb files come and go.
    $context->registerEventListener(MetadataLiveEvent::class, HasNotebookMetadataListener::class);
    $context->registerEventListener(NodeCreatedEvent::class, HasNotebookMetadataListener::class);
    $context->registerEventListener(NodeWrittenEvent::class, HasNotebookMetadataListener::class);
    $context->registerEventListener(NodeRenamedEvent::class, HasNotebookMetadataListener::class);
    $context->registerEventListener(NodeDeletedEvent::class, HasNotebookMetadataListener::class);

  }

  public function boot(IBootContext $context): void
  {
    // Globally swap NC's ICloudFederationProviderManager for our decorator.
    // IRegistrationContext::registerService() binds in the app container,
    // but NC stock resolves ICloudFederationProviderManager off the server
    // container — so the app-level override never reaches stock. Register
    // the alias directly on the server container at boot time instead.
    //
    // The decorator forwards every method to the wrapped instance and only
    // rewrites the outbound payload when an originating IShare carries our
    // webapp attribute. All other apps' federated traffic passes through
    // unchanged. See CloudFederationProviderManagerDecorator for details.
    \OC::$server->registerService(
      ICloudFederationProviderManager::class,
      function ($c) {
        return new CloudFederationProviderManagerDecorator(
          $c->get(\OC\Federation\CloudFederationProviderManager::class),
          $c->get(WebappShareIntent::class),
          $c->get(IURLGenerator::class),
          $c->get(IConfig::class),
          $c->get(OCMHubBackChannel::class),
          $c->get(LoggerInterface::class),
        );
      },
    );
  }
}
