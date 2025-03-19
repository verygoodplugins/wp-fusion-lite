import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';
import icons from './icons';
import '../scss/editor.scss';

const Edit = () => {
	const blockProps = useBlockProps();

	const { className } = blockProps;

	return (
		<div { ...blockProps } className={ className }>
			<header className={ className + '__handle' }>
				<span className={ className + '__description' }>
					<span>
						{ __(
							'Content shown if the conditions are not met.',
							'secure-blocks-for-gutenberg'
						) }
					</span>
				</span>
				<span className={ className + '__icon' }>
					{ icons.unlocked }
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

export default registerBlockType( 'wp-fusion/secure-block-inner-unsecure', {
	apiVersion: 3,
	title: __( 'Unsecure', 'secure-blocks-for-gutenberg' ),
	category: 'layout',
	attributes: {},
	parent: [ 'wp-fusion/secure-block' ],
	edit: Edit,
	save: Save,
} );
