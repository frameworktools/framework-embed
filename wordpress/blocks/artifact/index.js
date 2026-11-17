/**
 * Editor script for the framework/artifact block. Plain wp.element calls, no build step.
 *
 * The block stores the embed document in its `document` attribute. Entering a
 * URL fetches it once through POST /framework-embed/v1/fetch and pins the
 * version URL. "Fetch again" and "Use newest version" fetch into a pending
 * copy, which replaces the stored one only when the author confirms. The
 * preview renders the stored copy with the same renderer the site uses
 * (window.FrameworkEmbed from framework-embed.iife.js).
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useRef = wp.element.useRef;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var blockEditor = wp.blockEditor;
	var components = wp.components;
	var apiFetch = wp.apiFetch;

	function parse( json ) {
		try {
			return json ? JSON.parse( json ) : null;
		} catch ( e ) {
			return null;
		}
	}

	function fetchDocument( url, newest ) {
		return apiFetch( {
			path: '/framework-embed/v1/fetch',
			method: 'POST',
			data: { url: url, newest: !! newest },
		} );
	}

	function attributesFrom( result, url ) {
		return {
			url: url,
			embedUrl: result.embedUrl || '',
			document: result.document,
			fetchedAt: result.fetchedAt,
			removed: false,
		};
	}

	/** The stored copy, rendered by the site's renderer, with charts drawn. */
	function Preview( props ) {
		var ref = useRef();
		var doc = parse( props.document );
		var html = doc && window.FrameworkEmbed ? window.FrameworkEmbed.render( doc ) : '';
		useEffect(
			function () {
				if ( ref.current && window.FrameworkEmbed && window.FrameworkEmbed.enhanceCharts ) {
					window.FrameworkEmbed.enhanceCharts( ref.current );
				}
			},
			[ html ]
		);
		if ( ! html ) {
			return el( components.Notice, { status: 'warning', isDismissible: false }, __( 'The stored copy cannot be shown.', 'framework-embed' ) );
		}
		return el( 'div', { ref: ref, className: 'framework-artifact__preview', dangerouslySetInnerHTML: { __html: html } } );
	}

	function UrlForm( props ) {
		var draft = useState( props.url || '' );
		return el(
			'form',
			{
				style: { display: 'flex', gap: '8px', width: '100%', alignItems: 'flex-end' },
				onSubmit: function ( event ) {
					event.preventDefault();
					props.onSubmit( draft[ 0 ].trim() );
				},
			},
			el(
				'div',
				{ style: { flexGrow: 1 } },
				el( components.TextControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					label: __( 'Artifact URL', 'framework-embed' ),
					type: 'url',
					placeholder: 'https://framework.pub/d/…',
					value: draft[ 0 ],
					onChange: draft[ 1 ],
				} )
			),
			el(
				components.Button,
				{ variant: 'primary', type: 'submit', __next40pxDefaultSize: true, isBusy: props.busy, disabled: props.busy || ! draft[ 0 ].trim() },
				__( 'Embed', 'framework-embed' )
			)
		);
	}

	wp.blocks.registerBlockType( 'framework/artifact', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var blockProps = blockEditor.useBlockProps();
			var busyState = useState( false );
			var errorState = useState( '' );
			var pendingState = useState( null );
			var busy = busyState[ 0 ];
			var pending = pendingState[ 0 ];
			var doc = parse( attributes.document );

			function run( request, onDone ) {
				busyState[ 1 ]( true );
				errorState[ 1 ]( '' );
				request
					.then( onDone )
					.catch( function ( error ) {
						errorState[ 1 ]( ( error && error.message ) || __( 'The artifact could not be fetched.', 'framework-embed' ) );
					} )
					.finally( function () {
						busyState[ 1 ]( false );
					} );
			}

			function insert( url ) {
				run( fetchDocument( url, false ), function ( result ) {
					props.setAttributes( attributesFrom( result, url ) );
				} );
			}

			function fetchAgain( newest ) {
				var target = attributes.embedUrl || attributes.url;
				run( fetchDocument( target, newest ), function ( result ) {
					pendingState[ 1 ]( result );
				} );
			}

			var error = errorState[ 0 ]
				? el( components.Notice, { status: 'error', onRemove: function () { errorState[ 1 ]( '' ); } }, errorState[ 0 ] )
				: null;

			if ( ! attributes.url ) {
				return el(
					'div',
					blockProps,
					el(
						components.Placeholder,
						{
							icon: 'chart-bar',
							label: __( 'Framework artifact', 'framework-embed' ),
							instructions: __( 'Paste the URL of a public or unlisted artifact. A copy is stored with the post.', 'framework-embed' ),
						},
						error,
						el( UrlForm, { url: '', busy: busy, onSubmit: insert } )
					)
				);
			}

			var newer = doc && typeof doc.latest_version === 'number' && typeof doc.version === 'number' && doc.latest_version > doc.version;
			var stored = doc
				? sprintf(
						/* translators: 1: version number, 2: date and time the copy was fetched. */
						__( 'Stored copy of version %1$s, fetched %2$s.', 'framework-embed' ),
						doc.version,
						attributes.fetchedAt ? new Date( attributes.fetchedAt ).toLocaleString() : '—'
				  )
				: attributes.removed
				? __( 'The server reported this artifact gone, so its stored copy was removed.', 'framework-embed' )
				: __( 'No stored copy.', 'framework-embed' );

			var actions = el(
				'div',
				{ style: { display: 'flex', gap: '8px', flexWrap: 'wrap' } },
				el(
					components.Button,
					{ variant: 'secondary', isBusy: busy, disabled: busy, onClick: function () { fetchAgain( false ); } },
					doc ? __( 'Fetch again', 'framework-embed' ) : __( 'Fetch', 'framework-embed' )
				),
				newer
					? el(
							components.Button,
							{ variant: 'secondary', disabled: busy, onClick: function () { fetchAgain( true ); } },
							/* translators: %s: newest version number. */
							sprintf( __( 'Use newest version (%s)', 'framework-embed' ), doc.latest_version )
					  )
					: null
			);

			var confirm = pending
				? el(
						components.Notice,
						{
							status: 'info',
							isDismissible: false,
							actions: [
								{
									label: __( 'Replace stored copy', 'framework-embed' ),
									variant: 'primary',
									onClick: function () {
										props.setAttributes( attributesFrom( pending, attributes.url ) );
										pendingState[ 1 ]( null );
									},
								},
								{
									label: __( 'Keep current copy', 'framework-embed' ),
									onClick: function () {
										pendingState[ 1 ]( null );
									},
								},
							],
						},
						/* translators: 1: version number, 2: artifact title. */
						sprintf( __( 'Fetched version %1$s of “%2$s”. Replace the stored copy?', 'framework-embed' ), pending.version, pending.title )
				  )
				: null;

			return el(
				Fragment,
				null,
				el(
					blockEditor.BlockControls,
					null,
					el(
						components.ToolbarGroup,
						null,
						el( components.ToolbarButton, {
							icon: 'update',
							label: __( 'Fetch again', 'framework-embed' ),
							disabled: busy,
							onClick: function () {
								fetchAgain( false );
							},
						} ),
						el( components.ToolbarButton, {
							icon: 'edit',
							label: __( 'Change URL', 'framework-embed' ),
							onClick: function () {
								props.setAttributes( { url: '', embedUrl: '', document: '', fetchedAt: '', removed: false } );
							},
						} )
					)
				),
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Stored copy', 'framework-embed' ) },
						el( 'p', null, stored ),
						attributes.embedUrl ? el( 'p', null, el( 'code', { style: { wordBreak: 'break-all' } }, attributes.embedUrl ) ) : null,
						actions
					)
				),
				el(
					'div',
					blockProps,
					error,
					confirm,
					pending ? el( Preview, { document: pending.document } ) : doc ? el( Preview, { document: attributes.document } ) : el( components.Placeholder, { icon: 'chart-bar', label: __( 'Framework artifact', 'framework-embed' ) }, stored, actions )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
