#!/usr/bin/env bash
set -e

DIR=$APACHE_ROOT/opensearch
INI=$DIR/opensearch.ini
INSTALL=$INI"_INSTALL"

cp $DIR/opensearch.wsdl_INSTALL $DIR/opensearch.wsdl
cp $INSTALL $INI

while IFS='=' read -r name value ; do
  echo "$name $value"
  sed -i "s/@${name}@/$(echo $value | sed -e 's/\//\\\//g; s/&/\\\&/g')/g" $INI
done < <(env)

cat $INI

if [ -n "`grep '@[A-Z_]*@' $INI`" ] 
then
  printf "\nMissed some settings:\n"
  echo "------------------------------"
  grep '@[A-Z_]*@' $INI
  echo "------------------------------"
  printf "\nAdd the missing setting(s) and try again\n\n"
  exit 1
fi


if [ -z "$URL_PATH" ]
then
  printf "\nMissed PATH configuration :\n"
  echo "------------------------------"

  echo "------------------------------"
  printf "\nAdd the missing setting(s) and try again\n\n"
  exit 1
fi

mv $APACHE_ROOT/opensearch $APACHE_ROOT/$URL_PATH

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


ln -sf /dev/stdout /var/log/apache2/access.log
ln -sf /dev/stderr /var/log/apache2/error.log

/etc/init.d/memcached start

exec apache2ctl -DFOREGROUND
