#!groovy
@Library('art-shared@master') _



pipeline {
    agent none

    options {
        buildDiscarder(logRotator(numToKeepStr: '5', artifactNumToKeepStr: '5'))
        quietPeriod(120)
        disableConcurrentBuilds()
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
                sh 'tar -czf src.tar.gz ./src'
                stash 'src.tar.gz'
                archiveArtifacts artifacts: 'src.tar.gz'
            }

        }
    }
}
