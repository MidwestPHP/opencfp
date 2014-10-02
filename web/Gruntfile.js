module.exports = function(grunt) {

    grunt.initConfig({
        pkg: grunt.file.readJSON('package.json'),
        watch: {
            options: {
                livereload: true
            },
            scripts: {
                files: ['assets/js/main.js'],
                tasks: ['uglify']
            },
            css: {
                files: ['assets/scss/**/*.scss'],
                tasks: ['compass']
            },
            php: {
                files: ['*.php', '*/*.php', '**/*/*.php'],
                tasks: ['templateUpdate']
            }
        },
        uglify: {
            my_target: {
                files: {
                    'assets/js/main.min.js': ['assets/js/main.js']
                }
            }
        },
        compass: {
            dist: {
                options: {
                    config: 'config.rb'
                }
            }
        }
    });

    grunt.loadNpmTasks('grunt-contrib-watch');
    grunt.loadNpmTasks('grunt-contrib-uglify');
    grunt.loadNpmTasks('grunt-contrib-compass');

    grunt.registerTask('templateUpdate', function() {
        console.log('WordPress template files updated');
    });

    grunt.registerTask('default', ['compass', 'uglify']);

}

