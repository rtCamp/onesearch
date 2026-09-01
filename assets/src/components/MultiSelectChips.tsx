/**
 * WordPress dependencies
 */
import { Popover, Icon } from '@wordpress/components';
import { chevronDown, closeSmall } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';

/**
 * External dependencies
 */
import {
	useState,
	useRef,
	useEffect,
	type MouseEvent,
	type KeyboardEvent,
} from 'react';

interface MultiSelectChipsProps {
	label?: string;

	/**
	 * Accessible name for the control, for when several appear on one screen
	 * and the visible placeholder is the same for all of them.
	 */
	ariaLabel?: string;
	placeholder?: string;
	options: Array< Record< string, string | number > >;
	value?: string[];
	onChange?: ( selected: string[] ) => void;
	disabled?: boolean;
	valueField?: string;
	labelField?: string;
}

function MultiSelectChips( {
	label,
	ariaLabel,
	placeholder = __( 'Select…', 'onesearch' ),
	options = [],
	value = [],
	onChange = () => {},
	disabled = false,
	valueField = 'value',
	labelField = 'label',
}: MultiSelectChipsProps ) {
	const [ open, setOpen ] = useState( false );
	const anchorRef = useRef< HTMLDivElement >( null );

	// If the parent disables the control while the menu is open, close it.
	useEffect( () => {
		if ( disabled && open ) {
			setOpen( false );
		}
	}, [ disabled, open ] );

	const valueSet = new Set( ( value || [] ).map( String ) );

	const getVal = ( item: Record< string, unknown > | string ): string => {
		if ( typeof item === 'string' ) {
			return item;
		}
		return String(
			item[ valueField ] ??
				item[ 'slug' ] ??
				item[ 'id' ] ??
				item[ 'key' ] ??
				''
		);
	};

	const getLabel = ( item: Record< string, unknown > | string ): string => {
		if ( typeof item === 'string' ) {
			return item;
		}
		return String(
			item[ labelField ] ??
				item[ 'label' ] ??
				item[ 'name' ] ??
				item[ 'slug' ] ??
				getVal( item )
		);
	};

	// Change the set based on toggled values.
	const toggleValue = ( val: string ) => {
		if ( disabled ) {
			return;
		}
		const key = String( val );
		const set = new Set( valueSet );

		if ( set.has( key ) ) {
			set.delete( key );
		} else {
			set.add( key );
		}

		onChange( Array.from( set ) );
	};

	const removeValue = ( val: string ) => {
		if ( disabled ) {
			return;
		}
		const key = String( val );
		onChange( ( value || [] ).filter( ( v ) => String( v ) !== key ) );
	};

	const selected = options.filter( ( item ) =>
		valueSet.has( getVal( item ) )
	);

	const handleToggleOpen = () => {
		if ( ! disabled ) {
			setOpen( ( v ) => ! v );
		}
	};

	return (
		// @todo Semantic classname.
		<div className="msc" aria-disabled={ disabled } ref={ anchorRef }>
			{ label && <span className="msc-label">{ label }</span> }
			<div
				role="button"
				tabIndex={ disabled ? -1 : 0 }
				aria-label={ ariaLabel || label || placeholder }
				className="msc-control"
				onClick={ handleToggleOpen }
				onKeyDown={ ( e: KeyboardEvent< HTMLDivElement > ) => {
					if ( disabled ) {
						return;
					}
					if ( e.key === 'Enter' || e.key === ' ' ) {
						e.preventDefault();
						handleToggleOpen();
					}
				} }
				aria-haspopup="listbox"
				aria-expanded={ open }
			>
				<div
					className={ `msc-chips ${
						disabled ? 'msc--disabled' : ''
					}` }
				>
					{ selected.length === 0 ? (
						<span className="msc-placeholder">{ placeholder }</span>
					) : (
						selected.map( ( item ) => {
							const itemValue = getVal( item );
							const itemLabel = getLabel( item );

							const handleRemove = (
								e: MouseEvent< HTMLButtonElement >
							) => {
								e.stopPropagation();
								removeValue( itemValue );
							};

							const handleKeyDown = (
								e: KeyboardEvent< HTMLButtonElement >
							) => {
								if ( disabled ) {
									return;
								}
								if ( e.key === 'Enter' || e.key === ' ' ) {
									e.preventDefault();
									e.stopPropagation();
									removeValue( itemValue );
								}
							};

							return (
								<span key={ itemValue } className="msc-chip">
									<span className="msc-chip-label">
										{ itemLabel }
									</span>
									<button
										type="button"
										tabIndex={ disabled ? -1 : 0 }
										className="msc-chip-close"
										onClick={ handleRemove }
										onKeyDown={ handleKeyDown }
										aria-label={ sprintf(
											/* translators: %s: item label */
											__( 'Remove %s', 'onesearch' ),
											itemLabel
										) }
										disabled={ disabled }
									>
										<Icon
											icon={ closeSmall }
											aria-hidden={ false }
										/>
									</button>
								</span>
							);
						} )
					) }
				</div>
				<Icon icon={ chevronDown } />
			</div>

			{ open && (
				<Popover
					anchor={ anchorRef.current }
					onClose={ () => setOpen( false ) }
				>
					<div
						className="msc-menu"
						role="listbox"
						style={ {
							minWidth: anchorRef.current?.offsetWidth,
						} }
					>
						<ul className="msc-list">
							{ options.map( ( item ) => {
								const val = getVal( item );
								const itemLabel = getLabel( item );
								const id = `msc-opt-${ encodeURIComponent(
									val
								) }`;
								const checked = valueSet.has( val );
								return (
									<li
										key={ val }
										className="msc-row"
										role="option"
										aria-selected={ checked }
									>
										<label
											htmlFor={ id }
											className="msc-row-label"
										>
											<input
												id={ id }
												type="checkbox"
												checked={ checked }
												onChange={ () =>
													toggleValue( val )
												}
												disabled={ disabled }
											/>
											<span className="msc-row-text">
												{ itemLabel }
											</span>
										</label>
									</li>
								);
							} ) }
						</ul>
					</div>
				</Popover>
			) }
		</div>
	);
}

export default MultiSelectChips;
