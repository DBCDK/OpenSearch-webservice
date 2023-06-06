#!groovy

pipeline {
  agent {
    label "devel10"
  }
//  triggers {
//    cron("02 02 * * *")
//  }
  environment {
    DATE = new Date().previous().format("yyyy-MM-dd")
    LOG_FILE = "os_daily_queries.${DATE}"
    ELK_URI = "https://elk.dbc.dk:9100"
    ELK_CREDENTIALS = credentials('elk_user');
    ARTIFACTORY_GENERIC = "https://artifactory.dbccloud.dk/artifactory/generic-fbiscrum-production/opensearch/"
    ARTIFACTORY_LOGIN = credentials("artifactory_login")

  }
  stages {
    stage("clear workspace") {
      steps {
        deleteDir()
        checkout scm
      }
    }
    stage("extract loglines") {
      steps { script {
        sh "echo Fetch searches from log for ${DATE}"
        sh "rm -f /tmp/${LOG_FILE}"
        sh "./cron/fetch_queries_from_elk -o /tmp/${LOG_FILE} -e ${ELK_URI} -p ${ELK_CREDENTIALS} -d ${DATE}"
      } }
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
        sh "echo archive ${LOG_FILE}"
        archiveArtifacts "${LOG_FILE}"
        sh "echo push to ${ARTIFACTORY_GENERIC}${LOG_FILE}"
        sh "curl -u ${ARTIFACTORY_LOGIN} -T /tmp/${LOG_FILE} ${ARTIFACTORY_GENERIC}${LOG_FILE}"
        slackSend(channel: 'fbi-frontend-is',
          color: 'good',
          message: "${env.JOB_NAME} #${env.BUILD_NUMBER} completed, and pushed ${LOG_FILE} to ${ARTIFACTORY_GENERIC}",
          tokenCredentialId: 'slack-global-integration-token')
      }
    }
  }
}
