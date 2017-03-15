FROM docker.dbc.dk/dbc-apache-php

# This should probably be included in a base image
RUN apt-get update
RUN DEBIAN_FRONTEND=noninteractive apt-get -y install gettext \
 && apt-get -y install memcached			      \
 && apt-get clean                                             \
 && rm -rf /var/lib/apt/lists/*

# Dummy front html page.
ADD index.html $APACHE_ROOT/index.html
ADD run.sh /run.sh

# Download and prepare php source-code
RUN mkdir $APACHE_ROOT/opensearch
ADD opensearch-webservice.tar.gz $APACHE_ROOT/opensearch

RUN ln -s $APACHE_ROOT/opensearch/server.php $APACHE_ROOT/opensearch/index.php \
 && echo "error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT & ~E_NOTICE" >> /etc/php5/apache2/php.ini

EXPOSE 80

# Provide means of traceability via build time arguments
ARG JENKINS_BUILD_NUMBER="N/A"
ARG JENKINS_BUILD_ID="N/A"
ARG JENKINS_BUILD_URL="N/A"
ARG JENKINS_BUILD_TAG="N/A"
ARG OPENSEARCH_TAG="N/A"
ENV JENKINS_BUILD_NUMBER=$JENKINS_BUILD_NUMBER
ENV JENKINS_BUILD_ID=$JENKINS_BUILD_ID
ENV JENKINS_BUILD_URL=$JENKINS_BUILD_URL
ENV JENKINS_BUILD_TAG=$JENKINS_BUILD_TAG
ENV OPENSEARCH_TAG=$OPENSEARCH_TAG

# openSearch default settings
ENV AAA_IP_RIGHTS_BLOCK="aaa_ip_rights[dbc][ip_list] = 0.0.0.0-255.255.255.255\naaa_ip_rights[dbc][ressource][opensearch] = 500"
ENV AGENCY_FALLBACK=""
ENV AGENCY_PROFILE_FALLBACK=""
ENV FORMAT_JS_SERVER_BLOCK=""
ENV FORMAT_CURL_TIMEOUT=""
ENV FORMAT_CACHE_SETTINGS_BLOCK=""
ENV MY_DOMAIN=".dbc.dk"
ENV MY_DOMAIN_IP_LIST="127.0.0.1;172.16.0.0-172.31.255.255;193.111.162.0-193.111.162.255"
ENV EXTRA_REPOSITORIES=""
ENV LOGFILE="php://stdout"
ENV RAW_RECORD_REPOSITORY_NAME="rawrepo"
ENV REPOSITORY_NAME="test"
ENV VERBOSE_LEVEL="WARNING+ERROR+FATAL+STAT+TIMER"
ENV WORLDCAT_ZTARGET=""
ENV WORLDCAT_ZTARGET_AUTHENTICATION=""
ENV WORLDCAT_ZTARGET_DATABASE=""
ENV WORLDCAT_ZTARGET_ELEMENT=""
ENV WORLDCAT_ZTARGET_PROXY=""
ENV WORLDCAT_ZTARGET_SYNTAX=""

LABEL AAA_IP_RIGHTS_BLOCK="Sets authentication and access scheme; Use aaa_ip_rights and/or aaa_fors_rights"
LABEL AGENCY_END_POINT="The full url for the openAgency service"
LABEL AGENCY_FALLBACK="Default agency fallback code - for development and testing only"
LABEL AGENCY_PROFILE_FALLBACK="Default search profile fallback - for development and testing only"
LABEL FEDORA="Url to the corepo server"
LABEL FORMAT_JS_SERVER_BLOCK="To include openFormat code in opensearch - only for special circumstances"
LABEL FORMAT_CURL_TIMEOUT="timeout (in seconds) if openFormat is included"
LABEL FORMAT_CACHE_SETTINGS_BLOCK="cache host, port and lifetime if openFormat is included"
LABEL HOLDINGS_DB="The full url for the holdings_db service"
LABEL HOLDINGS_ITEMS_INDEX="The name of the holdings items core in solr"
LABEL MY_DOMAIN="The domain for trusted inhouse. If MY_DOMAIN_IP_LIST is set, MY_DOMAIN is ignored. Like .dbc.dk"
LABEL MY_DOMAIN_IP_LIST="; separated intervals of ip's to be trusted as in house addresses. Like 1.2.3.1-1.2.3.255;127.0.0.1"
LABEL EXTRA_REPOSITORIES="Only used if multiple repositories are needed in one opensearch - normally only used for development and testing"
LABEL LOGFILE="Full path for logile. Normally php://stdout is used"
LABEL OPEN_FORMAT="The full url for the openFormat service"
LABEL RAW_RECORD_CONTENT_SERVICE="The url for raw records content service"
LABEL RAW_RECORD_REPOSITORY_NAME="Name of raw record repository - normally rawrepo"
lABEL RAW_RECORD_SOLR="The url for raw records solr"
LABEL REPOSITORY_NAME="Some name describind the repository, like cisterne, mytest"
LABEL SOLR="Url to the solr server"
LABEL VERBOSE_LEVEL=" List of log levels needed, maximum as: WARNING+ERROR+FATAL+STAT+TIMER+TRACE+DEBUG"
LABEL WORLDCAT_ZTARGET="Not used"
LABEL WORLDCAT_ZTARGET_AUTHENTICATION="Not used"
LABEL WORLDCAT_ZTARGET_DATABASE="Not used"
LABEL WORLDCAT_ZTARGET_ELEMENT="Not used"
LABEL WORLDCAT_ZTARGET_PROXY="Not used"
LABEL WORLDCAT_ZTARGET_SYNTAX="Not used"


CMD ["/run.sh"]
