FROM docker.dbc.dk/dbc-apache-php7

COPY memcached.conf /etc/

# Download and prepare php source-code
RUN mkdir $APACHE_ROOT/opensearch
ADD opensearch-webservice.tar.gz $APACHE_ROOT/opensearch

RUN $( cd $APACHE_ROOT/opensearch/ ; ln -s server.php index.php ) \
 && echo "error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT & ~E_NOTICE" >> /etc/php/7.0/apache2/php.ini  \
 && perl -pi -e 's/MaxRequestWorkers.*/MaxRequestWorkers\t 50/' /etc/apache2/mods-available/mpm_prefork.conf


EXPOSE 80

# openSearch default settings
ENV AGENCY_FALLBACK="" \
    AGENCY_CACHE_SETTINGS="agency[cache_host] = localhost\nagency[cache_port] = 11211\nagency[cache_expire] = 300" \
    AGENCY_PROFILE_FALLBACK="" \
    CACHE_SETTINGS="cache_host = localhost\ncache_port = 11211\ncache_expire = 600" \
    FORMAT_JS_SERVER_BLOCK="" \
    FORMAT_CURL_TIMEOUT="" \
    FORMAT_CACHE_SETTINGS_BLOCK="" \
    MY_DOMAIN=".dbc.dk" \
    MY_DOMAIN_IP_LIST="127.0.0.1;172.16.0.0-172.31.255.255;193.111.162.0-193.111.162.255" \
    EXTRA_REPOSITORIES="" \
    LOGFILE="php://stdout" \
    RAW_RECORD_REPOSITORY_NAME="rawrepo" \
    REPOSITORY_NAME="test" \
    SERVICE_LOCATION="" \
    VERBOSE_LEVEL="WARNING+ERROR+FATAL+STAT+TIMER"

LABEL AAA_IP_RIGHTS_BLOCK="Sets authentication and access scheme; Use aaa_ip_rights and/or aaa_fors_rights" \
      AGENCY_CACHE_SETTINGS="cache host, port and timeout to cache openAgency calls; use CACHE_SETTINGS if not set" \
      AGENCY_END_POINT="The full url for the openAgency service" \
      AGENCY_FALLBACK="Default agency fallback code - for development and testing only" \
      AGENCY_PROFILE_FALLBACK="Default search profile fallback - for development and testing only" \
      CACHE_SETTINGS="cache host, port and timeout to cache search-structures" \
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
      VERBOSE_LEVEL=" List of log levels needed, maximum as: WARNING+ERROR+FATAL+STAT+TIMER+TRACE+DEBUG"

COPY run.sh /run.sh
CMD ["/run.sh"]
