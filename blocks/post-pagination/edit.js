import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { useSelect } from '@wordpress/data';

export function edit( { attributes, setAttributes } ) {
	const { postType } = attributes;
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
				<PanelBody title={ __( 'Pagination Settings', 'jpkcom-post-filter' ) }>
					<SelectControl
						label={ __( 'Post Type', 'jpkcom-post-filter' ) }
						help={ __( 'Must match the Post List block on the same page.', 'jpkcom-post-filter' ) }
						value={ postType }
						options={ postTypes }
						onChange={ ( val ) => setAttributes( { postType: val } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<ServerSideRender
					block="jpkcom/post-pagination"
					attributes={ attributes }
				/>
			</div>
		</>
	);
}
