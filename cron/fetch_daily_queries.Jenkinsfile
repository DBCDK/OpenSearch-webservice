#!groovy

pipeline {
  agent {
    label "devel10"
  }
  triggers {
    cron("H 03 * * *")
  }
  environment {
    YESTERDATE = new Date().previous().format("yyyy-MM-dd")
    LOG_QUERIES = "os_daily_queries.${YESTERDATE}"
    ELK_URI = "https://elk.dbc.dk:9100"
    ELK_CREDENTIALS = credentials('elk_user');
    ARTIFACTORY_GENERIC = "https://artifactory.dbccloud.dk/artifactory/generic-fbiscrum-production/opensearch/"
    ARTIFACTORY_CREDENTIALS = credentials("artifactory_login")

  }
  stages {
    stage("Clear workspace") {
      steps {
        deleteDir()
        checkout scm
      }
    }
    stage("Extract loglines") {
      steps {
        script {
          sh "echo Fetch searches from log for ${YESTERDATE}"
          sh "rm -f ${LOG_QUERIES}"
          sh "./cron/fetch_queries_from_elk -o ${LOG_QUERIES} -e ${ELK_URI} -p ${ELK_CREDENTIALS} -d ${YESTERDATE}"
        }
      }
    }
    stage("Show some stats") {
      steps {
        script {
          sh "echo Search profiles"
          sh "cut -d',' -f3-3 ${LOG_QUERIES} | sort | uniq -c"
          sh "echo Search agencies"
          sh "cut -d',' -f1-1 ${LOG_QUERIES} | tr -d '{' | sort | uniq -c"
        }
      }
    }
  }
  post {
    failure {
      script {
        slackSend(channel: 'fbi-frontend-is',
          color: 'warning',
          message: "${env.JOB_NAME} #${env.BUILD_NUMBER} failed and needs attention: ${env.BUILD_URL}",
          tokenCredentialId: 'slack-global-integration-token')
      }
    }
    success {
      script {
        if ("${env.BRANCH_NAME}" == 'master') {
          sh "echo archive ${LOG_QUERIES}"
          archiveArtifacts "${LOG_QUERIES}"
          sh "echo push to ${ARTIFACTORY_GENERIC}${LOG_QUERIES}"
          sh "curl -u ${ARTIFACTORY_CREDENTIALS} -T ${LOG_QUERIES} ${ARTIFACTORY_GENERIC}${LOG_QUERIES}"
          slackSend(channel: 'fbi-frontend-is',
            color: 'good',
            message: "${env.JOB_NAME} #${env.BUILD_NUMBER} completed, and pushed ${LOG_QUERIES} to ${ARTIFACTORY_GENERIC}",
            tokenCredentialId: 'slack-global-integration-token')
        }
      }
    }
  }
}
