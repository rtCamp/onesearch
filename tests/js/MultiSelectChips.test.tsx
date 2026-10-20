/**
 * External dependencies
 */
import { describe, expect, it, vi } from 'vitest';
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
		const onChange = vi.fn();

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
				screen.getByRole( 'button', { name: 'Select…' } )
			);
		} );

		await act( async () => {
			fireEvent.click( screen.getByLabelText( 'Posts' ) );
		} );

		expect( onChange ).toHaveBeenCalledWith( [ 'post' ] );
	} );

	it( 'renders selected chips and lets users remove them', async () => {
		const onChange = vi.fn();

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

	it( 'uses a native button to open the menu, with the chips outside it', async () => {
		render(
			<MultiSelectChips
				label="Entities"
				options={ options }
				value={ [ 'post' ] }
				valueField="slug"
				labelField="label"
			/>
		);

		const control = screen.getByRole( 'button', { name: 'Select…' } );
		expect( control.tagName ).toBe( 'BUTTON' );
		expect(
			control.contains(
				screen.getByRole( 'button', { name: 'Remove Posts' } )
			)
		).toBe( false );

		await act( async () => {
			fireEvent.click( control );
		} );

		expect( control ).toHaveAttribute( 'aria-expanded', 'true' );
		expect(
			screen.getByRole( 'checkbox', { name: 'Posts' } )
		).toBeChecked();
		expect( screen.queryByRole( 'listbox' ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'option' ) ).not.toBeInTheDocument();
	} );

	it( 'closes the menu and blocks changes when disabled', async () => {
		const onChange = vi.fn();
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
				screen.getByRole( 'button', { name: 'Select…' } )
			);
		} );
		expect(
			screen.getByRole( 'checkbox', { name: 'Posts' } )
		).toBeInTheDocument();

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

		expect(
			screen.queryByRole( 'checkbox', { name: 'Posts' } )
		).not.toBeInTheDocument();
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Select…' } )
			);
		} );
		expect(
			screen.queryByRole( 'checkbox', { name: 'Posts' } )
		).not.toBeInTheDocument();
		expect( onChange ).not.toHaveBeenCalled();
	} );
} );
