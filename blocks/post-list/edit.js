import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, RangeControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { useSelect } from '@wordpress/data';

export function edit( { attributes, setAttributes } ) {
	const { postType, layout, limit, orderby, order } = attributes;
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
				<PanelBody title={ __( 'List Settings', 'jpkcom-post-filter' ) }>
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
							{ label: __( 'Cards (grid)', 'jpkcom-post-filter' ), value: 'cards' },
							{ label: __( 'Rows (list)', 'jpkcom-post-filter' ), value: 'rows' },
							{ label: __( 'Minimal', 'jpkcom-post-filter' ), value: 'minimal' },
							{ label: __( 'Theme Default', 'jpkcom-post-filter' ), value: 'theme' },
						] }
						onChange={ ( val ) => setAttributes( { layout: val } ) }
					/>
					<RangeControl
						label={ __( 'Posts per Page', 'jpkcom-post-filter' ) }
						help={ __( '-1 = show all posts', 'jpkcom-post-filter' ) }
						value={ limit }
						onChange={ ( val ) => setAttributes( { limit: val } ) }
						min={ -1 }
						max={ 100 }
					/>
					<SelectControl
						label={ __( 'Order By', 'jpkcom-post-filter' ) }
						value={ orderby }
						options={ [
							{ label: __( 'Date', 'jpkcom-post-filter' ), value: 'date' },
							{ label: __( 'Title', 'jpkcom-post-filter' ), value: 'title' },
							{ label: __( 'Menu Order', 'jpkcom-post-filter' ), value: 'menu_order' },
						] }
						onChange={ ( val ) => setAttributes( { orderby: val } ) }
					/>
					<SelectControl
						label={ __( 'Order', 'jpkcom-post-filter' ) }
						value={ order }
						options={ [
							{ label: __( 'Descending', 'jpkcom-post-filter' ), value: 'DESC' },
							{ label: __( 'Ascending', 'jpkcom-post-filter' ), value: 'ASC' },
						] }
						onChange={ ( val ) => setAttributes( { order: val } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<ServerSideRender
					block="jpkcom/post-list"
					attributes={ attributes }
				/>
			</div>
		</>
	);
}
