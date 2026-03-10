import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, TextControl, ToggleControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { useSelect } from '@wordpress/data';

export function edit( { attributes, setAttributes } ) {
	const { postType, layout, groups, reset } = attributes;
	const blockProps = useBlockProps();

	const postTypes = useSelect( ( select ) => {
		const types = select( 'core' ).getPostTypes( { per_page: -1 } );
		if ( ! types ) return [];
		return types
			.filter( ( t ) => t.viewable && ! [ 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' ].includes( t.slug ) )
			.map( ( t ) => ( { label: t.labels.singular_name, value: t.slug } ) );
	}, [] );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Filter Settings', 'jpkcom-post-filter' ) }>
					<SelectControl
						label={ __( 'Post Type', 'jpkcom-post-filter' ) }
						value={ postType }
						options={ postTypes }
						onChange={ ( val ) => setAttributes( { postType: val } ) }
					/>
					<SelectControl
						label={ __( 'Layout', 'jpkcom-post-filter' ) }
						value={ layout }
						options={ [
							{ label: __( 'Default (Backend Setting)', 'jpkcom-post-filter' ), value: '' },
							{ label: __( 'Bar', 'jpkcom-post-filter' ), value: 'bar' },
							{ label: __( 'Sidebar', 'jpkcom-post-filter' ), value: 'sidebar' },
							{ label: __( 'Dropdown', 'jpkcom-post-filter' ), value: 'dropdown' },
							{ label: __( 'Columns', 'jpkcom-post-filter' ), value: 'columns' },
						] }
						onChange={ ( val ) => setAttributes( { layout: val } ) }
					/>
					<TextControl
						label={ __( 'Filter Groups', 'jpkcom-post-filter' ) }
						help={ __( 'Comma-separated group slugs. Leave empty for all.', 'jpkcom-post-filter' ) }
						value={ groups }
						onChange={ ( val ) => setAttributes( { groups: val } ) }
					/>
					<ToggleControl
						label={ __( 'Show Reset Button', 'jpkcom-post-filter' ) }
						checked={ reset !== 'false' }
						onChange={ ( val ) => setAttributes( { reset: val ? 'true' : 'false' } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<ServerSideRender
					block="jpkcom/post-filter"
					attributes={ attributes }
				/>
			</div>
		</>
	);
}
