/**
 * External dependencies
 */
import { act, fireEvent, render, screen } from '@testing-library/react';

import MultiSelectChips from '@/components/MultiSelectChips';

const options = [
	{ slug: 'post', label: 'Posts' },
	{ slug: 'page', label: 'Pages' },
];

describe( 'MultiSelectChips', () => {
	it( 'shows a placeholder when nothing is selected', () => {
		render( <MultiSelectChips label="Entities" options={ options } /> );

		expect( screen.getByText( 'Select…' ) ).toBeInTheDocument();
	} );

	it( 'opens the menu and reports selected values', async () => {
		const onChange = jest.fn();

		render(
			<MultiSelectChips
				label="Entities"
				options={ options }
				onChange={ onChange }
				valueField="slug"
				labelField="label"
			/>
		);

		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Entities' } )
			);
		} );

		await act( async () => {
			fireEvent.click( screen.getByLabelText( 'Posts' ) );
		} );

		expect( onChange ).toHaveBeenCalledWith( [ 'post' ] );
	} );

	it( 'renders selected chips and lets users remove them', async () => {
		const onChange = jest.fn();

		render(
			<MultiSelectChips
				label="Entities"
				options={ options }
				value={ [ 'post', 'page' ] }
				onChange={ onChange }
				valueField="slug"
				labelField="label"
			/>
		);

		expect( screen.getByText( 'Posts' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Pages' ) ).toBeInTheDocument();

		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Remove Posts' } )
			);
		} );

		expect( onChange ).toHaveBeenCalledWith( [ 'page' ] );
	} );

	it( 'supports keyboard removal for selected chips', async () => {
		const onChange = jest.fn();

		render(
			<MultiSelectChips
				label="Entities"
				options={ options }
				value={ [ 'page' ] }
				onChange={ onChange }
				valueField="slug"
				labelField="label"
			/>
		);

		await act( async () => {
			fireEvent.keyDown(
				screen.getByRole( 'button', { name: 'Remove Pages' } ),
				{
					key: 'Enter',
				}
			);
		} );

		expect( onChange ).toHaveBeenCalledWith( [] );
	} );

	it( 'closes the menu and blocks changes when disabled', async () => {
		const onChange = jest.fn();
		const { rerender } = render(
			<MultiSelectChips
				label="Entities"
				options={ options }
				onChange={ onChange }
				valueField="slug"
				labelField="label"
			/>
		);

		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Entities' } )
			);
		} );
		expect( screen.getByRole( 'listbox' ) ).toBeInTheDocument();

		rerender(
			<MultiSelectChips
				label="Entities"
				options={ options }
				onChange={ onChange }
				valueField="slug"
				labelField="label"
				disabled
			/>
		);

		expect( screen.queryByRole( 'listbox' ) ).not.toBeInTheDocument();
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Entities' } )
			);
		} );
		expect( screen.queryByRole( 'listbox' ) ).not.toBeInTheDocument();
		expect( onChange ).not.toHaveBeenCalled();
	} );
} );
