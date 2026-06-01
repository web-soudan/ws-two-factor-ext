import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'WS Two Factor Extension', () => {
	test( 'プラグインが有効化されている', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'plugins.php' );
		const pluginRow = page.locator( 'tr[data-slug="ws-two-factor-ext"]' );
		await expect( pluginRow ).toHaveClass( /active/ );
	} );
} );
