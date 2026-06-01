<?php
/**
 * Integration tests: verify plugin loads correctly.
 */

namespace WS\TwoFactorExt\Tests\Integration;

use WP_UnitTestCase;

class PluginActiveTest extends WP_UnitTestCase {

	public function test_plugin_constants_defined(): void {
		$this->assertTrue( defined( 'WS_2FA_EXT_DIR' ) );
		$this->assertTrue( defined( 'WS_2FA_EXT_VERSION' ) );
	}

	public function test_plugin_version(): void {
		$this->assertSame( '1.2.0', WS_2FA_EXT_VERSION );
	}
}
