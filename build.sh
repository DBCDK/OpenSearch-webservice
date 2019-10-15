#!/usr/bin/env bash

function die() {
   echo ERROR : $@
   exit 1
}
[ -f  opensearch.ini_INSTALL ] || die "Run fetch script from opensearch top level directory"

[ -d OLS_class_lib ] || mkdir OLS_class_lib

cd OLS_class_lib

FILES_TO_FETCH="aaa_class.php cql2tree_class.php curl_class.php inifile_class.php ip_class.php jsonconvert_class.php memcache_class.php objconvert_class.php object_class.php _object_class.php oci_class.php open_agency_v2_class.php registry_class.php restconvert_class.php solr_query_class.php timer_class.php verbose_json_class.php webServiceServer_class.php xmlconvert_class.php"

for f in ${FILES_TO_FETCH} ; do
  echo fetch $f from https://svn.dbc.dk/repos/php/OpenLibrary/class_lib/trunk/$f
  [ -f $f ] && rm $f
  wget -q https://svn.dbc.dk/repos/php/OpenLibrary/class_lib/trunk/$f
done

cd ..

if [ -n "${BUILD_NUMBER}" ]; then
  echo ${BUILD_NUMBER} > BUILDNUMBER
else
  echo "Build Number Unknown" > BUILDNUMBER
fi


ln -s server.php index.php
tar czf docker/opensearch-webservice.tar.gz --exclude-vcs doc includes xml *.xsd *.php *.html *_INSTALL OLS_class_lib BUILDNUMBER

echo 
echo "ready for docker build"
echo
echo "to rebuild docker image run"
echo "cd docker ; docker build -t opensearch:devel ."
