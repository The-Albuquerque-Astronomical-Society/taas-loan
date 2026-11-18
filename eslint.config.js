const js = require( '@eslint/js' );
const globals = require( 'globals' );

module.exports = [
	{
		ignores: [ 'node_modules/**', 'vendor/**' ],
	},
	js.configs.recommended,
	{
		files: [ 'js/**/*.js' ],
		languageOptions: {
			ecmaVersion: 2015,
			sourceType: 'script',
			globals: {
				...globals.browser,
				...globals.jquery,
			},
		},
	},
	{
		files: [ 'eslint.config.js' ],
		languageOptions: {
			sourceType: 'commonjs',
			globals: globals.node,
		},
	},
];
