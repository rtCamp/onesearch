/**
 * WordPress dependencies
 */
/**
 * External dependencies
 */
import { useState } from 'react';
import {
	Button,
	CardBody,
	Card,
	CardHeader,
	TextControl,
	__experimentalGrid as Grid,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import type { NoticeType } from '@/admin/settings/page';

interface AlgoliaCredentials {
	app_id: string;
	write_key: string;
}

const CREDENTIALS_ENDPOINT = '/onesearch/v1/algolia-credentials';

const AlgoliaSettings = ( {
	setNotice,
}: {
	setNotice: ( notice: NoticeType ) => void;
} ) => {
	// The write key never leaves the server, so it's typed in again to save.
	const [ algoliaCreds, setAlgoliaCreds ] = useState< AlgoliaCredentials >( {
		app_id: window.OneSearchSettings.algoliaAppId ?? '',
		write_key: '',
	} );
	const [ initial, setInitial ] =
		useState< AlgoliaCredentials >( algoliaCreds );
	const [ saving, setSaving ] = useState( false );

	const hasChanges =
		algoliaCreds.app_id !== initial.app_id ||
		algoliaCreds.write_key !== initial.write_key;

	// Validate that required fields are filled
	const isValid = !! (
		algoliaCreds &&
		algoliaCreds.app_id !== '' &&
		algoliaCreds.write_key !== ''
	);

	const onSave = async () => {
		setSaving( true );
		apiFetch( {
			path: CREDENTIALS_ENDPOINT,
			method: 'POST',
			data: algoliaCreds,
		} )
			.then( () => {
				setInitial( algoliaCreds );

				setNotice( {
					type: 'success',
					message: __(
						'Algolia credentials saved successfully.',
						'onesearch'
					),
				} );
			} )
			.catch( () => {
				setNotice( {
					type: 'error',
					message: __(
						'Error saving Algolia credentials. Please try again later.',
						'onesearch'
					),
				} );
			} )
			.finally( () => {
				setSaving( false );
			} );
	};

	return (
		<Card className="onesearch-card" style={ { marginTop: '30px' } }>
			<CardHeader>
				<h2>{ __( 'Algolia Credentials', 'onesearch' ) }</h2>
				<Button
					variant="primary"
					onClick={ onSave }
					disabled={ ! hasChanges || saving || ! isValid }
					isBusy={ saving }
				>
					{ __( 'Save Credentials', 'onesearch' ) }
				</Button>
			</CardHeader>
			<CardBody>
				<Grid columns={ 2 }>
					<TextControl
						label={ __( 'Application ID*', 'onesearch' ) }
						placeholder={ __(
							'Enter your Algolia Application ID',
							'onesearch'
						) }
						help={ __(
							"It's used to identify your application when using Algolia API.",
							'onesearch'
						) }
						value={ algoliaCreds.app_id }
						onChange={ ( value ) =>
							setAlgoliaCreds( ( prev ) => ( {
								...prev,
								app_id: value.trim(),
							} ) )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __( 'Write API Key*', 'onesearch' ) }
						placeholder={
							window.OneSearchSettings.hasAlgoliaCredentials
								? __(
										'A key is saved. Enter it again to save changes.',
										'onesearch'
									)
								: __(
										'Enter your Algolia Write API Key',
										'onesearch'
									)
						}
						help={ __(
							"This key is usable for write operations and it's also able to list the indices you've got access to.",
							'onesearch'
						) }
						type="password"
						value={ algoliaCreds.write_key }
						onChange={ ( value ) =>
							setAlgoliaCreds( ( prev ) => ( {
								...prev,
								write_key: value.trim(),
							} ) )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</Grid>
			</CardBody>
		</Card>
	);
};

export default AlgoliaSettings;
