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
        stage ('php 7.4 docker') {
            agent {
                dockerfile {
                    filename 'php74.build.Dockerfile'
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

        stage ('php 7.4 docker-mysql') {
            agent {
                label 'dockerhost'
            }
            environment {
                HOME = '.'
            }
            steps {
                sh 'docker-compose -f docker-compose-mysql-8.yaml down'
                sh 'docker-compose -f docker-compose-mysql-8.yaml build'
                sh 'docker-compose -f docker-compose-mysql-8.yaml run php /usr/bin/run_tests.sh'
                sh 'docker-compose -f docker-compose-mysql-8.yaml down'
            }
        }
    }
}