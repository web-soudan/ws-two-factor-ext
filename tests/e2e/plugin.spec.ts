import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'WS Two Factor Extension', () => {
	test( 'プラグインが有効化されている', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'plugins.php' );
		// "Deactivate" リンクが表示されているとき = プラグインは active。
		const pluginRow = page.locator( 'tr' ).filter( { hasText: 'WS Two Factor Extension' } ).first();
		await expect( pluginRow ).toContainText( 'Deactivate' );
	} );
} );
