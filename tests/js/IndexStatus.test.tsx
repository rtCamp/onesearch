/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';

import IndexSummary from '@/components/IndexSummary';
import JobStatus from '@/components/JobStatus';
import type { JobStatusData } from '@/js/use-index-status';

const job = ( data: Partial< JobStatusData > ): JobStatusData => ( {
	job: 'index_site',
	site: 'https://governing.example.com/',
	status: 'queued',
	error: null,
	...data,
} );

describe( 'JobStatus', () => {
	it( 'shows a queued or running job, with its progress', () => {
		const { rerender } = render( <JobStatus job={ job( {} ) } /> );
		expect(
			screen.getByText( 'Indexing will start soon.' )
		).toBeInTheDocument();

		rerender(
			<JobStatus
				job={ job( {
					status: 'running',
					progress: { total: 200, done: 100, failed: 3 },
				} ) }
			/>
		);
		expect( screen.getByText( 'Indexing…' ) ).toBeInTheDocument();
		expect( screen.getByText( '100 of 200 items' ) ).toBeInTheDocument();
		expect(
			screen.getByText( "3 items couldn't be indexed." )
		).toBeInTheDocument();
	} );

	it( 'says what a failed job means for search, and why it failed', () => {
		render(
			<JobStatus
				job={ job( {
					site: 'https://brand.example.com/',
					status: 'failed',
					error: 'cURL error 7',
				} ) }
			/>
		);

		expect(
			screen.getByText(
				'Search may show outdated or missing content from this site. Re-index to try again.'
			)
		).toBeInTheDocument();
		expect( screen.getByText( 'cURL error 7' ) ).toBeInTheDocument();
	} );

	it( 'shows nothing for a completed job, or one it has no copy for', () => {
		const { container, rerender } = render(
			<JobStatus job={ job( { status: 'completed' } ) } />
		);
		expect( container ).toBeEmptyDOMElement();

		rerender( <JobStatus job={ job( { job: 'unknown' } ) } /> );
		expect( container ).toBeEmptyDOMElement();
	} );
} );

describe( 'IndexSummary', () => {
	it( 'shows how much is in search, and the first failed items until all are asked for', () => {
		const failed = Array.from( { length: 7 }, ( _, i ) => ( {
			id: i + 1,
			title: i ? `Post ${ i + 1 }` : '',
			edit_url: `https://governing.example.com/wp-admin/post.php?post=${
				i + 1
			}&action=edit`,
			error: 'The post is too big to index.',
		} ) );

		render( <IndexSummary index={ { total: 20, indexed: 13, failed } } /> );

		expect(
			screen.getByText( '13 of 20 items are in search.' )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				"7 items couldn't be indexed, so search doesn't show their latest version. Update them, or re-index, to try again."
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'Item 1' } )
		).toHaveAttribute( 'href', failed[ 0 ]?.edit_url );
		expect( screen.getAllByRole( 'listitem' ) ).toHaveLength( 5 );

		fireEvent.click( screen.getByRole( 'button', { name: 'Show all 7' } ) );

		expect( screen.getAllByRole( 'listitem' ) ).toHaveLength( 7 );
		expect( screen.queryByRole( 'button' ) ).toBeNull();
	} );

	it( 'lists a deleted item without a link', () => {
		render(
			<IndexSummary
				index={ {
					total: 0,
					indexed: 0,
					failed: [
						{
							id: 9,
							title: 'Deleted item',
							edit_url: '',
							error: 'Failed to delete records.',
						},
					],
				} }
			/>
		);

		expect( screen.getByRole( 'listitem' ) ).toHaveTextContent(
			'Deleted item Failed to delete records.'
		);
		expect( screen.queryByRole( 'link' ) ).toBeNull();
	} );

	it( "says when a site can't tell what it indexes", () => {
		render( <IndexSummary index={ null } /> );

		expect(
			screen.getByText(
				"This site can't tell which content it indexes. Check its connection to the governing site."
			)
		).toBeInTheDocument();
	} );
} );
