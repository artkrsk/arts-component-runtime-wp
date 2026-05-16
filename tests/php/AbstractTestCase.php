<?php
/**
 * Shared PHPUnit base — wires Brain Monkey + Mockery lifecycle so each
 * cell starts with a fresh function-interception state. Matches the
 * convention used in sibling Arts packages (ArtsDataSlots,
 * ArtsGithubReleaseBrowser).
 *
 * `MockeryPHPUnitIntegration` auto-closes Mockery between tests and
 * counts expectations as PHPUnit assertions.
 */

declare(strict_types=1);

namespace Arts\ComponentRuntime\Tests;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

abstract class AbstractTestCase extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
