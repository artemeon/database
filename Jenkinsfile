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

        stage ('php 8.0 docker') {
            agent {
                dockerfile {
                    filename 'php80.build.Dockerfile'
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
                stage ('php 7.4 docker-mysql-8') {
                    steps {
                        sh 'docker-compose -f docker-compose-mysql-8.yaml down'
                        sh 'docker-compose -f docker-compose-mysql-8.yaml build'
                        sh 'docker-compose -f docker-compose-mysql-8.yaml run php /usr/bin/run_tests.sh'
                        sh 'docker-compose -f docker-compose-mysql-8.yaml down'
                    }
                }
                stage ('php 7.4 docker-mysql-5-7') {
                    steps {
                        sh 'docker-compose -f docker-compose-mysql-5-7.yaml down'
                        sh 'docker-compose -f docker-compose-mysql-5-7.yaml build'
                        sh 'docker-compose -f docker-compose-mysql-5-7.yaml run php /usr/bin/run_tests.sh'
                        sh 'docker-compose -f docker-compose-mysql-5-7.yaml down'
                    }
                }
                stage ('php 7.4 docker-postgres-10') {
                    steps {
                        sh 'docker-compose -f docker-compose-postgres-10.yaml down'
                        sh 'docker-compose -f docker-compose-postgres-10.yaml build'
                        sh 'docker-compose -f docker-compose-postgres-10.yaml run php /usr/bin/run_tests.sh'
                        sh 'docker-compose -f docker-compose-postgres-10.yaml down'
                    }
                }
                stage ('php 7.4 docker-postgres-11') {
                    steps {
                        sh 'docker-compose -f docker-compose-postgres-11.yaml down'
                        sh 'docker-compose -f docker-compose-postgres-11.yaml build'
                        sh 'docker-compose -f docker-compose-postgres-11.yaml run php /usr/bin/run_tests.sh'
                        sh 'docker-compose -f docker-compose-postgres-11.yaml down'
                    }
                }
                stage ('php 7.4 docker-postgres-12') {
                    steps {
                        sh 'docker-compose -f docker-compose-postgres-12.yaml down'
                        sh 'docker-compose -f docker-compose-postgres-12.yaml build'
                        sh 'docker-compose -f docker-compose-postgres-12.yaml run php /usr/bin/run_tests.sh'
                        sh 'docker-compose -f docker-compose-postgres-12.yaml down'
                    }
                }
                stage ('php 7.4 docker-postgres-13') {
                    steps {
                        sh 'docker-compose -f docker-compose-postgres-13.yaml down'
                        sh 'docker-compose -f docker-compose-postgres-13.yaml build'
                        sh 'docker-compose -f docker-compose-postgres-13.yaml run php /usr/bin/run_tests.sh'
                        sh 'docker-compose -f docker-compose-postgres-13.yaml down'
                    }
                }
                stage ('php 7.4 docker-mssql-2017') {
                    steps {
                        sh 'docker-compose -f docker-compose-mssql-2017.yaml down'
                        sh 'docker-compose -f docker-compose-mssql-2017.yaml build'
                        sh 'docker-compose -f docker-compose-mssql-2017.yaml run php /usr/bin/run_tests.sh skip-wait'
                        sh 'docker-compose -f docker-compose-mssql-2017.yaml down'
                    }
                }
                stage ('php 7.4 docker-oracle-12c') {
                    steps {
                        sh 'docker-compose -f docker-compose-oracle-12c.yaml down'
                        sh 'docker-compose -f docker-compose-oracle-12c.yaml build'
                        sh 'docker-compose -f docker-compose-oracle-12c.yaml run php /usr/bin/run_tests.sh skip-wait'
                        sh 'docker-compose -f docker-compose-oracle-12c.yaml down'
                    }
                }
                stage ('php 7.4 docker-oracle-19c') {
                    steps {
                        sh 'docker-compose -f docker-compose-oracle-19c.yaml down'
                        sh 'docker-compose -f docker-compose-oracle-19c.yaml build'
                        sh 'docker-compose -f docker-compose-oracle-19c.yaml run php /usr/bin/run_tests.sh skip-wait'
                        sh 'docker-compose -f docker-compose-oracle-19c.yaml down'
                    }
                }
            }
        }
    }
}
