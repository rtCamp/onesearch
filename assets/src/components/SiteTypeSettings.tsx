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
	CardHeader,
	CardBody,
	Notice,
	Button,
	SelectControl,
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import type { SiteType } from '@/types/global';

const BRAND_SITE = 'brand-site' as const;
const GOVERNING_SITE = 'governing-site' as const;

const SiteTypeSelector = ( {
	value,
	setSiteType,
}: {
	value: SiteType;
	setSiteType: ( v: SiteType ) => void;
} ) => (
	<SelectControl
		label={ __( 'Site Type', 'onesearch' ) }
		value={ value }
		help={ __(
			"Choose your site's primary purpose. This determines which settings are available on this site.",
			'onesearch'
		) }
		onChange={ ( v: SiteType ) => {
			setSiteType( v );
		} }
		__nextHasNoMarginBottom
		__next40pxDefaultSize
		options={ [
			{ label: __( 'Select…', 'onesearch' ), value: '' },
			{ label: __( 'Brand Site', 'onesearch' ), value: BRAND_SITE },
			{
				label: __( 'Governing site', 'onesearch' ),
				value: GOVERNING_SITE,
			},
		] }
	/>
);

const SiteTypeSettings = () => {
	const [ siteType, setSiteType ] = useState< SiteType >( '' );
	const [ error, setError ] = useState( '' );
	const [ isSaving, setIsSaving ] = useState( false );

	const handleSiteTypeChange = async ( value: SiteType ) => {
		setIsSaving( true );

		try {
			const settings = await apiFetch< {
				onesearch_site_type?: SiteType;
			} >( {
				path: '/wp/v2/settings',
				method: 'POST',
				data: { onesearch_site_type: value },
			} );

			if ( ! settings?.onesearch_site_type ) {
				throw new Error( 'No site type in response' );
			}

			// Reload so the settings screen and menu match the new site type.
			window.location.reload();
		} catch {
			setError( __( 'Error setting site type.', 'onesearch' ) );
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<Card>
			{ !! error && (
				<Notice
					status="error"
					isDismissible
					onRemove={ () => setError( '' ) }
				>
					{ error }
				</Notice>
			) }

			<CardHeader>
				<h2>{ __( 'OneSearch', 'onesearch' ) }</h2>
			</CardHeader>

			<CardBody>
				<SiteTypeSelector
					value={ siteType }
					setSiteType={ setSiteType }
				/>
				<p>
					<strong>{ __( 'Governing site', 'onesearch' ) }</strong>:{ ' ' }
					{ __(
						'holds the Algolia credentials and manages the connected brand sites.',
						'onesearch'
					) }
					<br />
					<strong>{ __( 'Brand site', 'onesearch' ) }</strong>:{ ' ' }
					{ __(
						'connects to a governing site, which indexes its content.',
						'onesearch'
					) }
				</p>
				<Button
					variant="primary"
					onClick={ () => handleSiteTypeChange( siteType ) }
					disabled={ isSaving || ! siteType }
					isBusy={ isSaving }
					style={ { marginTop: '1.5rem' } }
				>
					{ __( 'Select Current Site Type', 'onesearch' ) }
				</Button>
			</CardBody>
		</Card>
	);
};

export default SiteTypeSettings;
