/**
 * Editor side of the dynamic "AI Hazır rozeti" block: the server renders it (no build step).
 *
 * @package AIHazirSite
 */

( function ( blocks, element, serverSideRender, blockEditor ) {
	const el = element.createElement;

	blocks.registerBlockType( 'ai-hazir-site/rozet', {
		edit: function ( props ) {
			return el(
				'div',
				blockEditor.useBlockProps(),
				el( serverSideRender, {
					block: 'ai-hazir-site/rozet',
					attributes: props.attributes,
				} )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.serverSideRender, window.wp.blockEditor );
