/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { __experimentalText as Text } from '@wordpress/components';
/**
 * Internal dependencies
 */
import MultiSelectChips from '../MultiSelectChips';
import type { PostTypeOption } from '../SiteSearchSettings';
import { toMultiSelectOptions } from './utils';

interface EntitySiteCardProps {
	siteName: string;
	siteUrl: string;
	options: PostTypeOption[] | undefined;
	selectedValues: string[];
	disabled: boolean;
	isBrand?: boolean;
	onChange: ( values: string[] ) => void;
}

const EntitySiteCard = ( {
	siteName,
	siteUrl,
	options,
	selectedValues,
	disabled,
	isBrand = false,
	onChange,
}: EntitySiteCardProps ) => (
	<div
		className={ `onesearch-entity-site${
			isBrand ? ' onesearch-entity-brand' : ''
		}` }
	>
		<div className="onesearch-entity-site-header">
			<h3 className="onesearch-entity-site-name">{ siteName }</h3>
			<p className="onesearch-entity-site-url">{ siteUrl }</p>
		</div>
		{ ! options ? (
			<Text variant="muted">
				{ __(
					'No entities to select. Please check site configuration',
					'onesearch'
				) }
			</Text>
		) : (
			<div className="onesearch-entity-selector">
				<MultiSelectChips
					placeholder={ __( 'Select entities…', 'onesearch' ) }
					options={ toMultiSelectOptions( options ) }
					value={ selectedValues }
					onChange={ onChange }
					valueField="slug"
					labelField="label"
					disabled={ disabled }
				/>
			</div>
		) }
	</div>
);

export default EntitySiteCard;
