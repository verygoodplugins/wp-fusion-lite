import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import {
	InnerBlocks,
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import { useState, useEffect } from '@wordpress/element';
import { PanelBody, PanelRow } from '@wordpress/components';
import WpfSelect from '@verygoodplugins/wpfselect';
import '../scss/editor.scss';

const Edit = ( { attributes: { tag }, setAttributes } ) => {
	const [ selectedTags, setSelectedTags ] = useState( [] );
	const blockProps = useBlockProps();

	useEffect( () => {
		if ( tag ) {
			setSelectedTags( JSON.parse( tag ) );
		}
	}, [ tag, setSelectedTags ] );

	const { className } = blockProps;

	const handleTagChange = ( innerTag ) => {
		setAttributes( { tag: JSON.stringify( innerTag ) } );
	};

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Required Tags (Any)',
						'secure-blocks-for-gutenberg'
					) }
					className="secure-block-inspector"
				>
					<PanelRow>
						<label
							htmlFor="secure-block-tags"
							className="secure-block-inspector__label"
						>
							{ __(
								'Restricted content is presented to users that are logged-in and have at least one of the following tags:',
								'secure-blocks-for-gutenberg'
							) }
						</label>
					</PanelRow>
					<PanelRow>
						<WpfSelect
							existingTags={ selectedTags }
							onChange={ handleTagChange }
							elementID="wpf-secure-block-select"
						/>
					</PanelRow>
					<PanelRow>
						<em className="muted">
							{ __(
								'No selected tags mean that restricted content will be presented to all logged-in users.',
								'secure-blocks-for-gutenberg'
							) }
						</em>
					</PanelRow>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps } className={ className }>
				<InnerBlocks
					template={ [
						[ 'wp-fusion/secure-block-inner-secure' ],
						[ 'wp-fusion/secure-block-inner-unsecure' ],
					] }
					templateLock="all"
					allowedBlocksExample={ [
						[ 'wp-fusion/secure-block-inner-secure' ],
						[ 'wp-fusion/secure-block-inner-unsecure' ],
					] }
				/>
				<footer className={ className + '__footer' }>
					{ __( 'End: WP Fusion', 'wp-fusion-lite' ) }
				</footer>
			</div>
		</>
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

export default registerBlockType( 'wp-fusion/secure-block', {
	apiVersion: 3,
	title: __( 'WP Fusion', 'secure-blocks-for-gutenberg' ),
	description: __(
		'By default the secure content is only shown if a user is logged in. You can also restrict the block to be visible to users with certain tags.',
		'wp-fusion-lite'
	),
	category: 'layout',
	icon: 'lock',
	keywords: [
		__( 'Secure Block' ),
		__( 'Permissions' ),
		__( 'Password Protected' ),
	],
	attributes: {
		tag: {
			type: 'string',
			default: null,
		},
	},
	edit: Edit,
	save: Save,
} );
