def workerNode = 'devel10'

pipeline {
    agent { label "devel8" }
    tools {
        maven "maven 3.5"
    }
    environment {
        DOCKER_PUSH_TAG = "${env.BUILD_NUMBER}"
        GITLAB_PRIVATE_TOKEN = credentials("metascrum-gitlab-api-token")
    }
    triggers {
        pollSCM("H/3 * * * *")
    }
    options {
        buildDiscarder(logRotator(artifactDaysToKeepStr: "", artifactNumToKeepStr: "", daysToKeepStr: "30", numToKeepStr: "30"))
        timestamps()
    }
    stages {
        stage("build") {
            steps {
                // Fail Early..
                script {
                    if (!env.BRANCH_NAME) {
                        throw new hudson.AbortException('Job Started from non MultiBranch Build')
                    } else {
                        println(" Building BRANCH_NAME == ${BRANCH_NAME}")
                    }

                }
                sh """                     
                    ./build.sh                                                                                    
                """
                archiveArtifacts artifacts: '**/docker/*.tar.gz', fingerprint: true
                //junit "**/target/surefire-reports/TEST-*.xml,**/target/failsafe-reports/TEST-*.xml"
            }
        }
        stage("docker") {
            steps {
                script {
                    dirName = "docker"
                    version = 5.0
                    dir(dirName) {
                        def imageName = "opensearch-webservice-${version}".toLowerCase()
                        def imageLabel = env.BUILD_NUMBER
                        if (!(env.BRANCH_NAME ==~ /master|trunk/)) {
                            println("Using branch_name ${BRANCH_NAME}")
                            imageLabel = BRANCH_NAME.split(/\//)[-1]
                            imageLabel = imageLabel.toLowerCase()
                        } else {
                            println(" Using Master branch ${BRANCH_NAME}")
                        }
                        println("In ${dirName} build opensearch as ${imageName}:$imageLabel")
                        def app = docker.build("$imageName:${imageLabel}".toLowerCase(), '--pull --no-cache .')

                        if (currentBuild.resultIsBetterOrEqualTo('SUCCESS')) {
                            docker.withRegistry('https://docker-os.dbc.dk', 'docker') {
                                app.push()
                                if (env.BRANCH_NAME ==~ /master|trunk/) {
                                    app.push "latest"
                                }
                            }
                        }
                    }
                }
            }
        }
        stage("Update DIT") {
            agent {
                docker {
                    label workerNode
                    image "docker.dbc.dk/build-env:latest"
                    alwaysPull true
                }
            }
            when {
                expression {
                    (currentBuild.result == null || currentBuild.result == 'SUCCESS') && env.BRANCH_NAME == 'master'
                }
            }
            steps {
                script {
                    dir("deploy") {
                        sh "set-new-version services/search/opensearch.yaml ${env.GITLAB_PRIVATE_TOKEN} metascrum/dit-gitops-secrets ${DOCKER_PUSH_TAG} -b master"
                        sh "set-new-version services/search/opensearch-dbckat.yaml ${env.GITLAB_PRIVATE_TOKEN} metascrum/dit-gitops-secrets ${DOCKER_PUSH_TAG} -b master"
                    }
                }
            }
        }

    } // stages

    post {
        failure {
            script {
                if ("${env.BRANCH_NAME}" == 'master') {
                    emailext(
                            recipientProviders: [developers(), culprits()],
                            to: "os-team@dbc.dk",
                            subject: "[Jenkins] ${env.JOB_NAME} #${env.BUILD_NUMBER} failed",
                            mimeType: 'text/html; charset=UTF-8',
                            body: "<p>The master build failed. Log attached. </p><p><a href=\"${env.BUILD_URL}\">Build information</a>.</p>",
                            attachLog: true,
                    )
                    slackSend(channel: 'search',
                            color: 'warning',
                            message: "${env.JOB_NAME} #${env.BUILD_NUMBER} failed and needs attention: ${env.BUILD_URL}",
                            tokenCredentialId: 'slack-global-integration-token')

                } else {
                    // this is some other branch, only send to developer
                    emailext(
                            recipientProviders: [developers()],
                            subject: "[Jenkins] ${env.BUILD_TAG} failed and needs your attention",
                            mimeType: 'text/html; charset=UTF-8',
                            body: "<p>${env.BUILD_TAG} failed and needs your attention. </p><p><a href=\"${env.BUILD_URL}\">Build information</a>.</p>",
                            attachLog: false,
                    )
                }
            }
        }
        success {
            step([$class: 'JavadocArchiver', javadocDir: 'target/site/apidocs', keepAll: false])
            archiveArtifacts artifacts: '**/target/*-jar-with-dependencies.jar', fingerprint: true
        }
    } // post

} // pipeline
