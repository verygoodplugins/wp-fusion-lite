/**
 * Import Assets
 */
import '../scss/editor.scss';
import '../scss/admin.scss';

/**
 * Block Dependencies
 */
import icons from './icons';
import classnames from 'classnames';
import Select from 'react-select';

/**
 * Internal Block Libraries
 */
const { __ }                = wp.i18n;
const { registerBlockType } = wp.blocks;
const { apiFetch }          = wp;
const {
	registerStore,
	withSelect,
} = wp.data;
const {
	InnerBlocks,
	InspectorControls,
} = wp.editor;
const {
	PanelBody,
	PanelRow,
	Spinner,
} = wp.components;

const actions = {
	setUserTags( userTags ) {
		return {
			type: 'SET_USER_TAGS',
			userTags,
		};
	},
	receiveUserTags( path ) {
		return {
			type: 'RECEIVE_USER_TAGS',
			path,
		};
	},
};

const store = registerStore( 'wp-fusion/secure-block', {
	reducer( state = { userTags: {} }, action ) {

		switch ( action.type ) {
			case 'SET_USER_TAGS':
				return {
					state,
					userTags: action.userTags,
				};
		}

		return state;
	},

	actions,

	selectors: {
		receiveUserTags( state ) {
			const { userTags } = state;
			return userTags;
		},
	},

	controls: {
		RECEIVE_USER_TAGS( action ) {
			return apiFetch( { path: action.path } );
		},
	},

	resolvers: {
		* receiveUserTags( state ) {
			const userTags = yield actions.receiveUserTags( '/wp-fusion/secure-blocks/v1/available-tags/' );
			return actions.setUserTags( userTags );
		},
	},
} );

/**
 * Register secure block
 */
export default registerBlockType(
	'wp-fusion/secure-block',
	{
		title:       __( 'WP Fusion', 'secure-blocks-for-gutenberg' ),
		description: __( 'By default the secure content is only shown if a user is logged in. You can also restrict the block to be visible to users with certain tags.', 'wp-fusion' ),
		category:   'layout',
		icon:       'lock',
		keywords:   [
			__( 'Secure Block' ),
			__( 'Permissions' ),
			__( 'Password Protected' )
		],
		attributes: {
			tag: {
				type:    'string',
				default: null,
			},
		},
		edit: withSelect( ( select ) => {
				return {
					userTags: select('wp-fusion/secure-block').receiveUserTags(),
				};
			} )( props => {
			const { attributes: { tag }, userTags, className, setAttributes } = props;
			const handleTagChange = ( tag ) => setAttributes( { tag: JSON.stringify( tag ) } );
			let tagsToString = '';
			let selectedTags = [];
			if ( null !== tag ) {
				selectedTags = JSON.parse( tag );
			}

			if ( ! userTags.length ) {
				return (
					<p className={className} >
						<Spinner />
						{ __( 'Loading Data', 'wp-fusion' ) }
					</p>
				);
			}
			return [
				<InspectorControls>
					<PanelBody title={ __( 'Required Tags (Any)', 'secure-blocks-for-gutenberg' ) } className="secure-block-inspector">
						<PanelRow>
							<label htmlFor="secure-block-tags" className="secure-block-inspector__label">
								{ __( 'Restricted content is presented to users that are logged-in and have at least one of the following tags:', 'secure-blocks-for-gutenberg' ) }
							</label>
						</PanelRow>
						<PanelRow>
							<Select
								className="secure-block-inspector__control"
								name='secure-block-tags'
								value={ selectedTags }
								onChange={ handleTagChange }
								options={ userTags }
								isMulti='true'
							 />
						</PanelRow>
						<PanelRow>
							<em className="muted">{ __( 'No selected tags mean that restricted content will be presented to all logged-in users.', 'secure-blocks-for-gutenberg' ) }</em>
						</PanelRow>
					</PanelBody>
				</InspectorControls>,
				<div className={ classnames( props.className ) }>
					<header className={ classnames( props.className ) + '__handle' }>
						<span className={ classnames( props.className ) + '__icon' }>
							{ icons.lock }
						</span>
						<span className={classnames( props.className ) + '__description'}>
							<span>{ __( 'Content shown to users that are ', 'secure-blocks-for-gutenberg' ) }</span>
							<strong>{ __( 'logged-in', 'secure-blocks-for-gutenberg' ) }</strong>
							{ selectedTags.length === 0  ?
								<span>.</span>
							:
								<span>
									{ 1 === selectedTags.length ?
										<span>
											{ __( ' and have the following tag: ', 'secure-blocks-for-gutenberg' ) }
										</span>
									:
										<span>
											{ __( ' and have any of the following tags: ', 'secure-blocks-for-gutenberg' ) }
										</span>
									}
									<span className={classnames( props.className ) + '__tags'}>
									{ Object( selectedTags ).map( ( value, key ) =>
										<span className="tag">
											<span className="tag__name">
												{ value['label'] }
											</span>
										</span>
									)}
									</span>
								</span>
							}
						</span>
					</header>
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
					<footer className={ classnames( props.className ) + '__footer' }>
						{ __( 'End: WP Fusion', 'wp-fusion' ) }
					</footer>
				</div>
			];
		} ),
		save: props => {
			return (
				<div>
					<InnerBlocks.Content />
				</div>
			);
		},
	},
);
