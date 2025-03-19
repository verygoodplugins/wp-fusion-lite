import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';
import { useState, useEffect } from '@wordpress/element';
import '../scss/editor.scss';
import icons from './icons';

const Edit = ( { clientId } ) => {
	const [ selectedTags, setSelectedTags ] = useState( [] );
	const blockProps = useBlockProps();

	const parentBlock = useSelect(
		( select ) => {
			const { getBlockParents, getBlock } = select( 'core/block-editor' );
			const parentIds = getBlockParents( clientId );
			const parent = parentIds.filter(
				( id ) => getBlock( id ).name === 'wp-fusion/secure-block'
			);

			return parent.length ? getBlock( parent[ 0 ] ) : null;
		},
		[ clientId ]
	);
	const parentAttributes = parentBlock ? parentBlock.attributes : {};

	const { className } = blockProps;
	const { tag } = parentAttributes;

	useEffect( () => {
		if ( tag ) {
			setSelectedTags( JSON.parse( tag ) );
		}
	}, [ tag, setSelectedTags ] );

	return (
		<div { ...blockProps }>
			<header className={ className + '__handle' }>
				<span className={ className + '__icon' }>{ icons.lock }</span>
				<span className={ className + '__description' }>
					<span>
						{ __(
							'Content shown to users that are',
							'secure-blocks-for-gutenberg'
						) }
					</span>
					<strong>
						{ __( 'logged-in', 'secure-blocks-for-gutenberg' ) }
					</strong>
					{ selectedTags.length === 0 ? (
						<span>.</span>
					) : (
						<span>
							{ 1 === selectedTags.length ? (
								<span>
									{ __(
										'and have the following tag:',
										'secure-blocks-for-gutenberg'
									) }
								</span>
							) : (
								<span>
									{ __(
										'and have any of the following tags:',
										'secure-blocks-for-gutenberg'
									) }
								</span>
							) }
							<span className={ className + '__tags' }>
								{ Object( selectedTags ).map(
									( value, key ) => (
										<span
											className="tag"
											key={ `tag-${ key }` }
										>
											<span className="tag__name">
												{ value.label }
											</span>
										</span>
									)
								) }
							</span>
						</span>
					) }
				</span>
			</header>

			<InnerBlocks
				template={ [ [ 'core/paragraph' ] ] }
				templateLock={ false }
			/>
		</div>
	);
};

const Save = () => {
	const blockProps = useBlockProps.save();
	return (
		<div { ...blockProps }>
			<InnerBlocks.Content />
		</div>
	);
};

export default registerBlockType( 'wp-fusion/secure-block-inner-secure', {
	apiVersion: 3,
	title: __( 'Inner', 'secure-blocks-for-gutenberg' ),
	category: 'layout',
	attributes: {},
	parent: [ 'wp-fusion/secure-block' ],
	edit: Edit,
	save: Save,
} );
