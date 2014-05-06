<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE xsl:stylesheet [
	<!ENTITY nbsp "&#160;">
]>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:exp="http://explain.z3950.org/dtd/2.0/">
	<xsl:template match="/">
		<html>
			<head>
				<title>CQL indexes</title>
				<style type="text/css">
					td {font-family: verdana; font-size: 10; padding: 0px 4px 0px 4px;}
					ol {font-family: verdana; font-size: 10}
					ul {font-family: verdana; font-size: 12}
					div {font-family: verdana; font-size: 12}
					h1 {font-family: verdana; font-size: 18}
				</style>
			</head>
			<body>
				<h1>CQL Indexes</h1>
				<div>This list is manually updated. Last updated february 17, 2014</div>
				<div>Denne liste opdateres manuelt. Senest opdateret 17. februar 2014</div>
				<br/>
				<table border="1" cellspacing="0">
					<tr>
						<td>
							<b>Namespace</b>
						</td>
						<td>
							<b>Index</b>
						</td>
						<td>
							<b>Slop</b>
						</td>
						<td>
							<b>Filter</b>
						</td>
						<td>
							<b>English description</b>
						</td>
						<td>
							<b>Danish description</b>
						</td>
					</tr>
					<xsl:for-each select="/exp:explain/exp:indexInfo/exp:index">
					  <xsl:if test="not(exp:map/@hidden)">
						<tr>
							<td>
								<xsl:value-of select="exp:map/exp:name/@set"/>
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
								<xsl:value-of select="exp:title[@lang='en']"/>
							</td>
							<td>
								<xsl:value-of select="exp:title[@lang='da']"/>
							</td>
						</tr>
					  </xsl:if>
					</xsl:for-each>
				</table>
                <br />
				<div>Aliases (short forms)</div>
                <br />
				<table border="1" cellspacing="0">
					<tr>
						<td>
							<b>Namespace</b>
						</td>
						<td>
							<b>Name</b>
						</td>
						<td>
							<b>Slop</b>
						</td>
						<td>
							<b>Alias namespace</b>
						</td>
						<td>
							<b>Alias name</b>
						</td>
					</tr>
					<xsl:for-each select="/exp:explain/exp:indexInfo/exp:index/exp:map">
					  <xsl:if test="exp:alias">
						<tr>
							<td>
								<xsl:value-of select="exp:alias/@set"/>
							</td>
							<td>
								<xsl:value-of select="exp:alias"/>
							</td>
							<td>
								<xsl:value-of select="exp:alias/@slop"/>
							</td>
							<td>
								<xsl:value-of select="exp:name/@set"/>
							</td>
							<td>
								<xsl:value-of select="exp:name"/>
							</td>
						</tr>
					  </xsl:if>
					</xsl:for-each>
				</table>
			</body>
		</html>
	</xsl:template>
</xsl:stylesheet>
