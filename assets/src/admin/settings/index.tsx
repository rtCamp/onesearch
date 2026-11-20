import { createRoot } from 'react-dom/client';
import SettingsPage from './page';

// Render to Gutenberg admin page with ID: onesearch-settings-page
const target = document.getElementById( 'onesearch-settings-page' );
if ( target ) {
	const root = createRoot( target );
	root.render( <SettingsPage /> );
}
