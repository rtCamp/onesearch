/**
 * External dependencies
 */
import { screen } from '@testing-library/react';

/**
 * Finds a notice by its text. Notices also echo their text to a screen reader live region, so this looks inside the notice itself.
 *
 * @param text - The notice's text.
 */
export const findNotice = ( text: string ) =>
	screen.findByText( text, {
		selector: '.components-notice__content, .components-notice__content *',
	} );
