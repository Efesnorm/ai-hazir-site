/**
 * Editor side of the dynamic "Komşu ülkelerde" block: settings in the sidebar, the server renders it (no build step).
 * window.aihsNetworkBlock = { sectors: { letter: label }, sites: [ { url, name } ] } is printed by NetworkBlock.
 *
 * @package AIHazirSite
 */

( function ( blocks, element, serverSideRender, blockEditor, components ) {
	const el   = element.createElement;
	const data = window.aihsNetworkBlock || { sectors: {}, sites: [] };
	const none = { label: '—', value: '' };

	blocks.registerBlockType( 'ai-hazir-site/komsu-ulkeler', {
		edit: function ( props ) {
			const a   = props.attributes;
			const set = function ( key ) {
				return function ( value ) {
					const change  = {};
					change[ key ] = value;
					props.setAttributes( change );
				};
			};
			return el(
				'div',
				blockEditor.useBlockProps(),
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: 'İlanlar' },
						el( components.TextControl, { label: 'Başlık (boşsa "Komşu ülkelerde")', value: a.title, onChange: set( 'title' ) } ),
						el( components.RangeControl, { label: 'Adet', value: a.count, min: 1, max: 12, onChange: set( 'count' ) } ),
						el( components.SelectControl, {
							label: 'Tür',
							value: a.type,
							options: [ none, { label: 'Satılan', value: 'offer' }, { label: 'Aranan', value: 'demand' }, { label: 'Tedarik edilebilen', value: 'supply' } ],
							onChange: set( 'type' ),
						} ),
						el( components.TextControl, { label: 'Kategori (tam eşleşme)', value: a.category, onChange: set( 'category' ) } ),
						el( components.TextControl, { label: 'Bölge (tam eşleşme)', value: a.region, onChange: set( 'region' ) } ),
						el( components.SelectControl, {
							label: 'Faaliyet alanı (NACE Rev. 2.1)',
							value: a.sector,
							options: [ none ].concat( Object.keys( data.sectors ).map( function ( letter ) {
								return { label: data.sectors[ letter ], value: letter };
							} ) ),
							onChange: set( 'sector' ),
						} ),
						el( components.SelectControl, {
							label: 'Yalnızca bu kardeş portal',
							value: a.site,
							options: [ none ].concat( data.sites.map( function ( site ) {
								return { label: site.name || site.url, value: site.url };
							} ) ),
							onChange: set( 'site' ),
						} )
					)
				),
				el( serverSideRender, {
					block: 'ai-hazir-site/komsu-ulkeler',
					attributes: a,
					EmptyResponsePlaceholder: function () {
						return el( 'p', null, 'Komşu ülkelerde: şu an uygun kardeş ilanı yok (sayfada hiçbir şey görünmez).' );
					},
				} )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.serverSideRender, window.wp.blockEditor, window.wp.components );
