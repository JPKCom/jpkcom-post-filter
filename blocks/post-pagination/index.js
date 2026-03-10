import { registerBlockType } from '@wordpress/blocks';
import { edit } from './edit';

registerBlockType( 'jpkcom/post-pagination', { edit } );
