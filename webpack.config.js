const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path          = require( 'path' );

/**
 * Three entry points built into a single flat build/ directory:
 *
 *   index  — WordPress admin app  → build/index.js  + build/index.asset.php
 *   client — Client proposal view → build/client.js + build/client.asset.php
 *   portal — Client portal app    → build/portal.js + build/portal.asset.php
 *
 * The @wordpress/dependency-extraction-webpack-plugin writes asset manifests
 * as [name].asset.php relative to output.path, so a flat directory is the
 * simplest way to keep all bundles and their manifests co-located.
 */
module.exports = {
	...defaultConfig,

	entry: {
		index:     './admin/index.jsx',
		projects:  './admin/projects.jsx',
		analytics: './admin/analytics.jsx',
		setup:     './admin/setup.jsx',
		clients:   './admin/clients.jsx',
		team:      './admin/team.jsx',
		webhooks:  './admin/webhooks.jsx',
		client:    './client/index.jsx',
		portal:    './portal/index.jsx',
	},

	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
