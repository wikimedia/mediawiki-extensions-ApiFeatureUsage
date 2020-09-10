/* eslint-env node */
module.exports = function ( grunt ) {
	grunt.loadNpmTasks( 'grunt-eslint' );
	grunt.loadNpmTasks( 'grunt-banana-checker' );
	grunt.loadNpmTasks( 'grunt-stylelint' );

	grunt.initConfig( {
		banana: {
			all: [
				'i18n/'
			]
		},
		eslint: {
			options: {
				cache: true
			},
			all: '.'
		},
		stylelint: {
			all: [
				'**/*.css',
				'!node_modules/**',
				'!vendor/**'
			]
		}
	} );

	grunt.registerTask( 'test', [ 'banana', 'eslint', 'stylelint' ] );
};
