pipeline {
    agent { label "devel8" }
    tools {
        maven "maven 3.5"
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
        stage("Inform #search on Slack"){
            steps {
                script {
                    def changeLogSets = currentBuild.changeSets
                    def message = "${env.JOB_NAME}: build #${env.BUILD_NUMBER}\n\n"
                    for (int i = 0; i < changeLogSets.size(); i++) {
                        message += "ChangeSet ${i}\n\n"
                        def entries = changeLogSets[i].items
                        for (int j = 0; j < entries.length; j++) {
                            def entry = entries[j]
                            message += "${entry.commitId} by ${entry.author} on ${new Date(entry.timestamp)}:\n" +
                                    "    ${entry.msg}\n\n"
                            // Following commented code shows how to extract information about which files changed
                            //def files = new ArrayList(entry.affectedFiles)
                            //for (int k = 0; k < files.size(); k++) {
                            //    def file = files[k]
                            //    message += "  ${file.editType.name} ${file.path}\n"
                            //}
                        }
                    }
                    slackSend baseUrl: 'https://dbcdk.slack.com/services/hooks/jenkins-ci/', channel: '#search', message: message, token: 'ILw3OJoa6a9sniVqMuomJ8AP'
                }
            }
        }
    }
}
