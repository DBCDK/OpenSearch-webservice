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
					td {font-family: verdana; font-size: 10}
					ol {font-family: verdana; font-size: 10}
					ul {font-family: verdana; font-size: 12}
					div {font-family: verdana; font-size: 12}
					h1 {font-family: verdana; font-size: 18}
				</style>
			</head>
			<body>
				<h1>CQL Indexes</h1>
				<div>This list is manually updated. Last updated July 23, 2013</div>
				<div>Denne liste opdateres manuelt. Senest opdateret 23. juli 2013</div>
				<br/>
				<table border="1">
					<tr>
						<td>
							<b>Namespace</b>
						</td>
						<td>
							<b>Index</b>
						</td>
						<td>
							<b>English description</b>
						</td>
						<td>
							<b>Danish description</b>
						</td>
					</tr>
					<xsl:for-each select="/exp:explain/exp:indexInfo/exp:index">
						<tr>
							<td>
								<xsl:value-of select="exp:map/exp:name/@set"/>
							</td>
							<td>
								<xsl:value-of select="exp:map/exp:name/@set"/>.<xsl:value-of select="exp:map/exp:name"/>
							</td>
							<td>
								<xsl:value-of select="exp:title[@lang='en']"/>
							</td>
							<td>
								<xsl:value-of select="exp:title[@lang='da']"/>
							</td>
						</tr>
					</xsl:for-each>
				</table>
			</body>
		</html>
	</xsl:template>
</xsl:stylesheet>
