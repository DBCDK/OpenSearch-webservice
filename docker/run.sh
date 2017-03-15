#!/usr/bin/env bash
set -e

DIR=/var/www/html/opensearch
INI=$DIR/opensearch.ini
INSTALL=$INI"_INSTALL"

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


ln -sf /dev/stdout /var/log/apache2/access.log
ln -sf /dev/stderr /var/log/apache2/error.log

/etc/init.d/memcached start

exec apache2ctl -DFOREGROUND
