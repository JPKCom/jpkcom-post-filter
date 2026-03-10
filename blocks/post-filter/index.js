import { registerBlockType } from '@wordpress/blocks';
import { edit } from './edit';

registerBlockType( 'jpkcom/post-filter', { edit } );
