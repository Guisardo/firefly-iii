<?php

declare(strict_types=1);

namespace Tests\integration\Api\System;

use FireflyIII\User;
use Laravel\Passport\Passport;
use Override;
use Tests\integration\TestCase;

/**
 * The about endpoint tells a client which parts of this API it can rely on, so a client
 * that also talks to servers without them knows when to fall back.
 *
 * @internal
 *
 * @coversNothing
 */
final class AboutCapabilitiesTest extends TestCase
{
    private ?User $user = null;

    public function testAboutKeepsReportingTheSystemInformation(): void
    {
        $response = $this->getJson(route('api.v1.about.index'));

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['version', 'api_version', 'php_version', 'os', 'driver']]);
    }

    public function testAboutListsTheCapabilitiesOfThisServer(): void
    {
        $response = $this->getJson(route('api.v1.about.index'));

        $response->assertOk();
        $capabilities = $response->json('data.capabilities');
        self::assertIsArray($capabilities, 'about: capabilities is a list');
        self::assertContains('incremental.transactions', $capabilities, 'about: incremental transaction list announced');
        self::assertContains('deleted_items', $capabilities, 'about: deleted items announced');
    }

    public function testCapabilitiesAreUniqueNames(): void
    {
        $response = $this->getJson(route('api.v1.about.index'));

        $response->assertOk();
        $capabilities = $response->json('data.capabilities');
        self::assertIsArray($capabilities, 'about: capabilities is a list');
        foreach ($capabilities as $name) {
            self::assertIsString($name, 'about: a capability is a name');
            self::assertNotSame('', $name, 'about: a capability is not empty');
        }
        self::assertSame($capabilities, array_values(array_unique($capabilities)), 'about: capabilities are unique');
    }

    public function testCapabilitiesFollowTheConfiguration(): void
    {
        config(['firefly.api_capabilities' => ['first.token', 'second']]);

        $response = $this->getJson(route('api.v1.about.index'));

        $response->assertOk();
        self::assertSame(['first.token', 'second'], $response->json('data.capabilities'), 'about: capabilities from the configuration');
    }

    public function testNoCapabilitiesGiveAnEmptyList(): void
    {
        config(['firefly.api_capabilities' => []]);

        $response = $this->getJson(route('api.v1.about.index'));

        $response->assertOk();
        self::assertSame([], $response->json('data.capabilities'), 'about: an empty configuration gives an empty list');
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->user instanceof User) {
            $this->user = $this->createAuthenticatedUser();
        }
        Passport::actingAs($this->user);
    }
}
