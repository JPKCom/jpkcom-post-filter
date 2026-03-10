const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'post-filter': path.resolve( __dirname, 'blocks/post-filter/index.js' ),
		'post-list': path.resolve( __dirname, 'blocks/post-list/index.js' ),
		'post-pagination': path.resolve( __dirname, 'blocks/post-pagination/index.js' ),
	},
	output: {
		path: path.resolve( __dirname, 'blocks/build' ),
		filename: '[name].js',
	},
};
