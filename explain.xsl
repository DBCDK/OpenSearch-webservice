<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE xsl:stylesheet [
  <!ENTITY nbsp "&#160;">
]>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:exp="http://explain.z3950.org/dtd/2.0/">
  <xsl:variable name="header">
    <tr>
      <td><b>Namespace</b></td>
      <td><b>Index</b></td>
      <td><b>Slop</b></td>
      <td><b>Filter</b></td>
      <td><b>Alias</b></td>
      <td><b>Alias slop</b></td>
      <td><b>English description</b></td>
      <td><b>Danish description</b></td>
    </tr>
  </xsl:variable>
  <xsl:template match="/">
    <html>
      <head>
        <title>CQL indexes</title>
        <style type="text/css">
          td {font-family: verdana; font-size: 10; padding: 0px 4px 0px 4px; vertical-align:top}
          ol {font-family: verdana; font-size: 10}
          ul {font-family: verdana; font-size: 12}
          div {font-family: verdana; font-size: 12}
          h1 {font-family: verdana; font-size: 18}
        </style>
      </head>
      <body>
        <h1>CQL Indexes</h1>
        <div>This list is manually updated. Last updated <xsl:value-of select="/exp:explain/exp:metaInfo/exp:dateModified"/></div>
        <div>Denne liste opdateres manuelt. Senest opdateret <xsl:value-of select="/exp:explain/exp:metaInfo/exp:dateModified"/></div>
        <br />
        <div><b>Namespace</b> and <b>Index</b> defines the name of the index, like dkcclterm.fo</div>
        <div><b>Slop</b> is used when search for more words in the same word-register, like dkcclterm.fo="more than one word" and defines the maximum distance the words may have. If no slop is defined, the default 9999 is used, which will work almost like boolean AND</div>
        <div><b>Filter</b> defines the use of filterQuery for the index</div>
        <div><b>Alias</b> defines alias'es for the index. There are no limit to the number of alias'es, as long as they are unique</div>
        <div><b>Alias slop</b> sets the slop for the alias, this can be different from the index slop</div>
        <br/>
        <table border="1" cellspacing="0">
          <xsl:copy-of select="$header" />
          <xsl:for-each select="/exp:explain/exp:indexInfo/exp:index">
            <xsl:if test="not(exp:map/@hidden)">
              <xsl:variable name="thisset" select="exp:map/exp:name/@set"/>
            <tr>
              <td>
                <xsl:value-of select="$thisset"/>
              </td>
              <td>
                <xsl:value-of select="exp:map/exp:name"/>
              </td>
              <td>
                <xsl:value-of select="exp:map/exp:name/@slop"/>
              </td>
              <td>
                <xsl:value-of select="exp:map/exp:name/@filter"/>
              </td>
              <td>
                <xsl:for-each select="exp:map/exp:alias">
                  <xsl:value-of select="current()"/>
                  <br/>
                </xsl:for-each>
              </td>
              <td>
                <xsl:for-each select="exp:map/exp:alias">
                  <xsl:value-of select="current()/@slop"/>
                  <br/>
                </xsl:for-each>
              </td>
              <td>
                <xsl:value-of select="exp:title[@lang='en']"/>
              </td>
              <td>
                <xsl:value-of select="exp:title[@lang='da']"/>
              </td>
            </tr>
              <xsl:variable name="lastset" select="$thisset"/>
            </xsl:if>
          </xsl:for-each>
        </table>
        <br />
      </body>
    </html>
  </xsl:template>
</xsl:stylesheet>
