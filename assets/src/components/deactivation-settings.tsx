/**
 * External dependencies
 */
import { useState } from 'react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import {
	Card,
	CardBody,
	CardHeader,
	ToggleControl,
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import type { NoticeType } from '@/admin/settings/page';
import type { SiteType } from '@/types/global';

const OPTION = 'onesearch_delete_data_on_deactivation';
const SITE_TYPE = ( window.OneSearchSettings.siteType as SiteType ) || '';

const HELP: Record< SiteType, string > = {
	'governing-site': __(
		"Use this on hosts that never uninstall plugins. Deactivating OneSearch then deletes its settings and the search index, and disconnects the brand sites. This can't be undone, so turn it off before deactivating OneSearch to troubleshoot.",
		'onesearch'
	),
	'brand-site': __(
		"Use this on hosts that never uninstall plugins. Deactivating OneSearch then deletes its settings, removes this site's content from search, and disconnects it from the governing site. This can't be undone, so turn it off before deactivating OneSearch to troubleshoot.",
		'onesearch'
	),
	'': __(
		"Use this on hosts that never uninstall plugins. Deactivating OneSearch then deletes its settings. This can't be undone.",
		'onesearch'
	),
};

const DeactivationSettings = ( {
	setNotice,
}: {
	setNotice: ( notice: NoticeType ) => void;
} ) => {
	const [ isEnabled, setIsEnabled ] = useState(
		!! window.OneSearchSettings.deleteDataOnDeactivation
	);
	const [ isSaving, setIsSaving ] = useState( false );

	const onChange = async ( value: boolean ) => {
		setIsSaving( true );

		try {
			const settings = await apiFetch< Record< string, unknown > >( {
				path: '/wp/v2/settings',
				method: 'POST',
				data: { [ OPTION ]: value },
			} );

			setIsEnabled( !! settings?.[ OPTION ] );
			setNotice( {
				type: 'success',
				message: __( 'Settings saved.', 'onesearch' ),
			} );
		} catch {
			setNotice( {
				type: 'error',
				message: __(
					'Failed to save the setting. Please try again.',
					'onesearch'
				),
			} );
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<Card style={ { marginTop: '30px' } }>
			<CardHeader>
				<h2>{ __( 'Deactivation', 'onesearch' ) }</h2>
			</CardHeader>
			<CardBody>
				<ToggleControl
					label={ __(
						'Delete plugin data on deactivation',
						'onesearch'
					) }
					help={ HELP[ SITE_TYPE ] }
					checked={ isEnabled }
					disabled={ isSaving }
					onChange={ onChange }
					__nextHasNoMarginBottom
				/>
			</CardBody>
		</Card>
	);
};

export default DeactivationSettings;
