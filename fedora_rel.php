<?php

if (empty($_REQUEST['pid'])) $_REQUEST['pid'] = '870970-basis:23645564';
if (empty($_REQUEST['fedoraurl'])) $_REQUEST['fedoraurl'] = 'http://wellness-p01.dbc.dk:8080/fedora/objects/';

require_once('OLS_class_lib/inifile_class.php');
require_once('OLS_class_lib/curl_class.php');

$vars = array('pid' => trim($_REQUEST['pid']), 'fedora_url_options' => '', 'content' => '');

$cfg = new inifile('opensearch.ini');
in_house_or_die($cfg->get_value('in_house_domain'));

$template = get_template_or_die();

$repository = $cfg->get('repository');
$vars['fedora_url_options'] = set_fedora_options($repository, $_REQUEST['fedoraurl']);

if ($vars['pid']) {
  if ($rels = get_fedora_rels($_REQUEST['fedoraurl'], $vars['pid'], $repository['defaults'])) {
    $vars['content'] = rels_to_html($rels);
  }
  else {
    $vars['content'] = 'Kan ikke finde posten';
  }
}

set_placeholders($template, $vars);

header('Content-Type: text/html; charset=utf-8');
echo $template;

/* ------------------------------------------------------------------------ */

function rels_to_html($rels) {
  $html = '';
  foreach ($rels as $type => $rel) {
    $html .= '<a class="butt" href="javascript:flip_div(\'' . $type . '\')">' . $type . ' (' . count($rel) . ')</a><br /><br />' .
             '<div id="' . $type . '" style="display:none"><table>' . PHP_EOL;
    foreach ($rel as $pid) {
      $html .= '<tr><td>' . str_replace('(', '</td><td>(', $pid) . '</td></tr>' . PHP_EOL;
    }
    $html .= '</table><br/></div>' . PHP_EOL;
  }
  return $html;
}

function get_fedora_rels($url, $pid, $urls) {
  if ($unit_no = get_unit_from_rels_sys($url . sprintf($urls['fedora_get_rels_hierarchy'], $pid))) {
    $rels = get_rels_from_rels_ext($url . sprintf($urls['fedora_get_rels_addi'], $unit_no));
    foreach ($rels as &$rel) {
      foreach ($rel as &$unit) {
        $unit = get_unit_from_pid($url . sprintf($urls['fedora_get_rels_hierarchy'], $unit)) . ' (' . $unit . ')';
      }
    }
    //$pids = get_unit_from_pid($url . sprintf($urls['fedora_get_rels_hierarchy'], 'unit:1080592'));
    return $rels;
  }
  else {
    return FALSE;
  }
}

function get_unit_from_pid($url) {
  static $dom;
  if (empty($dom)) {
    $dom = new DomDocument();
    $dom->preserveWhiteSpace = false;
  }
  $rels_hierarchy = get_fedora($url);
  if (@ $dom->loadXML($rels_hierarchy)) {
    //$hmof = $dom->getElementsByTagName('hasMemberOfUnit');
    $hpbo = $dom->getElementsByTagName('hasPrimaryBibObject');
    if ($hpbo->item(0))
      return($hpbo->item(0)->nodeValue);
  }
  return FALSE;
}

function get_rels_from_rels_ext($url) {
  static $dom;
  if (empty($dom)) {
    $dom = new DomDocument();
    $dom->preserveWhiteSpace = false;
  }
  $ret = array();
  $rels_ext = get_fedora($url);
  if (@ $dom->loadXML($rels_ext)) {
    foreach ($dom->getElementsByTagName('Description')->item(0)->childNodes as $tag) {
      if ($tag->nodeType == XML_ELEMENT_NODE) {
        $ret[$tag->localName][] = $tag->nodeValue;
      }
    }
  }
  return $ret;
}

function get_unit_from_rels_sys($url) {
  static $dom;
  if (empty($dom)) {
    $dom = new DomDocument();
    $dom->preserveWhiteSpace = false;
  }
  $rels_hierarchy = get_fedora($url);
  if (@ $dom->loadXML($rels_hierarchy)) {
    $imo = $dom->getElementsByTagName('isPrimaryBibObjectFor');
    if ($imo->item(0))
      return($imo->item(0)->nodeValue);
    else {
      $imo = $dom->getElementsByTagName('isMemberOfUnit');
      if ($imo->item(0))
        return($imo->item(0)->nodeValue);
    }
    return FALSE;
  }
}

function get_fedora($url) {
  static $curl;
  if (empty($curl)) {
    $curl = new Curl();
    $curl->set_authentication('fedoraAdmin', 'fedoraAdmin');
  }
  $rec = $curl->get($url);
  return $rec;
}

function set_fedora_options($repository, $selected) {
  $used = array();
  foreach ($repository as $repos) {
    if (($fedora = $repos['fedora']) && !in_array($fedora, $used)) {
      $ret .= '<option' . ($fedora == $selected ? ' selected' : '') . '>' . $fedora . '</option>' . PHP_EOL;
      $used[] = $fedora;
    }
  }
  return $ret;
}

function set_placeholders(&$template, $vars) {
  foreach ($vars as $ph => $var) {
    $template = str_replace('__' . strtoupper($ph) . '__', $var, $template);
  }
}

function in_house_or_die($domain) {
  if (empty($domain)) {
    $domain = '.dbc.dk';
  }
  @ $remote = gethostbyaddr($_SERVER['REMOTE_ADDR']);
  $domains = explode(';', $domain);
  foreach ($domains as $dm) {
    $dm = trim($dm);
    if ($homie = (strpos($remote, $dm) + strlen($dm) == strlen($remote))) {
      if ($homie = (gethostbyname($remote) == $_SERVER['REMOTE_ADDR'])) {
        return;
      }
    }
  }
  header('HTTP/1.0 404 Not Found');
  die();
}

function get_template_or_die() {
  $name = str_replace('.php', '.html', basename($_SERVER['SCRIPT_NAME']));
  if (!$html = file_get_contents($name)) {
    die('Cannot find ' . $name);
  }
  return $html;
}
