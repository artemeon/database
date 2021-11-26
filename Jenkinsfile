#!groovy

pipeline {
    agent none

    options {
        buildDiscarder(logRotator(numToKeepStr: '5', artifactNumToKeepStr: '5'))
        quietPeriod(120)
        disableConcurrentBuilds()
    }

    triggers {
        pollSCM('H/15 * * * * ')
    }

    stages {


        stage ('php 8.1 docker') {
            agent {
                dockerfile {
                    filename 'php81.build.Dockerfile'
                    label 'dockerhost'
                }
            }
            environment {
                HOME = '.'
            }
            steps {
                sh 'composer install'
                sh './vendor/bin/phpunit'
            }
        }

        stage('Databases') {
            agent {
                label 'dockerhost'
            }
            environment {
                HOME = '.'
            }
            stages {

                stage ('php 8.1 docker-mysql-8') {
                    steps {
                        sh 'docker-compose -f docker-compose-mysql-8-1.yaml down'
                        sh 'docker-compose -f docker-compose-mysql-8-1.yaml build'
                        sh 'docker-compose -f docker-compose-mysql-8-1.yaml run php /usr/bin/run_tests.sh'
                        sh 'docker-compose -f docker-compose-mysql-8-1.yaml down'
                    }
                }

            }
        }
    }
}
