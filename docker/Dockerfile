FROM docker-dbc.artifacts.dbccloud.dk/dbc-apache-php8:latest

LABEL maintainer="Team Search <os-team@dbc.dk>" \
      AAA_IP_LIST="Sets AAA access for this IP pattern. Default is full access for localhost [127.0.0.1]" \
      AAA_FORS_RIGHTS="Sets the URL for aaa_fors_rights. Default is the empty string, which is 'not set'. []" \
      VIPCORE_ENDPOINT="The full url for the VipCore service" \
      AGENCY_FALLBACK="Default agency fallback code - for development and testing only" \
      AGENCY_PROFILE_FALLBACK="Default search profile fallback - for development and testing only" \
      VIPCORE_CACHE_HOST="VipCore calls memcache host [localhost]" \
      VIPCORE_CACHE_PORT="VipCore calls memcache port [11211]" \
      VIPCORE_CACHE_EXPIRE="VipCore calls mencache expiration [3600]" \
      CACHE_HOST="Search results memcache host [localhost]" \
      CACHE_PORT="Search results memcache port [11211]" \
      CACHE_EXPIRE="Search results memcache expiration [600]" \
      CACHE_TYPE="Set to 'redis' to use Redis as a cache for external caches (casesentive). [memcache]" \
      CACHE_CONNECT_TIMEOUT="Connect timeout when using Redis (seconds, with decimals) [1]" \
      CACHE_READ_TIMEOUT="Read timeout when using Redis (seconds, with decimals) [1]" \
      CACHE_IS_CLUSTER="Set to 1 if clustered Redis cache, 0 otherwise [0]" \
      FEDORA="Url to the corepo server" \
      FORMAT_JS_SERVER_BLOCK="To include openFormat code in opensearch - only for special circumstances" \
      FORMAT_CURL_TIMEOUT="timeout (in seconds) if openFormat is included" \
      FORMAT_CACHE_SETTINGS_BLOCK="cache host, port and lifetime if openFormat is included" \
      HOLDINGS_DB="The full url for the holdings_db service" \
      HOLDINGS_ITEMS_INDEX="The name of the holdings items core in solr" \
      MY_DOMAIN="The domain for trusted inhouse. If MY_DOMAIN_IP_LIST is set, MY_DOMAIN is ignored. Like .dbc.dk" \
      MY_DOMAIN_IP_LIST="; separated intervals of ip's to be trusted as in house addresses. Like 1.2.3.1-1.2.3.255;127.0.0.1" \
      EXTRA_REPOSITORIES="Only used if multiple repositories are needed in one opensearch - normally only used for development and testing" \
      LOGFILE="Full path for logile. Normally php://stdout is used" \
      OPEN_FORMAT="The full url for the openFormat service" \
      NETPUNKT_OPENFORMAT="The full url for the NETPUNKT openFormat service" \
      RAW_RECORD_CONTENT_SERVICE="The url for raw records content service" \
      RAW_RECORD_REPOSITORY_NAME="Name of raw record repository - normally rawrepo" \
      RAW_RECORD_SOLR="The url for raw records solr" \
      REPOSITORY_NAME="Some name describind the repository, like cisterne, mytest" \
      SERVICE_LOCATION="The url to the service. Used as endpoint in the wsdl-file. Default to the actual location" \
      SOLR="Url to the solr server" \
      USE_HOLDING_BLOCK_JOIN="Switch to control use of block_join for filtering holdings" \
      VERBOSE_LEVEL="List of log levels needed, maximum as: WARNING+ERROR+FATAL+STAT+TIMER+TRACE+DEBUG"


# This is modelled after vip-php. I think it is a bit many layers, but ... no big deal.
# NOTE: The BUILDNUMBER file is no longer generated or copied - we can add it back, if we need it
COPY --chown=sideejer:sideejer src/class_lib/ ${DBC_PHP_INSTALL_DIR}/class_lib/
COPY --chown=sideejer:sideejer src/xml/ ${DBC_PHP_INSTALL_DIR}/xml/
COPY --chown=sideejer:sideejer src/vendor/ ${DBC_PHP_INSTALL_DIR}/vendor/
COPY --chown=sideejer:sideejer src/*.xsd ${DBC_PHP_INSTALL_DIR}/
COPY --chown=sideejer:sideejer src/*.html ${DBC_PHP_INSTALL_DIR}/
COPY --chown=sideejer:sideejer src/*_INSTALL ${DBC_PHP_INSTALL_DIR}/
# This is slight hack - if BUILDNUMBER exists, it is copied. If not, it is not.
COPY --chown=sideejer:sideejer BUILDNUMBE? src/*.php ${DBC_PHP_INSTALL_DIR}/
# Would be nice to not have to have this file?
USER root
RUN ln -s ${DBC_PHP_INSTALL_DIR}/server.php ${DBC_PHP_INSTALL_DIR}/index.php && chown sideejer:sideejer ${DBC_PHP_INSTALL_DIR}/index.php
USER www-data

# Do not forget to add a memcache
ENV USE_MEMCACHE=yes

# openSearch default settings
ENV AGENCY_FALLBACK="" \
    AGENCY_PROFILE_FALLBACK="" \
    VIPCORE_CACHE_HOST="localhost" \
    VIPCORE_CACHE_PORT=11211 \
    VIPCORE_CACHE_EXPIRE=3600 \
    CACHE_HOST="localhost" \
    CACHE_PORT=11211 \
    CACHE_EXPIRE=600 \
    CACHE_TYPE="memcache" \
    CACHE_IS_CLUSTER="0" \
    CACHE_CONNECT_TIMEOUT="1" \
    CACHE_READ_TIMEOUT="1" \
    FORMAT_JS_SERVER_BLOCK="" \
    FORMAT_CURL_TIMEOUT="" \
    FORMAT_CACHE_SETTINGS_BLOCK="" \
    MY_DOMAIN=".dbc.dk" \
    MY_DOMAIN_IP_LIST="127.0.0.1;172.16.0.0-172.31.255.255;193.111.162.0-193.111.162.255" \
    AAA_IP_LIST="127.0.0.1" \
    AAA_FORS_RIGHTS="" \
    EXTRA_REPOSITORIES="" \
    LOGFILE="php://stdout" \
    RAW_RECORD_REPOSITORY_NAME="rawrepo" \
    REPOSITORY_NAME="test" \
    SERVICE_LOCATION="" \
    USE_HOLDING_BLOCK_JOIN="yes" \
    VERBOSE_LEVEL="WARNING+ERROR+FATAL+STAT+TIMER"
