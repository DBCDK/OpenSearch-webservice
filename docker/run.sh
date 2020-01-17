#!/usr/bin/env bash
set -e

DIR=$APACHE_ROOT/opensearch
INI=$DIR/opensearch.ini
INSTALL=$INI"_INSTALL"

cp $DIR/opensearch.wsdl_INSTALL $DIR/opensearch.wsdl

if [ ! -f $INI ] ; then
    cp $INSTALL $INI

    while IFS='=' read -r name value ; do
      echo "$name $value"
      sed -i "s/@${name}@/$(echo $value | sed -e 's/\//\\\//g; s/&/\\\&/g')/g" $INI
    done < <(env)

    if [ -n "`grep '@[A-Z_]*@' $INI`" ]
    then
      printf "\nMissed some settings:\n"
      echo "------------------------------"
      grep '@[A-Z_]*@' $INI
      echo "------------------------------"
      printf "\nAdd the missing setting(s) and try again\n\n"
      exit 1
    fi
else

    echo "######  ####### #     # ####### #       ####### ####### ######  ####### ######"
    echo "#     # #       #     # #       #       #       #     # #     # #       #     #"
    echo "#     # #       #     # #       #       #       #     # #     # #       #     #"
    echo "#     # #####   #     # #####   #       #####   #     # ######  #####   ######"
    echo "#     # #        #   #  #       #       #       #     # #       #       #   #"
    echo "#     # #         # #   #       #       #       #     # #       #       #    #"
    echo "######  #######    #    ####### ####### ####### ####### #       ####### #     #"
    echo ""
    echo "#     # ####### ######  #######"
    echo "##   ## #     # #     # #"
    echo "# # # # #     # #     # #"
    echo "#  #  # #     # #     # #####"
    echo "#     # #     # #     # #"
    echo "#     # #     # #     # #"
    echo "#     # ####### ######  #######"

fi


if [ -z "$URL_PATH" ]
then
  printf "\nMissed PATH configuration :\n"
  echo "------------------------------"

  echo "------------------------------"
  printf "\nAdd the missing setting(s) and try again\n\n"
  exit 1
fi

# MBD: I am not sure we want this?
cat - > $APACHE_ROOT/index.html <<EOF
<html>
<head>
<title>OpenSearch $URL_PATH</title>
<meta http-equiv="refresh" content="0; url=${URL_PATH}" />
</head>
<body>
<p><a href="${URL_PATH}/">Opensearch</a></p>
</body>
</html>
EOF

sed -i 's/CustomLog ${APACHE_LOG_DIR}\/access.log combined/CustomLog ${APACHE_LOG_DIR}\/access.log json_log/' /etc/apache2/sites-enabled/000-default.conf

/etc/init.d/memcached start

exec apache2ctl -DFOREGROUND
