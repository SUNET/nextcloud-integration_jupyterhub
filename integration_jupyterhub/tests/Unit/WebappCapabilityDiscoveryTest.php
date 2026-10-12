<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Tests\Unit;

use OCA\Jupyter\Federation\WebappCapabilityDiscovery;
use OCP\OCM\Exceptions\OCMProviderException;
use OCP\OCM\IOCMDiscoveryService;
use OCP\OCM\IOCMProvider;
use OCP\OCM\IOCMResource;
use OCP\OCM\OCMCapabilities;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class WebappCapabilityDiscoveryTest extends TestCase {

	private function cernbox(): array {
		return json_decode(
			(string)file_get_contents(__DIR__ . '/../fixtures/cernbox-discovery.json'),
			true,
			flags: JSON_THROW_ON_ERROR,
		);
	}

	private function subject(array $data): WebappCapabilityDiscovery {
		$resources = [];
		foreach ($data['resourceTypes'] as $entry) {
			$resource = $this->createStub(IOCMResource::class);
			$resource->method('getName')->willReturn($entry['name']);
			$resource->method('getProtocols')->willReturn($entry['protocols']);
			$resources[] = $resource;
		}
		$provider = $this->createStub(IOCMProvider::class);
		$provider->method('isEnabled')->willReturn($data['enabled']);
		$provider->method('getResourceTypes')->willReturn($resources);
		$provider->method('getCapabilities')->willReturn(new OCMCapabilities($data['capabilities']));
		$provider->method('getTokenEndPoint')->willReturn($data['tokenEndPoint'] ?? '');

		$discovery = $this->createMock(IOCMDiscoveryService::class);
		$discovery->expects(self::atLeastOnce())
			->method('discover')
			->with('eosc.cernbox.cern.ch', false)
			->willReturn($provider);
		return new WebappCapabilityDiscovery($discovery, new NullLogger());
	}

	public function testCernboxNegotiatesBlank(): void {
		$subject = $this->subject($this->cernbox());
		$targets = $subject->remoteSupportedTargets('eosc.cernbox.cern.ch');
		self::assertSame(['blank'], $targets);
		self::assertSame(['blank'], $subject->intersect(WebappCapabilityDiscovery::ALL_TARGETS, $targets));
		self::assertSame([], $subject->intersect(['iframe'], $targets));
		self::assertTrue($subject->remoteSupportsTokenExchange('eosc.cernbox.cern.ch'));
	}

	public function testFolderTargetsAreNotTakenFromFileResource(): void {
		$data = $this->cernbox();
		$data['resourceTypes'][0]['protocols']['webapp-receive']['targets'] = ['iframe'];
		self::assertSame(['blank'], $this->subject($data)->remoteSupportedTargets('eosc.cernbox.cern.ch'));
	}

	public function testFileOnlyPeerCannotReceiveNotebookFolder(): void {
		$data = $this->cernbox();
		$data['resourceTypes'] = [$data['resourceTypes'][0]];
		self::assertSame([], $this->subject($data)->remoteSupportedTargets('eosc.cernbox.cern.ch'));
	}

	public function testSenderMarkerDoesNotImplyReceiveSupport(): void {
		$data = $this->cernbox();
		foreach ($data['resourceTypes'] as &$resource) {
			unset($resource['protocols']['webapp-receive']);
		}
		self::assertSame([], $this->subject($data)->remoteSupportedTargets('eosc.cernbox.cern.ch'));
	}

	public function testDisabledPeerHasNoTargetsOrTokenExchange(): void {
		$data = $this->cernbox();
		$data['enabled'] = false;
		$subject = $this->subject($data);
		self::assertSame([], $subject->remoteSupportedTargets('eosc.cernbox.cern.ch'));
		self::assertFalse($subject->remoteSupportsTokenExchange('eosc.cernbox.cern.ch'));
	}

	public function testTokenEndpointIsRequired(): void {
		$data = $this->cernbox();
		unset($data['tokenEndPoint']);
		self::assertFalse($this->subject($data)->remoteSupportsTokenExchange('eosc.cernbox.cern.ch'));
	}

	public function testTokenExchangeCapabilityIsRequired(): void {
		$data = $this->cernbox();
		$data['capabilities'] = [];
		self::assertFalse($this->subject($data)->remoteSupportsTokenExchange('eosc.cernbox.cern.ch'));
	}

	public function testDiscoveryFailureCannotGrantCapabilities(): void {
		$discovery = $this->createStub(IOCMDiscoveryService::class);
		$discovery->method('discover')->willThrowException(new OCMProviderException('unavailable'));
		$subject = new WebappCapabilityDiscovery($discovery, new NullLogger());
		self::assertSame([], $subject->remoteSupportedTargets('eosc.cernbox.cern.ch'));
		self::assertFalse($subject->remoteSupportsTokenExchange('eosc.cernbox.cern.ch'));
	}

	public function testTargetsUseOnlyWireVocabularyAndPreservePreference(): void {
		self::assertSame(['blank', 'iframe'], WebappCapabilityDiscovery::normaliseTargets([
			'redirect', 'blank', 'popup', 1, 'iframe', 'blank',
		]));
		$subject = $this->subject($this->cernbox());
		$subject->remoteSupportedTargets('eosc.cernbox.cern.ch');
		self::assertSame(['blank', 'iframe'], $subject->intersect(
			['blank', 'iframe', 'blank', 'popup'],
			['iframe', 'blank'],
		));
	}
}
